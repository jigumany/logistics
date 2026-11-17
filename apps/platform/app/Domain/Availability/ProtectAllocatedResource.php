<?php

namespace App\Domain\Availability;

use App\Domain\Bookings\ResourceAllocation;
use App\Domain\Resources\Resource;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ProtectAllocatedResource
{
    public function assertMutable(string $column, int $entityId): void
    {
        if (! Schema::hasTable('resource_allocations')) {
            return;
        }
        $ids = Resource::where($column, $entityId)->select('id');
        if (ResourceAllocation::whereIn('resource_id', $ids)->whereIn('status', ['held', 'confirmed'])->where('ends_at', '>', now())->exists()) {
            throw ValidationException::withMessages(['resource' => 'Release or reschedule existing reservations before changing operational availability, qualifications, depot or vehicle capacity. Contact information and notes remain editable.']);
        }
    }
}
