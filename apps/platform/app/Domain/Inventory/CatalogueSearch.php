<?php

namespace App\Domain\Inventory;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class CatalogueSearch
{
    public function execute(string $search, ?string $category, int $page): array
    {
        $tenant = app(TenantContext::class)->tenant();
        $load = function () use ($search, $category, $page, $tenant): array {
            $query = CatalogueItem::where('active', true);
            if ($search !== '') {
                $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']);
            }
            if ($category) {
                $query->where('category', $category);
            }
            $result = $query->orderBy('name')->orderBy('id')->paginate(20, ['*'], 'page', $page);

            return ['total' => $result->total(), 'rows' => $result->getCollection()->map(fn ($item) => [...$item->toArray(), 'image_url' => $item->image_path ? '/q/'.$tenant->slug.'/catalogue/'.$item->public_id.'/image' : null])->all()];
        };
        if (DB::transactionLevel() > 0) {
            return $load();
        }
        $version = Cache::get('catalogue_version:'.$tenant->id, 0);

        return Cache::remember('catalogue:v1:'.$tenant->id.':'.$version.':'.hash('sha256', json_encode([$search, $category, $page], JSON_THROW_ON_ERROR)), 120, $load);
    }
}
