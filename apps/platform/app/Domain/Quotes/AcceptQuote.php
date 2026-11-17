<?php

namespace App\Domain\Quotes;

use App\Domain\Notifications\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcceptQuote
{
    public function execute(Quote $quote, string $revisionPublicId): Quote
    {
        if ($quote->simulation_price_book_id !== null) {
            throw ValidationException::withMessages(['quote' => 'Internal simulations cannot issue, approve or accept customer offers.']);
        }

        return DB::transaction(function () use ($quote, $revisionPublicId): Quote {
            $quote = Quote::whereKey($quote->id)->with('currentRevision')->lockForUpdate()->firstOrFail();
            $revision = $quote->currentRevision;
            if ($quote->status === 'accepted' && $revision?->public_id === $revisionPublicId) {
                return $quote;
            }
            if ($quote->status !== 'quoted' || ! $quote->submitted_at || ! $revision || $revision->public_id !== $revisionPublicId || ! in_array($revision->approval, ['automatic', 'approved'], true) || $revision->expires_at->isPast() || $revision->total_minor === null) {
                throw ValidationException::withMessages(['revision' => 'Only the current, issued and unexpired offer can be accepted.']);
            }
            $quote->draft_input = app(WriteQuoteRevision::class)->snapshotInput($quote->draft_input);
            $quote->accepted_terms_hash = $revision->terms_snapshot['hash'] ?? hash('sha256', json_encode(['legacy', $revision->terms, $revision->exclusions], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $quote->accepted_at = CarbonImmutable::now();
            app(QuoteLifecycle::class)->transition($quote, QuoteStatus::Accepted);
            OutboxMessage::create(['event_type' => 'quote.accepted', 'payload' => ['quote_public_id' => $quote->public_id, 'revision_public_id' => $revision->public_id, 'currency' => $revision->currency, 'total_minor' => $revision->total_minor, 'booking_confirmed' => false]]);

            return $quote;
        });
    }
}
