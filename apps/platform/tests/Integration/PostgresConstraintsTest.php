<?php

use App\Domain\Identity\Membership;
use App\Domain\Tenancy\AuditEntry;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires PostgreSQL/PostGIS.');
    }
});
it('installs PostGIS and scheduling constraint support', function (): void {
    expect(DB::selectOne("SELECT count(*) AS total FROM pg_extension WHERE extname IN ('postgis','btree_gist')")->total)->toBe(2);
});
it('rejects raw tenant reassignment', function (): void {
    $alpha = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta']);
    app(TenantContext::class)->set($alpha);
    $settings = CompanySettings::create(['display_name' => 'Alpha', 'contact_email' => 'alpha@example.test']);
    expect(fn () => DB::table('company_settings')->where('id', $settings->id)->update(['tenant_id' => $beta->id]))->toThrow(QueryException::class);
});
it('rejects invalid deposit amounts at database level', function (): void {
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    app(TenantContext::class)->set($tenant);
    expect(fn () => CompanySettings::create(['display_name' => 'Alpha', 'contact_email' => 'alpha@example.test', 'deposit_basis_points' => 10001]))->toThrow(QueryException::class);
});
it('keeps audit records immutable at database level', function (): void {
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $user = User::factory()->create();
    Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);
    app(TenantContext::class)->set($tenant);
    $audit = AuditEntry::create(['actor_id' => $user->id, 'action' => 'test', 'subject_public_id' => $tenant->public_id]);
    expect(fn () => DB::table('audit_entries')->where('id', $audit->id)->delete())->toThrow(QueryException::class);
});
