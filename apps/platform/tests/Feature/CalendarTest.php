<?php

use App\Domain\Availability\LocalTimeResolver;
use App\Domain\Availability\OperatingCalendar;
use App\Domain\Availability\ResourceBlock;
use App\Domain\Availability\ScheduleOverride;
use App\Domain\Availability\WorkingSchedule;
use App\Domain\Fleet\SaveVehicle;
use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleType;
use App\Domain\Identity\Membership;
use App\Domain\Identity\Role;
use App\Domain\Resources\Resource;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;

function calendarTenant(): array
{
    $tenant = Tenant::create(['name' => 'Calendar', 'slug' => 'calendar']);
    $user = User::factory()->create();
    Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => Role::Owner]);
    app(TenantContext::class)->set($tenant);
    CompanySettings::create(['display_name' => 'Calendar', 'contact_email' => 'calendar@example.test']);

    return [$tenant, $user];
}
function weekly(?int $resource = null, array $changes = []): WorkingSchedule
{
    return WorkingSchedule::create([...['resource_id' => $resource, 'weekday' => 1, 'opens_at' => '08:00', 'closes_at' => '17:00', 'ends_next_day' => false, 'timezone' => 'Africa/Johannesburg', 'active' => true], ...$changes]);
}
it('returns recurring local hours as UTC windows', function (): void {
    calendarTenant();
    weekly();
    expect(app(OperatingCalendar::class)->windows(CarbonImmutable::parse('2026-11-16T00:00:00Z'), CarbonImmutable::parse('2026-11-17T00:00:00Z')))->toBe([['starts_at' => '2026-11-16T06:00:00+00:00', 'ends_at' => '2026-11-16T15:00:00+00:00']]);
});
it('honours closed date overrides', function (): void {
    calendarTenant();
    weekly();
    ScheduleOverride::create(['date' => '2026-11-16', 'closed' => true, 'timezone' => 'Africa/Johannesburg']);
    expect(app(OperatingCalendar::class)->windows(CarbonImmutable::parse('2026-11-16T00:00Z'), CarbonImmutable::parse('2026-11-17T00:00Z')))->toBe([]);
});
it('clips overnight spillover on closed dates', function (): void {
    calendarTenant();
    weekly(null, ['weekday' => 0, 'opens_at' => '22:00', 'closes_at' => '06:00', 'ends_next_day' => true]);
    ScheduleOverride::create(['date' => '2026-11-16', 'closed' => true, 'timezone' => 'Africa/Johannesburg']);
    expect(app(OperatingCalendar::class)->windows(CarbonImmutable::parse('2026-11-16T00:00Z'), CarbonImmutable::parse('2026-11-17T00:00Z')))->toBe([]);
});
it('intersects company and resource hours and subtracts maintenance blocks', function (): void {
    [$tenant,$user] = calendarTenant();
    $vehicle = app(SaveVehicle::class)->execute(['capacity_verified' => true, 'capacity_verification_note' => 'Test vehicle specification evidence.', 'name' => 'Truck', 'registration_number' => 'CAL123', 'vehicle_type_id' => VehicleType::firstOrFail()->id, 'status' => 'available', 'capacity_margin_basis_points' => 1000], $user->id);
    $resource = Resource::firstOrFail();
    weekly();
    weekly($resource->id, ['opens_at' => '09:00', 'closes_at' => '16:00']);
    ResourceBlock::create(['resource_id' => $resource->id, 'starts_at' => '2026-11-16T10:00:00Z', 'ends_at' => '2026-11-16T11:00:00Z', 'reason' => 'maintenance']);
    $windows = app(OperatingCalendar::class)->windows(CarbonImmutable::parse('2026-11-16T00:00Z'), CarbonImmutable::parse('2026-11-17T00:00Z'), $resource);
    expect($windows)->toHaveCount(2)->and($windows[0]['starts_at'])->toBe('2026-11-16T07:00:00+00:00')->and($windows[1]['ends_at'])->toBe('2026-11-16T14:00:00+00:00');
});
it('resolves DST gaps and repeated hours deterministically', function (): void {
    $resolver = new LocalTimeResolver;
    expect($resolver->resolve('2026-03-29', '01:30', 'Europe/London'))->toBeNull();
    $opening = $resolver->resolve('2026-10-25', '01:30', 'Europe/London');
    $closing = $resolver->resolve('2026-10-25', '01:30', 'Europe/London', true);
    expect($closing->timestamp - $opening->timestamp)->toBe(3600);
});
it('rejects invalid local windows but accepts overnight configuration', function (): void {
    [$tenant,$user] = calendarTenant();
    $payload = ['weekday' => 1, 'opens_at' => '22:00', 'closes_at' => '06:00', 'ends_next_day' => false, 'timezone' => 'Africa/Johannesburg', 'active' => true];
    $this->actingAs($user)->post('/c/calendar/calendar/weekly', $payload)->assertSessionHasErrors('closes_at');
    $this->post('/c/calendar/calendar/weekly', [...$payload, 'ends_next_day' => true])->assertSessionHasNoErrors();
});
it('requires ordered UTC blackout intervals', function (): void {
    [$tenant,$user] = calendarTenant();
    $vehicle = Vehicle::factory()->create();
    $resource = Resource::create(['kind' => 'vehicle', 'vehicle_id' => $vehicle->id]);
    $this->actingAs($user)->post('/c/calendar/calendar/blocks', ['resource_public_id' => $resource->public_id, 'starts_at' => '2026-11-16T12:00:00+00:00', 'ends_at' => '2026-11-16T11:00:00+00:00', 'reason' => 'maintenance'])->assertSessionHasErrors('ends_at');
});
