<?php

namespace App\Domain\Pricing;

use App\Domain\Tenancy\CompanySettings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CommercialConfiguration
{
    public function validate(array $input, array $rules, bool $isDemonstration = false): array
    {
        $validation = [
            'included_km' => ['required', 'numeric', 'between:0,100000'],
            'minimum_chargeable_km' => ['required', 'numeric', 'between:0,100000'],
            'labour_distance_basis' => ['required', 'in:customer,operational'],
            'minimum_labour_hours' => ['required', 'numeric', 'between:0,240'],
            'standard_labour_hours' => ['required', 'numeric', 'gt:0', 'max:240'],
            'labour_model' => ['required', 'in:combined,separate'],
            'bill_waiting_in_labour' => ['required', 'boolean'],
            'toll_policy' => ['required', 'in:included,actual,review'],
            'tax_reviewed' => ['required', 'boolean'],
            'tax_mode' => ['required', $isDemonstration ? 'in:demo_zero' : 'in:not_registered,registered,exempt'],
            'tax_registration' => ['nullable', 'string', 'max:100', 'required_if:tax_mode,registered'],
            'tax_basis_points' => ['required', 'integer', 'between:0,10000'],
            'costs_reviewed' => ['required', 'boolean'],
            'cost_distance_basis' => ['required', 'in:customer,operational'],
            'target_margin_bps' => ['required', 'integer', 'between:0,9999'],
            'minimum_margin_bps' => ['required', 'integer', 'between:0,9999', 'lte:target_margin_bps'],
            'override_roles' => ['required', 'array', 'min:1', 'max:2'],
            'override_roles.*' => ['required', 'distinct', 'in:owner,admin'],
        ];
        foreach (['fuel_rate_micros', 'vehicle_rate_micros', 'driver_rate_micros', 'mover_rate_micros', 'driver_overtime_rate_micros', 'mover_overtime_rate_micros', 'fixed_cost_minor', 'overhead_minor', 'estimated_tolls_minor'] as $field) {
            $validation[$field] = ['required', 'integer', 'between:0,1000000000000'];
        }
        if (! array_filter($rules, fn ($rule) => $rule['stage'] === 'minimum')) {
            throw ValidationException::withMessages(['commercial' => 'Configure an explicit minimum quote amount (zero is permitted when deliberately reviewed).']);
        }
        $data = Validator::make($input, $validation)->validate();
        if ($data['tax_mode'] === 'registered' && trim($data['tax_registration']) !== trim((string) CompanySettings::value('vat_number'))) {
            throw ValidationException::withMessages(['commercial' => 'Use the tax registration number from the company business profile. Update that profile before publishing a new tariff.']);
        }
        $tax = array_sum(array_column(array_filter($rules, fn ($r) => $r['stage'] === 'tax'), 'rate_micros'));
        if ($tax !== $data['tax_basis_points'] * 100 || ($data['tax_mode'] !== 'registered' && $tax !== 0)) {
            throw ValidationException::withMessages(['commercial' => 'Tax rules must match the reviewed tax mode and percentage.']);
        }
        $seen = [];
        foreach ($rules as $rule) {
            if (! in_array($rule['stage'], ['charge', 'surcharge'], true)) {
                continue;
            }
            $key = ($rule['vehicle_type_key'] ?? '*').':'.$rule['metric'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['commercial' => 'Use one charge per metric and vehicle class to prevent duplicate fees.']);
            }
            $seen[$key] = true;
        }
        $priced = array_column($rules, 'metric');
        $forbidden = $data['labour_model'] === 'combined' ? ['driver_hours', 'mover_hours'] : ['labour_hours'];
        $forbidden = [...$forbidden, 'loading_hours', 'unloading_hours'];
        if ($data['bill_waiting_in_labour']) {
            $forbidden = [...$forbidden, 'waiting_hours', 'waiting_intervals'];
        }
        if (array_intersect($priced, $forbidden)) {
            throw ValidationException::withMessages(['commercial' => 'Labour already includes loading and unloading. Use the selected crew or separate driver/mover model; waiting may only be billed once.']);
        }
        if ($data['toll_policy'] === 'included' && in_array('tolls', $priced, true)) {
            throw ValidationException::withMessages(['commercial' => 'Included tolls cannot also be charged.']);
        }
        if ($data['toll_policy'] === 'actual' && ! in_array('tolls', $priced, true)) {
            throw ValidationException::withMessages(['commercial' => 'Actual tolls require an explicit pass-through pricing rule.']);
        }
        foreach ($rules as $rule) {
            if (($rule['metric'] ?? '') === 'tolls' && ($rule['kind'] !== 'rate' || $rule['rate_micros'] !== 10000)) {
                throw ValidationException::withMessages(['commercial' => 'Actual tolls use one minor unit per provider-reported minor unit.']);
            }
        }

        return $data;
    }

    public function applicableRules(array $rules, ?string $vehicleType): array
    {
        $specificRules = array_filter($rules, fn ($r) => ($r['vehicle_type_key'] ?? null) === $vehicleType && $vehicleType !== null);
        $specific = array_values(array_filter(array_column($specificRules, 'metric')));
        $specificMinimum = count(array_filter($specificRules, fn ($r) => $r['stage'] === 'minimum')) > 0;

        return array_values(array_filter($rules, fn ($r) => empty($r['vehicle_type_key']) ? ! (($r['stage'] === 'minimum' && $specificMinimum) || in_array($r['metric'] ?? null, $specific, true)) : $r['vehicle_type_key'] === $vehicleType));
    }
}
