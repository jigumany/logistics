<?php

namespace App\Domain\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

final class QuotePricingMetrics
{
    public function build(array $input, array $inventory, array $route, PriceBook $book, string $timezone): array
    {
        $config = $book->configuration;
        $details = $input['details'];
        $customer = $route['customer_metres'];
        $distance = match ($book->distance_basis) {
            'customer' => $customer,
            'customer_depot' => $customer !== null && $route['depot_metres'] !== null ? $customer + $route['depot_metres'] : null,
            'customer_return' => $customer !== null && $route['return_metres'] !== null ? $customer + $route['return_metres'] : null,
            'operational' => $customer !== null && $route['depot_metres'] !== null && $route['return_metres'] !== null ? $customer + $route['depot_metres'] + $route['return_metres'] : null,
        };
        $loading = BigDecimal::of($inventory['loading_complexity'])->multipliedBy((string) $config['loading_minutes_per_complexity']);
        $unloading = BigDecimal::of($inventory['loading_complexity'])->multipliedBy((string) $config['unloading_minutes_per_complexity']);
        $hours = fn (BigDecimal $minutes) => (string) $minutes->dividedBy('60', 6, RoundingMode::HalfUp);
        $date = CarbonImmutable::parse($input['preferred_date'], $timezone);
        $time = $input['preferred_time'] ?? null;
        $special = array_sum(array_map(fn ($item) => ($item['instructions'] || $item['oversized'] || $item['fragile']) ? $item['quantity'] : 0, $inventory['items']));

        $kilometres = $distance === null ? null : BigDecimal::of($distance)->dividedBy('1000', 6);
        $commercial = $config['commercial'] ?? null;
        $extra = [];
        if ($commercial) {
            if ($kilometres !== null) {
                $kilometres = $kilometres->minus((string) $commercial['included_km']);
                $kilometres = BigDecimal::max($kilometres, (string) $commercial['minimum_chargeable_km'], '0');
            }
            $duration = function (string $basis) use ($route): ?int {
                if (($route['duration_seconds'] ?? null) === null) {
                    return null;
                }
                if ($basis === 'customer') {
                    return $route['duration_seconds'];
                }

                return isset($route['depot_duration_seconds'], $route['return_duration_seconds']) ? $route['duration_seconds'] + $route['depot_duration_seconds'] + $route['return_duration_seconds'] : null;
            };
            $jobHours = function (?int $seconds, bool $waiting) use ($loading, $unloading, $details, $commercial): ?BigDecimal {
                if ($seconds === null) {
                    return null;
                }
                $minutes = $loading->plus($unloading)->plus(BigDecimal::of($seconds)->dividedBy(60, 6, RoundingMode::HalfUp))->plus($waiting ? $details['waiting_minutes'] : 0);

                return BigDecimal::max($minutes->dividedBy(60, 6, RoundingMode::HalfUp), (string) $commercial['minimum_labour_hours']);
            };
            $labour = $jobHours($duration($commercial['labour_distance_basis']), $commercial['bill_waiting_in_labour']);
            $costHours = $jobHours($duration($commercial['cost_distance_basis']), true);
            $overtime = $labour?->minus((string) $commercial['standard_labour_hours']);
            $extra = ['estimated_job_hours' => $labour === null ? null : (string) $labour, 'estimated_cost_hours' => $costHours === null ? null : (string) $costHours, 'driver_hours' => $labour === null ? null : (string) $labour, 'mover_hours' => $labour === null ? null : (string) $labour->multipliedBy($config['crew_size'] - 1), 'overtime_hours' => $overtime === null ? null : (string) BigDecimal::max('0', $overtime)->multipliedBy($config['crew_size']), 'difficult_access' => ($details['difficult_access'] || $details['parking_limited']) ? '1' : '0', 'oversized_items' => (string) array_sum(array_map(fn ($item) => $item['oversized'] ? $item['quantity'] : 0, $inventory['items'])), 'labour_hours' => $labour === null ? null : (string) $labour->multipliedBy($config['crew_size'])];
        }

        return [...['base' => '1', 'chargeable_km' => $kilometres === null ? null : (string) $kilometres, 'vehicle_category' => '1', 'loading_hours' => $hours($loading), 'unloading_hours' => $hours($unloading), 'labour_hours' => $route['duration_seconds'] === null ? null : $hours($loading->plus($unloading)->plus(BigDecimal::of($route['duration_seconds'])->dividedBy('60', 6, RoundingMode::HalfUp))->multipliedBy($config['crew_size'])), 'stairs_flights' => (string) (($details['pickup_lift'] ? 0 : $details['pickup_stairs']) + ($details['delivery_lift'] ? 0 : $details['delivery_stairs'])), 'carry_metres' => (string) max(0, $details['pickup_carry_metres'] + $details['delivery_carry_metres'] - $config['carry_included_metres']), 'additional_stops' => (string) (count($input['stops']) - 2), 'waiting_hours' => $hours(BigDecimal::of($details['waiting_minutes'])), 'after_hours' => $time === null ? null : (($time < $config['business_opens'] || $time >= $config['business_closes']) ? '1' : '0'), 'weekend' => in_array($date->dayOfWeek, $config['weekend_days'] ?? [0, 6], true) ? '1' : '0', 'holiday' => in_array($input['preferred_date'], $config['holiday_dates'], true) ? '1' : '0', 'packing_items' => $details['packing'] ? (string) $inventory['quantity'] : '0', 'special_handling_items' => (string) $special, 'tolls' => $route['tolls_minor'] === null ? null : (string) $route['tolls_minor']], ...$extra];
    }
}
