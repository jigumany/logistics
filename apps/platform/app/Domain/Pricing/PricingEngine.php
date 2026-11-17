<?php

namespace App\Domain\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class PricingEngine
{
    public const VERSION = '1';

    public function calculate(string $currency, array $rules, array $metrics, ?string $vehicleType = null): array
    {
        if (! in_array($currency, ['ZAR', 'USD', 'EUR', 'GBP'], true)) {
            throw new InvalidArgumentException('Select a supported currency with two minor-unit decimal places.');
        }
        $rules = (new PricingRules)->validate($rules);
        $subtotal = $tax = 0;
        $taxBase = null;
        $lines = [];
        // Precedence is contractual: charges, surcharges, sequential discounts, minimum top-up, then independent taxes on the final net subtotal.
        // Unit rates are ten-thousandths of a minor unit; percentage rates are millionths of one. Each displayed line rounds half up before totals are summed.
        foreach ($rules as $rule) {
            if (! empty($rule['vehicle_type_key']) && $rule['vehicle_type_key'] !== $vehicleType) {
                continue;
            }
            $quantity = BigDecimal::of('1');
            if ($rule['metric'] ?? null) {
                $raw = $metrics[$rule['metric']] ?? null;
                if ($raw === null || ! preg_match('/^\d{1,12}(\.\d{1,6})?$/D', (string) $raw)) {
                    throw new InvalidArgumentException('A configured pricing quantity is missing or invalid: '.$rule['metric']);
                }
                $metric = BigDecimal::of((string) $raw);
                if ($metric->isZero()) {
                    continue;
                }
                $quantity = $rule['kind'] === 'fixed' ? BigDecimal::of('1') : $metric;
            }
            $amount = $rule['kind'] === 'percent'
                ? BigDecimal::of($rule['stage'] === 'tax' ? ($taxBase ??= $subtotal) : $subtotal)->multipliedBy($rule['rate_micros'])->dividedBy('1000000', 0, RoundingMode::HalfUp)->toInt()
                : $quantity->multipliedBy($rule['rate_micros'])->dividedBy('10000', 0, RoundingMode::HalfUp)->toInt();
            if ($rule['stage'] === 'minimum') {
                $amount = max(0, $amount - $subtotal);
            }
            if ($rule['stage'] === 'discount') {
                if ($amount > $subtotal) {
                    throw new InvalidArgumentException('A discount cannot exceed the preceding subtotal.');
                }
                $amount = -$amount;
            }
            if ($rule['stage'] === 'tax') {
                $tax += $amount;
            } else {
                $subtotal += $amount;
            }
            if ($subtotal + $tax > 1000000000000) {
                throw new InvalidArgumentException('The calculated amount exceeds the supported financial limit.');
            }
            $lines[] = ['code' => $rule['code'], 'description' => $rule['label'], 'stage' => $rule['stage'], 'quantity' => (string) $quantity->toScale(6), 'rate_micros' => $rule['rate_micros'], 'amount_minor' => $amount, 'position' => count($lines)];
        }

        return ['engine_version' => self::VERSION, 'currency' => $currency, 'currency_exponent' => 2, 'subtotal_minor' => $subtotal, 'tax_minor' => $tax, 'total_minor' => $subtotal + $tax, 'lines' => $lines, 'metrics' => $metrics, 'rounding' => 'half_up_per_line'];
    }
}
