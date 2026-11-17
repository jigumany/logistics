<?php

namespace App\Domain\Resources;

use App\Domain\Fleet\Depot;
use App\Domain\Tenancy\RecordsAdministrativeChange;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveStaffMember
{
    public function execute(array $data, int $actorId, ?StaffMember $staff = null): StaffMember
    {
        return DB::transaction(function () use ($data, $actorId, $staff): StaffMember {
            Tenant::whereKey(app(TenantContext::class)->tenant()->id)->lockForUpdate()->firstOrFail();
            if (isset($data['depot_id']) && ! Depot::whereKey($data['depot_id'])->where('active', true)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['depot_public_id' => 'Select an active company depot.']);
            }
            if (isset($data['user_id']) && StaffMember::where('user_id', $data['user_id'])->when($staff, fn ($q) => $q->whereKeyNot($staff->id))->exists()) {
                throw ValidationException::withMessages(['user_public_id' => 'This login account is already linked to another staff record.']);
            }
            if ($staff?->archived_at) {
                $data['active'] = false;
            }
            $classes = $data['eligible_vehicle_classes'] ?? [];
            unset($data['eligible_vehicle_classes']);
            $staff ??= new StaffMember;
            $staff->fill($data)->save();
            DriverVehicleClass::where('tenant_id', app(TenantContext::class)->tenant()->id)->where('staff_id', $staff->id)->delete();
            foreach ($classes as $class) {
                DriverVehicleClass::create(['staff_id' => $staff->id, 'vehicle_class' => $class]);
            }
            app(ResourceRegistry::class)->staff($staff);
            app(RecordsAdministrativeChange::class)->record($staff, 'staff.saved', $actorId);

            return $staff;
        });
    }

    public function archive(StaffMember $staff, int $actorId): void
    {
        DB::transaction(function () use ($staff, $actorId): void {
            $staff->archived_at = now();
            $staff->active = false;
            $staff->save();
            app(ResourceRegistry::class)->staff($staff);
            app(RecordsAdministrativeChange::class)->record($staff, 'staff.archived', $actorId);
        });
    }
}
