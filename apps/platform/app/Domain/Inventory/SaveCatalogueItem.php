<?php

namespace App\Domain\Inventory;

use App\Domain\Quotes\QuotePermissions;
use App\Domain\Tenancy\RecordsAdministrativeChange;
use App\Domain\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveCatalogueItem
{
    public function execute(array $input, int $actorId, ?CatalogueItem $item = null): CatalogueItem
    {
        app(QuotePermissions::class)->manage($actorId);
        $rules = ['name' => ['required', 'string', 'max:160'], 'category' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:5000'], 'handling' => ['required', 'in:standard,fragile,special'], 'active' => ['required', 'boolean'], 'weight_kg' => ['nullable', 'numeric', 'between:0,999999'], 'volume_m3' => ['nullable', 'numeric', 'gt:0', 'max:999999']];
        foreach (['length_cm', 'width_cm', 'height_cm'] as $field) {
            $rules[$field] = ['nullable', 'numeric', 'gt:0', 'max:999999', 'required_with:'.implode(',', array_diff(['length_cm', 'width_cm', 'height_cm'], [$field]))];
        }
        $data = Validator::make($input, $rules)->validate();
        if (isset($data['length_cm'],$data['width_cm'],$data['height_cm'])) {
            $volume = BigDecimal::of((string) $data['length_cm'])->multipliedBy((string) $data['width_cm'])->multipliedBy((string) $data['height_cm'])->dividedBy('1000000', 6, RoundingMode::HalfUp);
            if ($volume->isZero() || $volume->isGreaterThan('999999')) {
                throw ValidationException::withMessages(['volume_m3' => 'These dimensions exceed the supported catalogue volume precision or range.']);
            }
            $data['volume_m3'] = (string) $volume;
        }
        $saved = DB::transaction(function () use ($data, $actorId, $item): CatalogueItem {
            $item ??= new CatalogueItem;
            $item->fill($data)->save();
            app(RecordsAdministrativeChange::class)->record($item, 'inventory_catalogue.saved', $actorId);

            return $item;
        });
        $tenantId = app(TenantContext::class)->tenant()->id;
        DB::afterCommit(fn () => Cache::increment('catalogue_version:'.$tenantId));

        return $saved;
    }
}
