<?php

namespace App\Domain\Bookings;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $quote_id
 * @property int|null $booking_id
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property array $details
 */
class BookingChangeRequest extends Model
{
    use BelongsToTenant;

    protected $table = 'booking_change_requests';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
