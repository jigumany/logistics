<?php

namespace App\Domain\Quotes;

use App\Domain\Bookings\GuestBookingLinks;
use App\Domain\Pricing\PriceBook;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\URL;

final class GuestQuoteLinks
{
    public function forQuote(Quote $quote): array
    {
        if ($quote->simulation_price_book_id !== null) {
            $book = PriceBook::whereKey($quote->simulation_price_book_id)->firstOrFail();
            $base = '/c/'.app(TenantContext::class)->tenant()->slug.'/price-books/'.$book->public_id.'/simulate/'.$quote->public_id;
            $links = [];
            foreach (['view', 'save', 'calculate', 'submit', 'accept', 'respond', 'photo', 'pdf', 'revisions', 'decline', 'cancel'] as $action) {
                $links[$action] = $base.'/'.$action;
            }

            return $links;
        }
        $parameters = ['company' => app(TenantContext::class)->tenant()->slug, 'quote' => $quote->public_id];
        $links = ['view' => URL::temporarySignedRoute('quote.guest.view', $quote->access_expires_at, $parameters)];
        foreach (['decline', 'cancel', 'save', 'calculate', 'submit', 'accept', 'respond', 'photo'] as $operation) {
            $links[$operation] = URL::temporarySignedRoute('quote.guest.operation', $quote->access_expires_at, [...$parameters, 'operation' => $operation]);
        }
        $links['pdf'] = URL::temporarySignedRoute('quote.guest.pdf', $quote->access_expires_at, $parameters);
        $links['booking'] = app(GuestBookingLinks::class)->forQuote($quote)['view'];
        $links['revisions'] = URL::temporarySignedRoute('quote.guest.revisions', $quote->access_expires_at, $parameters);

        return $links;
    }
}
