<?php

namespace App\Domain\Quotes;

use App\Domain\Fleet\AssessQuotationLoad;
use App\Domain\Fleet\Depot;
use App\Domain\Fleet\Vehicle;
use App\Domain\Geography\QuotationCoverage;
use App\Domain\Inventory\ResolveInventory;
use App\Domain\Pricing\CommercialConfiguration;
use App\Domain\Pricing\EstimateJobCost;
use App\Domain\Pricing\PriceBookConfiguration;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Pricing\PricingRule;
use App\Domain\Pricing\PricingRules;
use App\Domain\Pricing\QuotationReadiness;
use App\Domain\Pricing\QuotePricingMetrics;
use App\Domain\Routing\RoutingProvider;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class AssessQuote
{
    public function execute(Quote $quote): array
    {
        $input = $quote->draft_input;
        $service = TenantService::whereKey($quote->service_id)->where('active', true)->where('enabled', true)->firstOrFail();
        $policy = app(QuotationPolicies::class)->forService($service->id);
        $inventory = app(ResolveInventory::class)->execute($input['inventory'], $policy->safety_margin_bps);
        $attachments = QuoteAttachment::where('quote_id', $quote->id)->get()->groupBy('item_key');
        foreach ($inventory['items'] as &$item) {
            $item['photos'] = $attachments->get($item['item_key'], collect())->pluck('public_id')->all();
        }
        unset($item);
        $coverage = app(QuotationCoverage::class)->evaluate($input['stops'], $policy);
        $book = app(QuotationReadiness::class)->currentBook($service->id);
        $depot = $book && ! empty($book->configuration['depot_public_id']) ? Depot::where('public_id', $book->configuration['depot_public_id'])->where('active', true)->where('coordinates_verified', true)->first() : null;
        $route = app(RoutingProvider::class)->calculate($input['stops'], $depot?->only('latitude', 'longitude'));
        $acceptedSources = array_filter([$policy->allow_catalogue_estimates ? 'catalogue' : null, $policy->allow_customer_measurements ? 'customer' : null]);
        $vehicles = Vehicle::with('type')->whereNull('archived_at')->where('status', 'available')->when($book && $book->distance_basis !== 'customer', fn ($q) => $q->where('depot_id', $depot->id ?? 0))->orderBy('id')->limit(200)->get();
        $assessments = $vehicles->map(fn ($vehicle) => app(AssessQuotationLoad::class)->evaluate($vehicle, $inventory, $policy->borderline_bps, $acceptedSources))->all();
        $pricing = null;
        $pricingSnapshot = [];
        $selectedRank = PHP_INT_MAX;
        $pricingFailures = [];
        if ($book) {
            $rules = Cache::remember('pricing:'.app(TenantContext::class)->tenant()->id.':'.$book->public_id.':'.$book->version, 3600, fn () => PricingRule::where('price_book_id', $book->id)->get()->map(fn ($rule) => $rule->only(['code', 'label', 'stage', 'kind', 'metric', 'rate_micros', 'position', 'vehicle_type_key']))->all());
            try {
                app(PriceBookConfiguration::class)->validate($book->configuration, $rules);
                $metrics = app(QuotePricingMetrics::class)->build($input, $inventory, $route, $book, CompanySettings::value('timezone') ?? 'Africa/Johannesburg');
            } catch (ValidationException|InvalidArgumentException $failure) {
                $metrics = null;
                $pricingFailures[] = ['message' => $failure->getMessage()];
            }
            foreach ($metrics === null ? [] : $assessments as $assessment) {
                if (! $assessment['technically_fits']) {
                    continue;
                }
                $vehicleType = $assessment['vehicle_type_key'];
                $commercial = $book->configuration['commercial'] ?? null;
                $applicableRules = $commercial ? app(CommercialConfiguration::class)->applicableRules($rules, $vehicleType) : array_filter($rules, fn ($rule) => empty($rule['vehicle_type_key']) || $rule['vehicle_type_key'] === $vehicleType);
                if ($commercial && ! array_filter($applicableRules, fn ($rule) => $rule['stage'] === 'minimum')) {
                    continue;
                }
                $coveredMetrics = array_merge($book->configuration['included_metrics'], array_filter(array_column($applicableRules, 'metric')));
                if (array_diff($commercial ? PricingRules::ALL_METRICS : PricingRules::METRICS, $coveredMetrics)) {
                    continue;
                }
                try {
                    $candidate = app(PricingEngine::class)->calculate($book->currency, $applicableRules, $metrics, $vehicleType);
                    $rank = $assessment['status'] === 'suitable' ? 0 : 1;
                    if ($pricing === null || $rank < $selectedRank || ($rank === $selectedRank && $candidate['total_minor'] < $pricing['total_minor'])) {
                        $selectedRank = $rank;
                        $pricing = $candidate;
                        $pricingSnapshot = ['engine_version' => $candidate['engine_version'], 'schema_version' => 1, 'price_book_public_id' => $book->public_id, 'version' => $book->version, 'distance_basis' => $book->distance_basis, 'configuration' => $book->configuration, 'quantity_status' => 'estimated', 'route_snapshot' => array_intersect_key($route, array_flip(['provider', 'status', 'customer_metres', 'depot_metres', 'return_metres', 'duration_seconds', 'depot_duration_seconds', 'return_duration_seconds', 'tolls_minor'])), 'internal_costs' => $commercial ? app(EstimateJobCost::class)->evaluate($commercial, $metrics, $route, $book->configuration['crew_size'], $candidate['subtotal_minor']) : ['status' => 'incomplete', 'reason' => 'Commercial cost configuration is not published.'], 'rules' => array_values($applicableRules), 'metrics' => $metrics, 'screened_vehicle_public_id' => $assessment['vehicle_public_id'], 'screened_vehicle_type' => $vehicleType];
                    }
                } catch (InvalidArgumentException $failure) {
                    $pricingFailures[] = ['vehicle_type_key' => $vehicleType, 'message' => $failure->getMessage()];
                }
            }
        }
        if ($book && $pricing === null) {
            $pricingSnapshot = ['price_book_public_id' => $book->public_id, 'version' => $book->version, 'configuration' => $book->configuration, 'rules' => $rules, 'metrics' => $metrics ?? [], 'internal_costs' => ['status' => 'incomplete', 'reason' => 'No complete, suitable vehicle pricing calculation is available.']];
        }
        $pricingSnapshot['calculation_failures'] = $pricingFailures;
        $eligibility = app(QuotationEligibility::class)->evaluate($policy, $service, $input, $inventory, $coverage, $route, $assessments, $pricing !== null && filled($policy->terms), $pricingSnapshot['screened_vehicle_public_id'] ?? null);
        $readiness = app(QuotationReadiness::class)->evaluate($service, $book, $policy, $route);
        $pricingSnapshot['readiness'] = $readiness;
        $extraReasons = [];
        if (! $readiness['automatic_ready']) {
            $extraReasons[] = ['code' => 'commercial_readiness', 'customer_message' => 'The operations team needs to review this request before an offer can be issued.', 'internal_details' => ['missing' => array_values(array_filter($readiness['checks'], fn ($check) => ! $check['passed']))]];
        }
        if (($pricingSnapshot['internal_costs']['status'] ?? '') !== 'estimated') {
            $extraReasons[] = ['code' => 'cost_estimate_incomplete', 'customer_message' => 'A team member will confirm the quotation details.', 'internal_details' => $pricingSnapshot['internal_costs'] ?? []];
        } elseif ($pricingSnapshot['internal_costs']['below_minimum']) {
            $extraReasons[] = ['code' => 'below_minimum_margin', 'customer_message' => 'This estimate needs an operations review.', 'internal_details' => $pricingSnapshot['internal_costs']];
        }
        $pricedVehicle = collect($assessments)->firstWhere('vehicle_public_id', $pricingSnapshot['screened_vehicle_public_id'] ?? '');
        if (! ($pricedVehicle['capacity_verified'] ?? false)) {
            $extraReasons[] = ['code' => 'unverified_vehicle_capacity', 'customer_message' => 'Vehicle specifications need confirmation.', 'internal_details' => []];
        }
        if (($policy->commercial_terms['declared_value_required'] ?? false) && ! isset($input['details']['declared_goods_value_minor'])) {
            $extraReasons[] = ['code' => 'declared_goods_value', 'customer_message' => 'Please confirm the declared value of the goods with the operations team.', 'internal_details' => []];
        }
        $eligibility['reasons'] = [...$eligibility['reasons'], ...$extraReasons];
        $eligibility['automatic'] = $eligibility['automatic'] && $extraReasons === [];
        $termsSnapshot = app(QuotationTerms::class)->snapshot($policy);
        $expires = now()->addHours($policy->validity_hours);
        if ($route['retain_until']) {
            $expires = $expires->min(CarbonImmutable::parse($route['retain_until']));
        }

        return ['inventory' => $inventory, 'coverage' => $coverage, 'suitability' => $assessments, 'review_reasons' => $eligibility['reasons'], 'approval' => $eligibility['automatic'] ? 'automatic' : 'pending', 'pricing' => $pricing, 'pricing_snapshot' => $pricingSnapshot, 'price_book_id' => $book?->id, 'route' => $route, 'terms_snapshot' => $termsSnapshot, 'terms' => $termsSnapshot['terms'], 'exclusions' => $policy->exclusions ?? '', 'expires_at' => $expires];
    }
}
