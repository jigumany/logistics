<?php

namespace App\Domain\Quotes;

use App\Domain\Inventory\ResolveInventory;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateQuoteDraft
{
    public function execute(array $input, string $idempotencyKey, ?string $creatorSessionHash = null): Quote
    {
        $data = app(QuoteInput::class)->validate($input);
        $hash = app(QuoteInput::class)->hash($data);
        app(ResolveInventory::class)->execute($data['inventory']);

        return DB::transaction(function () use ($data, $hash, $idempotencyKey, $creatorSessionHash): Quote {
            Tenant::whereKey(app(TenantContext::class)->tenant()->id)->lockForUpdate()->firstOrFail();
            $existing = Quote::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if (($existing->creator_session_hash !== null && ! hash_equals($existing->creator_session_hash, $creatorSessionHash ?? '')) || ! hash_equals($existing->request_hash, $hash) || $existing->access_expires_at->isPast()) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This submission key was already used. Recover the original request or start a new one.']);
                }

                return $existing;
            }
            $service = TenantService::where('public_id', $data['service_public_id'])->where('active', true)->where('enabled', true)->firstOrFail();
            $quote = Quote::create(['service_id' => $service->id, 'reference' => 'Q-'.Str::upper(Str::random(12)), 'idempotency_key' => $idempotencyKey, 'request_hash' => $hash, 'creator_session_hash' => $creatorSessionHash, 'input_hash' => $hash, 'draft_input' => $data, 'preferred_date' => $data['preferred_date'], 'status' => 'draft', 'access_expires_at' => now()->addDays(45)]);
            QuoteStatusHistory::create(['quote_id' => $quote->id, 'from_status' => null, 'to_status' => 'draft', 'actor_id' => null]);

            return $quote;
        });
    }
}
