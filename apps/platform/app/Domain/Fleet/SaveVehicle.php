<?php

namespace App\Domain\Fleet;

use App\Domain\Resources\ResourceRegistry;
use App\Domain\Tenancy\RecordsAdministrativeChange;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveVehicle
{
    public function execute(array $data, int $actorId, ?Vehicle $vehicle = null): Vehicle
    {
        return DB::transaction(function () use ($data, $actorId, $vehicle): Vehicle {
            Tenant::whereKey(app(TenantContext::class)->tenant()->id)->lockForUpdate()->firstOrFail();
            if (isset($data['depot_id']) && ! Depot::whereKey($data['depot_id'])->where('active', true)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['depot_public_id' => 'Select an active company depot.']);
            }
            if ($vehicle?->archived_at) {
                $data['status'] = 'inactive';
            }
            $type = VehicleType::findOrFail($data['vehicle_type_id']);
            foreach (['usable_volume_m3', 'payload_kg', 'cargo_length_m', 'cargo_width_m', 'cargo_height_m'] as $field) {
                $data[$field] ??= $type->getAttribute($field);
            }
            if ((float) $data['usable_volume_m3'] > (float) $data['cargo_length_m'] * (float) $data['cargo_width_m'] * (float) $data['cargo_height_m'] * 1.001) {
                throw ValidationException::withMessages(['usable_volume_m3' => 'Usable volume cannot exceed the cargo envelope.']);
            }
            $vehicle ??= new Vehicle;
            $vehicle->fill($data)->save();
            app(ResourceRegistry::class)->vehicle($vehicle);
            app(RecordsAdministrativeChange::class)->record($vehicle, 'vehicle.saved', $actorId);

            return $vehicle;
        });
    }

    public function archive(Vehicle $vehicle, int $actorId): void
    {
        DB::transaction(function () use ($vehicle, $actorId): void {
            $vehicle->archived_at = now();
            $vehicle->status = 'inactive';
            $vehicle->save();
            app(ResourceRegistry::class)->vehicle($vehicle);
            app(RecordsAdministrativeChange::class)->record($vehicle, 'vehicle.archived', $actorId);
        });
    }
}
