<?php

namespace App\Domain\Bookings;

use App\Domain\Notifications\OutboxMessage;
use App\Domain\Quotes\Quote;
use App\Domain\Tenancy\RecordsAdministrativeChange;

final class BookingLifecycle
{
    public function record(Booking $booking, string $status, string $reason, ?int $actorId = null): void
    {
        $from = $booking->exists ? $booking->status : null;
        $allowed = ['pending_confirmation' => ['pending_confirmation', 'pending_payment', 'confirmed', 'cancelled', 'expired'], 'pending_payment' => ['pending_payment', 'pending_confirmation', 'confirmed', 'cancelled', 'expired'], 'confirmed' => ['confirmed', 'cancelled'], 'cancelled' => ['cancelled'], 'expired' => ['expired']];
        if ($from && ! in_array($status, $allowed[$from] ?? [], true)) {
            throw BookingConflict::because('invalid_booking_transition', 'This booking cannot enter the requested state.');
        }
        $booking->status = $status;
        $booking->dispatch_state = $status === 'confirmed' ? 'awaiting_dispatch' : 'blocked';
        $booking->lock_version = (int) $booking->lock_version + 1;
        $booking->save();
        BookingStatusHistory::create(['booking_id' => $booking->id, 'from_status' => $from, 'to_status' => $status, 'reason' => $reason, 'actor_id' => $actorId]);
        if ($actorId) {
            app(RecordsAdministrativeChange::class)->record($booking, 'booking.administration.'.$status, $actorId);
        }
    }

    public function event(string $type, Booking $booking): void
    {
        OutboxMessage::create(['event_type' => $type, 'payload' => ['booking_public_id' => $booking->public_id, 'quote_public_id' => Quote::whereKey($booking->quote_id)->value('public_id'), 'booking_reference' => $booking->reference, 'status' => $booking->status, 'booking_confirmed' => $booking->status === 'confirmed']]);
    }
}
