<?php

namespace App\Domain\Geography;

use App\Domain\Quotes\QuotationPolicy;
use LogicException;

final class QuotationCoverage
{
    public function __construct(private readonly CoverageLookup $lookup) {}

    public function evaluate(array $stops, QuotationPolicy $policy): array
    {
        $results = [];
        foreach ($stops as $index => $stop) {
            $purpose = $index === array_key_last($stops) ? 'delivery' : 'pickup';
            if (! isset($stop['latitude'], $stop['longitude'])) {
                $results[] = ['covered' => false, 'zone' => null, 'reason' => 'missing_coordinates'];

                continue;
            }
            try {
                $results[] = $this->lookup->check((float) $stop['latitude'], (float) $stop['longitude'], $purpose);
            } catch (LogicException) {
                $results[] = ['covered' => false, 'zone' => null, 'reason' => 'coverage_unavailable'];
            }
        }
        $outside = count(array_filter($results, fn ($result) => ! $result['covered'])) > 0;
        $restricted = count(array_filter($results, fn ($result) => ($result['zone']['manual_review'] ?? false) || ! empty($result['zone']['restrictions']))) > 0;
        $crossZone = count(array_unique(array_filter(array_map(fn ($result) => $result['zone']['public_id'] ?? null, $results)))) > 1;
        $outcome = $outside && $policy->outside_zone === 'reject' ? 'outside_supported_area' : (($outside || $restricted || ($crossZone && ! $policy->allow_cross_zone)) ? 'manual_approval' : 'automatically_serviceable');

        return ['outcome' => $outcome, 'stops' => $results, 'cross_zone' => $crossZone];
    }
}
