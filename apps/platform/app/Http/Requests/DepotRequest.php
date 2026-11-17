<?php

namespace App\Http\Requests;

class DepotRequest extends OperationalRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'], 'address_line' => ['required', 'string', 'max:255'], 'suburb' => ['nullable', 'string', 'max:100'], 'city' => ['required', 'string', 'max:100'], 'postal_code' => ['nullable', 'string', 'max:20'], 'country_code' => ['required', 'string', 'size:2'],
            'latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'], 'coordinate_source' => ['nullable', 'string', 'max:100'], 'coordinates_verified' => ['required', 'boolean'],
            'contact_name' => ['nullable', 'string', 'max:160'], 'contact_email' => ['nullable', 'email', 'max:254'], 'contact_phone' => ['nullable', 'string', 'max:30'], 'is_primary' => ['required', 'boolean'], 'active' => ['required', 'boolean'], 'timezone' => ['required', 'timezone'],
        ];
    }
}
