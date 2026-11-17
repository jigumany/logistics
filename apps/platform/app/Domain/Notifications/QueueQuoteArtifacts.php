<?php

namespace App\Domain\Notifications;

use App\Domain\Quotes\GenerateQuotePdf;
use App\Domain\Quotes\Quote;
use App\Domain\Tenancy\TenantContext;

final class QueueQuoteArtifacts
{
    public function handle(DomainEvent $event): void
    {
        if (! in_array($event->type, ['quote.issued', 'quote.revised', 'quote.review_requested', 'quote.information_requested'], true)) {
            return;
        }
        $quote = Quote::where('public_id', $event->payload['quote_public_id'])->firstOrFail();
        $tenantId = app(TenantContext::class)->tenant()->id;
        $delivery = QuoteDelivery::firstOrCreate(['event_id' => $event->id], ['quote_id' => $quote->id, 'event_type' => $event->type, 'status' => 'pending']);
        GenerateQuotePdf::dispatch($tenantId, $event->payload['revision_public_id'])->afterCommit();
        if ($delivery->status === 'pending') {
            DeliverQuoteNotice::dispatch($tenantId, $event->id, $event->payload['revision_public_id'], $event->payload['customer_message'] ?? null)->afterCommit();
        }
    }
}
