<?php

use App\Domain\Availability\WorkingSchedule;
use App\Domain\Bookings\Booking;
use App\Domain\Bookings\BookingConflict;
use App\Domain\Bookings\BookingHold;
use App\Domain\Bookings\BookingHoldResource;
use App\Domain\Bookings\ConfirmBooking;
use App\Domain\Bookings\CreateBookingHold;
use App\Domain\Bookings\RescheduleBooking;
use App\Domain\Bookings\ResourceAllocation;
use App\Domain\Fleet\SaveVehicle;
use App\Domain\Fleet\Vehicle;
use App\Domain\Quotes\AcceptQuote;
use App\Domain\Quotes\CalculateQuote;
use App\Domain\Quotes\CreateQuoteDraft;
use App\Domain\Quotes\Quote;
use App\Domain\Quotes\SubmitQuote;
use App\Domain\Resources\Resource;
use App\Domain\Resources\SaveStaffMember;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'logistics_booking_race_test') {
    throw new RuntimeException('Concurrency workers require the dedicated booking race test database.');
}
$mode = $argv[1];
$args = json_decode($argv[2] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
if ($mode === 'prepare') {
    Artisan::call('migrate:fresh', ['--force' => true]);
    require __DIR__.'/QuotationFixtures.php';
    require __DIR__.'/BookingFixtures.php';
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    for ($n = 2; $n < 20; $n++) {
        $q = app(CreateQuoteDraft::class)->execute(quoteInput($service, ['preferred_date' => $start->setTimezone('Africa/Johannesburg')->toDateString(), 'preferred_time' => '09:00']), (string) Str::uuid());
        $q = app(CalculateQuote::class)->execute($q, 0);
        $q = app(SubmitQuote::class)->execute($q, [...quoteContact(), 'email' => "race{$n}@example.test"], $q->lock_version);
        $quotes[] = app(AcceptQuote::class)->execute($q, $q->currentRevision->public_id);
    }
    echo json_encode(['quotes' => array_map(fn ($q) => $q->public_id, $quotes), 'start' => $start->toIso8601String(), 'resources' => Resource::orderBy('id')->pluck('public_id')->all(), 'owner_id' => $owner->id], JSON_THROW_ON_ERROR);
    exit;
}
$tenant = Tenant::where('slug', 'quotation-company')->firstOrFail();
app(TenantContext::class)->set($tenant);
if ($mode === 'add-fleet') {
    $old = Vehicle::firstOrFail();
    $vehicle = app(SaveVehicle::class)->execute(['name' => 'Truck 02', 'registration_number' => 'RACE002', 'vehicle_type_id' => $old->vehicle_type_id, 'status' => 'available', 'capacity_verified' => true, 'capacity_verification_note' => 'Verified test-only fleet measurements.', 'capacity_margin_basis_points' => 1000], $args['owner_id']);
    $before = Resource::pluck('id')->all();
    foreach (['driver', 'mover'] as $role) {
        app(SaveStaffMember::class)->execute(['full_name' => 'Second '.$role, 'role' => $role, 'active' => true, 'engagement_status' => 'employed', 'licence_category' => $role === 'driver' ? 'C1' : null, 'licence_expires_on' => $role === 'driver' ? now()->addYear()->toDateString() : null, 'eligible_vehicle_classes' => $role === 'driver' ? [$vehicle->type->vehicle_class] : []], $args['owner_id']);
    }
    $new = Resource::whereNotIn('id', $before)->get();
    $vehicleResource = Resource::where('vehicle_id', $vehicle->id)->firstOrFail();
    $all = $new->push($vehicleResource)->unique('id');
    foreach ($all as $r) {
        for ($day = 0; $day < 7; $day++) {
            WorkingSchedule::create(['resource_id' => $r->id, 'weekday' => $day, 'opens_at' => '00:00', 'closes_at' => '23:59', 'timezone' => 'Africa/Johannesburg', 'active' => true, 'ends_next_day' => false]);
        }
    }
    echo json_encode(['resources' => $all->sortBy('id')->pluck('public_id')->values()->all()], JSON_THROW_ON_ERROR);
    exit;
}
if (($args['advance_minutes'] ?? 0) > 0) {
    $time = CarbonImmutable::now()->addMinutes($args['advance_minutes']);
    Carbon::setTestNow($time);
    CarbonImmutable::setTestNow($time);
}
if (isset($args['barrier'])) {
    echo "READY\n";
    flush();
    $deadline = microtime(true) + 30;
    while (! file_exists($args['barrier'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Race barrier timed out');
        }usleep(10000);
        clearstatcache();
    }
}
try {
    $result = match ($mode) {
        'hold' => app(CreateBookingHold::class)->execute(Quote::where('public_id', $args['quote'])->firstOrFail(), CarbonImmutable::parse($args['start']), $args['key'], isset($args['booking']) ? Booking::where('public_id', $args['booking'])->firstOrFail() : null, $args['preferred'] ?? [], isset($args['preferred']) ? $args['owner_id'] : null),
        'confirm' => app(ConfirmBooking::class)->execute(BookingHold::where('public_id', $args['hold'])->firstOrFail(), $args['key']),
        'reschedule' => app(RescheduleBooking::class)->execute(Booking::where('public_id', $args['booking'])->firstOrFail(), BookingHold::where('public_id', $args['hold'])->firstOrFail(), $args['key'], 'Concurrent replacement test.', $args['version']),
        'raw' => DB::transaction(function () use ($args) {
            $q = Quote::where('public_id', $args['quote'])->firstOrFail();
            $start = CarbonImmutable::parse($args['start']);
            $h = BookingHold::create(['quote_id' => $q->id, 'accepted_revision_id' => $q->current_revision_id, 'customer_id' => $q->customer_id, 'purpose' => 'initial', 'status' => 'active', 'idempotency_key' => $args['key'], 'request_hash' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(15), 'arrival_starts_at' => $start, 'arrival_ends_at' => $start->addMinutes(30), 'service_starts_at' => $start, 'service_ends_at' => $start->addHour(), 'allocation_starts_at' => $start, 'allocation_ends_at' => $start->addHour(), 'requirements' => ['vehicles' => 1, 'drivers' => 1, 'movers' => 1], 'duration_snapshot' => []]);
            foreach (Resource::whereIn('public_id', $args['preferred'])->orderBy('id')->get() as $r) {
                BookingHoldResource::create(['hold_id' => $h->id, 'resource_id' => $r->id]);
                ResourceAllocation::create(['hold_id' => $h->id, 'resource_id' => $r->id, 'status' => 'held', 'starts_at' => $start, 'ends_at' => $start->addHour()]);
            }

            return $h;
        }),
        default => throw new RuntimeException('Unknown race command'),
    };
    echo 'RESULT '.json_encode(['outcome' => 'ok', 'public_id' => $result->public_id, 'status' => $result->status, 'version' => $result->lock_version, 'quote' => $args['quote'] ?? null], JSON_THROW_ON_ERROR)."\n";
} catch (BookingConflict $e) {
    echo 'RESULT '.json_encode(['outcome' => 'conflict', 'codes' => array_column($e->reasons, 'code')], JSON_THROW_ON_ERROR)."\n";
} catch (QueryException $e) {
    if (($e->errorInfo[0] ?? '') !== '23P01') {
        throw $e;
    }echo 'RESULT '.json_encode(['outcome' => 'conflict', 'codes' => ['23P01']], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).': '.$error->getMessage()."\n");
    exit(1);
}
fflush(STDOUT);
exit(0);
