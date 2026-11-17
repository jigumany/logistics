<?php

namespace App\Domain\Quotes;

use Illuminate\Validation\ValidationException;

final class QuoteLifecycle
{
    public function transition(Quote $quote, QuoteStatus $next, ?int $actorId = null): void
    {
        if ($quote->status === $next->value) {
            return;
        }
        if (! QuoteStatus::from($quote->status)->permits($next)) {
            throw ValidationException::withMessages(['status' => 'This quotation cannot make the requested transition.']);
        }
        $previous = $quote->status;
        $quote->status = $next->value;
        $quote->lock_version++;
        $quote->save();
        QuoteStatusHistory::create(['quote_id' => $quote->id, 'from_status' => $previous, 'to_status' => $next->value, 'actor_id' => $actorId]);
    }
}
