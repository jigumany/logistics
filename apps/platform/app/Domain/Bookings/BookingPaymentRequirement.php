<?php

namespace App\Domain\Bookings;

final class BookingPaymentRequirement
{
    public function amount(int $total, array $policy): int
    {
        return match ($policy['payment_terms']) {
            'invoice' => 0,'full' => $total,'deposit' => intdiv($total * $policy['deposit_basis_points'] + 9999, 10000),default => $total
        };
    }

    public function apply(Booking $booking): void
    {
        if ($booking->required_payment_minor === 0) {
            $booking->financial_state = 'not_required';

            return;
        }
        $evidence = app(BookingPaymentVerifier::class)->evidence($booking);
        if ($evidence && $evidence->reference !== '' && $evidence->currency === $booking->currency && $evidence->amountMinor >= $booking->required_payment_minor) {
            $booking->financial_state = 'verified';
            $booking->payment_reference = $evidence->reference;
        } else {
            $booking->financial_state = 'awaiting_verification';
        }
    }
}
