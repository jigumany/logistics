<?php

namespace App\Domain\Notifications;

use Illuminate\Mail\Mailable;

final class QuoteNotice extends Mailable
{
    public function __construct(public string $business, public string $reference, public string $eventType, public string $accessUrl, public ?string $customerMessage) {}

    public function build(): static
    {
        return $this->subject($this->business.' — quotation '.$this->reference)->view('quotes.email');
    }
}
