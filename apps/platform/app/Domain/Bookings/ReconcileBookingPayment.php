<?php

namespace App\Domain\Bookings;

use Ramsey\Uuid\Uuid;

final class ReconcileBookingPayment
{
    // Phase 5 boundary: an authenticated payment consumer must persist verified evidence before calling this action.
    public function execute(Booking $booking, string $key): Booking
    {
        $hash = hash('sha256', $booking->public_id);

        return app(SchedulingTransaction::class)->execute(function () use ($booking, $key, $hash) {
            app(ExpireBookingHolds::class)->execute();
            $receipt = app(BookingCommandReceipt::class)->find($key, 'payment', $hash);
            if ($receipt) {
                return Booking::whereKey($receipt->booking_id)->firstOrFail();
            }
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            app(BookingPaymentRequirement::class)->apply($booking);
            if ($booking->financial_state !== 'verified') {
                throw BookingConflict::because('payment_not_verified', 'No sufficient, reconciled payment evidence exists for this booking and currency.');
            }
            $hold = BookingHold::where('booking_id', $booking->id)->where('purpose', 'initial')->where('status', 'active')->where('expires_at', '>', now())->first();
            if ($hold && in_array($booking->status, ['pending_payment', 'pending_confirmation'], true)) {
                $booking = app(ConfirmBooking::class)->execute($hold, (string) Uuid::uuid5(Uuid::NAMESPACE_OID, $key));
            } elseif ($booking->status !== 'confirmed') {
                $booking->financial_state = 'manual_resolution';
                $booking->refund_state = 'review_required';
                app(BookingLifecycle::class)->record($booking, $booking->status, 'Verified payment arrived after resources were released. Manual recovery or refund review is required.');
                app(BookingLifecycle::class)->event('booking.payment_resolution_required', $booking);
            }
            app(BookingCommandReceipt::class)->save($booking, $key, 'payment', $hash, 'Verified local payment evidence reconciled.', ['payment_reference' => $booking->payment_reference]);

            return $booking;
        });
    }
}
