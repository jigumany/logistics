<?php

namespace App\Domain\Pricing;

final class DescribePriceLine
{
    public function execute(array $rule, string $currency): array
    {
        $kind = $rule['kind'] ?? 'fixed';
        $metric = $rule['metric'] ?? null;
        $unit = match ($metric) {
            'chargeable_km' => 'km','labour_hours','loading_hours','unloading_hours','waiting_hours' => 'hours','stairs_flights' => 'flights','carry_metres' => 'metres','additional_stops' => 'stops','packing_items','special_handling_items' => 'items','tolls' => 'minor units',default => 'charge'
        };
        $scale = $kind === 'percent' ? 4 : 6;
        $digits = str_pad((string) ($rule['rate_micros'] ?? 0), $scale + 1, '0', STR_PAD_LEFT);
        $rate = rtrim(rtrim(substr($digits, 0, -$scale).'.'.substr($digits, -$scale), '0'), '.');
        $display = $kind === 'percent' ? $rate.'%' : $currency.' '.$rate.($kind === 'rate' ? ' / '.$unit : '');
        if (($rule['stage'] ?? '') === 'minimum') {
            $display = 'Minimum '.$display;
        }

        return ['rate_kind' => $kind, 'quantity_unit' => $unit, 'rate_display' => $display];
    }
}
