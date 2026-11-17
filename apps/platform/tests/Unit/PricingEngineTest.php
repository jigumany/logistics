<?php

use App\Domain\Pricing\PricingEngine;

function priceRule(string $code, string $stage, string $kind, int $rate, ?string $metric = null, int $position = 0): array
{
    return ['code' => $code, 'label' => ucfirst($code), 'stage' => $stage, 'kind' => $kind, 'rate_micros' => $rate, 'metric' => $metric, 'position' => $position];
}

it('prices a base fee distance and labour using integer minor-unit totals', function (): void {
    $result = (new PricingEngine)->calculate('ZAR', [priceRule('base', 'charge', 'fixed', 1000000, 'base'), priceRule('distance', 'charge', 'rate', 123450, 'chargeable_km', 1), priceRule('labour', 'charge', 'rate', 100000, 'labour_hours', 2)], ['base' => '1', 'chargeable_km' => '12.345', 'labour_hours' => '1.5']);
    expect($result['lines'][1]['amount_minor'])->toBe(152)->and($result['total_minor'])->toBe(267)->and($result['currency'])->toBe('ZAR');
});
it('applies surcharges discounts minimum topups and taxes in deterministic order', function (): void {
    $rules = [priceRule('tax', 'tax', 'percent', 150000), priceRule('minimum', 'minimum', 'fixed', 1200000), priceRule('discount', 'discount', 'percent', 100000), priceRule('stairs', 'surcharge', 'rate', 200000, 'stairs_flights'), priceRule('base', 'charge', 'fixed', 1000000, 'base')];
    $result = (new PricingEngine)->calculate('ZAR', $rules, ['base' => '1', 'stairs_flights' => '1']);
    expect(array_column($result['lines'], 'amount_minor'))->toBe([100, 20, -12, 12, 18])->and($result['subtotal_minor'])->toBe(120)->and($result['tax_minor'])->toBe(18)->and($result['total_minor'])->toBe(138);
});
it('rounds each displayed line half up and reconciles totals', function (): void {
    $result = (new PricingEngine)->calculate('USD', [priceRule('one', 'charge', 'rate', 5000, 'base'), priceRule('two', 'charge', 'rate', 5000, 'base', 1)], ['base' => '1']);
    expect($result['total_minor'])->toBe(2)->and(array_sum(array_column($result['lines'], 'amount_minor')))->toBe(2);
});
it('applies independent taxes to the net subtotal without taxing previous taxes', function (): void {
    $result = (new PricingEngine)->calculate('GBP', [priceRule('base', 'charge', 'fixed', 1000000, 'base'), priceRule('tax_a', 'tax', 'percent', 100000), priceRule('tax_b', 'tax', 'percent', 50000, null, 1)], ['base' => '1']);
    expect($result['tax_minor'])->toBe(15)->and($result['total_minor'])->toBe(115);
});
it('filters vehicle category rules without charging a different category', function (): void {
    $rule = [...priceRule('heavy', 'charge', 'fixed', 1000000, 'vehicle_category'), 'vehicle_type_key' => 'heavy'];
    expect((new PricingEngine)->calculate('ZAR', [$rule], ['vehicle_category' => '1'], 'light')['total_minor'])->toBe(0);
});
it('rejects incomplete quantities rather than silently charging zero', function (): void {
    expect(fn () => (new PricingEngine)->calculate('ZAR', [priceRule('distance', 'charge', 'rate', 10000, 'chargeable_km')], []))->toThrow(InvalidArgumentException::class);
});
it('rejects ambiguous financial configurations', function (array $rules): void {
    expect(fn () => (new PricingEngine)->calculate('ZAR', $rules, ['base' => '1']))->toThrow(InvalidArgumentException::class);
})->with([
    [[priceRule('same', 'charge', 'fixed', 10000, 'base'), priceRule('same', 'charge', 'fixed', 10000, 'base')]],
    [[priceRule('tax', 'tax', 'fixed', 10000)]],
    [[priceRule('discount', 'discount', 'percent', 1000001)]],
    [[priceRule('negative', 'charge', 'fixed', -1, 'base')]],
    [[priceRule('discount', 'discount', 'fixed', 1000000)]],
]);
it('does not assume a business is VAT registered', function (): void {
    $result = (new PricingEngine)->calculate('ZAR', [priceRule('base', 'charge', 'fixed', 1000000, 'base')], ['base' => '1']);
    expect($result['tax_minor'])->toBe(0);
});
