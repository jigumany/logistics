<?php

use App\Domain\Identity\Membership;
use App\Domain\Identity\Role;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Database\Seeders\OperationalSeeder;
use Illuminate\Support\Facades\DB;

it('seeds operational examples idempotently without offering verified coverage', function (): void {
    $tenant = Tenant::create(['name' => 'Example', 'slug' => 'example']);
    $user = User::factory()->create();
    Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => Role::Owner]);
    app(TenantContext::class)->set($tenant);
    CompanySettings::create(['display_name' => 'Example', 'contact_email' => 'example@example.test']);
    app(TenantContext::class)->clear();
    $this->seed(OperationalSeeder::class);
    $this->seed(OperationalSeeder::class);
    $this->assertDatabaseCount('vehicles', 1);
    $this->assertDatabaseCount('staff_members', 1);
    $this->assertDatabaseCount('service_zones', 1);
    expect(DB::table('service_zones')->where('active', true)->exists())->toBeFalse()->and(DB::table('resources')->where('active', true)->exists())->toBeFalse();
});
