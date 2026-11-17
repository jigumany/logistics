<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateCompanySettingsRequest extends OperationalRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('membership')?->role->managesCompany() ?? false;
    }

    public function rules(): array
    {
        return [
            'registered_name' => ['nullable', 'string', 'max:255'], 'registration_number' => ['nullable', 'string', 'max:255'], 'vat_number' => ['nullable', 'string', 'max:255'], 'primary_contact_name' => ['nullable', 'string', 'max:255'], 'website' => ['nullable', 'url:http,https', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'operating_address' => ['nullable', 'string', 'max:255'], 'billing_address' => ['nullable', 'string', 'max:255'], 'operational_email' => ['nullable', 'email', 'max:254'], 'operational_phone' => ['nullable', 'string', 'max:30'],
            'operational_status' => ['sometimes', 'required', Rule::in(['active', 'paused', 'closed'])], 'measurement_units' => ['sometimes', 'required', Rule::in(['metric', 'imperial'])], 'timezone' => ['sometimes', 'required', 'timezone'], 'booking_notice_minutes' => ['sometimes', 'required', 'integer', 'between:0,525600'], 'job_buffer_minutes' => ['sometimes', 'required', 'integer', 'between:0,10080'], 'public_contact_visible' => ['sometimes', 'required', 'boolean'], 'booking_enabled' => ['sometimes', 'required', 'boolean'], 'default_service_public_id' => ['nullable', $this->tenantExists('tenant_services')->where('enabled', true)->where('active', true)],
            'display_name' => ['required', 'string', 'max:160'],
            'contact_email' => ['required', 'email', 'max:254'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'payment_terms' => ['required', Rule::in(['deposit', 'full', 'invoice'])],
            'deposit_basis_points' => ['required', 'integer', 'between:0,10000', Rule::when($this->input('payment_terms') === 'deposit', 'min:1')],
        ];
    }
}
