<?php

namespace App\Domain\Quotes;

use App\Domain\Inventory\QuoteInventory;
use App\Domain\Routing\RouteStop;
use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property array|null $terms_snapshot
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property array<string,mixed> $input
 * @property array<string,mixed> $inventory_summary
 * @property array<string,mixed> $coverage
 * @property array $suitability
 * @property array $review_reasons
 * @property array<string,mixed> $pricing_snapshot
 * @property array<string,mixed> $contact_snapshot
 * @property int $quote_id
 * @property int|null $price_book_id
 * @property int|null $route_calculation_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $sealed_at
 * @property int|null $total_minor
 * @property int|null $subtotal_minor
 * @property int|null $tax_minor
 * @property-read Collection<int,QuoteLineItem> $lines
 * @property-read Collection<int,QuoteInventory> $inventory
 * @property-read Collection<int,RouteStop> $stops
 */
class QuoteRevision extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['terms_snapshot' => 'array', 'branding_snapshot' => 'array', 'input' => 'array', 'inventory_summary' => 'array', 'coverage' => 'array', 'suitability' => 'array', 'review_reasons' => 'array', 'pricing_snapshot' => 'array', 'contact_snapshot' => 'array', 'expires_at' => 'immutable_datetime', 'sealed_at' => 'immutable_datetime', 'number' => 'integer', 'total_minor' => 'integer', 'subtotal_minor' => 'integer', 'tax_minor' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (QuoteRevision $revision): void {
            if ($revision->getOriginal('sealed_at')) {
                throw new LogicException('Quote revisions are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Quote revisions are retained.'));
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLineItem::class, 'revision_id')->orderBy('position');
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(QuoteInventory::class, 'revision_id')->orderBy('id');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class, 'revision_id')->orderBy('position');
    }
}
