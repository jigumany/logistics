<?php

namespace App\Domain\Bookings;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $hold_id
 * @property int|null $booking_id
 * @property int $resource_id
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
class ResourceAllocation extends Model
{
    use BelongsToTenant;

    protected $table = 'resource_allocations';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }
}
