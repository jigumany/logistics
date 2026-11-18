<?php

namespace App\Domain\Dispatch;

use App\Domain\Bookings\BookingPermissions;
use App\Domain\Resources\StaffMember;
use Illuminate\Auth\Access\AuthorizationException;

final class DispatchAccess
{
    public function manage(int $actor): void
    {
        app(BookingPermissions::class)->manage($actor);
    }

    public function driverIds(int $actor): array
    {
        return StaffMember::where('user_id', $actor)->where('role', 'driver')->where('active', true)->whereIn('engagement_status', ['employed', 'contractor'])->whereNull('archived_at')->pluck('public_id')->all();
    }

    public function assigned(DispatchJob $job, int $actor): void
    {
        $ids = $this->driverIds($actor);
        foreach ($job->resource_snapshot as $r) {
            if ($r['kind'] === 'driver' && in_array($r['staff_public_id'], $ids, true)) {
                return;
            }
        }throw new AuthorizationException('This job is not assigned to your active driver profile.');
    }
}
