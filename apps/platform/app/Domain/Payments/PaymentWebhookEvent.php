<?php

namespace App\Domain\Payments;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $attempt_id
 */
class PaymentWebhookEvent extends Model
{
    use BelongsToTenant;

    protected $table = 'payment_webhook_events';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['processed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Evidence is immutable.'));
        static::deleting(fn () => throw new \LogicException('Evidence is retained.'));
    }
}
