<?php

use App\Domain\Availability\ResourceBlock;
use App\Domain\Availability\SaveCalendarConfiguration;
use App\Domain\Availability\ScheduleOverride;
use App\Domain\Availability\WorkingSchedule;
use App\Domain\Bookings\Booking;
use App\Domain\Bookings\BookingConflict;
use App\Domain\Bookings\BookingHold;
use App\Domain\Bookings\BookingPaymentVerifier;
use App\Domain\Bookings\BookingPolicy;
use App\Domain\Bookings\CancelBooking;
use App\Domain\Bookings\ConfirmBooking;
use App\Domain\Bookings\CreateBookingHold;
use App\Domain\Bookings\GuestBookingLinks;
use App\Domain\Bookings\ReconcileBookingPayment;
use App\Domain\Bookings\ResourceAllocation;
use App\Domain\Bookings\VerifiedBookingPayment;
use App\Domain\Fleet\SaveVehicle;
use App\Domain\Fleet\Vehicle;
use App\Domain\Identity\Membership;
use App\Domain\Identity\Role;
use App\Domain\Resources\DriverVehicleClass;
use App\Domain\Resources\Resource;
use App\Domain\Resources\SaveStaffMember;
use App\Domain\Resources\StaffMember;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/BookingFixtures.php';
it('rejects unavailable vehicle driver crew maintenance closure and invalid capacity', function (string $case) {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    $vehicle = Resource::where('kind', 'vehicle')->firstOrFail();
    $driver = Resource::where('kind', 'driver')->firstOrFail();
    $mover = Resource::where('kind', 'mover')->firstOrFail();
    match ($case) {
        'vehicle' => Vehicle::query()->update(['status' => 'inactive']),
        'unverified' => Vehicle::query()->update(['capacity_verified' => false, 'capacity_verified_at' => null]),
        'demo' => DB::table('vehicles')->where('tenant_id', $tenant->id)->update(['demonstration_key' => 'demonstration_origin']),
        'payload' => Vehicle::query()->update(['payload_kg' => 1]),
        'driver' => StaffMember::where('role', 'driver')->update(['active' => false]),
        'qualification' => DriverVehicleClass::query()->delete(),
        'licence' => StaffMember::where('role', 'driver')->update(['licence_expires_on' => $start->subDay()->toDateString()]),
        'crew' => StaffMember::where('role', 'mover')->update(['active' => false]),
        'maintenance' => ResourceBlock::create(['resource_id' => $vehicle->id, 'starts_at' => $start->subHour(), 'ends_at' => $start->addHours(3), 'reason' => 'maintenance']),
        'leave' => ResourceBlock::create(['resource_id' => $driver->id, 'starts_at' => $start->subHour(), 'ends_at' => $start->addHours(3), 'reason' => 'leave']),
        'closure' => ScheduleOverride::create(['date' => $start->setTimezone('Africa/Johannesburg')->toDateString(), 'closed' => true, 'timezone' => 'Africa/Johannesburg']),
        'notice' => CompanySettings::query()->update(['booking_notice_minutes' => 30000]),
        'service_hours' => $service->update(['booking_rules' => ['opens_at' => '14:00', 'closes_at' => '18:00']]),
        'multi_vehicle' => $service->update(['booking_rules' => ['vehicles' => 2]]),
    };
    expect(fn () => app(CreateBookingHold::class)->execute($quotes[0], $start, (string) Str::uuid()))->toThrow(BookingConflict::class);
    expect(ResourceAllocation::count())->toBe(0);
})->with(['vehicle', 'unverified', 'demo', 'payload', 'driver', 'qualification', 'licence', 'crew', 'maintenance', 'leave', 'closure', 'notice', 'service_hours', 'multi_vehicle']);
it('honours a date opening override and overnight resource hours', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    WorkingSchedule::query()->update(['opens_at' => '20:00', 'closes_at' => '06:00', 'ends_next_day' => true]);
    $late = $start->setTimezone('Africa/Johannesburg')->setTime(23, 30)->utc();
    expect(app(CreateBookingHold::class)->execute($quotes[0], $late, (string) Str::uuid())->status)->toBe('active');
});
it('does not let calendar edits invalidate an existing complete reservation', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    app(CreateBookingHold::class)->execute($quotes[0], $start, (string) Str::uuid());
    expect(fn () => app(SaveCalendarConfiguration::class)->execute(ScheduleOverride::class, ['date' => $start->setTimezone('Africa/Johannesburg')->toDateString(), 'closed' => true, 'timezone' => 'Africa/Johannesburg'], $owner->id))->toThrow(ValidationException::class);
    expect(ScheduleOverride::count())->toBe(0);
});
it('requires manual confirmation when configured and rejects stale cancellation and hold reuse', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    CompanySettings::firstOrFail()->update(['booking_policy' => [...BookingPolicy::DEFAULTS, 'reviewed' => true, 'manual_confirmation' => true]]);
    $hold = app(CreateBookingHold::class)->execute($quotes[0], $start, (string) Str::uuid());
    $book = app(ConfirmBooking::class)->execute($hold, (string) Str::uuid());
    expect($book->status)->toBe('pending_confirmation');
    $book = app(ConfirmBooking::class)->execute($hold, (string) Str::uuid(), $owner->id);
    expect($book->status)->toBe('confirmed');
    expect(fn () => app(ConfirmBooking::class)->execute($hold, (string) Str::uuid()))->toThrow(BookingConflict::class);
    expect(fn () => app(CancelBooking::class)->execute($book, (string) Str::uuid(), 'Stale cancellation request.', 0, $owner->id))->toThrow(BookingConflict::class);
    expect($book->fresh()->status)->toBe('confirmed');
});
it('reconciles verified payments once and sends late payment to manual recovery without allocating twice', function (bool $late) {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    CompanySettings::query()->update(['payment_terms' => 'full']);
    $hold = app(CreateBookingHold::class)->execute($quotes[0], $start, (string) Str::uuid());
    $book = app(ConfirmBooking::class)->execute($hold, (string) Str::uuid());
    if ($late) {
        $this->travel(16)->minutes();
        app(CreateBookingHold::class)->execute($quotes[1], $start, (string) Str::uuid());
    }
    app()->instance(BookingPaymentVerifier::class, new class implements BookingPaymentVerifier
    {
        public function evidence(Booking $booking): ?VerifiedBookingPayment
        {
            return new VerifiedBookingPayment('verified-test-payment', 'ZAR', 20000);
        }
    });
    $key = (string) Str::uuid();
    $result = app(ReconcileBookingPayment::class)->execute($book, $key);
    expect($result->status)->toBe($late ? 'expired' : 'confirmed')->and($result->financial_state)->toBe($late ? 'manual_resolution' : 'verified');
    expect(app(ReconcileBookingPayment::class)->execute($book, $key)->id)->toBe($result->id);
    expect(ResourceAllocation::where('status', 'confirmed')->count())->toBe($late ? 0 : 3);
})->with([false, true]);
it('keeps guest scheduling scoped to its signed accepted quote and hides operational details', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    $links = app(GuestBookingLinks::class)->forQuote($quotes[0]);
    $this->getJson($links['view'])->assertOk()->assertJsonPath('booking', null);
    $this->postJson($links['hold'], ['starts_at' => $start->toIso8601String(), 'idempotency_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('hold.status', 'active');
    app(TenantContext::class)->set($tenant);
    $hold = BookingHold::firstOrFail();
    $other = app(GuestBookingLinks::class)->forQuote($quotes[1]);
    $this->postJson($other['confirm'], ['hold_public_id' => $hold->public_id, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
    $result = $this->postJson($links['confirm'], ['hold_public_id' => $hold->public_id, 'idempotency_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('booking.status', 'confirmed');
    expect($result->getContent())->not->toContain('internal_costs', 'licence_category', 'registration_number', 'Booking driver', 'payment_reference');
    $this->getJson(preg_replace('/signature=[^&]+/', 'signature=invalid', $links['view']))->assertForbidden();
    $viewer = User::factory()->create();
    Membership::create(['tenant_id' => $tenant->id, 'user_id' => $viewer->id, 'role' => Role::Viewer]);
    $this->actingAs($viewer)->get('/c/'.$tenant->slug.'/bookings')->assertForbidden();
    $this->actingAs($owner)->get('/c/'.$tenant->slug.'/bookings')->assertOk();
    $this->get('/c/'.$tenant->slug.'/planner')->assertOk();
});
it('rejects cross-tenant allocation foreign keys and booking access', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    $hold = app(CreateBookingHold::class)->execute($quotes[0], $start, (string) Str::uuid());
    $book = app(ConfirmBooking::class)->execute($hold, (string) Str::uuid());
    $other = Tenant::create(['name' => 'Other booking company', 'slug' => 'other-booking']);
    Membership::create(['tenant_id' => $other->id, 'user_id' => $owner->id, 'role' => Role::Owner]);
    $this->actingAs($owner)->get('/c/other-booking/bookings/'.$book->public_id)->assertNotFound();
    app(TenantContext::class)->set($tenant);
    expect(fn () => DB::transaction(fn () => DB::table('resource_allocations')->insert(['tenant_id' => $other->id, 'public_id' => (string) Str::ulid(), 'hold_id' => $hold->id, 'resource_id' => Resource::firstOrFail()->id, 'booking_id' => $book->id, 'status' => 'held', 'starts_at' => $start, 'ends_at' => $start->addHour(), 'created_at' => now(), 'updated_at' => now()])))->toThrow(QueryException::class);
});
it('requires allocations to be released before a truck or driver can be archived', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    app(CreateBookingHold::class)->execute($quotes[0], $start, (string) Str::uuid());
    expect(fn () => app(SaveVehicle::class)->archive(Vehicle::firstOrFail(), $owner->id))->toThrow(ValidationException::class);
    expect(fn () => app(SaveStaffMember::class)->archive(StaffMember::where('role', 'driver')->firstOrFail(), $owner->id))->toThrow(ValidationException::class);
});
