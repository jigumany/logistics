<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolvePublicTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->clear();
        try {
            $tenant = Tenant::where('slug', $request->route('company'))->where('active', true)->firstOrFail();
            $this->context->set($tenant);
            $response = $next($request);
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('Referrer-Policy', 'no-referrer');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        } finally {
            $this->context->clear();
        }
    }
}
