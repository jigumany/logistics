<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ServiceZoneRequest extends OperationalRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:5000'], 'zone_type' => ['required', Rule::in(['pickup', 'delivery', 'both'])], 'boundary' => ['required', 'array'], 'active' => ['required', 'boolean'], 'priority' => ['required', 'integer', 'between:-100000,100000'], 'restrictions' => ['nullable', 'string', 'max:5000'], 'manual_review' => ['required', 'boolean'], 'example_only' => ['required', 'boolean']];
    }
}
