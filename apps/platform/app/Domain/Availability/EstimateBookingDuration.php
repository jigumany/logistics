<?php

namespace App\Domain\Availability;

use App\Domain\Bookings\BookingConflict;
use App\Domain\Quotes\QuoteRevision;
use App\Domain\Routing\RouteCalculation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

final class EstimateBookingDuration
{
    public function estimate(QuoteRevision $revision, array $policy): array
    {
        $config = $revision->pricing_snapshot['configuration'] ?? [];
        $route = $revision->route_calculation_id ? RouteCalculation::whereKey($revision->route_calculation_id)->first() : null;
        $seconds = $revision->pricing_snapshot['route_snapshot']['duration_seconds'] ?? ($route && (! $route->retain_until || $route->retain_until->isFuture()) ? $route->duration_seconds : null);
        $complexity = $revision->inventory_summary['loading_complexity'] ?? null;
        $fallback = false;
        if ($seconds === null || $complexity === null || ! isset($config['loading_minutes_per_complexity'],$config['unloading_minutes_per_complexity'])) {
            if (! $policy['conservative_job_minutes']) {
                throw BookingConflict::because('duration_unknown', 'A route duration and loading estimate, or an explicitly configured conservative duration, is required.');
            }
            $minutes = (int) $policy['conservative_job_minutes'];
            $fallback = true;
        } else {
            $handling = BigDecimal::of((string) $config['loading_minutes_per_complexity'])->plus((string) $config['unloading_minutes_per_complexity'])->multipliedBy((int) $complexity);
            $minutes = $handling->plus(BigDecimal::of($seconds)->dividedBy(60, 6, RoundingMode::Ceiling))->toScale(0, RoundingMode::Ceiling)->toInt();
            $minutes = max($minutes, BigDecimal::of((string) ($config['commercial']['minimum_labour_hours'] ?? 0))->multipliedBy(60)->toScale(0, RoundingMode::Ceiling)->toInt());
        }
        $details = $revision->input['details'];
        $access = (($details['pickup_lift'] ? 0 : $details['pickup_stairs']) + ($details['delivery_lift'] ? 0 : $details['delivery_stairs'])) * $policy['stairs_minutes'];
        $minutes += max(0, count($revision->input['stops']) - 2) * $policy['stop_minutes'] + $access + $details['waiting_minutes'];
        if ($minutes < 1 || $minutes > 2880) {
            throw BookingConflict::because('duration_review', 'The estimated job duration needs manual review.');
        }

        $before = (int) $policy['reposition_minutes'];
        $after = (int) $policy['reposition_minutes'];
        if (! empty($config['depot_public_id'])) {
            $routeSnapshot = $revision->pricing_snapshot['route_snapshot'] ?? [];
            $approach = $routeSnapshot['depot_duration_seconds'] ?? ($route && (! $route->retain_until || $route->retain_until->isFuture()) ? $route->depot_duration_seconds : null);
            $return = $routeSnapshot['return_duration_seconds'] ?? ($route && (! $route->retain_until || $route->retain_until->isFuture()) ? $route->return_duration_seconds : null);
            if (($approach === null || $return === null) && $before === 0) {
                throw BookingConflict::because('reposition_duration_unknown', 'Configure a conservative depot travel allowance or obtain verified operational route durations.');
            }
            $before = max($before, (int) ceil(($approach ?? 0) / 60));
            $after = max($after, (int) ceil(($return ?? 0) / 60));
        }
        if ($before > 1440 || $after > 1440) {
            throw BookingConflict::because('reposition_duration_review', 'Long depot travel requires an operations scheduling review.');
        }

        return ['service_minutes' => $minutes, 'before_minutes' => $before, 'after_minutes' => $policy['buffer_minutes'] + $after, 'arrival_minutes' => min($policy['arrival_window_minutes'], $minutes), 'used_conservative_estimate' => $fallback, 'basis' => 'Stored route, inventory loading, access, stop and waiting estimates; repositioning and turnaround are internal allowances.'];
    }

    public function intervals(CarbonImmutable $start, array $duration): array
    {
        return ['arrival_starts_at' => $start, 'arrival_ends_at' => $start->addMinutes($duration['arrival_minutes']), 'service_starts_at' => $start, 'service_ends_at' => $start->addMinutes($duration['service_minutes']), 'allocation_starts_at' => $start->subMinutes($duration['before_minutes']), 'allocation_ends_at' => $start->addMinutes($duration['service_minutes'] + $duration['after_minutes'])];
    }
}
