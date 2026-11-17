<?php

namespace App\Domain\Fleet;

use InvalidArgumentException;

final class LoadCapacity
{
    public function evaluate(Vehicle $vehicle, float $volumeM3, float $weightKg, array $largestItem = [], bool $restrictionsAcknowledged = false, bool $rotationAllowed = true): array
    {
        if (! is_finite($volumeM3) || ! is_finite($weightKg) || $volumeM3 < 0 || $weightKg < 0) {
            throw new InvalidArgumentException('Load measurements must be finite and non-negative.');
        }
        if ($largestItem !== [] && (count($largestItem) !== 3 || count(array_filter($largestItem, fn ($v) => is_numeric($v) && is_finite((float) $v) && (float) $v > 0)) !== 3)) {
            throw new InvalidArgumentException('Supply three positive item dimensions.');
        }
        $reasons = [];
        $margin = 1 - $vehicle->capacity_margin_basis_points / 10000;
        if ($vehicle->archived_at || $vehicle->status !== 'available') {
            $reasons[] = 'Vehicle is not operationally available.';
        }
        if ($volumeM3 > (float) $vehicle->usable_volume_m3 * $margin) {
            $reasons[] = 'Estimated volume exceeds the usable volume after the safety margin.';
        }
        if ($weightKg > (float) $vehicle->payload_kg * $margin) {
            $reasons[] = 'Estimated weight exceeds the payload after the safety margin.';
        }
        if ($largestItem !== []) {
            $limits = [min((float) ($vehicle->max_item_length_m ?? $vehicle->cargo_length_m), (float) $vehicle->cargo_length_m), min((float) ($vehicle->max_item_width_m ?? $vehicle->cargo_width_m), (float) $vehicle->cargo_width_m), min((float) ($vehicle->max_item_height_m ?? $vehicle->cargo_height_m), (float) $vehicle->cargo_height_m)];
            $item = array_map('floatval', $largestItem);
            if ($rotationAllowed) {
                sort($item);
                sort($limits);
            }
            foreach ($item as $i => $dimension) {
                if ($dimension > $limits[$i]) {
                    $reasons[] = 'Largest item exceeds an individual item or cargo dimension in every axis-aligned orientation.';
                    break;
                }
            }
        }
        if ($vehicle->operating_restrictions && ! $restrictionsAcknowledged) {
            $reasons[] = 'Operating restrictions require manual review.';
        }

        return ['qualifies' => $reasons === [], 'reasons' => $reasons, 'usable_volume_m3' => (float) $vehicle->usable_volume_m3 * $margin, 'usable_payload_kg' => (float) $vehicle->payload_kg * $margin, 'limitation' => 'This is a dimensional screening check, not a packing guarantee or booking availability result.'];
    }
}
