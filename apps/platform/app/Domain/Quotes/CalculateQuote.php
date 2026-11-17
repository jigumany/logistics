<?php

namespace App\Domain\Quotes;

use App\Domain\Notifications\OutboxMessage;
use App\Domain\Routing\RouteCalculation;
use App\Domain\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CalculateQuote
{
    public function execute(Quote $quote, int $expectedVersion, ?array $input = null, bool $force = false): Quote
    {
        $started = microtime(true);
        $data = $input === null ? null : app(QuoteInput::class)->validate($input);
        [$quote,$lease,$cached] = DB::transaction(function () use ($quote, $expectedVersion, $data, $force): array {
            $quote = Quote::whereKey($quote->id)->with('currentRevision')->lockForUpdate()->firstOrFail();
            if (in_array($quote->status, ['accepted', 'declined', 'cancelled'], true)) {
                throw ValidationException::withMessages(['quote' => 'This quotation is closed. Start a new request to change its inputs.']);
            }
            $hash = $data === null ? $quote->input_hash : app(QuoteInput::class)->hash($data);
            if (! $force && $hash === $quote->input_hash && $quote->currentRevision && $quote->currentRevision->expires_at->isFuture() && $quote->status !== 'calculating') {
                return [$quote, $quote->lock_version, true];
            }
            if ($quote->lock_version !== $expectedVersion || ($quote->status === 'calculating' && $quote->updated_at->gt(now()->subMinutes(2)))) {
                throw ValidationException::withMessages(['lock_version' => 'This quotation changed or is calculating. Refresh it before retrying.']);
            }
            if ($data !== null) {
                $service = TenantService::where('public_id', $data['service_public_id'])->where('active', true)->where('enabled', true)->firstOrFail();
                $quote->service_id = $service->id;
                $quote->draft_input = $data;
                $quote->input_hash = $hash;
                $quote->preferred_date = $data['preferred_date'];
            }
            if ($quote->status === 'calculating') {
                $quote->lock_version++;
                $quote->save();
            } else {
                app(QuoteLifecycle::class)->transition($quote, QuoteStatus::Calculating);
            }

            return [$quote, $quote->lock_version, false];
        });
        if ($cached) {
            return $quote;
        }
        try {
            // Provider work occurs after releasing the row lock. A versioned lease rejects stale results.
            $result = app(AssessQuote::class)->execute($quote);
            $quote = DB::transaction(function () use ($quote, $lease, $result): Quote {
                $quote = Quote::whereKey($quote->id)->with('currentRevision')->lockForUpdate()->firstOrFail();
                if ($quote->lock_version !== $lease || $quote->status !== 'calculating') {
                    throw ValidationException::withMessages(['lock_version' => 'The inputs changed during calculation. Refresh and retry.']);
                }
                $route = RouteCalculation::create([...array_intersect_key($result['route'], array_flip(['provider', 'status', 'failure_code', 'customer_metres', 'depot_metres', 'return_metres', 'duration_seconds', 'tolls_minor', 'retain_until'])), 'quote_id' => $quote->id, 'input_hash' => $quote->input_hash]);
                $result['route_calculation_id'] = $route->id;
                $revision = app(WriteQuoteRevision::class)->execute($quote, $result, $quote->currentRevision->contact_snapshot ?? []);
                app(QuoteLifecycle::class)->transition($quote, $revision->approval === 'automatic' ? QuoteStatus::Quoted : QuoteStatus::RequiresReview);
                if ($quote->submitted_at) {
                    OutboxMessage::create(['event_type' => $revision->approval === 'automatic' ? 'quote.revised' : 'quote.review_requested', 'payload' => ['quote_public_id' => $quote->public_id, 'revision_public_id' => $revision->public_id]]);
                }

                return $quote;
            });
        } catch (Throwable $failure) {
            DB::transaction(function () use ($quote, $lease): void {
                $current = Quote::whereKey($quote->id)->lockForUpdate()->first();
                if ($current && $current->lock_version === $lease && $current->status === 'calculating') {
                    app(QuoteLifecycle::class)->transition($current, QuoteStatus::RequiresReview);
                }
            });
            throw $failure;
        } finally {
            Log::info('quote.calculation_completed', ['quote_public_id' => $quote->public_id, 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)]);
        }

        return $quote->fresh(['currentRevision']);
    }
}
