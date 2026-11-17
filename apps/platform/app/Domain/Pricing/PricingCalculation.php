<?php

namespace App\Domain\Pricing;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 */
class PricingCalculation extends Model
{
    use BelongsToTenant;

    protected $table = 'pricing_calculations';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['metrics' => 'array', 'configuration' => 'array'];
    }
}
