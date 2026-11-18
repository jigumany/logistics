<?php

namespace App\Domain\Payments;

use App\Domain\Bookings\Booking;

final class PaymentBalance
{
    public function paid(Booking $booking): int
    {
        $q = PaymentAttempt::where('booking_id', $booking->id)->where('status', 'succeeded')->where('currency', $booking->currency)->whereNotNull('verified_at');
        if (app()->isProduction()) {
            $q->where('mode', 'live');
        }

return (int) $q->sum('amount_minor');
    }
}
