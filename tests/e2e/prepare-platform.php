<?php

use App\Domain\Identity\Membership;
use App\Domain\Identity\Role;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../../apps/platform/vendor/autoload.php';
$app = require __DIR__.'/../../apps/platform/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = realpath(__DIR__.'/../../.cache').DIRECTORY_SEPARATOR.'playwright.sqlite';
if (! $app->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $expected) {
    throw new RuntimeException('Browser tests require their dedicated SQLite database.');
}
Artisan::call('migrate:fresh', ['--force' => true]);
$owner = User::create(['name' => 'Browser Owner', 'email' => 'browser-owner@example.test', 'password' => 'browser-tests-only-password']);
$viewer = User::create(['name' => 'Browser Viewer', 'email' => 'browser-viewer@example.test', 'password' => 'browser-tests-only-password']);
$tenant = Tenant::create(['name' => 'Browser Movers', 'slug' => 'browser-movers']);
Membership::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'role' => Role::Owner]);
Membership::create(['tenant_id' => $tenant->id, 'user_id' => $viewer->id, 'role' => Role::Viewer]);
app(TenantContext::class)->set($tenant);
CompanySettings::create(['display_name' => 'Browser Movers', 'contact_email' => 'browser@example.test', 'payment_terms' => 'deposit', 'deposit_basis_points' => 2500]);
TenantService::create(['code' => 'household', 'name' => 'Household moving', 'enabled' => true]);
app(TenantContext::class)->clear();
Tenant::create(['name' => 'Private Company', 'slug' => 'private-company']);
