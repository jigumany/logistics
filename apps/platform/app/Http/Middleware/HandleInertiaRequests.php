<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request),
            'auth' => fn () => ['user' => $request->user()?->only('name', 'email'), 'role' => $request->attributes->get('membership')?->role->value],
            'company' => fn () => $request->attributes->get('membership') ? app(TenantContext::class)->tenant()->only('name', 'slug', 'public_id') : null,
            'flash' => ['success' => fn () => $request->session()->get('success')],
        ];
    }
}
