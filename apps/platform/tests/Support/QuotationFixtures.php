<?php

use App\Domain\Fleet\SaveVehicle;
use App\Domain\Fleet\VehicleType;
use App\Domain\Geography\CoverageLookup;
use App\Domain\Geography\ServiceZone;
use App\Domain\Identity\Membership;
use App\Domain\Identity\Role;
use App\Domain\Pricing\ManagePriceBook;
use App\Domain\Pricing\PricingRules;
use App\Domain\Quotes\CalculateQuote;
use App\Domain\Quotes\CreateQuoteDraft;
use App\Domain\Quotes\QuotationPolicy;
use App\Domain\Quotes\QuotationTerms;
use App\Domain\Quotes\Quote;
use App\Domain\Routing\RoutingProvider;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

function quoteFixture(bool $pricing = true, array $commercialChanges = []): array
{
    $tenant = Tenant::create(['name' => 'Quotation Company', 'slug' => 'quotation-company']);
    $user = User::factory()->create();
    Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => Role::Owner]);
    app(TenantContext::class)->set($tenant);
    CompanySettings::create(['display_name' => 'Quotation Company', 'contact_email' => 'quotes@example.test']);
    $service = TenantService::create(['code' => 'household', 'name' => 'Household moving', 'enabled' => true, 'active' => true]);
    ServiceZone::create(['name' => 'Fixture verified coverage', 'zone_type' => 'both', 'active' => true, 'example_only' => false, 'boundary' => ['type' => 'Polygon', 'coordinates' => [[[18.3, -34.1], [18.7, -34.1], [18.7, -33.7], [18.3, -33.7], [18.3, -34.1]]]]]);
    QuotationPolicy::create(['terms_version' => (string) Str::ulid(), 'commercial_terms' => [...array_fill_keys(QuotationTerms::SECTIONS, 'Illustrative test policy; not approved business terms.'), 'status' => 'reviewed', 'insurance_status' => 'reviewed'], 'service_id' => $service->id, 'automatic_estimates' => true, 'allow_customer_measurements' => true, 'allow_catalogue_estimates' => true, 'terms' => 'Estimated service only. Acceptance does not confirm a booking.', 'exclusions' => 'Packing is excluded unless itemised.']);
    app(SaveVehicle::class)->execute(['capacity_verified' => true, 'capacity_verification_note' => 'Illustrative test specifications confirmed for this scenario.', 'name' => 'Screened van', 'registration_number' => 'QUOTE001', 'vehicle_type_id' => VehicleType::firstOrFail()->id, 'status' => 'available', 'capacity_margin_basis_points' => 1000], $user->id);
    if ($pricing) {
        $book = app(ManagePriceBook::class)->save(['name' => 'Service tariff', 'service_public_id' => $service->public_id, 'currency' => 'ZAR', 'effective_from' => now()->subDay()->toIso8601String(), 'effective_until' => null, 'distance_basis' => 'customer', 'configuration' => ['schema_version' => 2, 'commercial' => commercialFixture($commercialChanges), 'included_metrics' => array_values(array_diff(PricingRules::ALL_METRICS, ['base', 'chargeable_km'])), 'crew_size' => 2, 'loading_minutes_per_complexity' => 2, 'unloading_minutes_per_complexity' => 1, 'carry_included_metres' => 20, 'business_opens' => '08:00', 'business_closes' => '17:00', 'holiday_dates' => []], 'rules' => [['code' => 'base', 'label' => 'Base transport', 'stage' => 'charge', 'kind' => 'fixed', 'metric' => 'base', 'rate_micros' => 100000000, 'position' => 0], ['code' => 'distance', 'label' => 'Customer journey distance', 'stage' => 'charge', 'kind' => 'rate', 'metric' => 'chargeable_km', 'rate_micros' => 5000000, 'position' => 1], ['code' => 'minimum', 'label' => 'Illustrative minimum', 'stage' => 'minimum', 'kind' => 'fixed', 'metric' => null, 'rate_micros' => 0, 'position' => 0]]], $user->id);
        app(ManagePriceBook::class)->publish($book, $user->id);
    }
    app()->instance(CoverageLookup::class, Mockery::mock(CoverageLookup::class)->shouldReceive('check')->andReturn(['covered' => true, 'zone' => ['public_id' => 'covered-zone', 'manual_review' => false, 'restrictions' => null], 'coordinate_verification_required' => true])->getMock());
    app()->instance(RoutingProvider::class, Mockery::mock(RoutingProvider::class)->shouldReceive('calculate')->andReturn(['provider' => 'fake', 'status' => 'available', 'failure_code' => null, 'customer_metres' => 20000, 'depot_metres' => 10000, 'return_metres' => 30000, 'depot_duration_seconds' => 900, 'return_duration_seconds' => 2700, 'duration_seconds' => 1800, 'tolls_minor' => 0, 'retain_until' => now()->addDays(7)->toIso8601String()])->getMock());

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

function commercialFixture(array $changes = []): array
{
    return [...['included_km' => 0, 'minimum_chargeable_km' => 0, 'minimum_labour_hours' => 0, 'standard_labour_hours' => 8, 'labour_model' => 'combined', 'labour_distance_basis' => 'customer', 'bill_waiting_in_labour' => false, 'toll_policy' => 'included', 'tax_reviewed' => true, 'tax_mode' => 'not_registered', 'tax_registration' => '', 'tax_basis_points' => 0, 'costs_reviewed' => true, 'cost_distance_basis' => 'customer', 'target_margin_bps' => 3000, 'minimum_margin_bps' => 1000, 'override_roles' => ['owner'], 'fuel_rate_micros' => 100000, 'vehicle_rate_micros' => 100000, 'driver_rate_micros' => 1000000, 'mover_rate_micros' => 1000000, 'driver_overtime_rate_micros' => 0, 'mover_overtime_rate_micros' => 0, 'fixed_cost_minor' => 0, 'overhead_minor' => 0, 'estimated_tolls_minor' => 0], ...$changes];
}
