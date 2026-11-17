<?php

namespace App\Domain\Notifications;

use App\Domain\Bookings\Booking;
use App\Domain\Bookings\BookingDelivery;
use App\Domain\Bookings\BookingHold;
use App\Domain\Bookings\GuestBookingLinks;
use App\Domain\Quotes\QuotationPolicies;
use App\Domain\Quotes\Quote;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

final class DeliverBookingNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public int $tenantId, public string $eventId, public ?string $bookingPublicId = null) {}

    public function handle(TenantContext $context): void
    {
        $context->set(Tenant::findOrFail($this->tenantId));
        try {
            Cache::lock('booking-notice:'.$this->eventId, 60)->block(10, function () {
                $delivery = BookingDelivery::where('event_id', $this->eventId)->firstOrFail();
                if ($delivery->status !== 'pending') {
                    return;
                }
                $quote = Quote::whereKey($delivery->quote_id)->with('currentRevision')->firstOrFail();
                $contact = $quote->currentRevision->contact_snapshot;
                if (! app(QuotationPolicies::class)->forService($quote->service_id)->notifications_enabled || ($contact['communication'] ?? 'none') !== 'email') {
                    $delivery->update(['status' => 'skipped']);

                    return;
                }
                if ($delivery->event_type === 'booking.hold_created' && ! BookingHold::where('quote_id', $quote->id)->where('status', 'active')->where('expires_at', '>', now())->exists()) {
                    $delivery->update(['status' => 'skipped']);

                    return;
                }
                $booking = $this->bookingPublicId ? Booking::where('public_id', $this->bookingPublicId)->first() : null;
                if (in_array($delivery->event_type, ['booking.confirmed', 'booking.rescheduled'], true) && $booking?->status !== 'confirmed') {
                    $delivery->update(['status' => 'skipped']);

                    return;
                }
                Mail::to($contact['email'])->send(new BookingNotice(CompanySettings::value('display_name'), ($booking ? $booking->reference : $quote->reference), $delivery->event_type, app(GuestBookingLinks::class)->forQuote($quote)['view']));
                $delivery->update(['status' => 'sent', 'sent_at' => now()]);
            });
        } finally {
            $context->clear();
        }
    }
}
