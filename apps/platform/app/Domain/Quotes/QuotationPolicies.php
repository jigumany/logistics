<?php

namespace App\Domain\Quotes;

final class QuotationPolicies
{
    public function forService(int $serviceId): QuotationPolicy
    {
        return QuotationPolicy::where('service_id', $serviceId)->first() ?? new QuotationPolicy(['service_id' => $serviceId, 'automatic_estimates' => false, 'allow_catalogue_estimates' => false, 'allow_customer_measurements' => false, 'allow_cross_zone' => true, 'outside_zone' => 'review', 'safety_margin_bps' => 1000, 'borderline_bps' => 9000, 'max_auto_stops' => 0, 'max_auto_stairs' => 0, 'validity_hours' => 72, 'notifications_enabled' => false, 'terms' => '', 'exclusions' => '']);
    }
}
