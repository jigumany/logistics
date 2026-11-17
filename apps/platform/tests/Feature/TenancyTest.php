<?php

use App\Domain\Identity\Membership;
use App\Domain\Identity\Role;
use App\Domain\Notifications\OutboxMessage;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\AuditEntry;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\UpdateCompanySettings;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function company(string $slug): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
    app(TenantContext::class)->set($tenant);
    CompanySettings::create(['display_name' => $tenant->name, 'contact_email' => $slug.'@example.test', 'payment_terms' => 'deposit', 'deposit_basis_points' => 2500]);
    TenantService::create(['code' => 'household', 'name' => 'Household moving', 'enabled' => true]);
    app(TenantContext::class)->clear();

    return $tenant;
}
function member(Tenant $tenant, Role $role = Role::Owner): User
{
    $user = User::factory()->create();
    Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);

    return $user;
}
function settingsPayload(): array
{
    return ['display_name' => 'Updated company', 'contact_email' => 'ops@example.test', 'contact_phone' => '+27215550000', 'payment_terms' => 'deposit', 'deposit_basis_points' => 3000];
}
beforeEach(function (): void {
    $this->withoutVite();
});
it('requires authentication', function (): void {
    $this->get('/c/alpha')->assertRedirect('/login');
});
it('conceals companies from non members', function (): void {
    $tenant = company('alpha');
    $user = User::factory()->create();
    $this->actingAs($user)->get('/c/alpha')->assertNotFound();
});
it('rejects inactive tenants', function (): void {
    $tenant = company('alpha');
    $user = member($tenant);
    $tenant->update(['active' => false]);
    $this->actingAs($user)->get('/c/alpha')->assertNotFound();
});
it('shares only the active company data and clears context', function (): void {
    $alpha = company('alpha');
    company('beta');
    $user = member($alpha);
    $this->actingAs($user)->get('/c/alpha')->assertOk()->assertInertia(fn (Assert $page) => $page->component('company/Dashboard')->where('company.slug', 'alpha')->where('auth.role', 'owner')->where('settings.display_name', 'Alpha')->has('services', 1)->missing('settings.tenant_id'));
    expect(fn () => app(TenantContext::class)->tenant())->toThrow(LogicException::class);
    $this->get('/c/beta')->assertNotFound();
});
it('denies writes for operational and read only roles', function (Role $role): void {
    $tenant = company('alpha');
    $user = member($tenant, $role);
    $this->actingAs($user)->put('/c/alpha/settings', settingsPayload())->assertForbidden();
    expect(DB::table('audit_entries')->count())->toBe(0);
})->with([Role::Viewer, Role::Driver, Role::Dispatcher]);
it('atomically saves settings audit and outbox for managers', function (Role $role): void {
    $tenant = company('alpha');
    $user = member($tenant, $role);
    $this->actingAs($user)->from('/c/alpha')->put('/c/alpha/settings', settingsPayload())->assertRedirect('/c/alpha');
    $this->assertDatabaseHas('company_settings', ['tenant_id' => $tenant->id, 'display_name' => 'Updated company', 'deposit_basis_points' => 3000]);
    $this->assertDatabaseHas('audit_entries', ['tenant_id' => $tenant->id, 'actor_id' => $user->id, 'action' => 'company.settings.updated']);
    $this->assertDatabaseHas('outbox_messages', ['tenant_id' => $tenant->id, 'event_type' => 'company.settings.updated', 'published_at' => null]);
})->with([Role::Owner, Role::Admin]);
it('validates deposits without changing settings', function (): void {
    $tenant = company('alpha');
    $user = member($tenant);
    $this->actingAs($user)->put('/c/alpha/settings', [...settingsPayload(), 'deposit_basis_points' => 10001])->assertSessionHasErrors('deposit_basis_points');
    $this->assertDatabaseHas('company_settings', ['tenant_id' => $tenant->id, 'deposit_basis_points' => 2500]);
});
it('prevents forged ownership and currency input', function (): void {
    $tenant = company('alpha');
    $other = company('beta');
    $user = member($tenant);
    $this->actingAs($user)->put('/c/alpha/settings', [...settingsPayload(), 'tenant_id' => $other->id, 'currency' => 'USD']);
    $this->assertDatabaseHas('company_settings', ['tenant_id' => $tenant->id, 'currency' => 'ZAR', 'display_name' => 'Updated company']);
    $this->assertDatabaseHas('company_settings', ['tenant_id' => $other->id, 'display_name' => 'Beta']);
});
it('rejects service identifiers belonging to another tenant', function (): void {
    $alpha = company('alpha');
    $beta = company('beta');
    $user = member($alpha);
    $service = DB::table('tenant_services')->where('tenant_id', $beta->id)->first();
    $this->actingAs($user)->patch('/c/alpha/services/'.$service->public_id, ['enabled' => false])->assertNotFound();
});
it('fails closed for tenant queries without a context', function (): void {
    expect(fn () => TenantService::count())->toThrow(LogicException::class);
});
it('prevents model ownership reassignment', function (): void {
    $alpha = company('alpha');
    $beta = company('beta');
    app(TenantContext::class)->set($alpha);
    $settings = CompanySettings::firstOrFail();
    $settings->tenant_id = $beta->id;
    expect(fn () => $settings->save())->toThrow(LogicException::class);
});
it('rejects cross tenant audit actors at database level', function (): void {
    $alpha = company('alpha');
    $beta = company('beta');
    $user = member($beta);
    app(TenantContext::class)->set($alpha);
    expect(fn () => AuditEntry::create(['actor_id' => $user->id, 'action' => 'forged', 'subject_public_id' => $alpha->public_id]))->toThrow(QueryException::class);
});
it('lists only companies the authenticated user belongs to', function (): void {
    $alpha = company('alpha');
    company('beta');
    $user = member($alpha);
    $this->actingAs($user)->get('/companies')->assertInertia(fn (Assert $page) => $page->has('companies.data', 1)->where('companies.data.0.slug', 'alpha'));
});

