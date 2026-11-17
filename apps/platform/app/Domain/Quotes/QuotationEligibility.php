<?php

namespace App\Domain\Quotes;

use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;

final class QuotationEligibility
{
    public function evaluate(QuotationPolicy $policy, TenantService $service, array $input, array $inventory, array $coverage, array $route, array $vehicles, bool $pricingComplete, ?string $pricedVehiclePublicId = null): array
    {
        $reasons = [];
        $add = function (string $code, string $message, array $details = []) use (&$reasons): void {
            $reasons[] = ['code' => $code, 'customer_message' => $message, 'internal_details' => $details];
        };
        if (CompanySettings::value('operational_status') !== 'active') {
            $add('company_operations_review', 'The operations team needs to confirm service arrangements before issuing an offer.');
        }
        if (! $policy->automatic_estimates || $service->manual_approval) {
            $add('manual_policy', 'This request will be checked by the operations team.');
        }
        if (count(array_filter($input['stops'], fn ($stop) => $stop['verification'] !== 'provider'))) {
            $add('unverified_address', 'An address needs confirmation before we can issue an estimate.');
        }
        if ($coverage['outcome'] !== 'automatically_serviceable') {
            $add($coverage['outcome'] === 'outside_supported_area' ? 'outside_supported_area' : 'coverage_review', 'The requested service area needs an operations review.');
        }
        if ($inventory['unknown_weight_quantity'] || $inventory['unknown_volume_quantity']) {
            $add('unknown_measurements', 'Please confirm the weight and dimensions of the items with missing measurements.');
        }
        $sources = $inventory['measurement_sources'];
        if ((! $policy->allow_catalogue_estimates && isset($sources['catalogue'])) || (! $policy->allow_customer_measurements && isset($sources['customer']))) {
            $add('measurement_confidence', 'The supplied measurements need verification.');
        }
        $suitable = array_filter($vehicles, fn ($assessment) => $assessment['status'] === 'suitable' && ($pricedVehiclePublicId === null || $assessment['vehicle_public_id'] === $pricedVehiclePublicId));
        if ($suitable === []) {
            $add('vehicle_review', 'The load and vehicle requirements need a technical review.');
        }
        if ($route['status'] !== 'available') {
            $add('route_unavailable', 'The route could not be confirmed automatically. We can still review your request.', ['provider_reason' => $route['failure_code']]);
        }
        if (! $pricingComplete) {
            $add('pricing_incomplete', 'A team member will prepare the price for this request.');
        }
        if (count($input['stops']) - 2 > $policy->max_auto_stops) {
            $add('multiple_stops', 'The additional stops need an operations review.');
        }
        $details = $input['details'];
        if ($policy->max_auto_stairs < $details['pickup_stairs'] + $details['delivery_stairs'] || $details['parking_limited'] || $details['difficult_access']) {
            $add('difficult_access', 'The access and loading arrangements need confirmation.');
        }
        if ($details['special_equipment']) {
            $add('special_equipment', 'Special equipment requirements need confirmation.');
        }
        foreach ($inventory['items'] as $item) {
            if (($item['provenance']['weight'] ?? '') === 'customer' && ($item['provenance']['weight_certainty'] ?? '') !== 'known') {
                $add('uncertain_weight', 'Some customer weight estimates need confirmation.');
            }
            if ($item['oversized'] || $item['instructions'] || ! $item['stackable'] || ! $item['rotation_allowed']) {
                $add('special_handling', 'Some items or packing restrictions need a specialist review.');
                break;
            }
        }

        return ['automatic' => $reasons === [], 'reasons' => $reasons];
    }
}
