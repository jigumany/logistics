<?php

use App\Domain\Pricing\CommercialConfiguration;
use App\Domain\Pricing\EstimateJobCost;
use App\Domain\Pricing\PricingEngine;

it('calculates contribution margin from net revenue rather than treating markup as margin', function (): void {
    $result = (new EstimateJobCost)->evaluate(commercialFixture(['fuel_rate_micros' => 0, 'vehicle_rate_micros' => 0, 'driver_rate_micros' => 0, 'mover_rate_micros' => 0, 'fixed_cost_minor' => 7500, 'overhead_minor' => 500, 'minimum_margin_bps' => 2500]), ['estimated_cost_hours' => '2'], ['customer_metres' => 10000, 'tolls_minor' => 0], 2, 10000);
    expect($result['direct_cost_minor'])->toBe(7500)->and($result['contribution_minor'])->toBe(2000)->and($result['margin_bps'])->toBe(2000)->and($result['below_minimum'])->toBeTrue();
});
it('counts one driver and only additional movers with explicit overtime premiums', function (): void {
    $result = (new EstimateJobCost)->evaluate(commercialFixture(['fuel_rate_micros' => 1000000, 'vehicle_rate_micros' => 2000000, 'driver_rate_micros' => 10000000, 'mover_rate_micros' => 5000000, 'standard_labour_hours' => 1, 'driver_overtime_rate_micros' => 2000000, 'mover_overtime_rate_micros' => 1000000, 'overhead_minor' => 100]), ['estimated_cost_hours' => '2'], ['customer_metres' => 10000, 'tolls_minor' => 50], 3, 10000);
    expect(array_column($result['lines'], 'amount_minor', 'code'))->toBe(['fuel' => 1000, 'vehicle' => 2000, 'driver' => 2200, 'movers' => 2200, 'tolls' => 50, 'direct_costs' => 0]);
    expect($result['total_cost_minor'])->toBe(7550)->and($result['margin_bps'])->toBe(2450);
});
it('does not invent operational distance or confirmed tolls for profitability', function (): void {
    $result = (new EstimateJobCost)->evaluate(commercialFixture(['cost_distance_basis' => 'operational', 'toll_policy' => 'actual']), ['estimated_cost_hours' => '2'], ['customer_metres' => 10000, 'tolls_minor' => null], 2, 10000);
    expect($result['status'])->toBe('incomplete');
});
it('treats zero and negative contributions conservatively', function (): void {
    $engine = new EstimateJobCost;
    $estimate = ['total_cost_minor' => 1000, 'minimum_margin_bps' => 0, 'target_margin_bps' => 1000];
    expect($engine->withRevenue($estimate, 0)['margin_bps'])->toBeNull()->and($engine->withRevenue($estimate, 500)['margin_bps'])->toBe(-10000)->and($engine->withRevenue($estimate, 500)['below_minimum'])->toBeTrue();
});
it('replaces general class fees rather than adding the same fee twice', function (): void {
    $rules = [['code' => 'base', 'metric' => 'base', 'stage' => 'charge'], ['code' => 'van_base', 'metric' => 'base', 'stage' => 'charge', 'vehicle_type_key' => 'van'], ['code' => 'tax', 'metric' => null, 'stage' => 'tax']];
    expect(array_column((new CommercialConfiguration)->applicableRules($rules, 'van'), 'code'))->toBe(['van_base', 'tax']);
});
it('applies an explicitly triggered weekend surcharge before discounts minimums and tax', function (): void {
    $rules = [['code' => 'base', 'label' => 'Transport', 'stage' => 'charge', 'kind' => 'fixed', 'metric' => 'base', 'rate_micros' => 100000000, 'position' => 0], ['code' => 'weekend', 'label' => 'Weekend premium', 'stage' => 'surcharge', 'kind' => 'percent', 'metric' => 'weekend', 'rate_micros' => 200000, 'position' => 0], ['code' => 'discount', 'label' => 'Discount', 'stage' => 'discount', 'kind' => 'percent', 'metric' => null, 'rate_micros' => 100000, 'position' => 0], ['code' => 'min', 'label' => 'Minimum', 'stage' => 'minimum', 'kind' => 'fixed', 'metric' => null, 'rate_micros' => 110000000, 'position' => 0], ['code' => 'tax', 'label' => 'Illustrative tax', 'stage' => 'tax', 'kind' => 'percent', 'metric' => null, 'rate_micros' => 150000, 'position' => 0]];
    $price = (new PricingEngine)->calculate('ZAR', $rules, ['base' => '1', 'weekend' => '1']);
    expect(array_column($price['lines'], 'amount_minor'))->toBe([10000, 2000, -1200, 200, 1650])->and($price['total_minor'])->toBe(12650);
    expect((new PricingEngine)->calculate('ZAR', $rules, ['base' => '1', 'weekend' => '0'])['subtotal_minor'])->toBe(11000);
});
