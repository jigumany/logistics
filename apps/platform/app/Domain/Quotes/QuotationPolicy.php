<?php

namespace App\Domain\Quotes;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $service_id
 */
class QuotationPolicy extends Model
{
    use BelongsToTenant;

    protected $table = 'quotation_policies';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['automatic_estimates' => 'boolean', 'allow_catalogue_estimates' => 'boolean', 'allow_customer_measurements' => 'boolean', 'allow_cross_zone' => 'boolean', 'notifications_enabled' => 'boolean', 'safety_margin_bps' => 'integer', 'borderline_bps' => 'integer', 'max_auto_stops' => 'integer', 'max_auto_stairs' => 'integer', 'validity_hours' => 'integer'];
    }
}
