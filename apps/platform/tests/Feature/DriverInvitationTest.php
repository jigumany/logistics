<?php

use App\Domain\Identity\AcceptDriverInvitation;
use App\Domain\Identity\DriverInvitation;
use App\Domain\Identity\InviteDriver;
use App\Domain\Identity\Membership;
use App\Domain\Identity\Role;
use App\Domain\Notifications\DriverInvitationNotice;
use App\Domain\Resources\StaffMember;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\DriverInvitationController;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;

function driverInvitationFixture(): array
{
    Mail::fake();
    $t = Tenant::create(['name' => 'Driver Test Company', 'slug' => 'driver-test-company']);
    $owner = User::factory()->create();
    Membership::create(['tenant_id' => $t->id, 'user_id' => $owner->id, 'role' => Role::Owner]);
    app(TenantContext::class)->set($t);
    CompanySettings::create(['display_name' => 'Driver Test Company', 'contact_email' => 'office@example.test']);
    $s = StaffMember::factory()->create(['role' => 'driver', 'full_name' => 'Invited Driver', 'email' => 'invited-driver@example.test', 'active' => true, 'engagement_status' => 'employed', 'user_id' => null]);

    return [$t, $owner, $s];
}
it('provisions a driver through a single-use private invitation without an admin setting the password', function () {
    [$t,$o,$s] = driverInvitationFixture();
    $link = app(InviteDriver::class)->execute($s, $o->id);
    $token = basename($link);
    $i = DriverInvitation::firstOrFail();
    expect($i->token_hash)->toBe(hash('sha256', $token))->and($i->toArray())->not->toHaveKey('token_hash');
    $u = app(AcceptDriverInvitation::class)->execute($token, ['password' => 'Driver-password-123', 'password_confirmation' => 'Driver-password-123'], null);
    expect(Hash::check('Driver-password-123', $u->password))->toBeTrue()->and($s->fresh()->user_id)->toBe($u->id)->and(Membership::where('tenant_id', $t->id)->where('user_id', $u->id)->value('role'))->toBe(Role::Driver);
    expect(fn () => app(AcceptDriverInvitation::class)->execute($token, [], null))->toThrow(ModelNotFoundException::class);
});
it('does not reset or take over an existing email account through an invitation', function () {
    [$t,$o,$s] = driverInvitationFixture();
    $u = User::factory()->create(['email' => $s->email]);
    $original = $u->password;
    $token = basename(app(InviteDriver::class)->execute($s, $o->id));
    expect(fn () => app(AcceptDriverInvitation::class)->execute($token, ['password' => 'Untrusted-reset-123', 'password_confirmation' => 'Untrusted-reset-123'], null))->toThrow(HttpException::class);
    expect($u->fresh()->password)->toBe($original);
    app(AcceptDriverInvitation::class)->execute($token, [], $u);
    expect($u->fresh()->password)->toBe($original)->and($s->fresh()->user_id)->toBe($u->id);
});
it('expires old invitations on reissue and rejects expired links', function () {
    [$t,$o,$s] = driverInvitationFixture();
    $first = basename(app(InviteDriver::class)->execute($s, $o->id));
    $next = basename(app(InviteDriver::class)->execute($s, $o->id));
    expect(fn () => app(AcceptDriverInvitation::class)->execute($first, [], null))->toThrow(ModelNotFoundException::class);
    $this->travel(25)->hours();
    expect(fn () => app(AcceptDriverInvitation::class)->execute($next, [], null))->toThrow(ModelNotFoundException::class);
});
it('restricts invitation creation to administrators and blocks cross-company links', function () {
    [$t,$o,$s] = driverInvitationFixture();
    $u = User::factory()->create();
    Membership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role' => Role::Dispatcher]);
    expect(fn () => app(InviteDriver::class)->execute($s, $u->id))->toThrow(AuthorizationException::class);
    $token = basename(app(InviteDriver::class)->execute($s, $o->id));
    $other = Tenant::create(['name' => 'Other Company', 'slug' => 'other-driver-company']);
    app(TenantContext::class)->set($other);
    expect(fn () => app(AcceptDriverInvitation::class)->execute($token, [], null))->toThrow(ModelNotFoundException::class);
});
it('accepts invitations through the customer-facing form and signs into the driver workspace', function () {
    [$t,$o,$s] = driverInvitationFixture();
    $url = app(InviteDriver::class)->execute($s, $o->id);
    $path = parse_url($url, PHP_URL_PATH);
    $this->get($path)->assertOk();
    $this->post($path, ['password' => 'Driver-password-123', 'password_confirmation' => 'Driver-password-123'])->assertRedirect('/c/'.$t->slug.'/dispatch');
    $this->assertAuthenticated();
    $this->get('/c/'.$t->slug.'/dispatch')->assertOk();
    $this->get($path)->assertNotFound();
});
it('queues invitations only to the stored driver email and hides acceptance links in production responses', function () {
    [$t,$o,$s] = driverInvitationFixture();
    app()->instance('env', 'production');
    config(['app.url' => 'https://trusted.example.test']);
    URL::setRequest(Request::create('https://untrusted.example.test'));
    $r = Request::create('/c/'.$t->slug.'/drivers/'.$s->public_id.'/invitation', 'POST');
    $r->setUserResolver(fn () => $o);
    $response = app(DriverInvitationController::class)->store($r, $t->slug, $s->public_id);
    expect($response->getData(true))->not->toHaveKey('url');
    Mail::assertQueued(DriverInvitationNotice::class, fn ($m) => $m->hasTo($s->email) && str_starts_with($m->invitationUrl, 'https://trusted.example.test/driver/'.$t->slug.'/invitations/'));
});
