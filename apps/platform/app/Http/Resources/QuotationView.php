<?php

namespace App\Http\Resources;

use App\Domain\Bookings\Booking;
use App\Domain\Pricing\DescribePriceLine;
use App\Domain\Quotes\GuestQuoteLinks;
use App\Domain\Quotes\Quote;
use App\Domain\Quotes\QuoteAttachment;
use App\Domain\Quotes\QuoteReviewDecision;
use App\Domain\Quotes\QuoteRevision;
use App\Domain\Routing\RouteCalculation;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\URL;

final class QuotationView
{
    public function make(Quote $quote, bool $internal = false): array
    {
        $quote->loadMissing(['currentRevision.lines', 'currentRevision.inventory', 'currentRevision.stops']);
        $revision = $quote->currentRevision;
        $data = [...$quote->only('public_id', 'reference', 'status', 'lock_version', 'preferred_date', 'submitted_at', 'accepted_at'), 'revision' => $revision ? $this->revision($revision, $internal) : null, 'links' => app(GuestQuoteLinks::class)->forQuote($quote), 'booking_confirmed' => $quote->status === 'accepted' && Booking::where('quote_id', $quote->id)->where('status', 'confirmed')->exists()];
        $data['attachments'] = QuoteAttachment::where('quote_id', $quote->id)->get()->map(fn ($file) => ['public_id' => $file->public_id, 'item_key' => $file->item_key, 'url' => URL::temporarySignedRoute('quote.guest.attachment', $quote->access_expires_at, ['company' => app(TenantContext::class)->tenant()->slug, 'quote' => $quote->public_id, 'attachment' => $file->public_id])]);
        $request = QuoteReviewDecision::where('quote_id', $quote->id)->where('revision_id', $quote->current_revision_id)->where('decision', 'request_information')->orderByDesc('id')->first();
        $data['information_request'] = $request?->reason;
        if ($internal) {
            $data['input'] = $quote->draft_input;
            $route = $revision?->route_calculation_id ? RouteCalculation::whereKey($revision->route_calculation_id)->first() : null;
            $data['route'] = $route && (! $route->retain_until || $route->retain_until->isFuture()) ? $route->only('provider', 'status', 'failure_code', 'customer_metres', 'depot_metres', 'return_metres', 'duration_seconds') : null;
            $data['reviews'] = QuoteReviewDecision::where('quote_id', $quote->id)->orderByDesc('id')->paginate(20, ['*'], 'reviews_page')->withQueryString()->through(fn ($decision) => $decision->only('public_id', 'decision', 'reason', 'adjustment', 'created_at'));
        }

        return $data;
    }

    public function revision(QuoteRevision $revision, bool $internal = false): array
    {
        $revision->loadMissing(['lines', 'inventory', 'stops']);
        $data = $revision->only('public_id', 'number', 'approval', 'service_name', 'currency', 'subtotal_minor', 'tax_minor', 'total_minor', 'terms', 'exclusions', 'expires_at', 'contact_snapshot', 'inventory_summary');
        if ($data['contact_snapshot'] === []) {
            $data['contact_snapshot'] = new \stdClass;
        }
        $data['terms_version'] = $revision->terms_snapshot['version'] ?? 'legacy';
        $data['expired'] = $revision->expires_at->isPast();
        $rules = collect($revision->pricing_snapshot['rules'] ?? [])->keyBy('code');
        $data['lines'] = $revision->lines->map(fn ($line) => [...$line->only('code', 'description', 'stage', 'quantity', 'rate_micros', 'amount_minor'), ...app(DescribePriceLine::class)->execute($rules->get($line->code, ['rate_micros' => $line->rate_micros]), $revision->currency ?? '')])->all();
        $data['distance_basis'] = $revision->pricing_snapshot['distance_basis'] ?? null;
        $data['move_details'] = $revision->input['details'];
        $data['preferred_time'] = $revision->input['preferred_time'] ?? null;
        $data['inventory'] = $revision->inventory->map(fn ($item) => $item->only('item_key', 'public_id', 'name', 'quantity', 'length_cm', 'width_cm', 'height_cm', 'weight_kg', 'volume_m3', 'measurement_source', 'fragile', 'oversized', 'stackable', 'rotation_allowed', 'instructions', 'photos', 'provenance'))->all();
        $data['stops'] = $revision->stops->map(fn ($stop) => $stop->only('position', 'kind', 'address_line', 'suburb', 'city', 'postal_code', 'country_code', 'instructions', 'verification'))->all();
        $data['review_reasons'] = array_map(fn ($reason) => $internal ? $reason : ['code' => in_array($reason['code'], ['below_minimum_margin', 'cost_estimate_incomplete', 'commercial_readiness'], true) ? 'operations_review' : $reason['code'], 'customer_message' => $reason['customer_message']], $internal ? $revision->review_reasons : array_values(array_filter($revision->review_reasons, fn ($reason) => empty($reason['resolved']))));
        $data['pricing_method'] = $revision->total_minor === null ? 'Awaiting an operations price review.' : (($revision->pricing_snapshot['manual_override'] ?? false) ? 'Prepared by the operations team.' : (($revision->pricing_snapshot['is_demonstration'] ?? false) ? 'Internal simulation using unapproved demonstration rates.' : 'Calculated estimate using the published price book.'));
        if ($internal) {
            $data['coverage'] = $revision->coverage;
            $data['suitability'] = $revision->suitability;
            $data['pricing_snapshot'] = $revision->pricing_snapshot;
            $data['input'] = $revision->input;
        }

        return $data;
    }
}
