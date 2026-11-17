<?php

use App\Domain\Inventory\InventoryCalculator;

function measuredInventoryItem(array $changes = []): array
{
    return [...['item_key' => '00000000-0000-4000-8000-000000000001', 'name' => 'Crate', 'quantity' => 2, 'length_cm' => '100', 'width_cm' => '50', 'height_cm' => '40', 'weight_kg' => '12.5', 'measurement_source' => 'measured', 'fragile' => false, 'oversized' => false, 'stackable' => true, 'rotation_allowed' => true, 'instructions' => null, 'photos' => []], ...$changes];
}

it('converts centimetres to cubic metres and aggregates quantities without binary rounding', function (): void {
    $result = (new InventoryCalculator)->calculate([measuredInventoryItem(), measuredInventoryItem(['quantity' => 1])]);
    expect($result['volume_m3'])->toBe('0.600000')->and($result['total_weight_kg'])->toBe('37.500000')->and($result['quantity'])->toBe(3)->and($result['quantity_by_name']['Crate'])->toBe(3);
});
it('preserves unknown weights separately from known zero weight', function (): void {
    $result = (new InventoryCalculator)->calculate([measuredInventoryItem(['weight_kg' => null]), measuredInventoryItem(['weight_kg' => '0'])]);
    expect($result['total_weight_kg'])->toBeNull()->and($result['unknown_weight_quantity'])->toBe(2)->and($result['known_weight_kg'])->toBe('0.000000');
});
it('applies explicit safety margins and retains measurement confidence', function (): void {
    $result = (new InventoryCalculator)->calculate([measuredInventoryItem(['measurement_source' => 'catalogue'])], 1250);
    expect($result['adjusted_volume_m3'])->toBe('0.450000')->and($result['adjusted_known_weight_kg'])->toBe('28.125000')->and($result['measurement_sources'])->toBe(['catalogue' => 2]);
});
it('estimates loading complexity from handling restrictions', function (): void {
    $result = (new InventoryCalculator)->calculate([measuredInventoryItem(['quantity' => 1, 'fragile' => true, 'oversized' => true, 'stackable' => false, 'rotation_allowed' => false, 'instructions' => 'Use specialist lifting equipment.'])]);
    expect($result['loading_complexity'])->toBe(8);
});
it('rejects invalid dimensions and quantities', function (array $changes): void {
    expect(fn () => (new InventoryCalculator)->calculate([measuredInventoryItem($changes)]))->toThrow(InvalidArgumentException::class);
})->with([
    [['length_cm' => '-1']], [['width_cm' => '0']], [['height_cm' => null]], [['quantity' => 0]], [['quantity' => 1001]], [['weight_kg' => 'NaN']], [['volume_m3' => 'INF', 'length_cm' => null, 'width_cm' => null, 'height_cm' => null]],
]);
it('keeps unknown volume visible rather than pretending the load is empty', function (): void {
    $result = (new InventoryCalculator)->calculate([measuredInventoryItem(['length_cm' => null, 'width_cm' => null, 'height_cm' => null, 'volume_m3' => null])]);
    expect($result['unknown_volume_quantity'])->toBe(2)->and($result['items'][0]['volume_m3'])->toBeNull();
});
