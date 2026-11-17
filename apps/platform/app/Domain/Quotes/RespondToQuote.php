<?php

namespace App\Domain\Quotes;

use App\Domain\Notifications\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RespondToQuote
{
    public function execute(Quote $quote, array $input): Quote
    {
        $data = Validator::make($input, ['message' => ['required', 'string', 'min:1', 'max:5000'], 'request_key' => ['required', 'uuid'], 'lock_version' => ['required', 'integer', 'min:0']])->validate();

        return DB::transaction(function () use ($quote, $data): Quote {
            $quote = Quote::whereKey($quote->id)->lockForUpdate()->firstOrFail();
            $existing = QuoteReviewDecision::where('quote_id', $quote->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                if ($existing->reason !== $data['message']) {
                    throw ValidationException::withMessages(['request_key' => 'This response key already belongs to another message.']);
                }

                return $quote;
            }
            if ($quote->status !== 'requires_review' || ! $quote->submitted_at || $quote->lock_version !== $data['lock_version']) {
                throw ValidationException::withMessages(['quote' => 'Refresh this quotation before sending more information.']);
            }
            QuoteReviewDecision::create(['quote_id' => $quote->id, 'revision_id' => $quote->current_revision_id, 'decision' => 'customer_response', 'reason' => $data['message'], 'actor_id' => null, 'request_key' => $data['request_key']]);
            $quote->lock_version++;
            $quote->save();
            OutboxMessage::create(['event_type' => 'quote.customer_replied', 'payload' => ['quote_public_id' => $quote->public_id]]);

            return $quote;
        });
    }
}
