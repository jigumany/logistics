<?php

use App\Domain\Fleet\Vehicle;
use App\Domain\Geography\CoverageLookup;
use App\Domain\Geography\ServiceZone;
use App\Domain\Resources\Resource;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function spatialTenant(string $slug): Tenant
{
    $tenant = Tenant::create(['name' => $slug, 'slug' => $slug]);
    app(TenantContext::class)->set($tenant);

    return $tenant;
}
function spatialZone(array $changes = []): ServiceZone
{
    return ServiceZone::create([...['name' => 'Coverage', 'zone_type' => 'both', 'boundary' => ['type' => 'Polygon', 'coordinates' => [[[18.3, -34.1], [18.6, -34.1], [18.6, -33.8], [18.3, -33.8], [18.3, -34.1]]]], 'active' => true, 'priority' => 1, 'manual_review' => false, 'example_only' => false], ...$changes]);
}
beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires PostgreSQL/PostGIS.');
    }
});
it('covers interior and boundary points but rejects exterior coordinates', function (): void {
    spatialTenant('spatial');
    spatialZone();
    $lookup = app(CoverageLookup::class);
    expect($lookup->check(-33.9, 18.4)['covered'])->toBeTrue()->and($lookup->check(-34.1, 18.3)['covered'])->toBeTrue()->and($lookup->check(-35, 19)['covered'])->toBeFalse();
});
it('resolves overlapping zones using priority then stable identity', function (): void {
    spatialTenant('spatial');
    $first = spatialZone(['name' => 'First', 'priority' => 10]);
    spatialZone(['name' => 'Second', 'priority' => 10]);
    expect(app(CoverageLookup::class)->check(-33.9, 18.4)['zone']['public_id'])->toBe($first->public_id);
    $priority = spatialZone(['name' => 'Priority', 'priority' => 20, 'manual_review' => true]);
    expect(app(CoverageLookup::class)->check(-33.9, 18.4)['zone']['public_id'])->toBe($priority->public_id);
});
it('ignores inactive and example zones and respects coverage purpose', function (): void {
    spatialTenant('spatial');
    spatialZone(['active' => false]);
    spatialZone(['example_only' => true]);
    spatialZone(['zone_type' => 'delivery']);
    expect(app(CoverageLookup::class)->check(-33.9, 18.4, 'pickup')['covered'])->toBeFalse()->and(app(CoverageLookup::class)->check(-33.9, 18.4, 'delivery')['covered'])->toBeTrue();
});
it('never uses another company spatial coverage', function (): void {
    $tenant = spatialTenant('first');
    spatialZone();
    spatialTenant('second');
    expect(app(CoverageLookup::class)->check(-33.9, 18.4)['covered'])->toBeFalse();
    app(TenantContext::class)->set($tenant);
    expect(app(CoverageLookup::class)->check(-33.9, 18.4)['covered'])->toBeTrue();
});
it('keeps PostGIS geometry synchronized when boundaries change', function (): void {
    spatialTenant('spatial');
    $zone = spatialZone();
    $zone->boundary = ['type' => 'Polygon', 'coordinates' => [[[20, -30], [21, -30], [21, -29], [20, -29], [20, -30]]]];
    $zone->save();
    expect(app(CoverageLookup::class)->check(-33.9, 18.4)['covered'])->toBeFalse();
});
it('prevents direct updates to shared service definitions', function (): void {
    expect(fn () => DB::table('service_types')->where('key', 'household_moving')->update(['name' => 'Tenant override']))->toThrow(QueryException::class);
});
it('prevents cross tenant resource entity references at database level', function (): void {
    spatialTenant('first');
    $vehicle = Vehicle::factory()->create();
    spatialTenant('second');
    expect(fn () => Resource::create(['kind' => 'vehicle', 'vehicle_id' => $vehicle->id]))->toThrow(QueryException::class);
});
