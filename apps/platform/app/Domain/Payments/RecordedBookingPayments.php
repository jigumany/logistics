<?php

namespace App\Domain\Payments;

use App\Domain\Bookings\Booking;
use App\Domain\Bookings\BookingPaymentVerifier;
use App\Domain\Bookings\VerifiedBookingPayment;

final class RecordedBookingPayments implements BookingPaymentVerifier
{
    public function evidence(Booking $booking): ?VerifiedBookingPayment
    {
        $total = app(PaymentBalance::class)->paid($booking);
        if ($total === 0) {
            return null;
        }

        return new VerifiedBookingPayment('yoco-booking:'.$booking->public_id, $booking->currency, $total);
    }
}
