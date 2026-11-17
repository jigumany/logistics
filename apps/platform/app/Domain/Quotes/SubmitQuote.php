<?php

namespace App\Domain\Quotes;

use App\Domain\Customers\Customer;
use App\Domain\Notifications\OutboxMessage;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SubmitQuote
{
    public function execute(Quote $quote, array $contact, int $expectedVersion): Quote
    {
        $contact = Validator::make($contact, ['name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email:rfc', 'max:254'], 'phone' => ['nullable', 'string', 'max:30'], 'consent' => ['required', 'accepted'], 'communication' => ['required', 'in:email,none'], 'marketing_consent' => ['sometimes', 'boolean']])->validate();
        $contact['name'] = trim($contact['name']);
        $contact['consent'] = true;
        $contact['email'] = mb_strtolower(trim($contact['email']));
        $contact['marketing_consent'] = (bool) ($contact['marketing_consent'] ?? false);

        return DB::transaction(function () use ($quote, $contact, $expectedVersion): Quote {
            Tenant::whereKey(app(TenantContext::class)->tenant()->id)->lockForUpdate()->firstOrFail();
            $quote = Quote::whereKey($quote->id)->with('currentRevision')->lockForUpdate()->firstOrFail();
            $revision = $quote->currentRevision;
            if ($quote->submitted_at && $revision && $revision->contact_snapshot === $contact) {
                return $quote;
            }
            if (! in_array($quote->status, ['quoted', 'requires_review'], true) || ! $revision || $revision->expires_at->isPast() || $quote->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages(['quote' => 'Refresh and calculate this quotation before submitting it.']);
            }
            // A submitted address is contact information, not evidence of verified email ownership.
            $customer = Customer::firstOrCreate(['email' => $contact['email']], ['name' => $contact['name'], 'phone' => $contact['phone'] ?? null]);
            $quote->customer_id = $customer->id;
            $revision = app(WriteQuoteRevision::class)->copy($quote, $revision, $contact);
            $quote->submitted_at = CarbonImmutable::now();
            $quote->save();
            OutboxMessage::create(['event_type' => $quote->status === 'quoted' ? 'quote.issued' : 'quote.review_requested', 'payload' => ['quote_public_id' => $quote->public_id, 'revision_public_id' => $revision->public_id]]);

            return $quote->fresh(['currentRevision']);
        });
    }
}
