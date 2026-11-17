<?php

namespace App\Http\Controllers;

use App\Domain\Fleet\VehicleType;
use App\Domain\Pricing\PriceBook;
use App\Domain\Pricing\PricingRule;
use App\Domain\Quotes\CalculateQuote;
use App\Domain\Quotes\CreateQuoteDraft;
use App\Domain\Quotes\Quote;
use App\Domain\Quotes\QuotePermissions;
use App\Domain\Quotes\SaveQuoteDraft;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Resources\QuotationView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class QuoteSimulationController extends Controller
{
    private function book(Request $request, string $publicId): PriceBook
    {
        app(QuotePermissions::class)->manage($request->user()->id);
        abort_unless(app()->environment(['local', 'testing']) && app(TenantContext::class)->tenant()->slug === 'cape-town-movers', 404);

        return PriceBook::where('public_id', $publicId)->where('is_demonstration', true)->where('status', 'draft')->firstOrFail();
    }

    public function show(Request $request, string $company, string $book): Response
    {
        $model = $this->book($request, $book);
        $types = PricingRule::where('price_book_id', $model->id)->whereNotNull('vehicle_type_key')->pluck('vehicle_type_key')->unique();

        return Inertia::render('customer/QuoteWizard', [
            'business' => app(TenantContext::class)->tenant()->only('name', 'slug'),
            'services' => TenantService::whereKey($model->service_id)->get(['public_id', 'name', 'description']),
            'currency' => $model->currency,
            'simulation' => ['book_public_id' => $book, 'name' => $model->name, 'version' => $model->version,
                'base_url' => '/c/'.$company.'/price-books/'.$book.'/simulate',
                'vehicle_types' => VehicleType::whereIn('key', $types)->where('active', true)->orderBy('name')->get(['key', 'name']),
            ],
        ]);
    }

    public function create(Request $request, string $company, string $book, QuotationView $view): JsonResponse
    {
        $model = $this->book($request, $book);
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'], 'input' => ['required', 'array'],
            'options' => ['required', 'array:vehicle_type_key,additional_movers,waiting_confirmed,route_distance_km,route_duration_minutes'],
            'options.vehicle_type_key' => ['required', 'string', 'max:50'],
            'options.additional_movers' => ['required', 'integer', 'between:0,19'],
            'options.waiting_confirmed' => ['required', 'boolean'],
            'options.route_distance_km' => ['nullable', 'required_with:options.route_duration_minutes', 'numeric', 'between:0,10000', 'decimal:0,3'],
            'options.route_duration_minutes' => ['nullable', 'required_with:options.route_distance_km', 'numeric', 'between:0,43200', 'decimal:0,2'],
        ]);
        VehicleType::where('key', $data['options']['vehicle_type_key'])->where('active', true)->firstOrFail();
        abort_unless(TenantService::whereKey($model->service_id)->value('public_id') === ($data['input']['service_public_id'] ?? ''), 422);
        $data['options']['additional_movers'] = (int) $data['options']['additional_movers'];
        $data['options']['waiting_confirmed'] = (bool) $data['options']['waiting_confirmed'];
        $quote = app(CreateQuoteDraft::class)->execute($data['input'], $data['idempotency_key'], hash('sha256', $request->session()->getId()), $model, $data['options']);

        return response()->json($view->make($quote, true), 201);
    }

    public function operate(Request $request, string $company, string $book, string $quote, string $operation, QuotationView $view): JsonResponse
    {
        $model = $this->book($request, $book);
        $quoteModel = Quote::where('public_id', $quote)->where('simulation_price_book_id', $model->id)->firstOrFail();
        if ($operation === 'view') {
            return response()->json($view->make($quoteModel, true));
        }
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'input' => ['required', 'array']]);
        abort_unless(TenantService::whereKey($model->service_id)->value('public_id') === ($data['input']['service_public_id'] ?? ''), 422);
        $result = match ($operation) {
            'save' => app(SaveQuoteDraft::class)->execute($quoteModel, $data['input'], $data['lock_version']),
            'calculate' => app(CalculateQuote::class)->execute($quoteModel, $data['lock_version'], $data['input'], true),
            default => abort(404),
        };

        return response()->json($view->make($result, true));
    }
}
