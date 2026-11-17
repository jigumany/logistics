<?php

namespace App\Domain\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class EstimateJobCost
{
    public function evaluate(array $config, array $metrics, array $route, int $crewSize, int $revenueMinor): array
    {
        $distance = $route['customer_metres'] ?? null;
        if ($config['cost_distance_basis'] === 'operational') {
            $distance = $distance !== null && isset($route['depot_metres'], $route['return_metres']) ? $distance + $route['depot_metres'] + $route['return_metres'] : null;
        }
        $hours = $metrics['estimated_cost_hours'] ?? null;
        if (! $config['costs_reviewed'] || $distance === null || $hours === null || ($config['toll_policy'] !== 'included' && ($route['tolls_minor'] ?? null) === null)) {
            return ['status' => 'incomplete', 'reason' => 'Operating costs, journey quantities or tolls require confirmation.', 'revenue_ex_tax_minor' => $revenueMinor];
        }
        $km = BigDecimal::of($distance)->dividedBy(1000, 6);
        $jobHours = BigDecimal::of($hours);
        $overtime = $jobHours->minus((string) $config['standard_labour_hours']);
        $overtime = $overtime->isNegative() ? BigDecimal::zero() : $overtime;
        $cost = fn (BigDecimal $qty, int $rate) => $qty->multipliedBy($rate)->dividedBy(10000, 0, RoundingMode::HalfUp)->toInt();
        $lines = [
            ['code' => 'fuel', 'amount_minor' => $cost($km, $config['fuel_rate_micros'])],
            ['code' => 'vehicle', 'amount_minor' => $cost($km, $config['vehicle_rate_micros'])],
            ['code' => 'driver', 'amount_minor' => $cost($jobHours, $config['driver_rate_micros']) + $cost($overtime, $config['driver_overtime_rate_micros'])],
            ['code' => 'movers', 'amount_minor' => $cost($jobHours->multipliedBy($crewSize - 1), $config['mover_rate_micros']) + $cost($overtime->multipliedBy($crewSize - 1), $config['mover_overtime_rate_micros'])],
            ['code' => 'tolls', 'amount_minor' => (int) ($route['tolls_minor'] ?? $config['estimated_tolls_minor'])],
            ['code' => 'direct_costs', 'amount_minor' => $config['fixed_cost_minor']],
        ];
        $direct = array_sum(array_column($lines, 'amount_minor'));
        $costTotal = $direct + $config['overhead_minor'];

        return $this->withRevenue(['status' => 'estimated', 'distance_km' => (string) $km, 'job_hours' => (string) $jobHours, 'lines' => $lines, 'direct_cost_minor' => $direct, 'overhead_minor' => $config['overhead_minor'], 'total_cost_minor' => $costTotal, 'target_margin_bps' => $config['target_margin_bps'], 'minimum_margin_bps' => $config['minimum_margin_bps'], 'quantity_status' => 'estimated'], $revenueMinor);
    }

    public function withRevenue(array $estimate, int $revenueMinor): array
    {
        $contribution = $revenueMinor - $estimate['total_cost_minor'];
        $margin = $revenueMinor > 0 ? BigDecimal::of($contribution)->multipliedBy(10000)->dividedBy($revenueMinor, 0, RoundingMode::Floor)->toInt() : null;

        return [...$estimate, 'revenue_ex_tax_minor' => $revenueMinor, 'contribution_minor' => $contribution, 'margin_bps' => $margin, 'below_minimum' => $margin === null || $margin < $estimate['minimum_margin_bps'], 'below_target' => $margin === null || $margin < $estimate['target_margin_bps']];
    }
}
