<?php

namespace App\Http\Requests;

class PriceBookRequest extends OperationalRequest
{
    public function rules(): array
    {
        return [
            'service_public_id' => ['required', $this->tenantExists('tenant_services')],
            'name' => ['required', 'string', 'max:160'],
            'currency' => ['required', 'in:ZAR,USD,EUR,GBP'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'distance_basis' => ['required', 'in:customer,customer_depot,customer_return,operational'],
            'configuration' => ['required', 'array'],
            'rules' => ['required', 'array', 'min:1', 'max:100'],
        ];
    }
}
