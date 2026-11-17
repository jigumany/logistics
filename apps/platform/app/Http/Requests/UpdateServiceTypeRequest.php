<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('membership')?->role->managesCompany() ?? false;
    }

    public function rules(): array
    {
        return ['enabled' => ['required', 'boolean'], 'name' => ['sometimes', 'required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:5000'], 'notice_minutes' => ['sometimes', 'required', 'integer', 'between:0,525600'], 'restrictions' => ['nullable', 'string', 'max:5000'], 'manual_approval' => ['sometimes', 'required', 'boolean'], 'publicly_bookable' => ['sometimes', 'required', 'boolean'], 'active' => ['sometimes', 'required', 'boolean']];
    }
}
