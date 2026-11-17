<?php

namespace App\Domain\Quotes;

use App\Domain\Fleet\AssessQuotationLoad;
use App\Domain\Fleet\Depot;
use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleType;
use App\Domain\Geography\QuotationCoverage;
use App\Domain\Inventory\ResolveInventory;
use App\Domain\Pricing\CommercialConfiguration;
use App\Domain\Pricing\EstimateJobCost;
use App\Domain\Pricing\PriceBook;
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
use App\Infrastructure\Maps\SimulationRoutingProvider;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class AssessQuote
{
    public function execute(Quote $quote): array
    {
        $input = $quote->draft_input;
        $simulation = $quote->simulation_price_book_id !== null;
        $options = $quote->simulation_options ?? [];
        if ($simulation && (! app()->environment(['local', 'testing']) || app(TenantContext::class)->tenant()->slug !== 'cape-town-movers')) {
            throw ValidationException::withMessages(['quote' => 'Demonstration simulation is unavailable.']);
        }
        $service = TenantService::whereKey($quote->service_id)->where('active', true)->where('enabled', true)->firstOrFail();
        $policy = app(QuotationPolicies::class)->forService($service->id);
        $inventory = app(ResolveInventory::class)->execute($input['inventory'], $policy->safety_margin_bps);
        $attachments = QuoteAttachment::where('quote_id', $quote->id)->get()->groupBy('item_key');
        foreach ($inventory['items'] as &$item) {
            $item['photos'] = $attachments->get($item['item_key'], collect())->pluck('public_id')->all();
        }
        unset($item);
        $coverage = app(QuotationCoverage::class)->evaluate($input['stops'], $policy);
        $book = $simulation ? PriceBook::whereKey($quote->simulation_price_book_id)->where('is_demonstration', true)->where('service_id', $service->id)->firstOrFail() : app(QuotationReadiness::class)->currentBook($service->id);
        $depot = $book && ! empty($book->configuration['depot_public_id']) ? Depot::where('public_id', $book->configuration['depot_public_id'])->where('active', true)->where('coordinates_verified', true)->first() : null;
        $provider = $simulation && isset($options['route_distance_km'], $options['route_duration_minutes']) ? new SimulationRoutingProvider(BigDecimal::of((string) $options['route_distance_km'])->multipliedBy(1000)->toScale(0, RoundingMode::HalfUp)->toInt(), BigDecimal::of((string) $options['route_duration_minutes'])->multipliedBy(60)->toScale(0, RoundingMode::Ceiling)->toInt()) : app(RoutingProvider::class);
        $route = $provider->calculate($input['stops'], $depot?->only('latitude', 'longitude'));
        $acceptedSources = array_filter([$policy->allow_catalogue_estimates ? 'catalogue' : null, $policy->allow_customer_measurements ? 'customer' : null]);
        $vehicles = Vehicle::with('type')->whereNull('archived_at')->where('status', 'available')->when($book && $book->distance_basis !== 'customer', fn ($q) => $q->where('depot_id', $depot->id ?? 0))->orderBy('id')->limit(200)->get();
        if ($simulation) {
            $vehicles = VehicleType::where('key', $options['vehicle_type_key'] ?? '')->where('active', true)->get()->map(function ($type) {
                $vehicle = new Vehicle;
                $vehicle->forceFill([...$type->only('usable_volume_m3', 'payload_kg', 'cargo_length_m', 'cargo_width_m', 'cargo_height_m'), 'public_id' => 'catalogue:'.$type->key, 'name' => 'Catalogue simulation: '.$type->name, 'registration_number' => 'SIMULATION', 'status' => 'available', 'capacity_verified' => false, 'capacity_margin_basis_points' => 1000]);
                $vehicle->setRelation('type', $type);

                return $vehicle;
            });
        }
        $assessments = $vehicles->map(fn ($vehicle) => app(AssessQuotationLoad::class)->evaluate($vehicle, $inventory, $policy->borderline_bps, $acceptedSources))->all();
        $pricing = null;
        $pricingSnapshot = [];
        $selectedRank = PHP_INT_MAX;
        $pricingFailures = [];
        if ($book) {
            $loadRules = fn () => PricingRule::where('price_book_id', $book->id)->get()->map(fn ($rule) => $rule->only(['code', 'label', 'stage', 'kind', 'metric', 'rate_micros', 'position', 'vehicle_type_key']))->all();
            $rules = $simulation ? $loadRules() : Cache::remember('pricing:'.app(TenantContext::class)->tenant()->id.':'.$book->public_id.':'.$book->version, 3600, fn () => PricingRule::where('price_book_id', $book->id)->get()->map(fn ($rule) => $rule->only(['code', 'label', 'stage', 'kind', 'metric', 'rate_micros', 'position', 'vehicle_type_key']))->all());
            try {
                app(PriceBookConfiguration::class)->validate($book->configuration, $rules, $book->is_demonstration);
                $metricsInput = $input;
                if ($simulation) {
                    $metricsInput['details'] = [...$input['details'], 'additional_movers' => $options['additional_movers'] ?? 0, 'waiting_confirmed' => $options['waiting_confirmed'] ?? false];
                }
                $metrics = app(QuotePricingMetrics::class)->build($metricsInput, $inventory, $route, $book, CompanySettings::value('timezone') ?? 'Africa/Johannesburg');
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
                    $pricingFailures[] = ['vehicle_type_key' => $vehicleType, 'message' => 'The selected vehicle class has no configured minimum charge.'];

                    continue;
                }
                $coveredMetrics = array_merge($book->configuration['included_metrics'], array_filter(array_column($applicableRules, 'metric')));
                if (array_diff((int) $book->configuration['schema_version'] === 3 ? PricingRules::SUPPORTED_METRICS : ($commercial ? PricingRules::ALL_METRICS : PricingRules::METRICS), $coveredMetrics)) {
                    $pricingFailures[] = ['vehicle_type_key' => $vehicleType, 'message' => 'The selected vehicle class has missing required rates or explicit allowances.'];

                    continue;
                }
                try {
                    $candidate = app(PricingEngine::class)->calculate($book->currency, $applicableRules, $metrics, $vehicleType, $book->configuration['percentage_surcharge_basis'] ?? 'running_subtotal');
                    $rank = $assessment['status'] === 'suitable' ? 0 : 1;
                    if ($pricing === null || $rank < $selectedRank || ($rank === $selectedRank && $candidate['total_minor'] < $pricing['total_minor'])) {
                        $selectedRank = $rank;
                        $pricing = $candidate;
                        $pricingSnapshot = ['engine_version' => $candidate['engine_version'], 'schema_version' => 1, 'price_book_public_id' => $book->public_id, 'price_book_name' => $book->name, 'is_demonstration' => $book->is_demonstration, 'surcharge_basis' => $candidate['surcharge_basis'] ?? 'running_subtotal', 'surcharge_base_minor' => $candidate['surcharge_base_minor'] ?? null, 'version' => $book->version, 'distance_basis' => $book->distance_basis, 'configuration' => $book->configuration, 'quantity_status' => 'estimated', 'route_snapshot' => array_intersect_key($route, array_flip(['provider', 'status', 'customer_metres', 'depot_metres', 'return_metres', 'duration_seconds', 'depot_duration_seconds', 'return_duration_seconds', 'tolls_minor'])), 'internal_costs' => $commercial ? app(EstimateJobCost::class)->evaluate($commercial, $metrics, $route, $book->configuration['crew_size'], $candidate['subtotal_minor']) : ['status' => 'incomplete', 'reason' => 'Commercial cost configuration is not published.'], 'rules' => array_values($applicableRules), 'metrics' => $metrics, 'screened_vehicle_public_id' => $assessment['vehicle_public_id'], 'screened_vehicle_type' => $vehicleType];
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
        if ($simulation) {
            $eligibility['automatic'] = false;
            $eligibility['reasons'][] = ['code' => 'demonstration_only', 'customer_message' => 'Internal simulation only: synthetic rates, unverified catalogue vehicle, no customer offer or booking.', 'internal_details' => ['options' => $options, 'commercial_verification' => 'not_approved', 'tax_scenario' => 'demo_zero']];
        }
        $termsSnapshot = app(QuotationTerms::class)->snapshot($policy);
        if ($simulation) {
            $termsSnapshot = app(QuotationTerms::class)->record('Development simulation only. Rates and zero-tax treatment are synthetic and not commercially approved. No customer offer or booking can be accepted.', '', 'demonstration', 'draft');
        }
        $expires = now()->addHours($policy->validity_hours);
        if ($route['retain_until']) {
            $expires = $expires->min(CarbonImmutable::parse($route['retain_until']));
        }

        return ['inventory' => $inventory, 'coverage' => $coverage, 'suitability' => $assessments, 'review_reasons' => $eligibility['reasons'], 'approval' => $eligibility['automatic'] ? 'automatic' : 'pending', 'pricing' => $pricing, 'pricing_snapshot' => $pricingSnapshot, 'price_book_id' => $book?->id, 'route' => $route, 'terms_snapshot' => $termsSnapshot, 'terms' => $termsSnapshot['terms'], 'exclusions' => $policy->exclusions ?? '', 'expires_at' => $expires];
    }
}
