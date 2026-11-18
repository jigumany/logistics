<?php

namespace App\Domain\Bookings;

use App\Domain\Quotes\Quote;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\URL;

final class GuestBookingLinks
{
    public function forQuote(Quote $quote): array
    {
        $parameters = ['company' => app(TenantContext::class)->tenant()->slug, 'quote' => $quote->public_id];
        $expires = max($quote->access_expires_at, $quote->created_at->toImmutable()->addDays(90));
        $links = ['view' => URL::temporarySignedRoute('booking.guest.view', $expires, $parameters)];
        foreach (['availability', 'hold', 'release', 'confirm', 'reschedule', 'cancel'] as $operation) {
            $links[$operation] = URL::temporarySignedRoute('booking.guest.operation', $expires, [...$parameters, 'operation' => $operation]);
        }

        $links['checkout'] = URL::temporarySignedRoute('booking.payment.checkout', $expires, $parameters);
        $links['invoice'] = URL::temporarySignedRoute('booking.payment.invoice', $expires, $parameters);

        return $links;
    }
}
