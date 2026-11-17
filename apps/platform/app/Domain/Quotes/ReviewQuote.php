<?php

namespace App\Domain\Quotes;

use App\Domain\Identity\Membership;
use App\Domain\Notifications\OutboxMessage;
use App\Domain\Pricing\EstimateJobCost;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Tenancy\AuditEntry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReviewQuote
{
    public function execute(Quote $quote, array $input, int $actorId): Quote
    {
        app(QuotePermissions::class)->manage($actorId);
        $data = Validator::make($input, [
            'lock_version' => ['required', 'integer', 'min:0'],
            'decision' => ['required', 'in:note,approve,reject,request_information,revise'],
            'reason' => ['required', 'string', 'min:3', 'max:5000'],
            'currency' => ['required_if:decision,approve,revise', 'in:ZAR,USD,EUR,GBP'],
            'lines' => ['required_if:decision,approve,revise', 'array', 'min:1', 'max:50'],
            'lines.*' => ['array:description,amount_minor'],
            'lines.*.description' => ['required', 'string', 'max:160'],
            'lines.*.amount_minor' => ['required', 'integer', 'between:-100000000000,100000000000'],
            'tax_basis_points' => ['required_if:decision,approve,revise', 'integer', 'between:0,10000'],
            'expires_at' => ['required_if:decision,approve,revise', 'date', 'after:now', 'before:'.now()->addDays(30)->toIso8601String()],
            'terms' => ['required_if:decision,approve,revise', 'string', 'max:10000'],
            'exclusions' => ['present_if:decision,approve,revise', 'nullable', 'string', 'max:10000'],
        ])->validate();

        return DB::transaction(function () use ($quote, $data, $actorId): Quote {
            $quote = Quote::whereKey($quote->id)->with('currentRevision')->lockForUpdate()->firstOrFail();
            $revision = $quote->currentRevision;
            $previousTotal = $revision?->total_minor;
            $previousCurrency = $revision?->currency;
            if ($quote->status === 'accepted' || $quote->lock_version !== $data['lock_version'] || $quote->status === 'calculating' || ! $revision) {
                throw ValidationException::withMessages(['quote' => 'This quotation changed or cannot be reviewed. Refresh before retrying.']);
            }
            if (in_array($data['decision'], ['approve', 'revise'], true)) {
                if (! in_array($quote->status, ['requires_review', 'quoted', 'expired'], true) || ! $quote->submitted_at || ($revision->coverage['outcome'] ?? '') === 'outside_supported_area') {
                    throw ValidationException::withMessages(['quote' => 'A submitted, serviceable request is required before issuing an approved offer.']);
                }
                $commercial = $revision->pricing_snapshot['configuration']['commercial'] ?? null;
                if ($commercial) {
                    $role = Membership::where('tenant_id', $quote->tenant_id)->where('user_id', $actorId)->firstOrFail()->role->value;
                    if (! in_array($role, $commercial['override_roles'], true)) {
                        throw new AuthorizationException('This price book does not authorize your role to override prices.');
                    }
                    if ($commercial['tax_reviewed'] && ($data['tax_basis_points'] !== $commercial['tax_basis_points'])) {
                        throw ValidationException::withMessages(['tax_basis_points' => 'The override must preserve the published tax policy. Publish corrected tax configuration before recalculating.']);
                    }
                }
                $rules = [];
                foreach ($data['lines'] as $index => $line) {
                    $rules[] = ['code' => 'manual_'.($index + 1), 'label' => $line['description'], 'stage' => $line['amount_minor'] < 0 ? 'discount' : 'charge', 'kind' => 'fixed', 'metric' => $line['amount_minor'] < 0 ? null : 'base', 'rate_micros' => abs($line['amount_minor']) * 10000, 'position' => $index];
                }
                if ($data['tax_basis_points'] > 0) {
                    $rules[] = ['code' => 'manual_tax', 'label' => 'Tax ('.($data['tax_basis_points'] / 100).'%)', 'stage' => 'tax', 'kind' => 'percent', 'metric' => null, 'rate_micros' => $data['tax_basis_points'] * 100, 'position' => 0];
                }
                $pricing = app(PricingEngine::class)->calculate($data['currency'], $rules, ['base' => '1']);
                $oldCosts = $revision->pricing_snapshot['internal_costs'] ?? ['status' => 'incomplete'];
                $costs = $data['currency'] === $revision->currency && ($oldCosts['status'] ?? '') === 'estimated' ? app(EstimateJobCost::class)->withRevenue($oldCosts, $pricing['subtotal_minor']) : ['status' => 'incomplete', 'reason' => 'Manual pricing has no comparable cost estimate in this currency.'];
                $termsSnapshot = app(QuotationTerms::class)->record($data['terms'], $data['exclusions'] ?? '', (string) Str::ulid());
                $changes = ['terms_snapshot' => $termsSnapshot, 'price_book_id' => null, 'approval' => 'approved', 'review_reasons' => array_map(fn ($reason) => [...$reason, 'resolved' => true], $revision->review_reasons), 'pricing' => $pricing, 'pricing_snapshot' => ['configuration' => $revision->pricing_snapshot['configuration'] ?? [], 'internal_costs' => $costs, 'source_price_book' => array_intersect_key($revision->pricing_snapshot, array_flip(['price_book_public_id', 'version'])), 'schema_version' => 1, 'manual_override' => true, 'reason' => $data['reason'], 'actor_id' => $actorId, 'previous_revision_public_id' => $revision->public_id, 'rules' => $rules, 'metrics' => ['base' => '1']], 'terms' => $data['terms'], 'exclusions' => $data['exclusions'] ?? '', 'expires_at' => $data['expires_at']];
                if ($quote->status !== 'requires_review') {
                    app(QuoteLifecycle::class)->transition($quote, QuoteStatus::Calculating, $actorId);
                }
                $revision = app(WriteQuoteRevision::class)->copy($quote, $revision, $revision->contact_snapshot, $changes);
                app(QuoteLifecycle::class)->transition($quote, QuoteStatus::Quoted, $actorId);
                OutboxMessage::create(['event_type' => 'quote.revised', 'payload' => ['quote_public_id' => $quote->public_id, 'revision_public_id' => $revision->public_id]]);
            } elseif ($data['decision'] === 'reject') {
                app(QuoteLifecycle::class)->transition($quote, QuoteStatus::Declined, $actorId);
            } elseif ($data['decision'] === 'request_information') {
                if ($quote->status === 'quoted') {
                    app(QuoteLifecycle::class)->transition($quote, QuoteStatus::Calculating, $actorId);
                    app(QuoteLifecycle::class)->transition($quote, QuoteStatus::RequiresReview, $actorId);
                }
                if ($quote->status !== 'requires_review') {
                    throw ValidationException::withMessages(['quote' => 'This quotation cannot request more information.']);
                }
                $revision = app(WriteQuoteRevision::class)->copy($quote, $revision, $revision->contact_snapshot, ['approval' => 'pending', 'review_reasons' => [...$revision->review_reasons, ['code' => 'additional_information', 'customer_message' => $data['reason'], 'internal_details' => []]]]);
                OutboxMessage::create(['event_type' => 'quote.information_requested', 'payload' => ['quote_public_id' => $quote->public_id, 'revision_public_id' => $revision->public_id, 'customer_message' => $data['reason']]]);
            }
            $quote->lock_version++;
            $quote->save();
            QuoteReviewDecision::create(['quote_id' => $quote->id, 'revision_id' => $revision->id, 'actor_id' => $actorId, 'decision' => $data['decision'], 'reason' => $data['reason'], 'adjustment' => in_array($data['decision'], ['approve', 'revise'], true) ? ['previous_total_minor' => $previousTotal, 'previous_currency' => $previousCurrency, 'total_minor' => $revision->total_minor, 'currency' => $revision->currency] : null]);
            $audit = new AuditEntry(['actor_id' => $actorId, 'action' => 'quote.'.$data['decision'], 'subject_public_id' => $quote->public_id]);
            $audit->setAttribute('details', ['revision_public_id' => $revision->public_id, 'reason' => $data['reason'], 'previous_total_minor' => $previousTotal, 'previous_currency' => $previousCurrency, 'total_minor' => $revision->total_minor, 'currency' => $revision->currency]);
            $audit->save();

            return $quote->fresh(['currentRevision']);
        });
    }
}
