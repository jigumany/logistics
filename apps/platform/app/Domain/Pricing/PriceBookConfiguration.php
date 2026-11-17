<?php

namespace App\Domain\Pricing;

use App\Domain\Fleet\VehicleType;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PriceBookConfiguration
{
    public function validate(array $configuration, array $rules): array
    {
        $data = Validator::make($configuration, [
            'commercial' => ['sometimes', 'array'],
            'depot_public_id' => ['nullable', 'ulid', Rule::exists('depots', 'public_id')->where('tenant_id', app(TenantContext::class)->tenant()->id)->where('active', true)->where('coordinates_verified', true)],
            'schema_version' => ['required', 'integer', 'in:1,2'],
            'included_metrics' => ['required', 'array', 'max:30'],
            'included_metrics.*' => ['required', 'distinct', Rule::in(PricingRules::ALL_METRICS)],
            'crew_size' => ['required', 'integer', 'between:1,20'],
            'loading_minutes_per_complexity' => ['required', 'numeric', 'between:0,120'],
            'unloading_minutes_per_complexity' => ['required', 'numeric', 'between:0,120'],
            'carry_included_metres' => ['required', 'integer', 'between:0,10000'],
            'business_opens' => ['required', 'date_format:H:i'],
            'business_closes' => ['required', 'date_format:H:i', 'after:business_opens'],
            'weekend_days' => ['sometimes', 'array', 'max:7'], 'weekend_days.*' => ['integer', 'between:0,6', 'distinct'],
            'holiday_dates' => ['present', 'array', 'max:370'],
            'holiday_dates.*' => ['date_format:Y-m-d', 'distinct'],
        ])->validate();
        try {
            $rules = (new PricingRules)->validate($rules);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['rules' => $error->getMessage()]);
        }
        $categories = array_values(array_filter(array_unique(array_column($rules, 'vehicle_type_key'))));
        $knownCategories = VehicleType::whereIn('key', $categories)->where('active', true)->pluck('key')->all();
        if (array_diff($categories, $knownCategories)) {
            throw ValidationException::withMessages(['rules' => 'Select active vehicle categories for conditional pricing.']);
        }
        if ((int) $data['schema_version'] === 2) {
            $data['commercial'] = app(CommercialConfiguration::class)->validate($configuration['commercial'] ?? [], $rules);
            if ($data['commercial']['cost_distance_basis'] === 'operational' && empty($data['depot_public_id'])) {
                throw ValidationException::withMessages(['commercial' => 'Operational cost estimates require a verified depot.']);
            }
        } else {
            unset($data['commercial']);
        }
        $requiredMetrics = (int) $data['schema_version'] === 2 ? PricingRules::ALL_METRICS : PricingRules::METRICS;
        $priced = array_values(array_filter(array_column($rules, 'metric')));
        if (array_intersect($priced, $data['included_metrics']) || array_diff($requiredMetrics, array_merge($priced, $data['included_metrics']))) {
            throw ValidationException::withMessages(['configuration' => 'Every charge metric must be explicitly priced or included, without overlapping definitions.']);
        }

        $data['weekend_days'] ??= [0, 6];

        return $data;
    }
}
