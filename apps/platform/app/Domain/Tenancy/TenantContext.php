<?php

namespace App\Domain\Tenancy;

use LogicException;

final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw new LogicException('Tenant context is required.');
    }

    public function clear(): void
    {
        $this->tenant = null;
    }
}
