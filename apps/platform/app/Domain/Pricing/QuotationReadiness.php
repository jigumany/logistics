<?php

namespace App\Domain\Pricing;

use App\Domain\Fleet\Vehicle;
use App\Domain\Geography\ServiceZone;
use App\Domain\Quotes\QuotationPolicies;
use App\Domain\Quotes\QuotationPolicy;
use App\Domain\Quotes\QuotationTerms;
use App\Domain\Routing\RouteCalculation;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;
use App\Infrastructure\Maps\GoogleMapsTransport;
use Illuminate\Validation\ValidationException;

final class QuotationReadiness
{
    public function currentBook(int $serviceId): ?PriceBook
    {
        return PriceBook::where('is_demonstration', false)->where('service_id', $serviceId)->where('status', 'published')->where('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))->orderByDesc('version')->first();
    }

    public function evaluate(TenantService $service, ?PriceBook $book = null, ?QuotationPolicy $policy = null, ?array $route = null): array
    {
        $book ??= $this->currentBook($service->id);
        $policy ??= app(QuotationPolicies::class)->forService($service->id);
        $commercial = $book?->configuration['commercial'] ?? [];
        $rates = false;
        if ($book) {
            try {
                app(PriceBookConfiguration::class)->validate($book->configuration, PricingRule::where('price_book_id', $book->id)->get()->map(fn ($r) => $r->getAttributes())->all());
                $rates = (int) ($book->configuration['schema_version'] ?? 1) === 2;
                if ($rates) {
                    $bookRules = PricingRule::where('price_book_id', $book->id)->get()->map(fn ($rule) => $rule->getAttributes())->all();
                    $types = Vehicle::with('type')->whereNull('archived_at')->where('status', 'available')->where('capacity_verified', true)->get();
                    $rates = $types->contains(function ($vehicle) use ($book, $bookRules): bool {
                        $rules = app(CommercialConfiguration::class)->applicableRules($bookRules, $vehicle->type->key);

                        return array_diff(PricingRules::ALL_METRICS, [...$book->configuration['included_metrics'], ...array_column($rules, 'metric')]) === [] && count(array_filter($rules, fn ($r) => $r['stage'] === 'minimum')) > 0;
                    });
                }
            } catch (ValidationException) {
                $rates = false;
            }
        }
        $provider = $route !== null ? ($route['status'] ?? '') === 'available' : app(GoogleMapsTransport::class)->available() && RouteCalculation::where('provider', 'google')->where('status', 'available')->where('created_at', '>=', now()->subDay())->where('retain_until', '>', now())->exists();
        $checks = [
            ['code' => 'company_active', 'label' => 'Company operations are active', 'passed' => CompanySettings::value('operational_status') === 'active'],
            ['code' => 'automatic_service', 'label' => 'The service is enabled and permits automatic estimates', 'passed' => $service->enabled && $service->active && ! $service->manual_approval],
            ['code' => 'active_vehicle', 'label' => 'An active vehicle has verified capacity and specification evidence', 'passed' => Vehicle::whereNull('archived_at')->where('status', 'available')->where('capacity_verified', true)->whereNotNull('capacity_verified_at')->exists()],
            ['code' => 'service_areas', 'label' => 'Active service areas are configured, excluding examples', 'passed' => ServiceZone::where('active', true)->where('example_only', false)->exists()],
            ['code' => 'published_pricing', 'label' => 'A current published price book has complete commercial rates and allowances', 'passed' => $rates],
            ['code' => 'labour_costs', 'label' => 'Labour, operating costs and margin thresholds have been reviewed', 'passed' => (bool) ($commercial['costs_reviewed'] ?? false)],
            ['code' => 'tax', 'label' => 'Tax registration, mode and pricing rules have been reviewed', 'passed' => (bool) ($commercial['tax_reviewed'] ?? false)],
            ['code' => 'validity', 'label' => 'An explicit quotation validity period is saved', 'passed' => $policy->exists && $policy->validity_hours > 0],
            ['code' => 'terms', 'label' => 'Transport terms and insurance information are reviewed and versioned', 'passed' => app(QuotationTerms::class)->reviewed($policy)],
            ['code' => 'routing', 'label' => 'Routing is configured and a successful calculation was observed within 24 hours', 'passed' => $provider],
        ];
        $configuration = collect($checks)->whereNotIn('code', ['routing', 'terms', 'tax', 'labour_costs', 'automatic_service'])->every('passed');
        $reviewed = collect($checks)->whereNotIn('code', ['routing', 'automatic_service'])->every('passed');
        $ready = collect($checks)->every('passed');
        $state = ! $configuration ? 'configuration_incomplete' : (! $reviewed ? 'requires_review' : (! $ready || ! $policy->automatic_estimates ? 'ready_for_internal_testing' : 'ready_for_live_automatic_quotations'));

        return ['state' => $state, 'automatic_ready' => $ready && $policy->automatic_estimates, 'checks' => $checks, 'service_public_id' => $service->public_id, 'service_name' => $service->name];
    }
}
