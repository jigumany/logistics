<?php

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $query->where($query->getModel()->qualifyColumn('tenant_id'), app(TenantContext::class)->tenant()->id);
        });

        static::creating(function (Model $model): void {
            $tenantId = app(TenantContext::class)->tenant()->id;
            if ($model->getAttribute('tenant_id') !== null && (int) $model->getAttribute('tenant_id') !== $tenantId) {
                throw new LogicException('Cannot write outside the active tenant.');
            }
            $model->setAttribute('tenant_id', $tenantId);
            $model->setAttribute('public_id', $model->getAttribute('public_id') ?? (string) Str::ulid());
        });

        static::deleting(fn (Model $model) => static::assertActiveOwnership($model));

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id') || (int) $model->getAttribute('tenant_id') !== app(TenantContext::class)->tenant()->id) {
                throw new LogicException('Tenant ownership is immutable.');
            }
        });
    }

    protected static function assertActiveOwnership(Model $model): void
    {
        if ((int) $model->getAttribute('tenant_id') !== app(TenantContext::class)->tenant()->id) {
            throw new LogicException('Cannot delete outside the active tenant.');
        }
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
