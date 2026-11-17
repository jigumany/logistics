<?php

namespace App\Http\Requests;

use App\Domain\Identity\Membership;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

class StaffMemberRequest extends OperationalRequest
{
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:160'], 'email' => ['nullable', 'email', 'max:254'], 'phone' => ['nullable', 'string', 'max:30'], 'role' => ['required', Rule::in(['driver', 'mover', 'dispatcher', 'operations_manager'])], 'engagement_status' => ['required', Rule::in(['employed', 'contractor', 'suspended', 'ended'])], 'active' => ['required', 'boolean'], 'depot_public_id' => ['nullable', $this->tenantExists('depots')->where('active', true)],
            'user_public_id' => ['nullable', Rule::exists('users', 'public_id')->where(fn ($query) => $query->whereIn('id', Membership::where('tenant_id', app(TenantContext::class)->tenant()->id)->select('user_id')))],
            'licence_category' => ['nullable', 'required_if:role,driver', 'string', 'max:40'], 'licence_expires_on' => ['nullable', 'required_if:role,driver', 'date_format:Y-m-d', Rule::when($this->boolean('active') && in_array($this->input('engagement_status'), ['employed', 'contractor'], true), 'after_or_equal:'.now(CompanySettings::value('timezone') ?? 'UTC')->toDateString())], 'qualifications' => ['nullable', 'string', 'max:5000'], 'notes' => ['nullable', 'string', 'max:5000'], 'eligible_vehicle_classes' => ['array', 'required_if:role,driver', 'max:20'], 'eligible_vehicle_classes.*' => ['string', 'distinct', Rule::in(['light', 'medium', 'heavy', 'articulated'])],
        ];
    }
}
