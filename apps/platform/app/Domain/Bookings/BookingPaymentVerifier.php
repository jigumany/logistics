<?php

namespace App\Domain\Bookings;

interface BookingPaymentVerifier
{
    // Read locally authenticated and reconciled evidence. Never make provider calls inside booking transactions.
    public function evidence(Booking $booking): ?VerifiedBookingPayment;
}
