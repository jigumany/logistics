<?php

namespace App\Domain\Bookings;

use App\Domain\Dispatch\ProtectDispatchedBooking;

final class CancelBooking
{
    public function execute(Booking $booking, string $key, string $reason, int $version, ?int $actorId = null): Booking
    {
        if ($actorId) {
            app(BookingPermissions::class)->manage($actorId);
        }
        $hash = hash('sha256', json_encode([$booking->public_id, $reason, $version], JSON_THROW_ON_ERROR));

        return app(SchedulingTransaction::class)->execute(function () use ($booking, $key, $hash, $reason, $version, $actorId) {
            $receipt = app(BookingCommandReceipt::class)->find($key, 'cancel', $hash);
            if ($receipt) {
                return Booking::whereKey($receipt->booking_id)->firstOrFail();
            }
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            app(ProtectDispatchedBooking::class)->assertMutable($booking);
            if ($booking->lock_version !== $version || ! in_array($booking->status, ['confirmed', 'pending_confirmation', 'pending_payment'], true)) {
                throw BookingConflict::because('cancellation_conflict', 'This booking changed or is already closed. Refresh before cancelling.');
            }
            $policy = $booking->policy_snapshot;
            if (! $actorId && (! $policy['customer_cancellation'] || now()->addMinutes($policy['cancellation_notice_minutes']) > $booking->service_starts_at)) {
                throw BookingConflict::because('cancellation_review_required', 'Please contact operations to request cancellation outside the permitted policy.');
            }
            foreach (BookingHold::where('quote_id', $booking->quote_id)->where('status', 'active')->get() as $hold) {
                ResourceAllocation::where('hold_id', $hold->id)->where('status', 'held')->update(['status' => 'released', 'updated_at' => now()]);
                $hold->status = 'released';
                $hold->save();
            }
            ResourceAllocation::where('booking_id', $booking->id)->whereIn('status', ['held', 'confirmed'])->update(['status' => 'released', 'updated_at' => now()]);
            if ($booking->financial_state === 'verified') {
                $booking->refund_state = 'review_required';
            }
            app(BookingLifecycle::class)->record($booking, 'cancelled', $reason, $actorId);
            app(BookingCommandReceipt::class)->save($booking, $key, 'cancel', $hash, $reason, [], $actorId);
            app(BookingLifecycle::class)->event('booking.cancelled', $booking);

            return $booking;
        });
    }
}
