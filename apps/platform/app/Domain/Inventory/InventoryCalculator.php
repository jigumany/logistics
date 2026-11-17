<?php

namespace App\Domain\Inventory;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class InventoryCalculator
{
    public function calculate(array $items, int $marginBps = 0): array
    {
        if ($items === [] || count($items) > 100 || $marginBps < 0 || $marginBps > 5000) {
            throw new InvalidArgumentException('Supply 1–100 inventory entries and a valid safety margin.');
        }
        $volume = BigDecimal::zero();
        $knownWeight = BigDecimal::zero();
        $unknownWeight = $unknownVolume = $quantity = $complexity = 0;
        $sources = $byName = $normalized = [];
        $largest = ['length_cm' => '0', 'width_cm' => '0', 'height_cm' => '0'];
        foreach ($items as $item) {
            $count = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($count === false || $count < 1 || $count > 1000 || ($quantity += $count) > 10000) {
                throw new InvalidArgumentException('Inventory quantities must be 1–1000, with at most 10000 items.');
            }
            $dimensions = [];
            foreach (array_keys($largest) as $field) {
                $dimensions[$field] = isset($item[$field]) ? $this->measurement($item[$field], false) : null;
                if ($dimensions[$field] !== null && BigDecimal::of($dimensions[$field])->isGreaterThan($largest[$field])) {
                    $largest[$field] = $dimensions[$field];
                }
            }
            $supplied = count(array_filter($dimensions, fn ($value) => $value !== null));
            if ($supplied !== 0 && $supplied !== 3) {
                throw new InvalidArgumentException('Provide all three dimensions together.');
            }
            $itemVolume = $supplied === 3
                ? BigDecimal::of($dimensions['length_cm'])->multipliedBy($dimensions['width_cm'])->multipliedBy($dimensions['height_cm'])->dividedBy('1000000', 6, RoundingMode::HalfUp)->__toString()
                : (isset($item['volume_m3']) ? $this->measurement($item['volume_m3'], false) : null);
            if ($itemVolume !== null && BigDecimal::of($itemVolume)->isZero()) {
                throw new InvalidArgumentException('Dimensions are too small to represent a cubic metre volume to six decimal places.');
            }
            if ($itemVolume !== null && BigDecimal::of($itemVolume)->isGreaterThan('999999')) {
                throw new InvalidArgumentException('An item exceeds the supported volume range.');
            }
            $weight = isset($item['weight_kg']) ? $this->measurement($item['weight_kg'], true) : null;
            $itemVolume === null ? $unknownVolume += $count : $volume = $volume->plus(BigDecimal::of($itemVolume)->multipliedBy($count));
            $weight === null ? $unknownWeight += $count : $knownWeight = $knownWeight->plus(BigDecimal::of($weight)->multipliedBy($count));
            $source = $item['measurement_source'] ?? 'unknown';
            if (! in_array($source, ['catalogue', 'customer', 'measured', 'unknown'], true)) {
                throw new InvalidArgumentException('Unknown measurement confidence.');
            }
            $itemSources = array_unique([$source, $item['provenance']['dimensions'] ?? $source, $item['provenance']['weight'] ?? $source]);
            foreach ($itemSources as $itemSource) {
                if (in_array($itemSource, ['catalogue', 'customer', 'measured', 'unknown'], true)) {
                    $sources[$itemSource] = ($sources[$itemSource] ?? 0) + $count;
                }
            }
            $byName[$item['name']] = ($byName[$item['name']] ?? 0) + $count;
            $complexity += $count * (1 + (int) $item['fragile'] + 2 * (int) $item['oversized'] + 2 * (int) ! empty($item['instructions']) + (int) ! $item['stackable'] + (int) ! $item['rotation_allowed']);
            $normalized[] = [...$item, ...$dimensions, 'quantity' => $count, 'volume_m3' => $itemVolume, 'weight_kg' => $weight, 'measurement_source' => $source, 'provenance' => $item['provenance'] ?? ['dimensions' => $source, 'weight' => $source]];
        }
        $factor = BigDecimal::of(10000 + $marginBps)->dividedBy('10000', 4);

        return ['schema_version' => 1, 'items' => $normalized, 'quantity' => $quantity, 'quantity_by_name' => $byName, 'volume_m3' => (string) $volume->toScale(6), 'known_volume_m3' => (string) $volume->toScale(6), 'total_volume_m3' => $unknownVolume ? null : (string) $volume->toScale(6), 'total_weight_kg' => $unknownWeight ? null : (string) $knownWeight->toScale(6), 'known_weight_kg' => (string) $knownWeight->toScale(6), 'unknown_weight_quantity' => $unknownWeight, 'unknown_volume_quantity' => $unknownVolume, 'adjusted_volume_m3' => (string) $volume->multipliedBy($factor)->toScale(6, RoundingMode::HalfUp), 'adjusted_known_weight_kg' => (string) $knownWeight->multipliedBy($factor)->toScale(6, RoundingMode::HalfUp), 'safety_margin_bps' => $marginBps, 'largest_dimensions_cm' => $largest, 'measurement_sources' => $sources, 'loading_complexity' => $complexity, 'limitation' => 'Estimated measurements and dimensional screening do not guarantee that a load can be packed.'];
    }

    private function measurement(mixed $value, bool $zeroAllowed): string
    {
        if (! is_scalar($value) || ! preg_match('/^\d{1,6}(\.\d{1,6})?$/D', (string) $value)) {
            throw new InvalidArgumentException('Measurements must be non-negative metric decimals with at most six decimal places.');
        }
        $number = BigDecimal::of((string) $value);
        if (! $zeroAllowed && $number->isZero()) {
            throw new InvalidArgumentException('Dimensions and volumes must be positive.');
        }

        return (string) $number->toScale(6);
    }
}
