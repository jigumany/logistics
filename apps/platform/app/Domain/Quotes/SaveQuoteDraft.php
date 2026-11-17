<?php

namespace App\Domain\Quotes;

use App\Domain\Inventory\ResolveInventory;
use App\Domain\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveQuoteDraft
{
    public function execute(Quote $quote, array $input, int $expectedVersion): Quote
    {
        $data = app(QuoteInput::class)->validate($input);
        app(ResolveInventory::class)->execute($data['inventory']);
        $hash = app(QuoteInput::class)->hash($data);

        return DB::transaction(function () use ($quote, $data, $hash, $expectedVersion): Quote {
            $quote = Quote::whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if ($quote->status !== 'draft' || $quote->current_revision_id) {
                throw ValidationException::withMessages(['quote' => 'Calculate a new revision to change an existing estimate.']);
            }
            if ($quote->input_hash === $hash) {
                return $quote;
            }
            if ($quote->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages(['lock_version' => 'This draft changed. Refresh it before retrying.']);
            }
            $service = TenantService::where('public_id', $data['service_public_id'])->where('active', true)->where('enabled', true)->firstOrFail();
            $quote->fill(['service_id' => $service->id, 'draft_input' => $data, 'input_hash' => $hash, 'preferred_date' => $data['preferred_date'], 'lock_version' => $quote->lock_version + 1])->save();

            return $quote;
        });
    }
}
