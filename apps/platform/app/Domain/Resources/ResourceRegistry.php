<?php

namespace App\Domain\Resources;

use App\Domain\Fleet\Vehicle;
use App\Domain\Tenancy\CompanySettings;
use Illuminate\Database\Eloquent\Builder;

final class ResourceRegistry
{
    private function today(): string
    {
        return now(CompanySettings::value('timezone') ?? 'UTC')->toDateString();
    }

    public function vehicle(Vehicle $vehicle): void
    {
        Resource::updateOrCreate(['vehicle_id' => $vehicle->id], ['kind' => 'vehicle', 'active' => $vehicle->status === 'available' && $vehicle->archived_at === null && $vehicle->capacity_verified && $vehicle->capacity_verified_at !== null]);
    }

    public function staff(StaffMember $staff): void
    {
        $existing = Resource::where('staff_id', $staff->id)->lockForUpdate()->first();
        if (! in_array($staff->role, ['driver', 'mover'], true)) {
            if ($existing) {
                $existing->update(['active' => false]);
            }

            return;
        }
        Resource::updateOrCreate(['staff_id' => $staff->id], ['kind' => $staff->role, 'active' => ($staff->role !== 'driver' || ($staff->licence_category && ($staff->licence_expires_on && $staff->licence_expires_on->toDateString() >= $this->today()) && $staff->eligibleClasses()->exists())) && $staff->active && in_array($staff->engagement_status, ['employed', 'contractor'], true) && $staff->archived_at === null]);
    }

    public function eligible(): Builder
    {
        return Resource::query()->where('active', true)->where(function (Builder $q): void {
            $q->whereHas('vehicle', fn (Builder $v) => $v->whereNull('archived_at')->where('status', 'available')->where('capacity_verified', true)->whereNotNull('capacity_verified_at'))->orWhereHas('staff', fn (Builder $s) => $s->whereNull('archived_at')->where('active', true)->whereIn('engagement_status', ['employed', 'contractor'])->where(fn (Builder $qualified) => $qualified->where('role', 'mover')->orWhere(fn (Builder $driver) => $driver->where('role', 'driver')->whereNotNull('licence_category')->whereDate('licence_expires_on', '>=', $this->today())->whereHas('eligibleClasses'))));
        });
    }
}
