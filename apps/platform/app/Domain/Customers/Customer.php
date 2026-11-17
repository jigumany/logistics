<?php

namespace App\Domain\Customers;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 */
class Customer extends Model
{
    use BelongsToTenant;

    protected $table = 'customers';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'immutable_datetime'];
    }
}
