<?php

namespace App\Domain\Notifications;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $quote_id
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property CarbonImmutable|null $sent_at
 */
class QuoteDelivery extends Model
{
    use BelongsToTenant;

    protected $table = 'quote_deliveries';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }
}
