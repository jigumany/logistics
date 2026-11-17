<?php

namespace App\Domain\Quotes;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\URL;

final class GuestQuoteLinks
{
    public function forQuote(Quote $quote): array
    {
        $parameters = ['company' => app(TenantContext::class)->tenant()->slug, 'quote' => $quote->public_id];
        $links = ['view' => URL::temporarySignedRoute('quote.guest.view', $quote->access_expires_at, $parameters)];
        foreach (['decline', 'cancel', 'save', 'calculate', 'submit', 'accept', 'respond', 'photo'] as $operation) {
            $links[$operation] = URL::temporarySignedRoute('quote.guest.operation', $quote->access_expires_at, [...$parameters, 'operation' => $operation]);
        }
        $links['pdf'] = URL::temporarySignedRoute('quote.guest.pdf', $quote->access_expires_at, $parameters);
        $links['revisions'] = URL::temporarySignedRoute('quote.guest.revisions', $quote->access_expires_at, $parameters);

        return $links;
    }
}
