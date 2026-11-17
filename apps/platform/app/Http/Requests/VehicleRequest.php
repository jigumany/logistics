<?php

namespace App\Http\Requests;

use App\Domain\Fleet\Vehicle;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

class VehicleRequest extends OperationalRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('registration_number')) {
            $this->merge(['registration_number' => preg_replace('/[\s-]+/', '', strtoupper((string) $this->input('registration_number')))]);
        }
    }

    public function rules(): array
    {
        $current = $this->route('record') ? Vehicle::where('public_id', $this->route('record'))->first() : null;
        $rules = ['name' => ['required', 'string', 'max:160'], 'registration_number' => ['required', 'regex:/^[A-Z0-9]{2,20}$/', Rule::unique('vehicles', 'registration_number')->where('tenant_id', app(TenantContext::class)->tenant()->id)->ignore($current?->id)], 'vehicle_type_key' => ['required', Rule::exists('vehicle_types', 'key')->where('active', true)], 'make' => ['nullable', 'string', 'max:80'], 'model' => ['nullable', 'string', 'max:80'], 'manufacture_year' => ['nullable', 'integer', 'between:1950,'.(now()->year + 1)], 'status' => ['required', Rule::in(['available', 'inactive', 'maintenance', 'out_of_service'])], 'depot_public_id' => ['nullable', $this->tenantExists('depots')->where('active', true)], 'capacity_margin_basis_points' => ['required', 'integer', 'between:0,10000'], 'operating_restrictions' => ['nullable', 'string', 'max:5000'], 'notes' => ['nullable', 'string', 'max:5000']];
        foreach (['usable_volume_m3', 'payload_kg', 'cargo_length_m', 'cargo_width_m', 'cargo_height_m', 'max_item_length_m', 'max_item_width_m', 'max_item_height_m'] as $field) {
            $rules[$field] = ['nullable', 'numeric', 'gt:0', 'max:1000000'];
        }

        return $rules;
    }
}
