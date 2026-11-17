<?php

namespace App\Domain\Quotes;

use App\Domain\Notifications\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CloseQuote
{
    public function execute(Quote $quote, string $operation, int $expectedVersion): Quote
    {
        $next = match ($operation) {
            'decline' => QuoteStatus::Declined,'cancel' => QuoteStatus::Cancelled,default => throw ValidationException::withMessages(['operation' => 'Unknown quotation action.'])
        };

        return DB::transaction(function () use ($quote, $next, $expectedVersion): Quote {
            $quote = Quote::whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if ($quote->status === $next->value) {
                return $quote;
            }
            if ($quote->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages(['lock_version' => 'Refresh the quotation before closing it.']);
            }
            app(QuoteLifecycle::class)->transition($quote, $next);
            OutboxMessage::create(['event_type' => 'quote.'.$next->value, 'payload' => ['quote_public_id' => $quote->public_id]]);

            return $quote;
        });
    }
}
