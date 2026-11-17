<?php

namespace App\Domain\Notifications;

use Illuminate\Mail\Mailable;

final class BookingNotice extends Mailable
{
    public function __construct(public string $business, public string $reference, public string $eventType, public string $accessUrl) {}

    public function build(): static
    {
        return $this->subject($this->business.' — booking update '.$this->reference)->view('bookings.email');
    }
}
