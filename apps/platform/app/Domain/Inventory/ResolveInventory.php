<?php

namespace App\Domain\Inventory;

use Illuminate\Validation\ValidationException;

final class ResolveInventory
{
    public function execute(array $entries, int $marginBps = 0): array
    {
        $catalogue = CatalogueItem::where('active', true)->whereIn('public_id', array_filter(array_column($entries, 'catalogue_public_id')))->get()->keyBy('public_id');
        $items = [];
        foreach ($entries as $entry) {
            $definition = isset($entry['catalogue_public_id']) ? $catalogue->get($entry['catalogue_public_id']) : null;
            if (! empty($entry['catalogue_public_id']) && ! $definition) {
                throw ValidationException::withMessages(['inventory' => 'An inventory definition is unavailable.']);
            }
            $customDimensions = isset($entry['length_cm']) || isset($entry['width_cm']) || isset($entry['height_cm']);
            $customWeight = array_key_exists('weight_kg', $entry) && $entry['weight_kg'] !== null;
            $item = ['item_key' => $entry['item_key'], 'catalogue_public_id' => $definition?->public_id, 'name' => $entry['name'] ?? $definition?->name, 'quantity' => $entry['quantity'], 'measurement_source' => $customDimensions || $customWeight ? 'customer' : ($definition ? 'catalogue' : 'unknown'), 'fragile' => (bool) ($entry['fragile'] ?? $definition?->handling === 'fragile'), 'oversized' => (bool) ($entry['oversized'] ?? false), 'stackable' => (bool) ($entry['stackable'] ?? true), 'rotation_allowed' => (bool) ($entry['rotation_allowed'] ?? true), 'instructions' => $entry['instructions'] ?? ($definition?->handling === 'special' ? 'Special handling assessment required.' : null), 'photos' => []];
            foreach (['length_cm', 'width_cm', 'height_cm'] as $field) {
                $item[$field] = $customDimensions ? ($entry[$field] ?? null) : $definition?->getAttribute($field);
            }
            $item['weight_kg'] = $customWeight ? $entry['weight_kg'] : $definition?->weight_kg;
            $item['volume_m3'] = $customDimensions ? null : $definition?->volume_m3;
            if (! $item['name']) {
                throw ValidationException::withMessages(['inventory' => 'Name every custom item.']);
            }
            $item['provenance'] = ['dimensions' => $customDimensions ? 'customer' : ($definition ? 'catalogue' : 'unknown'), 'weight' => $customWeight ? 'customer' : ($definition?->weight_kg !== null ? 'catalogue' : 'unknown'), 'weight_certainty' => $customWeight ? ($entry['weight_certainty'] ?? 'known') : ($definition?->weight_kg !== null ? 'estimated' : 'unknown')];
            if (in_array('catalogue', $item['provenance'], true)) {
                $item['measurement_source'] = 'catalogue';
            }
            $items[] = $item;
        }

        try {
            return app(InventoryCalculator::class)->calculate($items, $marginBps);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['inventory' => $error->getMessage()]);
        }
    }
}
