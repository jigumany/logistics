<?php

namespace App\Infrastructure\Payments;

use App\Domain\Bookings\Booking;
use App\Domain\Bookings\BookingPaymentVerifier;
use App\Domain\Bookings\VerifiedBookingPayment;

final class UnconfiguredBookingPayments implements BookingPaymentVerifier
{
    public function evidence(Booking $booking): ?VerifiedBookingPayment
    {
        return null;
    }
}
