<?php

namespace App\Domain\Tenancy;

use App\Domain\Services\TenantService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property-read TenantService|null $defaultService */
class CompanySettings extends Model
{
    use BelongsToTenant;

    protected $table = 'company_settings';

    protected $fillable = ['booking_policy', 'display_name', 'contact_email', 'contact_phone', 'payment_terms', 'deposit_basis_points', 'registered_name', 'registration_number', 'vat_number', 'primary_contact_name', 'website', 'description', 'operating_address', 'billing_address', 'operational_email', 'operational_phone', 'operational_status', 'measurement_units', 'timezone', 'booking_notice_minutes', 'job_buffer_minutes', 'public_contact_visible', 'booking_enabled', 'default_service_id'];

    protected $hidden = ['id', 'tenant_id', 'default_service_id', 'logo_path'];

    public function defaultService(): BelongsTo
    {
        return $this->belongsTo(TenantService::class, 'default_service_id');
    }

    protected function casts(): array
    {
        return ['booking_policy' => 'array', 'deposit_basis_points' => 'integer', 'booking_notice_minutes' => 'integer', 'job_buffer_minutes' => 'integer', 'public_contact_visible' => 'boolean', 'booking_enabled' => 'boolean'];
    }
}
