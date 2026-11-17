<?php

namespace App\Domain\Bookings;

use App\Domain\Identity\Membership;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

final class BookingPermissions
{
    public function manage(int $actorId): void
    {
        $role = Membership::where('tenant_id', app(TenantContext::class)->tenant()->id)->where('user_id', $actorId)->first()?->role->value;
        if (! in_array($role, ['owner', 'admin', 'dispatcher'], true)) {
            throw new AuthorizationException('Only authorised operations staff can manage bookings.');
        }
    }
}
