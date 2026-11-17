<?php

namespace App\Domain\Inventory;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property string $public_id
 * @property string|null $weight_kg
 * @property string|null $volume_m3
 */
class CatalogueItem extends Model
{
    protected $guarded = ['id', 'tenant_id', 'public_id', 'image_path'];

    protected $hidden = ['id', 'tenant_id', 'image_path'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('catalogue_access', fn (Builder $query) => $query->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', app(TenantContext::class)->tenant()->id)));
        static::creating(function (CatalogueItem $item): void {
            $item->tenant_id = app(TenantContext::class)->tenant()->id;
            $item->public_id = (string) Str::ulid();
        });
        static::updating(function (CatalogueItem $item): void {
            if ($item->getOriginal('tenant_id') === null || (int) $item->getOriginal('tenant_id') !== app(TenantContext::class)->tenant()->id || $item->isDirty('tenant_id')) {
                throw new LogicException('Global and other tenant inventory definitions are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Archive inventory definitions instead.'));
    }
}
