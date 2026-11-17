<?php

namespace App\Domain\Routing;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 */
class RouteStop extends Model
{
    use BelongsToTenant;

    protected $table = 'route_stops';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'position' => 'integer'];
    }
}
