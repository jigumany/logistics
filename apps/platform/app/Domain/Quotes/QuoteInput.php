<?php

namespace App\Domain\Quotes;

use App\Domain\Routing\AddressVerification;
use App\Domain\Tenancy\CompanySettings;
use Illuminate\Support\Facades\Validator;

final class QuoteInput
{
    private function rules(): array
    {
        $rules = [
            'service_public_id' => ['required', 'ulid'],
            'preferred_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now(CompanySettings::value('timezone') ?? 'UTC')->toDateString()],
            'preferred_time' => ['nullable', 'date_format:H:i'],
            'stops' => ['required', 'array', 'min:2', 'max:10'],
            'stops.*' => ['required', 'array:address_line,suburb,city,postal_code,country_code,latitude,longitude,provider_place_id,verification_proof,instructions'],
            'stops.*.address_line' => ['required', 'string', 'max:255'],
            'stops.*.suburb' => ['nullable', 'string', 'max:100'],
            'stops.*.city' => ['required', 'string', 'max:100'],
            'stops.*.postal_code' => ['nullable', 'string', 'max:20'],
            'stops.*.country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/D'],
            'stops.*.latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:stops.*.longitude'],
            'stops.*.longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:stops.*.latitude'],
            'stops.*.provider_place_id' => ['nullable', 'string', 'max:255'],
            'stops.*.verification_proof' => ['nullable', 'string', 'max:4096'],
            'stops.*.instructions' => ['nullable', 'string', 'max:2000'],
            'inventory' => ['required', 'array', 'min:1', 'max:100'],
            'inventory.*' => ['required', 'array:item_key,catalogue_public_id,name,quantity,length_cm,width_cm,height_cm,weight_kg,weight_certainty,fragile,oversized,stackable,rotation_allowed,instructions'],
            'inventory.*.item_key' => ['required', 'uuid', 'distinct'],
            'inventory.*.catalogue_public_id' => ['nullable', 'ulid'],
            'inventory.*.name' => ['nullable', 'string', 'max:160'],
            'inventory.*.weight_certainty' => ['sometimes', 'in:known,estimated,unknown'],
            'inventory.*.quantity' => ['required', 'integer', 'between:1,1000'],
            'inventory.*.instructions' => ['nullable', 'string', 'max:2000'],
            'details' => ['required', 'array:pickup_stairs,delivery_stairs,pickup_lift,delivery_lift,pickup_carry_metres,delivery_carry_metres,parking_limited,difficult_access,special_equipment,packing,waiting_minutes'],
        ];
        foreach (['length_cm', 'width_cm', 'height_cm', 'weight_kg'] as $field) {
            $rules['inventory.*.'.$field] = ['nullable', 'numeric', 'regex:/^\d{1,6}(\.\d{1,6})?$/D', $field === 'weight_kg' ? 'min:0' : 'gt:0'];
        }
        foreach (['fragile', 'oversized', 'stackable', 'rotation_allowed'] as $field) {
            $rules['inventory.*.'.$field] = ['sometimes', 'boolean'];
        }
        foreach (['pickup_stairs', 'delivery_stairs'] as $field) {
            $rules['details.'.$field] = ['required', 'integer', 'between:0,100'];
        }
        foreach (['pickup_carry_metres', 'delivery_carry_metres', 'waiting_minutes'] as $field) {
            $rules['details.'.$field] = ['required', 'integer', 'between:0,10000'];
        }
        foreach (['pickup_lift', 'delivery_lift', 'parking_limited', 'difficult_access', 'special_equipment', 'packing'] as $field) {
            $rules['details.'.$field] = ['required', 'boolean'];
        }

        return $rules;
    }

    public function validate(array $input): array
    {
        $data = Validator::make($input, $this->rules())->validate();
        $data['preferred_time'] = ($data['preferred_time'] ?? '') ?: null;
        foreach ($data['details'] as $field => $value) {
            $data['details'][$field] = in_array($field, ['pickup_lift', 'delivery_lift', 'parking_limited', 'difficult_access', 'special_equipment', 'packing'], true) ? (bool) $value : (int) $value;
        }
        foreach ($data['inventory'] as &$entry) {
            $entry['quantity'] = (int) $entry['quantity'];
            foreach (['fragile', 'oversized', 'stackable', 'rotation_allowed'] as $field) {
                if (array_key_exists($field, $entry)) {
                    $entry[$field] = (bool) $entry[$field];
                }
            }
        }
        unset($entry);
        $data['stops'] = array_map(fn ($address) => app(AddressVerification::class)->resolve($address), $data['stops']);

        return $data;
    }

    public function inventory(array $entries): array
    {
        $rules = array_filter($this->rules(), fn ($key) => $key === 'inventory' || str_starts_with($key, 'inventory.'), ARRAY_FILTER_USE_KEY);

        return Validator::make(['inventory' => $entries], $rules)->validate()['inventory'];
    }

    public function hash(array $input): string
    {
        return hash('sha256', json_encode($this->canonical($input), JSON_THROW_ON_ERROR));
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->canonical($entry);
            }
        }

        return $value;
    }
}
