<?php

namespace App\Domain\Pricing;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property array<string,mixed> $configuration
 * @property int $service_id
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property CarbonImmutable|null $published_at
 */
class PriceBook extends Model
{
    use BelongsToTenant;

    protected $table = 'price_books';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'effective_from' => 'immutable_datetime', 'effective_until' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
