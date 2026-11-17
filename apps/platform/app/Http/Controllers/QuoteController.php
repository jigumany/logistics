<?php

namespace App\Http\Controllers;

use App\Domain\Customers\Customer;
use App\Domain\Quotes\Quote;
use App\Domain\Quotes\QuotePermissions;
use App\Domain\Quotes\QuoteRevision;
use App\Domain\Quotes\QuoteStatusHistory;
use App\Domain\Quotes\ReviewQuote;
use App\Http\Resources\QuotationView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class QuoteController extends Controller
{
    private function authorizeView(Request $request): void
    {
        abort_unless(in_array($request->attributes->get('membership')?->role->value, ['owner', 'admin', 'dispatcher'], true), 403);
    }

    public function index(Request $request): Response
    {
        $this->authorizeView($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:draft,calculating,requires_review,quoted,accepted,expired,declined,cancelled']]);
        $query = Quote::with('currentRevision')->whereNotNull('submitted_at');
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.mb_strtolower($filters['search']).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(reference) LIKE ?', [$term])->orWhereIn('customer_id', Customer::whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(email) LIKE ?', [$term])->select('id')));
        }

        return Inertia::render('company/quotes/Index', ['quotes' => $query->orderByDesc('id')->paginate(20)->withQueryString()->through(fn ($quote) => [...$quote->only('public_id', 'reference', 'status', 'preferred_date', 'created_at'), 'customer' => $quote->currentRevision?->contact_snapshot['name'] ?? '', 'total_minor' => $quote->currentRevision?->total_minor, 'currency' => $quote->currentRevision?->currency]), 'filters' => $filters]);
    }

    public function show(Request $request, string $company, string $quote, QuotationView $view): Response
    {
        $this->authorizeView($request);
        $model = Quote::where('public_id', $quote)->firstOrFail();

        return Inertia::render('company/quotes/Show', ['quote' => $view->make($model, true), 'history' => QuoteStatusHistory::where('quote_id', $model->id)->orderByDesc('id')->paginate(20, ['*'], 'history_page')->withQueryString()->through(fn ($row) => $row->only('from_status', 'to_status', 'created_at')), 'revisions' => QuoteRevision::where('quote_id', $model->id)->with(['lines', 'inventory', 'stops'])->orderByDesc('number')->paginate(10, ['*'], 'revisions_page')->withQueryString()->through(fn ($revision) => $view->revision($revision, true))]);
    }

    public function review(Request $request, string $company, string $quote, ReviewQuote $action): RedirectResponse
    {
        app(QuotePermissions::class)->manage($request->user()->id);
        $action->execute(Quote::where('public_id', $quote)->firstOrFail(), $request->all(), $request->user()->id);

        return back()->with('success', 'Quotation review recorded.');
    }
}
