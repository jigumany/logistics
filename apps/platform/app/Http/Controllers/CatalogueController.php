<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\CatalogueItem;
use App\Domain\Inventory\SaveCatalogueItem;
use App\Domain\Quotes\QuotePermissions;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CatalogueController extends Controller
{
    public function index(Request $request): Response
    {
        app(QuotePermissions::class)->manage($request->user()->id);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $query = CatalogueItem::query();
        if (! empty($data['search'])) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($data['search']).'%']);
        }

        return Inertia::render('company/inventory/Index', ['items' => $query->orderBy('name')->paginate(20)->withQueryString()->through(fn ($item) => [...$item->toArray(), 'global' => $item->tenant_id === null]), 'filters' => $data]);
    }

    public function edit(Request $request, string $company, ?string $item = null): Response
    {
        app(QuotePermissions::class)->manage($request->user()->id);
        $model = $item ? CatalogueItem::where('public_id', $item)->where('tenant_id', app(TenantContext::class)->tenant()->id)->firstOrFail() : null;

        return Inertia::render('company/inventory/Edit', ['item' => $model ? [...$model->toArray(), 'has_image' => (bool) $model->image_path] : null]);
    }

    public function store(Request $request, SaveCatalogueItem $action): RedirectResponse
    {
        $item = $action->execute($request->all(), $request->user()->id);

        return redirect('/c/'.$request->route('company').'/inventory/'.$item->public_id)->with('success', 'Catalogue item saved. Measurements remain estimates until independently verified.');
    }

    public function update(Request $request, string $company, string $item, SaveCatalogueItem $action): RedirectResponse
    {
        $model = CatalogueItem::where('public_id', $item)->where('tenant_id', app(TenantContext::class)->tenant()->id)->firstOrFail();
        $action->execute($request->all(), $request->user()->id, $model);

        return back()->with('success', 'Catalogue item saved.');
    }
}
