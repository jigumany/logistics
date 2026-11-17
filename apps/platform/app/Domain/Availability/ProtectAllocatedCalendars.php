<?php

namespace App\Domain\Availability;

use App\Domain\Bookings\ResourceAllocation;
use App\Domain\Resources\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ProtectAllocatedCalendars
{
    public function assertValid(): void
    {
        $allocations = ResourceAllocation::whereIn('status', ['held', 'confirmed'])->where('ends_at', '>', now())->orderBy('starts_at')->limit(5001)->get();
        if ($allocations->isEmpty()) {
            return;
        }
        if ($allocations->count() > 5000) {
            throw ValidationException::withMessages(['calendar' => 'Review allocated bookings in smaller scheduling batches before changing these hours.']);
        }
        $from = CarbonImmutable::instance($allocations->min('starts_at'));
        $until = CarbonImmutable::instance($allocations->max('ends_at'));
        $resources = Resource::whereIn('id', $allocations->pluck('resource_id')->unique())->get();
        $windows = app(OperatingCalendar::class)->batch($from, $until, $resources->all());
        foreach ($allocations as $allocation) {
            $covered = false;
            foreach ($windows[$allocation->resource_id] ?? [] as $window) {
                if (CarbonImmutable::parse($window['starts_at']) <= $allocation->starts_at && CarbonImmutable::parse($window['ends_at']) >= $allocation->ends_at) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                throw ValidationException::withMessages(['calendar' => 'This change would invalidate a held or confirmed booking. Release or reschedule the affected booking first.']);
            }
        }
    }
}
