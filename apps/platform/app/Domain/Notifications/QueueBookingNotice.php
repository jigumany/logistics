<?php

namespace App\Domain\Notifications;

use App\Domain\Bookings\BookingDelivery;
use App\Domain\Quotes\Quote;
use App\Domain\Tenancy\TenantContext;

final class QueueBookingNotice
{
    public function handle(DomainEvent $event): void
    {
        if (! str_starts_with($event->type, 'booking.') || ! isset($event->payload['quote_public_id'])) {
            return;
        }
        $quote = Quote::where('public_id', $event->payload['quote_public_id'])->firstOrFail();
        $delivery = BookingDelivery::firstOrCreate(['event_id' => $event->id], ['quote_id' => $quote->id, 'event_type' => $event->type, 'status' => 'pending']);
        if ($delivery->status === 'pending') {
            DeliverBookingNotice::dispatch(app(TenantContext::class)->tenant()->id, $event->id, $event->payload['booking_public_id'] ?? null)->afterCommit();
        }
    }
}
