<?php

namespace App\Domain\Fleet;

final class AssessQuotationLoad
{
    public function __construct(private readonly LoadCapacity $capacity) {}

    public function evaluate(Vehicle $vehicle, array $inventory, int $borderlineBps = 9000, array $acceptedSources = []): array
    {
        $screen = $this->capacity->evaluate($vehicle, (float) $inventory['adjusted_volume_m3'], (float) $inventory['adjusted_known_weight_kg'], [], true);
        $reasons = [];
        if (! $screen['qualifies']) {
            $reasons[] = ['code' => 'capacity_exceeded', 'explanation' => 'The estimated load exceeds a vehicle operating or capacity limit.'];
        }
        $missingDimensions = false;
        foreach ($inventory['items'] as $item) {
            if ($item['length_cm'] === null) {
                $missingDimensions = true;
            }
            if ($item['length_cm'] !== null) {
                $dimensions = array_map(fn ($field) => (float) $item[$field] / 100, ['length_cm', 'width_cm', 'height_cm']);
                if (! $this->capacity->evaluate($vehicle, 0, 0, $dimensions, true, $item['rotation_allowed'])['qualifies']) {
                    $reasons[] = ['code' => 'item_does_not_fit', 'explanation' => 'An item exceeds the permitted cargo dimensions or orientation.'];
                }
            }
        }
        $unsuitable = $reasons !== [];
        if ($missingDimensions) {
            $reasons[] = ['code' => 'unknown_dimensions', 'explanation' => 'Individual item dimensions need confirmation.'];
        }
        if ($inventory['unknown_weight_quantity'] || $inventory['unknown_volume_quantity']) {
            $reasons[] = ['code' => 'unknown_measurements', 'explanation' => 'Some load measurements need confirmation.'];
        }
        if (array_diff(array_keys($inventory['measurement_sources']), ['measured', ...$acceptedSources])) {
            $reasons[] = ['code' => 'estimated_measurements', 'explanation' => 'Capacity screening uses estimates or customer-supplied measurements.'];
        }
        if ($vehicle->operating_restrictions) {
            $reasons[] = ['code' => 'vehicle_restrictions', 'explanation' => 'Vehicle operating restrictions require review.'];
        }
        if ((float) $inventory['adjusted_volume_m3'] >= $screen['usable_volume_m3'] * $borderlineBps / 10000 || (float) $inventory['adjusted_known_weight_kg'] >= $screen['usable_payload_kg'] * $borderlineBps / 10000) {
            $reasons[] = ['code' => 'borderline_capacity', 'explanation' => 'The load is close to the screened capacity limit.'];
        }
        foreach ($inventory['items'] as $item) {
            if ($item['oversized'] || ! $item['stackable'] || ! $item['rotation_allowed'] || $item['instructions']) {
                $reasons[] = ['code' => 'complex_loading', 'explanation' => 'The packing arrangement or special handling needs review.'];
                break;
            }
        }

        return ['capacity_verified' => (bool) $vehicle->capacity_verified, 'capacity_verification_note' => $vehicle->capacity_verification_note, 'vehicle_public_id' => $vehicle->public_id, 'vehicle_name' => $vehicle->name, 'vehicle_registration' => $vehicle->registration_number, 'vehicle_type_key' => $vehicle->type->key, 'status' => $unsuitable ? 'unsuitable' : ($reasons ? 'requires_review' : 'suitable'), 'technically_fits' => ! $unsuitable, 'reasons' => array_values(array_unique($reasons, SORT_REGULAR)), 'screen' => $screen, 'limitation' => $screen['limitation']];
    }
}
