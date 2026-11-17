<?php

namespace App\Domain\Bookings;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int|null $booking_id
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 */
class BookingStatusHistory extends Model
{
    use BelongsToTenant;

    protected $table = 'booking_status_history';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [];
    }
}
