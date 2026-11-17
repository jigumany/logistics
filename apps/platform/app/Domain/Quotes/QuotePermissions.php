<?php

namespace App\Domain\Quotes;

use App\Domain\Identity\Membership;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

final class QuotePermissions
{
    public function manage(int $actorId): void
    {
        $membership = Membership::where('tenant_id', app(TenantContext::class)->tenant()->id)->where('user_id', $actorId)->first();
        if (! $membership?->role->managesCompany()) {
            throw new AuthorizationException('Only company owners and administrators can manage quotation configuration or approvals.');
        }
    }
}
