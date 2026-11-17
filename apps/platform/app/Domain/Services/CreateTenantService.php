<?php

namespace App\Domain\Services;

use App\Domain\Tenancy\RecordsAdministrativeChange;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateTenantService
{
    public function execute(array $data, int $actorId): TenantService
    {
        $definition = ServiceType::where('key', $data['service_type_key'])->firstOrFail();
        unset($data['service_type_key']);
        $data['service_type_id'] = $definition->id;
        $data['code'] = array_search($definition->key, ['household' => 'household_moving', 'office' => 'office_relocation', 'general' => 'goods_transport'], true);

        return DB::transaction(function () use ($definition, $data, $actorId): TenantService {
            Tenant::whereKey(app(TenantContext::class)->tenant()->id)->lockForUpdate()->firstOrFail();
            if (TenantService::where('service_type_id', $definition->id)->exists()) {
                throw ValidationException::withMessages(['service_type_key' => 'This service is already configured. Edit the existing service.']);
            }
            $service = TenantService::create($data);
            app(RecordsAdministrativeChange::class)->record($service, 'service.created', $actorId);
            app(TenantServiceCatalogue::class)->invalidateAfterCommit();

            return $service;
        });
    }
}
