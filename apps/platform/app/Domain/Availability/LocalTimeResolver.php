<?php

namespace App\Domain\Availability;

use Carbon\CarbonImmutable;
use DateTimeZone;

final class LocalTimeResolver
{
    // A DST gap has no valid instant. For folds, use the first opening and last closing instant.
    public function resolve(string $date, string $time, string $timezone, bool $closing = false): ?CarbonImmutable
    {
        $clock = $date.' '.substr($time, 0, 5);
        $naive = CarbonImmutable::createFromFormat('!Y-m-d H:i', $clock, 'UTC');
        if (! $naive) {
            return null;
        }$zone = new DateTimeZone($timezone);
        $transitions = $zone->getTransitions($naive->timestamp - 172800, $naive->timestamp + 172800);
        $offsets = array_unique(array_column($transitions, 'offset'));
        $candidates = [];
        foreach ($offsets as $offset) {
            $candidate = CarbonImmutable::createFromTimestampUTC($naive->timestamp - $offset);
            if ($candidate->setTimezone($zone)->format('Y-m-d H:i') === $clock) {
                $candidates[] = $candidate;
            }
        }
        usort($candidates, fn ($a, $b) => $a->timestamp <=> $b->timestamp);

        return $candidates === [] ? null : ($closing ? $candidates[count($candidates) - 1] : $candidates[0]);
    }
}
