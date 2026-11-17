<?php

namespace App\Domain\Fleet;

use App\Domain\Resources\StaffMember;
use App\Domain\Tenancy\RecordsAdministrativeChange;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveDepot
{
    public function execute(array $data, int $actorId, ?Depot $depot = null): Depot
    {
        return DB::transaction(function () use ($data, $actorId, $depot): Depot {
            Tenant::whereKey(app(TenantContext::class)->tenant()->id)->lockForUpdate()->firstOrFail();
            if ($depot && ! $data['active'] && (Vehicle::where('depot_id', $depot->id)->exists() || StaffMember::where('depot_id', $depot->id)->exists())) {
                throw ValidationException::withMessages(['active' => 'Reassign referenced vehicles and staff before disabling this depot.']);
            }
            if ($data['is_primary']) {
                Depot::where('is_primary', true)->update(['is_primary' => false]);
            }
            if ($data['is_primary'] && ! $data['active']) {
                throw ValidationException::withMessages(['is_primary' => 'A primary depot must be active.']);
            }
            $depot ??= new Depot;
            $depot->fill($data)->save();
            app(RecordsAdministrativeChange::class)->record($depot, 'depot.saved', $actorId);

            return $depot;
        });
    }
}
