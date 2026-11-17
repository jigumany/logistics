<?php

namespace App\Http\Controllers;

use App\Domain\Geography\QuotationCoverage;
use App\Domain\Inventory\CatalogueSearch;
use App\Domain\Inventory\ResolveInventory;
use App\Domain\Quotes\AcceptQuote;
use App\Domain\Quotes\CalculateQuote;
use App\Domain\Quotes\CloseQuote;
use App\Domain\Quotes\CreateQuoteDraft;
use App\Domain\Quotes\QuotationPolicies;
use App\Domain\Quotes\Quote;
use App\Domain\Quotes\QuoteInput;
use App\Domain\Quotes\QuoteRevision;
use App\Domain\Quotes\RespondToQuote;
use App\Domain\Quotes\SaveQuoteDraft;
use App\Domain\Quotes\SubmitQuote;
use App\Domain\Routing\AddressProvider;
use App\Domain\Routing\RoutingProvider;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\TenantContext;
use App\Http\Resources\QuotationView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

final class GuestQuoteController extends Controller
{
    private function business(): array
    {
        $tenant = app(TenantContext::class)->tenant();

        return $tenant->only('name', 'slug', 'public_id');
    }

    public function wizard(): Response
    {
        return Inertia::render('customer/QuoteWizard', ['business' => $this->business(), 'services' => TenantService::where('enabled', true)->where('active', true)->orderBy('id')->get(['public_id', 'name', 'description']), 'currency' => CompanySettings::value('currency') ?? 'ZAR']);
    }

    public function catalogue(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'string', 'max:80']]);
        $page = $request->validate(['page' => ['nullable', 'integer', 'between:1,10000']])['page'] ?? 1;
        $result = app(CatalogueSearch::class)->execute($filters['search'] ?? '', $filters['category'] ?? null, (int) $page);

        return response()->json(new LengthAwarePaginator($result['rows'], $result['total'], 20, (int) $page, ['path' => $request->url(), 'query' => $filters]));
    }

    public function addressSearch(Request $request, AddressProvider $provider): JsonResponse
    {
        $data = $request->validate(['query' => ['required', 'string', 'min:3', 'max:200'], 'session_token' => ['required', 'uuid']]);

        return response()->json($provider->search($data['query'], $data['session_token']));
    }

    public function addressDetails(Request $request, AddressProvider $provider): JsonResponse
    {
        $data = $request->validate(['place_id' => ['required', 'string', 'max:255'], 'session_token' => ['required', 'uuid']]);

        return response()->json($provider->details($data['place_id'], $data['session_token']));
    }

    public function preview(Request $request, string $company, string $operation): JsonResponse
    {
        if ($operation === 'inventory') {
            $entries = $request->validate(['inventory' => ['required', 'array']])['inventory'];

            return response()->json(app(ResolveInventory::class)->execute(app(QuoteInput::class)->inventory($entries)));
        }
        $input = app(QuoteInput::class)->validate($request->all());
        TenantService::where('public_id', $input['service_public_id'])->where('active', true)->where('enabled', true)->firstOrFail();

        return response()->json(match ($operation) {
            'inventory' => app(ResolveInventory::class)->execute($input['inventory']),
            'route' => app(RoutingProvider::class)->calculate($input['stops']),
            'coverage' => app(QuotationCoverage::class)->evaluate($input['stops'], app(QuotationPolicies::class)->forService((int) TenantService::where('public_id', $input['service_public_id'])->value('id'))),
            default => abort(404),
        });
    }

    public function create(Request $request, CreateQuoteDraft $action, QuotationView $view): JsonResponse
    {
        $data = $request->validate(['idempotency_key' => ['required', 'uuid'], 'input' => ['required', 'array'], 'website' => ['nullable', 'string', 'size:0']]);
        $quote = $action->execute($data['input'], $data['idempotency_key'], hash('sha256', $request->session()->getId()));

        return response()->json($view->make($quote), 201);
    }

    public function show(Request $request, string $company, string $quote, QuotationView $view): Response|JsonResponse
    {
        $model = $this->find($quote);
        $data = $view->make($model);

        return $request->expectsJson() ? response()->json($data) : Inertia::render('customer/QuoteDetail', ['business' => $this->business(), 'quote' => $data]);
    }

    public function revisions(string $company, string $quote, QuotationView $view): JsonResponse
    {
        $model = $this->find($quote);
        $pages = QuoteRevision::where('quote_id', $model->id)->with(['lines', 'inventory', 'stops'])->orderByDesc('number')->paginate(10)->through(fn ($revision) => $view->revision($revision));
        $signedPage = fn ($page) => URL::temporarySignedRoute('quote.guest.revisions', $model->access_expires_at, ['company' => $company, 'quote' => $quote, 'page' => $page]);

        return response()->json(['data' => $pages->items(), 'current_page' => $pages->currentPage(), 'last_page' => $pages->lastPage(), 'total' => $pages->total(), 'per_page' => $pages->perPage(), 'next_page_url' => $pages->hasMorePages() ? $signedPage($pages->currentPage() + 1) : null, 'prev_page_url' => $pages->currentPage() > 1 ? $signedPage($pages->currentPage() - 1) : null]);
    }

    public function operate(Request $request, string $company, string $quote, string $operation, QuotationView $view): JsonResponse
    {
        $model = $this->find($quote);
        $model = match ($operation) {
            'decline', 'cancel' => app(CloseQuote::class)->execute($model, $operation, (int) $request->validate(['lock_version' => ['required', 'integer', 'min:0']])['lock_version']),
            'save' => app(SaveQuoteDraft::class)->execute($model, $request->validate(['input' => ['required', 'array']])['input'], (int) $request->validate(['lock_version' => ['required', 'integer', 'min:0']])['lock_version']),
            'calculate' => $this->calculate($request, $model),
            'submit' => $this->submit($request, $model),
            'accept' => $this->accept($request, $model),
            'respond' => app(RespondToQuote::class)->execute($model, $request->all()),
            default => abort(404),
        };

        return response()->json($view->make($model));
    }

    private function calculate(Request $request, Quote $quote): Quote
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'input' => ['nullable', 'array'], 'force' => ['sometimes', 'boolean']]);

        return app(CalculateQuote::class)->execute($quote, $data['lock_version'], $data['input'] ?? null, (bool) ($data['force'] ?? false));
    }

    private function submit(Request $request, Quote $quote): Quote
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'contact' => ['required', 'array']]);

        return app(SubmitQuote::class)->execute($quote, $data['contact'], $data['lock_version']);
    }

    private function accept(Request $request, Quote $quote): Quote
    {
        $data = $request->validate(['revision_public_id' => ['required', 'ulid'], 'agree' => ['required', 'accepted']]);

        return app(AcceptQuote::class)->execute($quote, $data['revision_public_id']);
    }

    public function find(string $publicId): Quote
    {
        return Quote::where('public_id', $publicId)->where('access_expires_at', '>', now())->firstOrFail();
    }
}
