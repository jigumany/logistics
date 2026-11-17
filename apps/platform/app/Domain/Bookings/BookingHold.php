<?php

namespace App\Domain\Bookings;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $quote_id
 * @property int $accepted_revision_id
 * @property int $customer_id
 * @property int|null $booking_id
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property array $requirements
 * @property array $duration_snapshot
 * @property CarbonImmutable $arrival_starts_at
 * @property CarbonImmutable $arrival_ends_at
 * @property CarbonImmutable $service_starts_at
 * @property CarbonImmutable $service_ends_at
 * @property CarbonImmutable $allocation_starts_at
 * @property CarbonImmutable $allocation_ends_at
 * @property CarbonImmutable $expires_at
 */
class BookingHold extends Model
{
    use BelongsToTenant;

    protected $table = 'booking_holds';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['requirements' => 'array', 'duration_snapshot' => 'array', 'arrival_starts_at' => 'immutable_datetime', 'arrival_ends_at' => 'immutable_datetime', 'service_starts_at' => 'immutable_datetime', 'service_ends_at' => 'immutable_datetime', 'allocation_starts_at' => 'immutable_datetime', 'allocation_ends_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }
}
