<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class SessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $credentials['email'] = Str::lower(trim($credentials['email']));
        if (! Auth::attempt($credentials, false)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        $request->session()->regenerate();

        return redirect()->intended('/companies');
    }

    public function companies(Request $request): Response
    {
        return Inertia::render('company/Select', ['companies' => Tenant::query()->where('active', true)->whereIn('id', function ($query) use ($request): void {
            $query->select('tenant_id')->from('memberships')->where('user_id', $request->user()->id);
        })->orderBy('name')->paginate(20)->through(fn (Tenant $tenant) => $tenant->only('name', 'slug', 'public_id'))]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
