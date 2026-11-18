<?php

namespace App\Domain\Payments;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $booking_id
 * @property int $amount_minor
 * @property array $checkout_request
 */
class PaymentAttempt extends Model
{
    use BelongsToTenant;

    protected $table = 'payment_attempts';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['checkout_request' => 'array', 'amount_minor' => 'integer', 'verified_at' => 'immutable_datetime'];
    }
}
