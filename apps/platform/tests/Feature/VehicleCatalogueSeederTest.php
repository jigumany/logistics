<?php

use App\Domain\Fleet\AssessQuotationLoad;
use App\Domain\Fleet\Depot;
use App\Domain\Fleet\LoadCapacity;
use App\Domain\Fleet\SaveVehicle;
use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleType;
use App\Domain\Identity\Membership;
use App\Domain\Identity\Role;
use App\Domain\Inventory\InventoryCalculator;
use App\Domain\Quotes\CalculateQuote;
use App\Domain\Quotes\CreateQuoteDraft;
use App\Domain\Resources\Resource;
use App\Domain\Resources\ResourceRegistry;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Database\Seeders\DemonstrationVehicleSeeder;
use Database\Seeders\VehicleTypeCatalogueSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function demonstrationTenant(string $slug = 'cape-town-movers'): array
{
    $tenant = Tenant::create(['name' => $slug, 'slug' => $slug]);
    $owner = User::factory()->create();
    Membership::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'role' => Role::Owner]);
    app(TenantContext::class)->set($tenant);
    CompanySettings::create(['display_name' => $slug, 'contact_email' => 'fleet@example.test']);

    return [$tenant, $owner];
}
it('seeds six shared illustrative types with conservative three-decimal usable volumes', function (): void {
    $this->seed(VehicleTypeCatalogueSeeder::class);
    $this->seed(VehicleTypeCatalogueSeeder::class);
    $expected = [
        'demo_small_delivery_van' => ['2.400', '1.500', '1.400', '4.032', '700.000'],
        'demo_large_panel_van' => ['3.500', '1.750', '1.900', '9.310', '1200.000'],
        'demo_one_ton_canopy_pickup' => ['2.200', '1.500', '1.200', '3.168', '1000.000'],
        'demo_light_box_truck' => ['4.200', '2.100', '2.200', '15.523', '2000.000'],
        'demo_medium_box_truck' => ['5.500', '2.300', '2.400', '24.288', '4000.000'],
        'demo_large_moving_truck' => ['7.000', '2.400', '2.500', '33.600', '8000.000'],
    ];
    expect(VehicleType::whereIn('key', array_keys($expected))->count())->toBe(6);
    foreach ($expected as $key => $values) {
        $type = VehicleType::where('key', $key)->firstOrFail();
        expect(array_map(fn ($field) => number_format((float) $type->getAttribute($field), 3, '.', ''), ['cargo_length_m', 'cargo_width_m', 'cargo_height_m', 'usable_volume_m3', 'payload_kg']))->toBe($values);
        expect($type->description)->toContain('Synthetic demonstration', '80%');
    }
});
it('preserves edited catalogue records and all legacy catalogue keys', function (): void {
    $this->seed(VehicleTypeCatalogueSeeder::class);
    DB::table('vehicle_types')->where('key', 'demo_light_box_truck')->update(['payload_kg' => 1850, 'active' => false]);
    $count = VehicleType::count();
    $this->seed(VehicleTypeCatalogueSeeder::class);
    expect(VehicleType::count())->toBe($count)->and((float) VehicleType::where('key', 'demo_light_box_truck')->value('payload_kg'))->toBe(1850.0);
    expect((bool) VehicleType::where('key', 'demo_light_box_truck')->value('active'))->toBeFalse()->and(VehicleType::where('key', 'sample_van')->exists())->toBeTrue();
});
it('creates only one inactive unverified initial-tenant truck and reuses its depot', function (): void {
    [$tenant, $owner] = demonstrationTenant();
    $depot = Depot::factory()->create();
    $this->seed(DemonstrationVehicleSeeder::class);
    $this->seed(DemonstrationVehicleSeeder::class);
    app(TenantContext::class)->set($tenant);
    $truck = Vehicle::firstOrFail();
    expect(Vehicle::count())->toBe(1)->and(Depot::count())->toBe(1)->and($truck->name)->toBe('Demo Moving Truck 01')->and($truck->registration_number)->toBe('DEMOBOX0001')->and($truck->status)->toBe('inactive')->and($truck->capacity_verified)->toBeFalse()->and($truck->depot_id)->toBe($depot->id)->and($truck->type->key)->toBe('demo_light_box_truck');
    expect(Resource::where('vehicle_id', $truck->id)->firstOrFail()->active)->toBeFalse()->and(app(ResourceRegistry::class)->eligible()->exists())->toBeFalse();
});
it('preserves real vehicles and edited demo identities without creating duplicate trucks', function (): void {
    [$tenant, $owner] = demonstrationTenant();
    $real = app(SaveVehicle::class)->execute(['name' => 'Actual fleet', 'registration_number' => 'REAL001', 'vehicle_type_id' => VehicleType::firstOrFail()->id, 'status' => 'inactive', 'capacity_margin_basis_points' => 1000, 'payload_kg' => 850], $owner->id);
    $original = $real->fresh()->getAttributes();
    $this->seed(DemonstrationVehicleSeeder::class);
    app(TenantContext::class)->set($tenant);
    $demo = Vehicle::whereNotNull('demonstration_key')->firstOrFail();
    $demo->name = 'Edited truck';
    $demo->registration_number = 'USER001';
    $demo->payload_kg = '1750';
    $demo->save();
    $this->seed(DemonstrationVehicleSeeder::class);
    app(TenantContext::class)->set($tenant);
    expect(Vehicle::count())->toBe(2)->and($real->fresh()->getAttributes())->toBe($original)->and($demo->fresh()->name)->toBe('Edited truck')->and((float) $demo->fresh()->payload_kg)->toBe(1750.0);
    app(SaveVehicle::class)->archive($demo->fresh(), $owner->id);
    $this->seed(DemonstrationVehicleSeeder::class);
    app(TenantContext::class)->set($tenant);
    expect(Vehicle::count())->toBe(2)->and($demo->fresh()->archived_at)->not->toBeNull();
});
it('does not create vehicles for another tenant or create a depot when none exists', function (): void {
    [$initial, $owner] = demonstrationTenant();
    [$other] = demonstrationTenant('another-company');
    $this->seed(DemonstrationVehicleSeeder::class);
    expect(DB::table('vehicles')->where('tenant_id', $other->id)->exists())->toBeFalse()->and(DB::table('vehicles')->where('tenant_id', $initial->id)->count())->toBe(1)->and(DB::table('depots')->count())->toBe(0);
});
it('refuses production demonstration fleet seeding before making changes', function (): void {
    demonstrationTenant();
    app()->instance('env', 'production');
    try {
        expect(fn () => app(DemonstrationVehicleSeeder::class)->run())->toThrow(RuntimeException::class);
        expect(DB::table('vehicles')->count())->toBe(0);
    } finally {
        app()->instance('env', 'testing');
    }
});
it('screens all six types at their margins and rejects payload volume and dimension excesses', function (): void {
    [$tenant, $owner] = demonstrationTenant();
    $this->seed(VehicleTypeCatalogueSeeder::class);
    $screen = app(LoadCapacity::class);
    foreach (VehicleType::where('key', 'like', 'demo_%')->orderBy('id')->get() as $i => $type) {
        $vehicle = app(SaveVehicle::class)->execute(['name' => 'Test '.$i, 'registration_number' => 'CHECK'.$i, 'vehicle_type_id' => $type->id, 'status' => 'available', 'capacity_verified' => true, 'capacity_verification_note' => 'Illustrative test specification evidence.', 'capacity_margin_basis_points' => 1000], $owner->id);
        expect($screen->evaluate($vehicle, 1, 100, [1, 1, 1])['qualifies'])->toBeTrue();
        expect($screen->evaluate($vehicle, 1, (float) $vehicle->payload_kg * .9 + 1)['qualifies'])->toBeFalse();
        expect($screen->evaluate($vehicle, (float) $vehicle->usable_volume_m3 * .9 + .001, 100)['qualifies'])->toBeFalse();
        expect($screen->evaluate($vehicle, 1, 100, [8, 8, 8])['qualifies'])->toBeFalse();
        expect($screen->evaluate($vehicle, (float) $vehicle->usable_volume_m3 * .9, (float) $vehicle->payload_kg * .9)['qualifies'])->toBeTrue();
    }
    expect(app(ResourceRegistry::class)->eligible()->count())->toBe(6);
});
it('keeps uncertain inventory and unverified trucks out of automatic operational eligibility', function (): void {
    [$tenant, $owner] = demonstrationTenant();
    $this->seed(DemonstrationVehicleSeeder::class);
    app(TenantContext::class)->set($tenant);
    $vehicle = Vehicle::firstOrFail();
    $vehicle->status = 'available';
    $vehicle->save();
    app(ResourceRegistry::class)->vehicle($vehicle);
    expect(app(ResourceRegistry::class)->eligible()->exists())->toBeFalse();
    $inventory = app(InventoryCalculator::class)->calculate([['item_key' => 'crate', 'name' => 'Crate', 'quantity' => 1, 'length_cm' => '50', 'width_cm' => '50', 'height_cm' => '50', 'weight_kg' => null, 'fragile' => false, 'oversized' => false, 'stackable' => true, 'rotation_allowed' => true, 'instructions' => null]]);
    $assessment = app(AssessQuotationLoad::class)->evaluate($vehicle, $inventory);
    expect($assessment['status'])->toBe('requires_review')->and(array_column($assessment['reasons'], 'code'))->toContain('unknown_measurements');
});
it('allows catalogue defaults and real per-truck overrides and verification through administration', function (): void {
    [$tenant, $owner] = demonstrationTenant();
    $this->seed(VehicleTypeCatalogueSeeder::class);
    $payload = ['name' => 'Actual truck', 'registration_number' => 'CA12345', 'vehicle_type_key' => 'demo_light_box_truck', 'status' => 'inactive', 'capacity_margin_basis_points' => 1000];
    $this->actingAs($owner)->post('/c/cape-town-movers/fleet', $payload)->assertSessionHasNoErrors()->assertRedirect();
    app(TenantContext::class)->set($tenant);
    $vehicle = Vehicle::firstOrFail();
    expect((float) $vehicle->cargo_length_m)->toBe(4.2)->and((float) $vehicle->usable_volume_m3)->toBe(15.523);
    $this->put('/c/cape-town-movers/fleet/'.$vehicle->public_id, [...$payload, 'cargo_length_m' => 4, 'usable_volume_m3' => 14, 'payload_kg' => 1800, 'capacity_verified' => true, 'capacity_verification_note' => 'Actual measured dimensions and payload plate checked.', 'status' => 'available'])->assertSessionHasNoErrors();
    app(TenantContext::class)->set($tenant);
    expect((float) $vehicle->fresh()->payload_kg)->toBe(1800.0)->and($vehicle->fresh()->capacity_verified)->toBeTrue()->and(app(ResourceRegistry::class)->eligible()->count())->toBe(1);
    $this->getJson('/c/cape-town-movers/lookups/vehicle-types')->assertJsonFragment(['value' => 'demo_light_box_truck', 'label' => 'Light Box Truck · demo specifications']);
});
it('requires an actual registration before a demonstration record can be verified', function (): void {
    [$tenant, $owner] = demonstrationTenant();
    $this->seed(DemonstrationVehicleSeeder::class);
    app(TenantContext::class)->set($tenant);
    $vehicle = Vehicle::firstOrFail();
    app(SaveVehicle::class)->execute([...$vehicle->getAttributes(), 'status' => 'available', 'capacity_verified' => true, 'capacity_verification_note' => 'Checked specifications.'], $owner->id, $vehicle);
})->throws(ValidationException::class);

