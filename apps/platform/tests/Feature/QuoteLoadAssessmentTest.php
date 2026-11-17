<?php

use App\Domain\Fleet\AssessQuotationLoad;
use App\Domain\Fleet\Vehicle;
use App\Domain\Inventory\InventoryCalculator;

function quotationLoad(array $changes = []): array
{
    $item = ['item_key' => '00000000-0000-4000-8000-000000000001', 'name' => 'Measured load', 'quantity' => 1, 'length_cm' => '100', 'width_cm' => '50', 'height_cm' => '40', 'weight_kg' => '20', 'measurement_source' => 'measured', 'fragile' => false, 'oversized' => false, 'stackable' => true, 'rotation_allowed' => true, 'instructions' => null, 'photos' => []];

    return (new InventoryCalculator)->calculate([[...$item, ...$changes]]);
}
it('uses actual vehicle volume payload and largest-item limits', function (array $changes, string $reason): void {
    quoteFixture();
    $vehicle = Vehicle::with('type')->firstOrFail();
    $result = app(AssessQuotationLoad::class)->evaluate($vehicle, quotationLoad($changes));
    expect($result['status'])->toBe('unsuitable')->and(array_column($result['reasons'], 'code'))->toContain($reason);
})->with([
    [['quantity' => 46], 'capacity_exceeded'],
    [['weight_kg' => '901'], 'capacity_exceeded'],
    [['length_cm' => '310'], 'item_does_not_fit'],
]);
it('classifies uncertain weights and missing dimensions as review rather than a guarantee', function (): void {
    quoteFixture();
    $vehicle = Vehicle::with('type')->firstOrFail();
    expect(app(AssessQuotationLoad::class)->evaluate($vehicle, quotationLoad(['weight_kg' => null]))['status'])->toBe('requires_review');
    $unknown = quotationLoad(['length_cm' => null, 'width_cm' => null, 'height_cm' => null, 'volume_m3' => '0.2']);
    expect(array_column(app(AssessQuotationLoad::class)->evaluate($vehicle, $unknown)['reasons'], 'code'))->toContain('unknown_dimensions');
});
it('respects fixed orientation and complex non-stackable loads', function (): void {
    quoteFixture();
    $vehicle = Vehicle::with('type')->firstOrFail();
    $rotated = quotationLoad(['length_cm' => '50', 'width_cm' => '250', 'height_cm' => '50']);
    expect(app(AssessQuotationLoad::class)->evaluate($vehicle, $rotated)['technically_fits'])->toBeTrue();
    $fixed = quotationLoad(['length_cm' => '50', 'width_cm' => '250', 'height_cm' => '50', 'rotation_allowed' => false]);
    expect(app(AssessQuotationLoad::class)->evaluate($vehicle, $fixed)['status'])->toBe('unsuitable');
    expect(app(AssessQuotationLoad::class)->evaluate($vehicle, quotationLoad(['stackable' => false]))['status'])->toBe('requires_review');
});
it('flags a borderline load without reserving the vehicle', function (): void {
    quoteFixture();
    $vehicle = Vehicle::with('type')->firstOrFail();
    $load = quotationLoad(['quantity' => 40]);
    $result = app(AssessQuotationLoad::class)->evaluate($vehicle, $load, 8500);
    expect($result['status'])->toBe('requires_review')->and(array_column($result['reasons'], 'code'))->toContain('borderline_capacity');
});
it('does not promote catalogue estimates without the tenant confidence policy', function (): void {
    quoteFixture();
    $vehicle = Vehicle::with('type')->firstOrFail();
    $load = quotationLoad(['measurement_source' => 'catalogue']);
    expect(app(AssessQuotationLoad::class)->evaluate($vehicle, $load)['status'])->toBe('requires_review');
    expect(app(AssessQuotationLoad::class)->evaluate($vehicle, $load, 9000, ['catalogue'])['status'])->toBe('suitable');
});
