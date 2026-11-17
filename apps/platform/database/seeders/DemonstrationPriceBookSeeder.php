<?php

namespace Database\Seeders;

use App\Domain\Identity\Membership;
use App\Domain\Pricing\ManagePriceBook;
use App\Domain\Pricing\PriceBook;
use App\Domain\Pricing\PricingRules;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DemonstrationPriceBookSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demonstration tariffs are forbidden outside development/testing.');
        }
        $tenant = Tenant::where('slug', 'cape-town-movers')->first();
        if (! $tenant) {
            return;
        }
        $this->call(VehicleTypeCatalogueSeeder::class);
        $context = app(TenantContext::class);
        $context->set($tenant);
        try {
            DB::transaction(function () use ($tenant): void {
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $actor = Membership::where('tenant_id', $tenant->id)->where('role', 'owner')->value('user_id');
                if (! $actor) {
                    return;
                }
                foreach (TenantService::whereIn('code', ['household', 'office', 'general'])->orderBy('id')->get() as $service) {
                    if (PriceBook::where('service_id', $service->id)->where('demonstration_key', 'zar_demo_v1')->exists()) {
                        continue;
                    }
                    $rules = $this->rules();
                    $book = app(ManagePriceBook::class)->save([
                        'name' => 'Cape Town Logistics — Demo Rates', 'service_public_id' => $service->public_id,
                        'demonstration_key' => 'zar_demo_v1', 'is_demonstration' => true, 'currency' => 'ZAR', 'effective_from' => '2026-01-01T00:00:00Z',
                        'effective_until' => null, 'distance_basis' => 'customer',
                        'configuration' => [
                            'schema_version' => 3, 'included_metrics' => array_values(array_diff(PricingRules::SUPPORTED_METRICS, array_filter(array_column($rules, 'metric')))),
                            'crew_size' => 1, 'loading_minutes_per_complexity' => 5, 'unloading_minutes_per_complexity' => 5,
                            'carry_included_metres' => 0, 'business_opens' => '08:00', 'business_closes' => '17:00',
                            'weekend_days' => [0, 6], 'holiday_dates' => [], 'depot_public_id' => null,
                            'included_driver_hours' => 2, 'labour_rounding_minutes' => 15,
                            'long_carry_threshold_metres' => 30, 'waiting_grace_minutes' => 15, 'waiting_interval_minutes' => 30,
                            'percentage_surcharge_basis' => 'charges_subtotal',
                            'commercial' => [
                                'included_km' => 0, 'minimum_chargeable_km' => 0, 'minimum_labour_hours' => 2,
                                'standard_labour_hours' => 8, 'labour_model' => 'separate', 'labour_distance_basis' => 'customer',
                                'bill_waiting_in_labour' => false, 'toll_policy' => 'included', 'tax_mode' => 'demo_zero',
                                'tax_registration' => null, 'tax_basis_points' => 0, 'tax_reviewed' => false,
                                'costs_reviewed' => false, 'cost_distance_basis' => 'customer', 'target_margin_bps' => 0,
                                'minimum_margin_bps' => 0, 'override_roles' => ['owner'], 'fuel_rate_micros' => 0,
                                'vehicle_rate_micros' => 0, 'driver_rate_micros' => 0, 'mover_rate_micros' => 0,
                                'driver_overtime_rate_micros' => 0, 'mover_overtime_rate_micros' => 0,
                                'fixed_cost_minor' => 0, 'overhead_minor' => 0, 'estimated_tolls_minor' => 0,
                            ],
                        ], 'rules' => $rules,
                    ], (int) $actor);
                }
            });
        } finally {
            $context->clear();
        }
    }

    private function rules(): array
    {
        $rules = [];
        foreach ([
            ['demo_small_delivery_van', 250, 8, 400], ['demo_large_panel_van', 350, 10, 550],
            ['demo_one_ton_canopy_pickup', 300, 9, 500], ['demo_light_box_truck', 550, 14, 850],
            ['demo_medium_box_truck', 850, 18, 1300], ['demo_large_moving_truck', 1200, 24, 1900],
        ] as [$key, $base, $km, $minimum]) {
            foreach ([['base', 'Base transport', 'charge', 'fixed', 'base', $base], ['distance', 'Customer journey distance', 'charge', 'rate', 'chargeable_km', $km], ['minimum', 'Minimum quotation charge', 'minimum', 'fixed', null, $minimum]] as [$code,$label,$stage,$kind,$metric,$rate]) {
                $rules[] = ['code' => $key.'_'.$code, 'label' => $label, 'stage' => $stage, 'kind' => $kind, 'metric' => $metric, 'rate_micros' => $rate * 1000000, 'position' => $code === 'base' ? 0 : 1, 'vehicle_type_key' => $key];
            }
        }
        foreach ([
            ['driver_extra', 'Driver labour beyond included hours', 'charge', 'rate', 'driver_hours', 120, 10],
            ['additional_movers', 'Requested additional movers', 'charge', 'rate', 'mover_hours', 150, 11],
            ['stairs', 'Stairs after ground floor', 'surcharge', 'rate', 'stairs_flights', 100, 20],
            ['long_carry', 'Long carry per applicable stop', 'surcharge', 'rate', 'long_carry_stops', 150, 21],
            ['additional_stop', 'Additional pickup or delivery stop', 'surcharge', 'rate', 'additional_stops', 200, 22],
            ['fragile', 'Fragile handling per job', 'surcharge', 'fixed', 'fragile_jobs', 150, 23],
            ['oversized', 'Oversized handling per item', 'surcharge', 'rate', 'oversized_items', 250, 24],
            ['waiting', 'Agreed waiting after grace, per started 30 minutes', 'surcharge', 'rate', 'waiting_intervals', 100, 25],
            ['weekend', 'Weekend: 15% of base, distance and labour', 'surcharge', 'percent', 'weekend', 15, 100],
            ['after_hours', 'After hours: 20% of base, distance and labour', 'surcharge', 'percent', 'after_hours', 20, 110],
            ['demo_tax', 'Zero-tax development scenario; not production tax advice', 'tax', 'percent', null, 0, 500],
        ] as [$code,$label,$stage,$kind,$metric,$rate,$position]) {
            $rules[] = ['code' => $code, 'label' => $label, 'stage' => $stage, 'kind' => $kind, 'metric' => $metric, 'rate_micros' => $rate * ($kind === 'percent' ? 10000 : 1000000), 'position' => $position, 'vehicle_type_key' => null];
        }

        return $rules;
    }
}
