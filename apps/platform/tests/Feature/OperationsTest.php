<?php

use App\Domain\Fleet\Depot;
use App\Domain\Fleet\SaveVehicle;
use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleType;
use App\Domain\Identity\Membership;
use App\Domain\Identity\Role;
use App\Domain\Resources\DriverVehicleClass;
use App\Domain\Resources\Resource;
use App\Domain\Resources\ResourceRegistry;
use App\Domain\Resources\StaffMember;
use App\Domain\Services\ServiceType;
use App\Domain\Services\TenantService;
use App\Domain\Services\TenantServiceCatalogue;
use App\Domain\Services\UpdateServiceType;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function opTenant(string $slug = 'operations'): array
{
    $tenant = Tenant::create(['name' => $slug, 'slug' => $slug]);
    $user = User::factory()->create();
    Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => Role::Owner]);
    app(TenantContext::class)->set($tenant);
    CompanySettings::create(['display_name' => $slug, 'contact_email' => 'ops@example.test']);

    return [$tenant, $user];
}
function opDepotPayload(array $changes = []): array
{
    return [...['name' => 'Main depot', 'address_line' => 'Example address', 'city' => 'Cape Town', 'country_code' => 'ZA', 'latitude' => -33.92, 'longitude' => 18.42, 'coordinates_verified' => false, 'is_primary' => false, 'active' => true, 'timezone' => 'Africa/Johannesburg'], ...$changes];
}
function opVehiclePayload(array $changes = []): array
{
    return [...['name' => 'Truck One', 'registration_number' => 'CA 123-456', 'vehicle_type_key' => 'sample_van', 'status' => 'available', 'capacity_margin_basis_points' => 1000], ...$changes];
}
function opStaffPayload(array $changes = []): array
{
    return [...['full_name' => 'Example Mover', 'role' => 'mover', 'engagement_status' => 'employed', 'active' => true, 'eligible_vehicle_classes' => []], ...$changes];
}
beforeEach(function (): void {
    $this->withoutVite();
});
it('adds company preferences with validated tenant service references', function (): void {
    [$tenant,$user] = opTenant();
    $service = TenantService::create(['code' => 'household', 'name' => 'Household', 'enabled' => true]);
    $this->actingAs($user)->put('/c/operations/settings', ['display_name' => 'Trading', 'contact_email' => 'ops@example.test', 'payment_terms' => 'deposit', 'deposit_basis_points' => 2500, 'registered_name' => 'Registered Name', 'timezone' => 'Europe/London', 'measurement_units' => 'metric', 'booking_notice_minutes' => 120, 'job_buffer_minutes' => 30, 'default_service_public_id' => $service->public_id, 'booking_enabled' => true, 'public_contact_visible' => false])->assertSessionHasNoErrors()->assertRedirect();
    $this->assertDatabaseHas('company_settings', ['tenant_id' => $tenant->id, 'registered_name' => 'Registered Name', 'default_service_id' => $service->id, 'timezone' => 'Europe/London']);
});
it('rejects a default service owned by another tenant', function (): void {
    [$tenant,$user] = opTenant();
    opTenant('other');
    $service = TenantService::create(['code' => 'household', 'name' => 'Other', 'enabled' => true]);
    $this->actingAs($user)->put('/c/operations/settings', ['display_name' => 'Trading', 'contact_email' => 'ops@example.test', 'payment_terms' => 'deposit', 'deposit_basis_points' => 2500, 'default_service_public_id' => $service->public_id])->assertSessionHasErrors('default_service_public_id');
});
it('allows administrators to create depots without trusting tenant payloads', function (): void {
    [$tenant,$user] = opTenant();
    $this->actingAs($user)->post('/c/operations/depots', [...opDepotPayload(), 'tenant_id' => 99999])->assertSessionHasNoErrors()->assertRedirect();
    $this->assertDatabaseHas('depots', ['tenant_id' => $tenant->id, 'name' => 'Main depot']);
});
it('keeps exactly one primary depot', function (): void {
    [$tenant,$user] = opTenant();
    $this->actingAs($user)->post('/c/operations/depots', opDepotPayload(['is_primary' => true]))->assertSessionHasNoErrors();
    $this->post('/c/operations/depots', opDepotPayload(['name' => 'Second', 'is_primary' => true]))->assertSessionHasNoErrors();
    expect(DB::table('depots')->where('is_primary', true)->count())->toBe(1);
});
it('creates a vehicle and its canonical resource with catalogue defaults', function (): void {
    [$tenant,$user] = opTenant();
    $this->actingAs($user)->post('/c/operations/fleet', opVehiclePayload())->assertSessionHasNoErrors()->assertRedirect();
    $vehicle = DB::table('vehicles')->first();
    expect($vehicle->registration_number)->toBe('CA123456');
    $this->assertDatabaseHas('resources', ['tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'kind' => 'vehicle', 'active' => false]);
    expect($vehicle->uuid)->not->toBeNull();
});
it('rejects invalid vehicle dimensions and payload', function (string $field, mixed $value): void {
    [$tenant,$user] = opTenant();
    $this->actingAs($user)->post('/c/operations/fleet', opVehiclePayload([$field => $value]))->assertSessionHasErrors($field);
})->with([['payload_kg', -1], ['cargo_length_m', 0], ['capacity_margin_basis_points', 10001], ['manufacture_year', 1800]]);
it('enforces normalized registration uniqueness even after archival', function (): void {
    [$tenant,$user] = opTenant();
    $vehicle = app(SaveVehicle::class)->execute(['name' => 'Existing', 'registration_number' => 'CA123456', 'vehicle_type_id' => VehicleType::firstOrFail()->id, 'status' => 'available', 'capacity_margin_basis_points' => 1000], $user->id);
    $this->actingAs($user)->delete('/c/operations/fleet/'.$vehicle->public_id)->assertRedirect();
    $this->post('/c/operations/fleet', opVehiclePayload())->assertSessionHasErrors('registration_number');
    $this->assertDatabaseHas('resources', ['vehicle_id' => $vehicle->id, 'active' => false]);
});
it('prevents a cross company depot reference in HTTP and database writes', function (): void {
    [$tenant,$user] = opTenant();
    opTenant('other');
    $depot = Depot::factory()->create();
    app(TenantContext::class)->set($tenant);
    $this->actingAs($user)->post('/c/operations/fleet', opVehiclePayload(['depot_public_id' => $depot->public_id]))->assertSessionHasErrors('depot_public_id');
    app(TenantContext::class)->set($tenant);
    expect(fn () => Vehicle::factory()->create(['depot_id' => $depot->id]))->toThrow(QueryException::class);
});
it('blocks deactivation of a referenced depot', function (): void {
    [$tenant,$user] = opTenant();
    $depot = Depot::factory()->create();
    Vehicle::factory()->create(['depot_id' => $depot->id]);
    $this->actingAs($user)->put('/c/operations/depots/'.$depot->public_id, opDepotPayload(['active' => false]))->assertSessionHasErrors('active');
});
it('keeps operational staff independent from user accounts and privileges', function (): void {
    [$tenant,$user] = opTenant();
    $users = User::count();
    $members = Membership::count();
    $this->actingAs($user)->post('/c/operations/staff', opStaffPayload())->assertSessionHasNoErrors();
    $staff = DB::table('staff_members')->first();
    expect($staff->user_id)->toBeNull()->and(User::count())->toBe($users)->and(Membership::count())->toBe($members);
    $this->assertDatabaseHas('resources', ['staff_id' => $staff->id, 'kind' => 'mover']);
});
it('requires valid driver qualifications', function (): void {
    [$tenant,$user] = opTenant();
    $this->actingAs($user)->post('/c/operations/staff', opStaffPayload(['role' => 'driver']))->assertSessionHasErrors(['licence_category', 'licence_expires_on', 'eligible_vehicle_classes']);
});
it('records a qualified driver without granting a login', function (): void {
    [$tenant,$user] = opTenant();
    $this->actingAs($user)->post('/c/operations/staff', opStaffPayload(['role' => 'driver', 'licence_category' => 'Code 10', 'licence_expires_on' => now()->addYear()->toDateString(), 'eligible_vehicle_classes' => ['light', 'medium']]))->assertSessionHasNoErrors();
    $this->assertDatabaseCount('driver_vehicle_classes', 2);
    $this->assertDatabaseHas('resources', ['kind' => 'driver', 'active' => true]);
});
it('conceals private staff data from viewers and drivers', function (Role $role): void {
    [$tenant,$user] = opTenant();
    Membership::where('user_id', $user->id)->update(['role' => $role]);
    $this->actingAs($user)->get('/c/operations/staff')->assertForbidden();
    $this->post('/c/operations/staff', opStaffPayload())->assertForbidden();
})->with([Role::Viewer, Role::Driver, Role::Dispatcher]);
it('does not allow cross tenant staff or vehicle edits', function (string $module): void {
    [$tenant,$user] = opTenant();
    opTenant('other');
    $row = $module === 'fleet' ? Vehicle::factory()->create() : StaffMember::factory()->create();
    $this->actingAs($user)->get('/c/operations/'.$module.'/'.$row->public_id.'/edit')->assertNotFound();
})->with(['fleet', 'staff']);
it('paginates operational lists without returning internal foreign keys', function (): void {
    [$tenant,$user] = opTenant();
    Vehicle::factory()->count(25)->create();
    $this->actingAs($user)->get('/c/operations/fleet')->assertInertia(fn (Assert $page) => $page->has('records.data', 20)->where('records.total', 25)->missing('records.data.0.tenant_id')->missing('records.data.0.vehicle_type_id'));
});
it('creates tenant service configurations without changing global definitions', function (): void {
    [$tenant,$user] = opTenant();
    $before = ServiceType::orderBy('id')->get()->toArray();
    $this->actingAs($user)->post('/c/operations/services', ['service_type_key' => 'office_relocation', 'name' => 'Our Office Moves', 'enabled' => true])->assertSessionHasNoErrors();
    expect(ServiceType::orderBy('id')->get()->toArray())->toBe($before);
    $this->assertDatabaseHas('tenant_services', ['tenant_id' => $tenant->id, 'name' => 'Our Office Moves', 'code' => 'office']);
    $this->post('/c/operations/services', ['service_type_key' => 'office_relocation', 'name' => 'Duplicate', 'enabled' => true])->assertSessionHasErrors('service_type_key');
    $this->put('/c/operations/service-types/1', ['name' => 'Attack'])->assertNotFound();
});
it('rejects invalid and self intersecting zone boundaries', function (): void {
    [$tenant,$user] = opTenant();
    $this->actingAs($user)->post('/c/operations/zones', ['name' => 'Bad', 'zone_type' => 'both', 'boundary' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 1], [0, 1], [1, 0], [0, 0]]]], 'active' => true, 'priority' => 1, 'manual_review' => false, 'example_only' => false])->assertSessionHasErrors('boundary');
});
it('enforces canonical resource uniqueness and entity mapping', function (): void {
    [$tenant,$user] = opTenant();
    $vehicle = Vehicle::factory()->create();
    Resource::create(['kind' => 'vehicle', 'vehicle_id' => $vehicle->id, 'active' => true]);
    expect(fn () => Resource::create(['kind' => 'vehicle', 'vehicle_id' => $vehicle->id, 'active' => true]))->toThrow(QueryException::class);
});
it('rejects resources without an operational entity', function (): void {
    opTenant();
    expect(fn () => Resource::create(['kind' => 'driver', 'active' => true]))->toThrow(QueryException::class);
});
it('stores supporting documents privately and restricts downloads', function (): void {
    Storage::fake('s3');
    [$tenant,$user] = opTenant();
    $staff = StaffMember::factory()->create();
    $this->actingAs($user)->post('/c/operations/media/document/'.$staff->public_id, ['file' => UploadedFile::fake()->create('qualification.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
    $doc = DB::table('staff_documents')->first();
    Storage::disk('s3')->assertExists($doc->path);
    Membership::where('user_id', $user->id)->update(['role' => Role::Viewer]);
    $this->get('/c/operations/media/document/'.$doc->public_id)->assertForbidden();
});

it('does not expose staff from another company through login links or lookups', function (): void {
    [$tenant,$user] = opTenant();
    [$other,$outsider] = opTenant('outside');
    app(TenantContext::class)->set($tenant);
    $this->actingAs($user)->post('/c/operations/staff', opStaffPayload(['user_public_id' => $outsider->public_id]))->assertSessionHasErrors('user_public_id');
    $this->getJson('/c/operations/lookups/members?selected='.$outsider->public_id)->assertJsonMissing(['value' => $outsider->public_id]);
});
it('preserves resource identity through edits and archival', function (): void {
    [$tenant,$user] = opTenant();
    $action = app(SaveVehicle::class);
    $data = ['name' => 'Original', 'registration_number' => 'STABLE1', 'vehicle_type_id' => VehicleType::firstOrFail()->id, 'status' => 'available', 'capacity_margin_basis_points' => 1000];
    $vehicle = $action->execute($data, $user->id);
    $resource = Resource::firstOrFail();
    $action->execute([...$data, 'name' => 'Renamed'], $user->id, $vehicle);
    $action->archive($vehicle, $user->id);
    expect(Resource::firstOrFail()->public_id)->toBe($resource->public_id)->and(app(ResourceRegistry::class)->eligible()->exists())->toBeFalse();
});
it('does not offer a driver whose licence expired in the company time zone', function (): void {
    [$tenant,$user] = opTenant();
    $this->travelTo(CarbonImmutable::parse('2026-11-16T22:30:00Z'));
    $staff = StaffMember::factory()->create(['role' => 'driver', 'licence_category' => 'Code 10', 'licence_expires_on' => '2026-11-16']);
    DriverVehicleClass::create(['staff_id' => $staff->id, 'vehicle_class' => 'light']);
    Resource::create(['kind' => 'driver', 'staff_id' => $staff->id, 'active' => true]);
    expect(app(ResourceRegistry::class)->eligible()->exists())->toBeFalse();
});
it('keeps tenant service caches separate and invalidates updated configuration', function (): void {
    [$tenant,$user] = opTenant();
    $service = TenantService::create(['code' => 'household', 'name' => 'Original', 'enabled' => true]);
    $catalogue = app(TenantServiceCatalogue::class);
    expect($catalogue->enabled()[0]['name'])->toBe('Original');
    app(UpdateServiceType::class)->execute($service->public_id, ['enabled' => true, 'name' => 'Updated'], $user->id);
    expect($catalogue->enabled()[0]['name'])->toBe('Updated');
    opTenant('uncached');
    expect($catalogue->enabled())->toBe([]);
});

it('allows read-only fleet details while denying writes', function (): void {
    [$tenant,$user] = opTenant();
    $vehicle = Vehicle::factory()->create();
    Membership::where('user_id', $user->id)->update(['role' => Role::Viewer]);
    $this->actingAs($user)->get('/c/operations/fleet/'.$vehicle->public_id.'/edit')->assertOk();
    $this->put('/c/operations/fleet/'.$vehicle->public_id, opVehiclePayload())->assertForbidden();
    $this->delete('/c/operations/fleet/'.$vehicle->public_id)->assertForbidden();
});

it('accepts private raster branding and rejects non-image branding files', function (): void {
    Storage::fake('s3');
    [$tenant,$user] = opTenant();
    $settings = CompanySettings::firstOrFail();
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=');
    $this->actingAs($user)->post('/c/operations/media/logo/'.$settings->public_id, ['file' => UploadedFile::fake()->createWithContent('logo.png', $png)])->assertSessionHasNoErrors()->assertRedirect();
    $path = DB::table('company_settings')->where('tenant_id', $tenant->id)->value('logo_path');
    expect($path)->toStartWith('tenants/'.$tenant->public_id.'/logo/');
    Storage::disk('s3')->assertExists($path);
    $this->post('/c/operations/media/logo/'.$settings->public_id, ['file' => UploadedFile::fake()->create('not-an-image.pdf', 10, 'application/pdf')])->assertSessionHasErrors('file');
});

it('keeps fleet summary counts scoped to the company and excludes archived vehicles', function (): void {
    [$tenant, $user] = opTenant();
    $this->actingAs($user)->post('/c/operations/fleet', opVehiclePayload())->assertSessionHasNoErrors();
    [$other, $otherUser] = opTenant('other-summary');
    $this->actingAs($otherUser)->post('/c/other-summary/fleet', opVehiclePayload())->assertSessionHasNoErrors();
    $this->actingAs($user)->get('/c/operations/fleet')->assertInertia(fn (Assert $page) => $page->component('operations/Index')->where('summary.0.value', 1)->where('summary.1.value', 1));
    app(TenantContext::class)->set($tenant);
    $vehicle = Vehicle::firstOrFail();
    $this->delete('/c/operations/fleet/'.$vehicle->public_id)->assertRedirect();
    $this->get('/c/operations/fleet')->assertInertia(fn (Assert $page) => $page->where('summary.0.value', 0)->where('summary.1.value', 0));
});
