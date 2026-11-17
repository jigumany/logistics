<?php

namespace App\Domain\Bookings;

final readonly class VerifiedBookingPayment
{
    public function __construct(public string $reference, public string $currency, public int $amountMinor) {}
}