it('requires a review for missing or estimated inventory weight on the new catalogue truck', function (?string $weight, string $certainty): void {
    [$tenant, $owner, $service] = quoteFixture();
    $this->seed(VehicleTypeCatalogueSeeder::class);
    Vehicle::query()->update(['status' => 'inactive']);
    app(SaveVehicle::class)->execute(['name' => 'Test light box', 'registration_number' => 'REALBOX1', 'vehicle_type_id' => VehicleType::where('key', 'demo_light_box_truck')->value('id'), 'status' => 'available', 'capacity_verified' => true, 'capacity_verification_note' => 'Illustrative test specification evidence.', 'capacity_margin_basis_points' => 1000], $owner->id);
    $input = quoteInput($service);
    $input['inventory'][0]['weight_kg'] = $weight;
    $input['inventory'][0]['weight_certainty'] = $certainty;
    $quote = app(CreateQuoteDraft::class)->execute($input, (string) Str::uuid());
    $quote = app(CalculateQuote::class)->execute($quote, 0);
    expect($quote->status)->toBe('requires_review')->and(array_column($quote->currentRevision->review_reasons, 'code'))->toContain($weight === null ? 'unknown_measurements' : 'uncertain_weight');
})->with([[null, 'unknown'], ['5', 'estimated']]);

it('respects a deliberately disabled demonstration catalogue type', function (): void {
    demonstrationTenant();
    $this->seed(VehicleTypeCatalogueSeeder::class);
    DB::table('vehicle_types')->where('key', 'demo_light_box_truck')->update(['active' => false]);
    $this->seed(DemonstrationVehicleSeeder::class);
    expect(DB::table('vehicles')->count())->toBe(0);
});
