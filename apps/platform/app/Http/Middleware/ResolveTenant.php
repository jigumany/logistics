<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Membership;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->clear();
        try {
            $tenant = Tenant::where('slug', $request->route('company'))->where('active', true)->firstOrFail();
            $membership = Membership::where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->first();
            abort_unless($membership !== null, 404);
            $this->context->set($tenant);
            $request->attributes->set('membership', $membership);

            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
