<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class CreateTenantServiceRequest extends UpdateServiceTypeRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'service_type_key' => ['required', Rule::exists('service_types', 'key')], 'name' => ['required', 'string', 'max:100']];
    }
}
