<?php

namespace App\Domain\Identity;

use App\Domain\Resources\StaffMember;
use App\Domain\Tenancy\RecordsAdministrativeChange;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class AcceptDriverInvitation
{
    public function execute(string $token, array $input, ?User $actor): User
    {
        return DB::transaction(function () use ($token, $input, $actor) {
            Tenant::whereKey(app(TenantContext::class)->tenant()->id)->lockForUpdate()->firstOrFail();
            $invite = DriverInvitation::where('token_hash', hash('sha256', $token))->whereNull('accepted_at')->where('expires_at', '>', now())->lockForUpdate()->firstOrFail();
            $staff = StaffMember::whereKey($invite->staff_id)->lockForUpdate()->firstOrFail();
            abort_unless($staff->role === 'driver' && $staff->active && ! $staff->archived_at && ! $staff->user_id && in_array($staff->engagement_status, ['employed', 'contractor'], true) && mb_strtolower(trim($staff->email ?? '')) === $invite->email, 409, 'This driver invitation is no longer eligible.');
            $user = User::whereRaw('LOWER(email)=?', [$invite->email])->lockForUpdate()->first();
            if ($user) {
                abort_unless($actor && $actor->id === $user->id, 403, 'Sign in to the invited account before accepting.');
            } else {
                abort_unless($actor === null, 403, 'Sign out before creating the invited account.');
                Validator::make($input, ['password' => ['required', 'confirmed', Password::min(12)->letters()->numbers(), 'max:72']])->validate();
                if (strlen($input['password']) > 72) {
                    throw ValidationException::withMessages(['password' => 'Choose a shorter password.']);
                }
                $user = User::create(['name' => $staff->full_name, 'email' => $invite->email, 'password' => $input['password']]);
            }
            Membership::firstOrCreate(['tenant_id' => app(TenantContext::class)->tenant()->id, 'user_id' => $user->id], ['role' => Role::Driver]);
            if (StaffMember::where('user_id', $user->id)->whereKeyNot($staff->id)->exists()) {
                throw ValidationException::withMessages(['driver' => 'This account is already linked to a staff profile.']);
            }
            $staff->user_id = $user->id;
            $staff->save();
            $invite->accepted_at = now();
            $invite->save();
            app(RecordsAdministrativeChange::class)->record($staff, 'identity.driver_joined', $user->id);

            return $user;
        });
    }
}
