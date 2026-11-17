<?php

namespace App\Domain\Bookings;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $hold_id
 * @property int $resource_id
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 */
class BookingHoldResource extends Model
{
    use BelongsToTenant;

    protected $table = 'booking_hold_resources';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];
}
