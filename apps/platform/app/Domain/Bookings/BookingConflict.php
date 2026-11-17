<?php

namespace App\Domain\Bookings;

use RuntimeException;

final class BookingConflict extends RuntimeException
{
    public function __construct(public readonly array $reasons)
    {
        parent::__construct($reasons[0]['message'] ?? 'The requested resources are no longer available.');
    }

    public static function because(string $code, string $message): self
    {
        return new self([['code' => $code, 'message' => $message]]);
    }
}
