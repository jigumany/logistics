<?php

namespace App\Http\Controllers;

use App\Domain\Fleet\Depot;
use App\Domain\Fleet\VehicleType;
use App\Domain\Pricing\ManagePriceBook;
use App\Domain\Pricing\PriceBook;
use App\Domain\Pricing\PricingRule;
use App\Domain\Pricing\PricingRules;
use App\Domain\Quotes\QuotePermissions;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;
use App\Http\Requests\PriceBookRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PriceBookController extends Controller
{
    public function index(Request $request): Response
    {
        app(QuotePermissions::class)->manage($request->user()->id);
        $services = TenantService::orderBy('id')->get()->keyBy('id');

        return Inertia::render('company/pricing/Index', ['books' => PriceBook::orderByDesc('id')->paginate(20)->through(fn ($book) => [...$book->toArray(), 'service_name' => $services->get($book->service_id)->name ?? ''])]);
    }

    public function edit(Request $request, string $company, ?string $book = null): Response
    {
        app(QuotePermissions::class)->manage($request->user()->id);
        $selection = $request->validate(['copy' => ['nullable', 'ulid'], 'compare' => ['nullable', 'ulid']]);
        $copy = $selection['copy'] ?? null;
        $model = $book || $copy ? PriceBook::where('public_id', $book ?? $copy)->firstOrFail() : null;
        $data = $model ? [...$model->toArray(), 'service_public_id' => TenantService::whereKey($model->service_id)->value('public_id'), 'rules' => PricingRule::where('price_book_id', $model->id)->orderBy('position')->get()] : null;

        $comparison = $model && ! empty($selection['compare']) ? PriceBook::where('service_id', $model->service_id)->where('public_id', $selection['compare'])->firstOrFail() : null;

        return Inertia::render('company/pricing/Edit', ['versions' => $model ? PriceBook::where('service_id', $model->service_id)->orderByDesc('version')->get(['public_id', 'name', 'version', 'status', 'is_demonstration']) : [], 'comparison' => $comparison ? [...$comparison->toArray(), 'rules' => PricingRule::where('price_book_id', $comparison->id)->orderBy('position')->get()] : null, 'book' => $data, 'isNew' => $book === null, 'services' => TenantService::where('active', true)->orderBy('id')->get(['public_id', 'name']), 'depots' => Depot::where('active', true)->where('coordinates_verified', true)->orderBy('name')->get(['public_id', 'name']), 'vehicleTypes' => VehicleType::where('active', true)->orderBy('id')->get(['key', 'name']), 'companyTaxRegistration' => CompanySettings::value('vat_number') ?? '', 'metrics' => $model?->is_demonstration ? PricingRules::SUPPORTED_METRICS : PricingRules::ALL_METRICS]);
    }

    public function store(PriceBookRequest $request, ManagePriceBook $action): RedirectResponse
    {
        $book = $action->save($request->validated(), $request->user()->id);

        return redirect('/c/'.$request->route('company').'/price-books/'.$book->public_id)->with('success', $book->is_demonstration ? 'Demonstration draft saved. Rates remain unapproved and cannot be published.' : 'Draft price book saved. Publish it when its rules are complete.');
    }

    public function update(PriceBookRequest $request, string $company, string $book, ManagePriceBook $action): RedirectResponse
    {
        $action->save($request->validated(), $request->user()->id, PriceBook::where('public_id', $book)->firstOrFail());

        return back()->with('success', 'Draft pricing saved.');
    }

    public function transition(Request $request, string $company, string $book, string $operation, ManagePriceBook $action): RedirectResponse
    {
        app(QuotePermissions::class)->manage($request->user()->id);
        $model = PriceBook::where('public_id', $book)->firstOrFail();
        if ($operation === 'publish') {
            $action->publish($model, $request->user()->id);
        } elseif ($operation === 'retire') {
            $action->retire($model, $request->user()->id);
        } else {
            abort(404);
        }

        return back()->with('success', 'Price book '.$operation.' completed.');
    }
}
