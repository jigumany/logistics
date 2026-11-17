<?php

use App\Domain\Availability\BookingQuoteScope;
use App\Domain\Availability\ScheduleOverride;
use App\Domain\Availability\SearchAvailability;
use App\Domain\Availability\WorkingSchedule;
use App\Domain\Bookings\BookingConflict;
use App\Domain\Bookings\BookingDelivery;
use App\Domain\Bookings\BookingOperations;
use App\Domain\Bookings\BookingPolicy;
use App\Domain\Bookings\CancelBooking;
use App\Domain\Bookings\ConfirmBooking;
use App\Domain\Bookings\CreateBookingHold;
use App\Domain\Bookings\GuestBookingLinks;
use App\Domain\Bookings\RescheduleBooking;
use App\Domain\Notifications\BookingNotice;
use App\Domain\Notifications\DeliverBookingNotice;
use App\Domain\Notifications\DomainEvent;
use App\Domain\Notifications\QueueBookingNotice;
use App\Domain\Quotes\CreateQuoteDraft;
use App\Domain\Quotes\QuotationPolicy;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/BookingFixtures.php';
it('saves independent service booking rules without changing company payment terms', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    $other = TenantService::create(['code' => 'office', 'name' => 'Office', 'active' => true, 'enabled' => true]);
    $settings = CompanySettings::firstOrFail();
    $rules = ['weekdays' => [1, 2, 3, 4, 5, 6], 'opens_at' => null, 'closes_at' => null, 'vehicles' => 1, 'minimum_movers' => 0, 'depot_public_id' => null];
    $this->actingAs($owner)->put('/c/'.$tenant->slug.'/booking-policy', ['configuration' => [...BookingPolicy::DEFAULTS, 'reviewed' => true], 'booking_enabled' => true, 'services' => [['public_id' => $service->public_id, 'publicly_bookable' => true, 'rules' => $rules], ['public_id' => $other->public_id, 'publicly_bookable' => true, 'rules' => $rules]]])->assertSessionHasNoErrors();
    app(TenantContext::class)->set($tenant);
    expect($other->fresh()->booking_rules['vehicles'])->toBe(1)->and($settings->fresh()->payment_terms)->toBe('invoice');
});
it('honours a company date opening override and price-affecting reschedule review', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    WorkingSchedule::query()->update(['active' => false]);
    foreach ([null, ...App\Domain\Resources\Resource::pluck('id')->all()] as $id) {
        ScheduleOverride::create(['resource_id' => $id, 'date' => $start->setTimezone('Africa/Johannesburg')->toDateString(), 'closed' => false, 'opens_at' => '08:00', 'closes_at' => '18:00', 'ends_next_day' => false, 'timezone' => 'Africa/Johannesburg']);
    }
    expect(app(CreateBookingHold::class)->execute($quotes[0], $start, (string) Str::uuid())->status)->toBe('active');
    $revision = clone $quotes[0]->currentRevision;
    $revision->pricing_snapshot = [...$revision->pricing_snapshot, 'rules' => [['metric' => 'weekend']]];
    expect(fn () => app(BookingQuoteScope::class)->priceNeutral($revision, $start->next('Monday'), 'Africa/Johannesburg'))->toThrow(BookingConflict::class);
});
it('rejects expired and unaccepted quotations and bounds availability searches', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    $draft = app(CreateQuoteDraft::class)->execute(quoteInput($service), (string) Str::uuid());
    expect(fn () => app(CreateBookingHold::class)->execute($draft, $start, (string) Str::uuid()))->toThrow(BookingConflict::class);
    expect(fn () => app(SearchAvailability::class)->execute($quotes[0], $start->toDateString(), 8))->toThrow(BookingConflict::class);
    $this->travel(4)->days();
    expect(fn () => app(CreateBookingHold::class)->execute($quotes[0], $start->addWeek(), (string) Str::uuid()))->toThrow(BookingConflict::class);
});
it('prevents hold monopolisation and cancellation outside policy without releasing a booking', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    for ($n = 0; $n < 3; $n++) {
        $hold = app(CreateBookingHold::class)->execute($quotes[0], $start, (string) Str::uuid());
        app(BookingOperations::class)->execute($quotes[0], 'release', ['hold_public_id' => $hold->public_id, 'idempotency_key' => (string) Str::uuid()]);
    }
    expect(fn () => app(CreateBookingHold::class)->execute($quotes[0], $start, (string) Str::uuid()))->toThrow(BookingConflict::class);
    CompanySettings::firstOrFail()->update(['booking_policy' => [...BookingPolicy::DEFAULTS, 'reviewed' => true, 'manual_confirmation' => false, 'customer_cancellation' => false]]);
    $hold = app(CreateBookingHold::class)->execute($quotes[1], $start, (string) Str::uuid());
    $booking = app(ConfirmBooking::class)->execute($hold, (string) Str::uuid());
    expect(fn () => app(CancelBooking::class)->execute($booking, (string) Str::uuid(), 'Customer cancellation request.', $booking->lock_version))->toThrow(BookingConflict::class);
    expect($booking->fresh()->status)->toBe('confirmed');
});
it('requires an operator to approve replacements under a manual confirmation policy', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    CompanySettings::firstOrFail()->update(['booking_policy' => [...BookingPolicy::DEFAULTS, 'reviewed' => true, 'manual_confirmation' => true]]);
    $hold = app(CreateBookingHold::class)->execute($quotes[0], $start, (string) Str::uuid());
    $booking = app(ConfirmBooking::class)->execute($hold, (string) Str::uuid(), $owner->id);
    $replacement = app(CreateBookingHold::class)->execute($quotes[0], $start->addHours(2), (string) Str::uuid(), $booking);
    expect(fn () => app(RescheduleBooking::class)->execute($booking, $replacement, (string) Str::uuid(), 'New pickup requested.', $booking->lock_version))->toThrow(BookingConflict::class);
    expect($booking->fresh()->service_starts_at->eq($start))->toBeTrue();
});
it('persists notices idempotently and sends only customer-safe booking updates', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    $hold = app(CreateBookingHold::class)->execute($quotes[0], $start, (string) Str::uuid());
    $booking = app(ConfirmBooking::class)->execute($hold, (string) Str::uuid());
    QuotationPolicy::query()->update(['notifications_enabled' => true]);
    Queue::fake();
    $event = new DomainEvent((string) Str::ulid(), 'booking.confirmed', ['quote_public_id' => $quotes[0]->public_id, 'booking_public_id' => $booking->public_id]);
    app(QueueBookingNotice::class)->handle($event);
    app(QueueBookingNotice::class)->handle($event);
    expect(BookingDelivery::count())->toBe(1);
    Mail::fake();
    $job = new DeliverBookingNotice($tenant->id, $event->id, $booking->public_id);
    $job->handle(app(TenantContext::class));
    app(TenantContext::class)->set($tenant);
    $job->handle(app(TenantContext::class));
    app(TenantContext::class)->set($tenant);
    Mail::assertSent(BookingNotice::class, 1);
    expect(BookingDelivery::firstOrFail()->status)->toBe('sent');
    $mail = new BookingNotice('Example company', $booking->reference, 'booking.confirmed', app(GuestBookingLinks::class)->forQuote($quotes[0])['view']);
    expect($mail->render())->not->toContain('licence', 'internal_costs', 'margin', 'staff_members');
});
it('keeps a committed booking when notification delivery fails', function () {
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    $hold = app(CreateBookingHold::class)->execute($quotes[0], $start, (string) Str::uuid());
    $booking = app(ConfirmBooking::class)->execute($hold, (string) Str::uuid());
    $eventId = (string) Str::ulid();
    BookingDelivery::create(['quote_id' => $quotes[0]->id, 'event_id' => $eventId, 'event_type' => 'booking.confirmed', 'status' => 'pending']);
    QuotationPolicy::query()->update(['notifications_enabled' => true]);
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Test mail provider unavailable'));
    expect(fn () => (new DeliverBookingNotice($tenant->id, $eventId, $booking->public_id))->handle(app(TenantContext::class)))->toThrow(RuntimeException::class);
    app(TenantContext::class)->set($tenant);
    expect($booking->fresh()->status)->toBe('confirmed')->and(BookingDelivery::firstOrFail()->status)->toBe('pending');
});
