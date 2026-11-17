<?php

namespace App\Http\Requests;

use App\Domain\Fleet\Depot;
use App\Domain\Fleet\VehicleType;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

abstract class OperationalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('membership')?->role->managesCompany() ?? false;
    }

    protected function tenantExists(string $table, string $column = 'public_id'): Exists
    {
        return Rule::exists($table, $column)->where('tenant_id', app(TenantContext::class)->tenant()->id);
    }

    public function attributesForSave(): array
    {
        $data = $this->validated();
        foreach (['depot' => Depot::class, 'resource' => \App\Domain\Resources\Resource::class, 'default_service' => TenantService::class] as $prefix => $model) {
            $key = $prefix.'_public_id';
            if (array_key_exists($key, $data)) {
                $data[$prefix.'_id'] = $data[$key] ? $model::where('public_id', $data[$key])->firstOrFail()->id : null;
                unset($data[$key]);
            }
        }
        if (array_key_exists('vehicle_type_key', $data)) {
            $data['vehicle_type_id'] = VehicleType::where('key', $data['vehicle_type_key'])->firstOrFail()->id;
            unset($data['vehicle_type_key']);
        }
        if (array_key_exists('user_public_id', $data)) {
            $data['user_id'] = $data['user_public_id'] ? User::where('public_id', $data['user_public_id'])->firstOrFail()->id : null;
            unset($data['user_public_id']);
        }

        return $data;
    }
}
