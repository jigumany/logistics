<?php

namespace App\Domain\Quotes;

use Illuminate\Validation\ValidationException;

final class AcceptedQuotation
{
    public function snapshot(string $quotePublicId): array
    {
        $quote = Quote::where('public_id', $quotePublicId)->with('currentRevision.inventory')->firstOrFail();
        $revision = $quote->currentRevision;
        if ($quote->status !== 'accepted' || ! $revision) {
            throw ValidationException::withMessages(['quote' => 'An accepted quotation revision is required.']);
        }

        return ['schema_version' => 1, 'terms_version' => $revision->terms_snapshot['version'] ?? 'legacy', 'accepted_terms_hash' => $quote->accepted_terms_hash, 'terms' => $revision->terms, 'exclusions' => $revision->exclusions, 'quote_public_id' => $quote->public_id, 'revision_public_id' => $revision->public_id, 'service_public_id' => $revision->input['service_public_id'], 'customer_snapshot' => $revision->contact_snapshot, 'inventory_summary' => $revision->inventory_summary, 'currency' => $revision->currency, 'total_minor' => $revision->total_minor, 'preferred_date' => $revision->input['preferred_date'], 'availability_must_be_rechecked' => true, 'booking_confirmed' => false];
    }
}
