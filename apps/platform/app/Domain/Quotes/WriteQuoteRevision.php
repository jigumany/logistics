<?php

namespace App\Domain\Quotes;

use App\Domain\Inventory\QuoteInventory;
use App\Domain\Pricing\PricingCalculation;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Routing\RouteStop;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;
use Carbon\CarbonImmutable;

final class WriteQuoteRevision
{
    public function execute(Quote $quote, array $result, array $contact = []): QuoteRevision
    {
        $pricing = $result['pricing'] ?? null;
        $inventory = $result['inventory'];
        $summary = $inventory;
        unset($summary['items']);
        $input = $this->snapshotInput($quote->draft_input);
        $revision = QuoteRevision::create(['quote_id' => $quote->id, 'number' => (int) QuoteRevision::where('quote_id', $quote->id)->max('number') + 1, 'price_book_id' => $result['price_book_id'] ?? null, 'route_calculation_id' => $result['route_calculation_id'] ?? null, 'approval' => $result['approval'], 'service_name' => $result['service_name'] ?? TenantService::whereKey($quote->service_id)->value('name'), 'branding_snapshot' => $result['branding_snapshot'] ?? CompanySettings::firstOrFail()->only('display_name', 'registered_name', 'operating_address', 'contact_email', 'contact_phone', 'timezone', 'vat_number'), 'input' => $input, 'inventory_summary' => $summary, 'coverage' => $result['coverage'], 'suitability' => $result['suitability'], 'review_reasons' => $result['review_reasons'], 'pricing_snapshot' => $result['pricing_snapshot'] ?? [], 'contact_snapshot' => $contact, 'currency' => $pricing['currency'] ?? null, 'subtotal_minor' => $pricing['subtotal_minor'] ?? null, 'tax_minor' => $pricing['tax_minor'] ?? null, 'total_minor' => $pricing['total_minor'] ?? null, 'terms' => $result['terms'], 'exclusions' => $result['exclusions'], 'expires_at' => $result['expires_at']]);
        foreach ($inventory['items'] as $item) {
            QuoteInventory::create([...array_intersect_key($item, array_flip(['item_key', 'catalogue_public_id', 'name', 'quantity', 'length_cm', 'width_cm', 'height_cm', 'weight_kg', 'volume_m3', 'measurement_source', 'fragile', 'oversized', 'stackable', 'rotation_allowed', 'instructions', 'photos', 'provenance'])), 'revision_id' => $revision->id]);
        }
        foreach ($input['stops'] as $position => $stop) {
            RouteStop::create([...array_intersect_key($stop, array_flip(['address_line', 'suburb', 'city', 'postal_code', 'country_code', 'latitude', 'longitude', 'provider_place_id', 'verification', 'instructions'])), 'revision_id' => $revision->id, 'position' => $position, 'kind' => $position === 0 ? 'pickup' : ($position === array_key_last($input['stops']) ? 'delivery' : 'waypoint')]);
        }
        if ($pricing) {
            foreach ($pricing['lines'] as $line) {
                QuoteLineItem::create([...$line, 'revision_id' => $revision->id]);
            }
            PricingCalculation::create(['revision_id' => $revision->id, 'metrics' => $pricing['metrics'], 'configuration' => $result['pricing_snapshot'] ?? [], 'engine_version' => PricingEngine::VERSION]);
        }
        $revision->sealed_at = CarbonImmutable::now();
        $revision->save();
        $quote->current_revision_id = $revision->id;
        $quote->lock_version++;
        $quote->save();

        return $revision;
    }

    public function copy(Quote $quote, QuoteRevision $previous, array $contact, array $changes = []): QuoteRevision
    {
        $previous->loadMissing(['inventory', 'lines']);
        $result = ['inventory' => [...$previous->inventory_summary, 'items' => $previous->inventory->map(fn ($item) => [...$item->getAttributes(), 'photos' => $item->photos, 'provenance' => $item->provenance])->all()], 'service_name' => $previous->service_name, 'branding_snapshot' => $previous->branding_snapshot, 'coverage' => $previous->coverage, 'suitability' => $previous->suitability, 'review_reasons' => $previous->review_reasons, 'approval' => $previous->approval, 'price_book_id' => $previous->price_book_id, 'route_calculation_id' => $previous->route_calculation_id, 'pricing_snapshot' => $previous->pricing_snapshot, 'terms' => $previous->terms, 'exclusions' => $previous->exclusions, 'expires_at' => $previous->expires_at, 'pricing' => $previous->total_minor === null ? null : ['currency' => $previous->currency, 'subtotal_minor' => $previous->subtotal_minor, 'tax_minor' => $previous->tax_minor, 'total_minor' => $previous->total_minor, 'lines' => $previous->lines->map(fn ($line) => array_intersect_key($line->getAttributes(), array_flip(['code', 'description', 'stage', 'quantity', 'rate_micros', 'amount_minor', 'position'])))->all(), 'metrics' => $previous->pricing_snapshot['metrics'] ?? []]];

        return $this->execute($quote, [...$result, ...$changes], $contact);
    }

    public function snapshotInput(array $input): array
    {
        // Immutable commercial snapshots retain customer-entered addresses and place IDs, not provider geocoding responses.
        foreach ($input['stops'] as &$stop) {
            unset($stop['verification_proof']);
            if (($stop['verification'] ?? '') === 'provider') {
                $stop['latitude'] = $stop['longitude'] = null;
            }
        }
        unset($stop);

        return $input;
    }
}
