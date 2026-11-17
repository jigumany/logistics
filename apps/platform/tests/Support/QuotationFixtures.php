<?php

use App\Domain\Fleet\SaveVehicle;
use App\Domain\Fleet\VehicleType;
use App\Domain\Geography\CoverageLookup;
use App\Domain\Identity\Membership;
use App\Domain\Identity\Role;
use App\Domain\Pricing\ManagePriceBook;
use App\Domain\Pricing\PricingRules;
use App\Domain\Quotes\CalculateQuote;
use App\Domain\Quotes\CreateQuoteDraft;
use App\Domain\Quotes\QuotationPolicy;
use App\Domain\Quotes\Quote;
use App\Domain\Routing\RoutingProvider;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

function quoteFixture(bool $pricing = true): array
{
    $tenant = Tenant::create(['name' => 'Quotation Company', 'slug' => 'quotation-company']);
    $user = User::factory()->create();
    Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => Role::Owner]);
    app(TenantContext::class)->set($tenant);
    CompanySettings::create(['display_name' => 'Quotation Company', 'contact_email' => 'quotes@example.test']);
    $service = TenantService::create(['code' => 'household', 'name' => 'Household moving', 'enabled' => true, 'active' => true]);
    QuotationPolicy::create(['service_id' => $service->id, 'automatic_estimates' => true, 'allow_customer_measurements' => true, 'allow_catalogue_estimates' => true, 'terms' => 'Estimated service only. Acceptance does not confirm a booking.', 'exclusions' => 'Packing is excluded unless itemised.']);
    app(SaveVehicle::class)->execute(['name' => 'Screened van', 'registration_number' => 'QUOTE001', 'vehicle_type_id' => VehicleType::firstOrFail()->id, 'status' => 'available', 'capacity_margin_basis_points' => 1000], $user->id);
    if ($pricing) {
        $book = app(ManagePriceBook::class)->save(['name' => 'Service tariff', 'service_public_id' => $service->public_id, 'currency' => 'ZAR', 'effective_from' => now()->subDay()->toIso8601String(), 'effective_until' => null, 'distance_basis' => 'customer', 'configuration' => ['schema_version' => 1, 'included_metrics' => array_values(array_diff(PricingRules::METRICS, ['base', 'chargeable_km'])), 'crew_size' => 2, 'loading_minutes_per_complexity' => 2, 'unloading_minutes_per_complexity' => 1, 'carry_included_metres' => 20, 'business_opens' => '08:00', 'business_closes' => '17:00', 'holiday_dates' => []], 'rules' => [['code' => 'base', 'label' => 'Base transport', 'stage' => 'charge', 'kind' => 'fixed', 'metric' => 'base', 'rate_micros' => 100000000, 'position' => 0], ['code' => 'distance', 'label' => 'Customer journey distance', 'stage' => 'charge', 'kind' => 'rate', 'metric' => 'chargeable_km', 'rate_micros' => 5000000, 'position' => 1]]], $user->id);
        app(ManagePriceBook::class)->publish($book, $user->id);
    }
    app()->instance(CoverageLookup::class, Mockery::mock(CoverageLookup::class)->shouldReceive('check')->andReturn(['covered' => true, 'zone' => ['public_id' => 'covered-zone', 'manual_review' => false, 'restrictions' => null], 'coordinate_verification_required' => true])->getMock());
    app()->instance(RoutingProvider::class, Mockery::mock(RoutingProvider::class)->shouldReceive('calculate')->andReturn(['provider' => 'fake', 'status' => 'available', 'failure_code' => null, 'customer_metres' => 20000, 'depot_metres' => 10000, 'return_metres' => 30000, 'duration_seconds' => 1800, 'tolls_minor' => 0, 'retain_until' => now()->addDays(7)->toIso8601String()])->getMock());

    return [$tenant, $user, $service];
}

function quoteInput(TenantService $service, array $changes = []): array
{
    $stops = [];
    foreach ([[-33.92, 18.42], [-33.95, 18.5]] as $index => [$latitude,$longitude]) {
        $place = 'test-place-'.$index;
        $stops[] = ['address_line' => ($index + 1).' Customer-entered street', 'city' => 'Cape Town', 'country_code' => 'ZA', 'latitude' => $latitude, 'longitude' => $longitude, 'provider_place_id' => $place, 'verification_proof' => Crypt::encryptString(json_encode(['address_line' => ($index + 1).' Customer-entered street', 'city' => 'Cape Town', 'country_code' => 'ZA', 'place_id' => $place, 'latitude' => $latitude, 'longitude' => $longitude, 'expires_at' => now()->addMinutes(30)->timestamp]))];
    }

    return [...['service_public_id' => $service->public_id, 'preferred_date' => now()->addDays(7)->toDateString(), 'preferred_time' => '10:00', 'stops' => $stops, 'inventory' => [['item_key' => '00000000-0000-4000-8000-000000000001', 'name' => 'Measured crate', 'quantity' => 2, 'length_cm' => '100', 'width_cm' => '50', 'height_cm' => '40', 'weight_kg' => '12.5', 'fragile' => false, 'oversized' => false, 'stackable' => true, 'rotation_allowed' => true]], 'details' => ['pickup_stairs' => 0, 'delivery_stairs' => 0, 'pickup_lift' => false, 'delivery_lift' => false, 'pickup_carry_metres' => 0, 'delivery_carry_metres' => 0, 'parking_limited' => false, 'difficult_access' => false, 'special_equipment' => false, 'packing' => false, 'waiting_minutes' => 0]], ...$changes];
}
function quoteContact(): array
{
    return ['name' => 'Guest Customer', 'email' => 'guest@example.test', 'phone' => '+27215550000', 'consent' => true, 'communication' => 'email', 'marketing_consent' => false];
}
function calculatedQuote(TenantService $service): Quote
{
    $quote = app(CreateQuoteDraft::class)->execute(quoteInput($service), (string) Str::uuid());

    return app(CalculateQuote::class)->execute($quote, 0);
}
