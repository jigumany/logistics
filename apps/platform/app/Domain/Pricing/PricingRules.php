<?php

namespace App\Domain\Pricing;

use InvalidArgumentException;

final class PricingRules
{
    public const METRICS = ['base', 'chargeable_km', 'vehicle_category', 'labour_hours', 'loading_hours', 'unloading_hours', 'stairs_flights', 'carry_metres', 'additional_stops', 'waiting_hours', 'after_hours', 'weekend', 'holiday', 'packing_items', 'special_handling_items', 'tolls'];

    public const COMMERCIAL_METRICS = ['driver_hours', 'mover_hours', 'overtime_hours', 'difficult_access', 'oversized_items'];

    public const ALL_METRICS = [...self::METRICS, ...self::COMMERCIAL_METRICS];

    public const STAGES = ['charge' => 0, 'surcharge' => 1, 'discount' => 2, 'minimum' => 3, 'tax' => 4];

    public function validate(array $rules): array
    {
        if ($rules === [] || count($rules) > 100) {
            throw new InvalidArgumentException('A price book requires 1–100 rules.');
        }
        $codes = [];
        $minimums = [];
        foreach ($rules as $rule) {
            if (! preg_match('/^[a-z][a-z0-9_]{0,59}$/D', $rule['code'] ?? '') || isset($codes[$rule['code']]) || empty($rule['label']) || strlen($rule['label']) > 160) {
                throw new InvalidArgumentException('Rules need unique machine-readable codes and clear labels.');
            }
            $codes[$rule['code']] = true;
            if (! isset(self::STAGES[$rule['stage'] ?? '']) || ! in_array($rule['kind'] ?? '', ['fixed', 'rate', 'percent'], true) || ! is_int($rule['rate_micros'] ?? null) || $rule['rate_micros'] < 0 || $rule['rate_micros'] > 1000000000000000 || ! is_int($rule['position'] ?? null) || $rule['position'] < 0 || $rule['position'] > 1000) {
                throw new InvalidArgumentException('Invalid pricing stage, kind, precision or ordering.');
            }
            if (in_array($rule['stage'], ['charge', 'surcharge'], true)) {
                if (! in_array($rule['metric'] ?? '', self::ALL_METRICS, true) || ($rule['kind'] === 'percent' && ! ($rule['stage'] === 'surcharge' && in_array($rule['metric'], ['weekend', 'holiday', 'after_hours', 'difficult_access'], true)))) {
                    throw new InvalidArgumentException('Charges require a known quantity metric and a fixed or unit rate.');
                }
            } elseif (! empty($rule['metric']) || (! empty($rule['vehicle_type_key']) && $rule['stage'] !== 'minimum')) {
                throw new InvalidArgumentException('Discounts, minimums and taxes apply to the complete subtotal.');
            }
            if ($rule['stage'] === 'minimum' && ($rule['kind'] !== 'fixed' || isset($minimums[$rule['vehicle_type_key'] ?? '*']))) {
                throw new InvalidArgumentException('Use one fixed minimum charge.');
            }
            if ($rule['stage'] === 'minimum') {
                $minimums[$rule['vehicle_type_key'] ?? '*'] = true;
            }
            if ($rule['stage'] === 'tax' && $rule['kind'] !== 'percent') {
                throw new InvalidArgumentException('Taxes require an explicit percentage.');
            }
            if ($rule['stage'] === 'discount' && $rule['kind'] === 'rate') {
                throw new InvalidArgumentException('Discounts must be fixed or percentage based.');
            }
            if ($rule['kind'] === 'percent' && $rule['rate_micros'] > 1000000) {
                throw new InvalidArgumentException('Percentages must be between zero and 100 percent.');
            }
        }
        usort($rules, fn ($a, $b) => [self::STAGES[$a['stage']], $a['position'], $a['code']] <=> [self::STAGES[$b['stage']], $b['position'], $b['code']]);

        return $rules;
    }
}
