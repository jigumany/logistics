<?php

namespace Database\Seeders;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class VehicleTypeCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['demo_small_delivery_van', 'Small Delivery Van', 'light', '2.400', '1.500', '1.400', '5.04', '700.000'],
            ['demo_large_panel_van', 'Large Panel Van', 'light', '3.500', '1.750', '1.900', '11.6375', '1200.000'],
            ['demo_one_ton_canopy_pickup', '1-Ton Pickup with Canopy', 'light', '2.200', '1.500', '1.200', '3.96', '1000.000'],
            ['demo_light_box_truck', 'Light Box Truck', 'light', '4.200', '2.100', '2.200', '19.404', '2000.000'],
            ['demo_medium_box_truck', 'Medium Box Truck', 'medium', '5.500', '2.300', '2.400', '30.36', '4000.000'],
            ['demo_large_moving_truck', 'Large Moving Truck', 'heavy', '7.000', '2.400', '2.500', '42.00', '8000.000'],
        ];
        DB::transaction(function () use ($types): void {
            foreach ($types as [$key, $name, $class, $length, $width, $height, $envelope, $payload]) {
                DB::table('vehicle_types')->insertOrIgnore([
                    'key' => $key, 'name' => $name, 'vehicle_class' => $class,
                    'cargo_length_m' => $length, 'cargo_width_m' => $width, 'cargo_height_m' => $height,
                    'usable_volume_m3' => (string) BigDecimal::of($envelope)->multipliedBy('0.80')->toScale(3, RoundingMode::Floor),
                    'payload_kg' => $payload, 'active' => true,
                    'description' => 'Synthetic demonstration specifications, not verified vehicle data. Rectangular envelope '.$envelope.' m³; estimated usable volume is 80% of that envelope, rounded down to 0.001 m³. Individual vehicles retain their separate safety margin. Verify actual measurements and payload; this is not a packing guarantee.',
                ]);
            }
        });
        Cache::forget('system:vehicle-types:v1');
    }
}
