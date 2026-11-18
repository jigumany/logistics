<?php

namespace App\Http\Controllers;

use App\Domain\Identity\AcceptDriverInvitation;
use App\Domain\Identity\DriverInvitation;
use App\Domain\Identity\InviteDriver;
use App\Domain\Resources\StaffMember;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class DriverInvitationController extends Controller
{
    public function store(Request $r, string $company, string $staff): JsonResponse
    {
        $url = app(InviteDriver::class)->execute(StaffMember::where('public_id', $staff)->firstOrFail(), $r->user()->id);

        return response()->json(['message' => 'Invitation email queued for the driver.', ...(app()->environment(['local', 'testing']) ? ['url' => $url] : [])]);
    }

    public function show(Request $r, string $company, string $token): Response
    {
        $i = DriverInvitation::where('token_hash', hash('sha256', $token))->whereNull('accepted_at')->where('expires_at', '>', now())->firstOrFail();
        $existing = User::whereRaw('LOWER(email)=?', [$i->email])->exists();
        if ($existing && ! $r->user()) {
            $r->session()->put('url.intended', $r->url());
        }

        return Inertia::render('driver/Invitation', ['business' => app(TenantContext::class)->tenant()->name, 'email' => $i->email, 'existingAccount' => $existing, 'signedIn' => (bool) $r->user(), 'acceptUrl' => $r->url()]);
    }

    public function accept(Request $r, string $company, string $token): RedirectResponse
    {
        $u = app(AcceptDriverInvitation::class)->execute($token, $r->only(['password', 'password_confirmation']), $r->user());
        Auth::login($u);
        $r->session()->regenerate();

        return redirect('/c/'.$company.'/dispatch');
    }
}
