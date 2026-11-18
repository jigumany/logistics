<?php

namespace App\Http\Controllers;

use App\Domain\Bookings\Booking;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceDocumentRenderer;
use App\Domain\Invoicing\IssueInvoice;
use App\Domain\Payments\ConfigureYoco;
use App\Domain\Payments\PaymentAccount;
use App\Domain\Payments\PaymentAttempt;
use App\Domain\Quotes\QuotePermissions;
use App\Http\Resources\PaymentSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

final class FinanceController extends Controller
{
    public function index(Request $r, PaymentSummary $summary): Response
    {
        app(QuotePermissions::class)->manage($r->user()->id);
        $filter = $r->validate(['search' => ['nullable', 'string', 'max:100']]);
        $q = Booking::query();
        if ($filter['search'] ?? null) {
            $q->where('reference', 'like', '%'.$filter['search'].'%');
        }$rows = $q->orderByDesc('id')->paginate(20)->withQueryString()->through(fn ($b) => [...$b->only(['public_id', 'reference', 'status', 'financial_state', 'refund_state']), ...$summary->make($b)]);

        return Inertia::render('company/finance/Index', ['bookings' => $rows, 'filters' => $filter, 'yoco' => PaymentAccount::where('provider', 'yoco')->first()?->only(['mode']), 'webhookUrl' => url('/webhooks/yoco/'.$r->route('company'))]);
    }

    public function issue(Request $r, string $company, string $booking): JsonResponse
    {
        $v = $r->validate(['billing_address' => ['nullable', 'string', 'max:2000'], 'billing_name' => ['nullable', 'string', 'max:160'], 'vat_number' => ['nullable', 'string', 'max:40'], 'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addYear()->toDateString()]]);
        $i = app(IssueInvoice::class)->execute(Booking::where('public_id', $booking)->firstOrFail(), $r->user()->id, $v['due_date'], $v);

        return response()->json($i->only(['public_id', 'number', 'total_minor']));
    }

    public function pdf(Request $r, string $company, string $invoice, InvoiceDocumentRenderer $renderer): HttpResponse
    {
        app(QuotePermissions::class)->manage($r->user()->id);
        $i = Invoice::where('public_id', $invoice)->firstOrFail();

        return response($renderer->render($i), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$i->number.'.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function configureYoco(Request $r): JsonResponse
    {
        app(ConfigureYoco::class)->execute($r->only(['mode', 'secret_key', 'webhook_secret']), $r->user()->id);

        return response()->json(['saved' => true]);
    }

    public function payments(Request $r, string $company, string $booking): JsonResponse
    {
        app(QuotePermissions::class)->manage($r->user()->id);
        $b = Booking::where('public_id', $booking)->firstOrFail();

        return response()->json(PaymentAttempt::where('booking_id', $b->id)->orderByDesc('id')->limit(100)->get(['public_id', 'purpose', 'amount_minor', 'currency', 'mode', 'status', 'provider_payment_id', 'verified_at', 'created_at']));
    }
}
