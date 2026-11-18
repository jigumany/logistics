<?php

namespace App\Domain\Identity;

use App\Domain\Notifications\DriverInvitationNotice;
use App\Domain\Resources\StaffMember;
use App\Domain\Tenancy\RecordsAdministrativeChange;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InviteDriver
{
    public function execute(StaffMember $staff, int $actor): string
    {
        $membership = Membership::where('tenant_id', app(TenantContext::class)->tenant()->id)->where('user_id', $actor)->first();
        if (! $membership?->role->managesCompany()) {
            throw new AuthorizationException('Only owners and administrators can invite drivers.');
        }

        return DB::transaction(function () use ($staff, $actor) {
            Tenant::whereKey(app(TenantContext::class)->tenant()->id)->lockForUpdate()->firstOrFail();
            $s = StaffMember::whereKey($staff->id)->lockForUpdate()->firstOrFail();
            if ($s->role !== 'driver' || ! $s->active || $s->archived_at || ! in_array($s->engagement_status, ['employed', 'contractor'], true) || $s->user_id) {
                throw ValidationException::withMessages(['driver' => 'Select an active driver who does not already have a linked account.']);
            }
            $email = mb_strtolower(trim($s->email ?? ''));
            Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:254']])->validate();
            DriverInvitation::where('staff_id', $s->id)->whereNull('accepted_at')->update(['expires_at' => now()]);
            $token = Str::random(64);
            $i = DriverInvitation::create(['staff_id' => $s->id, 'email' => $email, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'invited_by' => $actor]);
            app(RecordsAdministrativeChange::class)->record($i, 'identity.driver_invited', $actor);

            $url = rtrim((string) config('app.url'), '/').'/driver/'.app(TenantContext::class)->tenant()->slug.'/invitations/'.$token;
            Mail::to($email)->queue(new DriverInvitationNotice(app(TenantContext::class)->tenant()->name, $url));

            return $url;
        });
    }
}