it('prevents deleting a loaded model after switching companies', function (): void {
    $alpha = company('alpha');
    $beta = company('beta');
    app(TenantContext::class)->set($alpha);
    $service = TenantService::firstOrFail();
    app(TenantContext::class)->set($beta);
    expect(fn () => $service->delete())->toThrow(LogicException::class);
});
it('rolls settings back if the outbox cannot be recorded', function (): void {
    $tenant = company('alpha');
    $user = member($tenant);
    app(TenantContext::class)->set($tenant);
    OutboxMessage::creating(fn () => throw new RuntimeException('Outbox unavailable'));
    try {
        expect(fn () => app(UpdateCompanySettings::class)->execute(settingsPayload(), $user->id))->toThrow(RuntimeException::class);
        $this->assertDatabaseHas('company_settings', ['tenant_id' => $tenant->id, 'display_name' => 'Alpha']);
        expect(DB::table('audit_entries')->count())->toBe(0);
    } finally {
        OutboxMessage::flushEventListeners();
        app(TenantContext::class)->clear();
    }
});
it('updates services for managers and records an event', function (): void {
    $tenant = company('alpha');
    $user = member($tenant);
    $service = DB::table('tenant_services')->where('tenant_id', $tenant->id)->first();
    $this->actingAs($user)->patch('/c/alpha/services/'.$service->public_id, ['enabled' => false])->assertRedirect();
    $this->assertDatabaseHas('tenant_services', ['id' => $service->id, 'enabled' => false]);
    $this->assertDatabaseHas('outbox_messages', ['tenant_id' => $tenant->id, 'event_type' => 'service.updated']);
});
it('does not allow duplicate company membership', function (): void {
    $tenant = company('alpha');
    $user = member($tenant);
    expect(fn () => Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => Role::Viewer]))->toThrow(QueryException::class);
});
