<?php

use App\Domain\Availability\WorkingSchedule;
use App\Domain\Bookings\BookingPolicy;
use App\Domain\Fleet\Vehicle;
use App\Domain\Quotes\AcceptQuote;
use App\Domain\Quotes\CalculateQuote;
use App\Domain\Quotes\CreateQuoteDraft;
use App\Domain\Quotes\SubmitQuote;
use App\Domain\Resources\Resource;
use App\Domain\Resources\SaveStaffMember;
use App\Domain\Tenancy\CompanySettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function bookingFixture(): array
{
    [$tenant,$owner,$service] = quoteFixture();
    CompanySettings::query()->update(['booking_enabled' => true, 'booking_notice_minutes' => 0, 'job_buffer_minutes' => 15, 'payment_terms' => 'invoice', 'booking_policy' => [...BookingPolicy::DEFAULTS, 'reviewed' => true, 'manual_confirmation' => false, 'cancellation_notice_minutes' => 0]]);
    $service->update(['publicly_bookable' => true, 'notice_minutes' => 0]);
    $vehicle = Vehicle::with('type')->firstOrFail();
    foreach (['driver', 'mover'] as $role) {
        app(SaveStaffMember::class)->execute(['full_name' => 'Booking '.$role, 'role' => $role, 'active' => true, 'engagement_status' => 'employed', 'licence_category' => $role === 'driver' ? 'C1' : null, 'licence_expires_on' => $role === 'driver' ? now()->addYear()->toDateString() : null, 'eligible_vehicle_classes' => $role === 'driver' ? [$vehicle->type->vehicle_class] : []], $owner->id);
    }
    foreach ([null, ...Resource::pluck('id')->all()] as $id) {
        for ($day = 0; $day < 7; $day++) {
            WorkingSchedule::create(['resource_id' => $id, 'weekday' => $day, 'opens_at' => '00:00', 'closes_at' => '23:59', 'timezone' => 'Africa/Johannesburg', 'active' => true, 'ends_next_day' => false]);
        }
    }
    $start = CarbonImmutable::now('Africa/Johannesburg')->next('Saturday')->setTime(9, 0)->utc();
    $quotes = [];
    for ($n = 0; $n < 2; $n++) {
        $input = quoteInput($service, ['preferred_date' => $start->setTimezone('Africa/Johannesburg')->toDateString(), 'preferred_time' => '09:00']);
        $q = app(CreateQuoteDraft::class)->execute($input, (string) Str::uuid());
        $q = app(CalculateQuote::class)->execute($q, 0);
        $q = app(SubmitQuote::class)->execute($q, [...quoteContact(), 'email' => "booking{$n}@example.test"], $q->lock_version);
        $quotes[] = app(AcceptQuote::class)->execute($q, $q->currentRevision->public_id);
    }

    return [$tenant, $owner, $service, $quotes, $start];
}
