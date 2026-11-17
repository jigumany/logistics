<?php

namespace App\Domain\Bookings;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $quote_id
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property CarbonImmutable $sent_at
 */
class BookingDelivery extends Model
{
    use BelongsToTenant;

    protected $table = 'booking_deliveries';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }
}
