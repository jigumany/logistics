<?php

namespace App\Domain\Payments;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 */
class PaymentAccount extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id', 'secret_key', 'webhook_secret'];

    protected function casts(): array
    {
        return ['secret_key' => 'encrypted', 'webhook_secret' => 'encrypted'];
    }
}
