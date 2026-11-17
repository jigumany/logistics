<?php

namespace App\Domain\Routing;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $quote_id
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property CarbonImmutable|null $retain_until
 */
class RouteCalculation extends Model
{
    use BelongsToTenant;

    protected $table = 'route_calculations';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['depot_duration_seconds' => 'integer', 'return_duration_seconds' => 'integer', 'retain_until' => 'immutable_datetime', 'customer_metres' => 'integer', 'depot_metres' => 'integer', 'return_metres' => 'integer', 'duration_seconds' => 'integer', 'tolls_minor' => 'integer'];
    }
}
