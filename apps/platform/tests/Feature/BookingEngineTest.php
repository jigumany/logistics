<?php

use App\Domain\Availability\EstimateBookingDuration;
use App\Domain\Availability\SearchAvailability;
use App\Domain\Bookings\Booking;
use App\Domain\Bookings\BookingConflict;
use App\Domain\Bookings\BookingPolicy;
use App\Domain\Bookings\CancelBooking;
use App\Domain\Bookings\ConfirmBooking;
use App\Domain\Bookings\CreateBookingHold;
use App\Domain\Bookings\ExpireBookingHolds;
use App\Domain\Bookings\RescheduleBooking;
use App\Domain\Bookings\ResourceAllocation;
use App\Domain\Bookings\SchedulingTransaction;
use App\Domain\Tenancy\CompanySettings;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/BookingFixtures.php';
it('searches and atomically holds and confirms a complete actual fleet combination', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    $result = app(SearchAvailability::class)->execute($quotes[0], $start->setTimezone('Africa/Johannesburg')->toDateString());
    expect($result['slots'])->not->toBeEmpty()->and(json_encode($result))->not->toContain('Booking driver', 'registration_number', 'resource_ids');
    $snapshot = $quotes[0]->currentRevision->getAttributes();
    $key = (string) Str::uuid();
    $hold = app(CreateBookingHold::class)->execute($quotes[0], $start, $key);
    expect(app(CreateBookingHold::class)->execute($quotes[0], $start, $key)->id)->toBe($hold->id)->and(ResourceAllocation::where('status', 'held')->count())->toBe(3);
    expect(fn () => app(CreateBookingHold::class)->execute($quotes[1], $start, (string) Str::uuid()))->toThrow(BookingConflict::class);
    $confirmKey = (string) Str::uuid();
    $booking = app(ConfirmBooking::class)->execute($hold, $confirmKey);
    expect($booking->status)->toBe('confirmed')->and($booking->dispatch_state)->toBe('awaiting_dispatch')->and(ResourceAllocation::where('status', 'confirmed')->count())->toBe(3);
    expect(app(ConfirmBooking::class)->execute($hold, $confirmKey)->id)->toBe($booking->id)->and(Booking::count())->toBe(1)->and($quotes[0]->currentRevision->fresh()->getAttributes())->toBe($snapshot);
});
it('expires holds inline before reusing the same truck and driver', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    $hold = app(CreateBookingHold::class)->execute($quotes[0], $start, (string) Str::uuid());
    $this->travel(16)->minutes();
    $replacement = app(CreateBookingHold::class)->execute($quotes[1], $start, (string) Str::uuid());
    expect($hold->fresh()->status)->toBe('expired')->and($replacement->status)->toBe('active')->and(ResourceAllocation::where('status', 'held')->count())->toBe(3);
    expect(fn () => app(ConfirmBooking::class)->execute($hold, (string) Str::uuid()))->toThrow(BookingConflict::class);
});
it('keeps payment-required bookings pending and releases them on expiry', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    CompanySettings::query()->update(['payment_terms' => 'deposit', 'deposit_basis_points' => 2500]);
    $hold = app(CreateBookingHold::class)->execute($quotes[0], $start, (string) Str::uuid());
    $booking = app(ConfirmBooking::class)->execute($hold, (string) Str::uuid());
    expect($booking->status)->toBe('pending_payment')->and($booking->financial_state)->toBe('awaiting_verification')->and(ResourceAllocation::where('status', 'confirmed')->count())->toBe(0);
    $this->travel(16)->minutes();
    app(SchedulingTransaction::class)->execute(fn () => app(ExpireBookingHolds::class)->execute());
    expect($booking->fresh()->status)->toBe('expired')->and(ResourceAllocation::where('status', 'held')->count())->toBe(0);
});
it('reschedules overlapping times by holding only extension intervals and atomically replacing allocations', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    $hold = app(CreateBookingHold::class)->execute($quotes[0], $start, (string) Str::uuid());
    $booking = app(ConfirmBooking::class)->execute($hold, (string) Str::uuid());
    $oldStart = $booking->service_starts_at;
    $oldVersion = $booking->lock_version;
    $replacement = app(CreateBookingHold::class)->execute($quotes[0], $start->addMinutes(30), (string) Str::uuid(), $booking);
    expect($booking->fresh()->service_starts_at->eq($oldStart))->toBeTrue();
    $booking = app(RescheduleBooking::class)->execute($booking, $replacement, (string) Str::uuid(), 'Customer requested a later arrival.', $oldVersion);
    expect($booking->service_starts_at->eq($start->addMinutes(30)))->toBeTrue()->and(ResourceAllocation::where('status', 'confirmed')->count())->toBe(3)->and(ResourceAllocation::where('status', 'held')->count())->toBe(0);
});
it('preserves the original booking when replacement is unavailable and releases resources on cancellation', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    $hold = app(CreateBookingHold::class)->execute($quotes[0], $start, (string) Str::uuid());
    $booking = app(ConfirmBooking::class)->execute($hold, (string) Str::uuid());
    $other = app(CreateBookingHold::class)->execute($quotes[1], $start->addHours(3), (string) Str::uuid());
    expect(fn () => app(CreateBookingHold::class)->execute($quotes[0], $start->addHours(3), (string) Str::uuid(), $booking))->toThrow(BookingConflict::class);
    expect($booking->fresh()->status)->toBe('confirmed')->and(ResourceAllocation::where('booking_id', $booking->id)->where('status', 'confirmed')->count())->toBe(3);
    $key = (string) Str::uuid();
    $version = $booking->lock_version;
    $booking = app(CancelBooking::class)->execute($booking, $key, 'Plans changed.', $version);
    expect($booking->status)->toBe('cancelled')->and(app(CancelBooking::class)->execute($booking, $key, 'Plans changed.', $version)->id)->toBe($booking->id);
    expect(ResourceAllocation::where('booking_id', $booking->id)->where('status', 'confirmed')->count())->toBe(0);
});
it('protects turnaround buffers and allows a back-to-back allocation only after the internal interval ends', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    $hold = app(CreateBookingHold::class)->execute($quotes[0], $start, (string) Str::uuid());
    expect($hold->allocation_ends_at->gt($hold->service_ends_at))->toBeTrue();
    expect(fn () => app(CreateBookingHold::class)->execute($quotes[1], $hold->service_ends_at, (string) Str::uuid()))->toThrow(BookingConflict::class);
    expect(app(CreateBookingHold::class)->execute($quotes[1], $hold->allocation_ends_at, (string) Str::uuid())->status)->toBe('active');
});
it('uses known depot approach and return durations without adding them to the customer service interval', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    $revision = clone $quotes[0]->currentRevision;
    $snapshot = $revision->pricing_snapshot;
    $snapshot['configuration']['depot_public_id'] = (string) Str::ulid();
    $revision->pricing_snapshot = $snapshot;
    $policy = app(BookingPolicy::class)->forService($service);
    $duration = app(EstimateBookingDuration::class)->estimate($revision, $policy);
    expect($duration['before_minutes'])->toBe(15)->and($duration['after_minutes'])->toBe(60)->and($duration['service_minutes'])->toBe(36);
});
