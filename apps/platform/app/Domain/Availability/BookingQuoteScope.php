<?php

namespace App\Domain\Availability;

use App\Domain\Bookings\BookingConflict;
use App\Domain\Inventory\InventoryCalculator;
use App\Domain\Quotes\Quote;
use App\Domain\Quotes\QuoteRevision;
use Carbon\CarbonImmutable;

final class BookingQuoteScope
{
    public function resolve(Quote $quote): array
    {
        $quote = Quote::whereKey($quote->id)->with(['currentRevision.inventory'])->firstOrFail();
        $revision = $quote->currentRevision;
        if ($quote->status !== 'accepted' || ! $quote->accepted_at || ! $quote->customer_id || $quote->simulation_price_book_id !== null || ! $revision || ! $revision->sealed_at || $revision->expires_at->isPast() || $revision->total_minor === null || ! in_array($revision->approval, ['automatic', 'approved'], true)) {
            throw BookingConflict::because('accepted_quote_required', 'A current, accepted and unexpired quotation is required.');
        }
        $items = $revision->inventory->map(fn ($item) => $item->toArray())->all();
        $inventory = app(InventoryCalculator::class)->calculate($items, $revision->inventory_summary['safety_margin_bps'] ?? 0);
        if ($inventory['unknown_weight_quantity'] || $inventory['unknown_volume_quantity'] || count(array_filter($items, fn ($item) => $item['length_cm'] === null))) {
            throw BookingConflict::because('load_verification_required', 'Confirm all item dimensions and weights with operations before reserving resources.');
        }
        $type = $revision->pricing_snapshot['screened_vehicle_type'] ?? collect($revision->suitability)->firstWhere('technically_fits', true)['vehicle_type_key'] ?? null;
        if (! $type) {
            throw BookingConflict::because('vehicle_requirement_unknown', 'Operations must confirm the required vehicle class.');
        }
        $config = $revision->pricing_snapshot['configuration'] ?? [];
        if (! isset($config['crew_size'])) {
            throw BookingConflict::because('crew_requirement_unknown', 'Operations must confirm the required crew.');
        }

        return ['quote' => $quote, 'revision' => $revision, 'inventory' => $inventory, 'vehicle_type_key' => $type, 'movers' => max(0, (int) $config['crew_size'] - 1)];
    }

    public function priceNeutral(QuoteRevision $revision, CarbonImmutable $start, string $timezone): void
    {
        $config = $revision->pricing_snapshot['configuration'] ?? [];
        $metrics = array_column($revision->pricing_snapshot['rules'] ?? [], 'metric');
        $old = CarbonImmutable::parse($revision->input['preferred_date'], $timezone);
        $new = $start->setTimezone($timezone);
        $weekends = $config['weekend_days'] ?? [0, 6];
        $holidays = $config['holiday_dates'] ?? [];
        $changed = (in_array('weekend', $metrics, true) && in_array($old->dayOfWeek, $weekends, true) !== in_array($new->dayOfWeek, $weekends, true)) || (in_array('holiday', $metrics, true) && in_array($old->toDateString(), $holidays, true) !== in_array($new->toDateString(), $holidays, true));
        if (in_array('after_hours', $metrics, true)) {
            $outside = fn ($time) => $time === null || $time < ($config['business_opens'] ?? '08:00') || $time >= ($config['business_closes'] ?? '17:00');
            $changed = $changed || $outside($revision->input['preferred_time'] ?? null) !== $outside($new->format('H:i'));
        }
        if ($changed) {
            throw BookingConflict::because('quote_amendment_required','This time changes a priced condition. Operations must issue and obtain acceptance of an amended quotation first.');
        }
    }
}
