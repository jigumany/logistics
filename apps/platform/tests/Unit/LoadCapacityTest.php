<?php

use App\Domain\Fleet\LoadCapacity;
use App\Domain\Fleet\Vehicle;

function screenedVehicle(): Vehicle
{
    return new Vehicle(['name' => 'Test', 'status' => 'available', 'usable_volume_m3' => 10, 'payload_kg' => 1000, 'cargo_length_m' => 3, 'cargo_width_m' => 2, 'cargo_height_m' => 2, 'capacity_margin_basis_points' => 1000]);
}
it('checks safety margins and axis aligned orientation', function (): void {
    $result = (new LoadCapacity)->evaluate(screenedVehicle(), 9, 900, [2, 3, 1]);
    expect($result['qualifies'])->toBeTrue()->and($result['usable_volume_m3'])->toBe(9.0);
});
it('explains volume weight dimension and restriction failures', function (): void {
    $vehicle = screenedVehicle();
    $vehicle->operating_restrictions = 'No hazardous goods';
    $result = (new LoadCapacity)->evaluate($vehicle, 10, 1001, [4, 2, 2]);
    expect($result['qualifies'])->toBeFalse()->and($result['reasons'])->toHaveCount(4);
});
it('does not confuse operational status with scheduling availability', function (): void {
    $vehicle = screenedVehicle();
    $vehicle->status = 'maintenance';
    expect((new LoadCapacity)->evaluate($vehicle, 1, 1)['qualifies'])->toBeFalse();
});
it('rejects invalid proposed loads', function (): void {
    expect(fn () => (new LoadCapacity)->evaluate(screenedVehicle(), -1, 1))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new LoadCapacity)->evaluate(screenedVehicle(), 1, 1, [1, 0, 2]))->toThrow(InvalidArgumentException::class);
});
