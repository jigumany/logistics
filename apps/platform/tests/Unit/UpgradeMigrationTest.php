<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);
it('upgrades legacy tenant services without changing their identities and rolls back safely', function (): void {
    $paths = ['database/migrations/0001_01_01_000000_create_users_table.php', 'database/migrations/0001_01_01_000001_create_cache_table.php', 'database/migrations/0001_01_01_000002_create_jobs_table.php', 'database/migrations/2026_11_16_000001_create_tenancy_tables.php', 'database/migrations/2026_11_16_000002_protect_tenant_ownership.php'];
    expect(Artisan::call('migrate:fresh', ['--path' => $paths, '--force' => true]))->toBe(0);
    $tenant = DB::table('tenants')->insertGetId(['public_id' => (string) Str::ulid(), 'name' => 'Legacy', 'slug' => 'legacy']);
    $publicId = (string) Str::ulid();
    $serviceId = DB::table('service_types')->insertGetId(['public_id' => $publicId, 'tenant_id' => $tenant, 'code' => 'household', 'name' => 'Legacy public name', 'enabled' => false]);
    expect(Artisan::call('migrate', ['--path' => ['database/migrations/2026_11_16_000003_create_operational_foundation.php'], '--force' => true]))->toBe(0);
    $service = DB::table('tenant_services')->find($serviceId);
    expect($service->public_id)->toBe($publicId)->and($service->name)->toBe('Legacy public name')->and((bool) $service->enabled)->toBeFalse();
    expect(DB::table('service_types')->where('id', $service->service_type_id)->value('key'))->toBe('household_moving');
    expect(Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]))->toBe(0);
    expect(DB::table('service_types')->find($serviceId)->public_id)->toBe($publicId);
});
