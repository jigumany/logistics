<?php

namespace App\Domain\Quotes;

use App\Domain\Fleet\AssessQuotationLoad;
use App\Domain\Fleet\Depot;
use App\Domain\Fleet\Vehicle;
use App\Domain\Geography\QuotationCoverage;
use App\Domain\Inventory\ResolveInventory;
use App\Domain\Pricing\PriceBook;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Pricing\PricingRule;
use App\Domain\Pricing\PricingRules;
use App\Domain\Pricing\QuotePricingMetrics;
use App\Domain\Routing\RoutingProvider;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
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
        $book = PriceBook::where('service_id', $service->id)->where('status', 'published')->where('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))->orderByDesc('version')->first();
        $depot = $book && ! empty($book->configuration['depot_public_id']) ? Depot::where('public_id', $book->configuration['depot_public_id'])->where('active', true)->where('coordinates_verified', true)->first() : null;
        $route = app(RoutingProvider::class)->calculate($input['stops'], $depot?->only('latitude', 'longitude'));
        $acceptedSources = array_filter([$policy->allow_catalogue_estimates ? 'catalogue' : null, $policy->allow_customer_measurements ? 'customer' : null]);
        $vehicles = Vehicle::with('type')->whereNull('archived_at')->where('status', 'available')->when($book && $book->distance_basis !== 'customer', fn ($q) => $q->where('depot_id', $depot->id ?? 0))->orderBy('id')->limit(200)->get();
        $assessments = $vehicles->map(fn ($vehicle) => app(AssessQuotationLoad::class)->evaluate($vehicle, $inventory, $policy->borderline_bps, $acceptedSources))->all();
        $pricing = null;
        $pricingSnapshot = [];
        $selectedRank = PHP_INT_MAX;
        if ($book) {
            $rules = Cache::remember('pricing:'.app(TenantContext::class)->tenant()->id.':'.$book->public_id.':'.$book->version, 3600, fn () => PricingRule::where('price_book_id', $book->id)->get()->map(fn ($rule) => $rule->only(['code', 'label', 'stage', 'kind', 'metric', 'rate_micros', 'position', 'vehicle_type_key']))->all());
            $metrics = app(QuotePricingMetrics::class)->build($input, $inventory, $route, $book, CompanySettings::value('timezone') ?? 'Africa/Johannesburg');
            foreach ($assessments as $assessment) {
                if (! $assessment['technically_fits']) {
                    continue;
                }
                $vehicleType = $assessment['vehicle_type_key'];
                $applicableRules = array_filter($rules, fn ($rule) => empty($rule['vehicle_type_key']) || $rule['vehicle_type_key'] === $vehicleType);
                $coveredMetrics = array_merge($book->configuration['included_metrics'], array_filter(array_column($applicableRules, 'metric')));
                if (array_diff(PricingRules::METRICS, $coveredMetrics)) {
                    continue;
                }
                try {
                    $candidate = app(PricingEngine::class)->calculate($book->currency, $rules, $metrics, $vehicleType);
                    $rank = $assessment['status'] === 'suitable' ? 0 : 1;
                    if ($pricing === null || $rank < $selectedRank || ($rank === $selectedRank && $candidate['total_minor'] < $pricing['total_minor'])) {
                        $selectedRank = $rank;
                        $pricing = $candidate;
                        $pricingSnapshot = ['schema_version' => 1, 'price_book_public_id' => $book->public_id, 'version' => $book->version, 'distance_basis' => $book->distance_basis, 'configuration' => $book->configuration, 'rules' => $rules, 'metrics' => $metrics, 'screened_vehicle_public_id' => $assessment['vehicle_public_id'], 'screened_vehicle_type' => $vehicleType];
                    }
                } catch (InvalidArgumentException) {
                    // Incomplete quantities become manual review; no zero-valued route is invented.
                }
            }
        }
        $eligibility = app(QuotationEligibility::class)->evaluate($policy, $service, $input, $inventory, $coverage, $route, $assessments, $pricing !== null && filled($policy->terms), $pricingSnapshot['screened_vehicle_public_id'] ?? null);
        $expires = now()->addHours($policy->validity_hours);
        if ($route['retain_until']) {
            $expires = $expires->min(CarbonImmutable::parse($route['retain_until']));
        }

        return ['inventory' => $inventory, 'coverage' => $coverage, 'suitability' => $assessments, 'review_reasons' => $eligibility['reasons'], 'approval' => $eligibility['automatic'] ? 'automatic' : 'pending', 'pricing' => $pricing, 'pricing_snapshot' => $pricingSnapshot, 'price_book_id' => $book?->id, 'route' => $route, 'terms' => $policy->terms ?? '', 'exclusions' => $policy->exclusions ?? '', 'expires_at' => $expires];
    }
}
