<?php

namespace Database\Seeders;

use App\Domain\Fleet\Depot;
use App\Domain\Fleet\SaveVehicle;
use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleType;
use App\Domain\Identity\Membership;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DemonstrationVehicleSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demonstration vehicles are forbidden outside local/testing.');
        }
        $this->call(VehicleTypeCatalogueSeeder::class);
        $tenant = Tenant::where('slug', 'cape-town-movers')->first();
        if (! $tenant) {
            return;
        }
        $context = app(TenantContext::class);
        $context->set($tenant);
        try {
            DB::transaction(function () use ($tenant): void {
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                if (Vehicle::where('demonstration_key', 'light_box_truck_01')->orWhere('registration_number', 'DEMOBOX0001')->exists()) {
                    return;
                }
                $actor = Membership::where('tenant_id', $tenant->id)->where('role', 'owner')->value('user_id');
                if (! $actor) {
                    return;
                }
                $type = VehicleType::where('key', 'demo_light_box_truck')->where('active', true)->first();
                if (! $type) {
                    return;
                }
                $depot = Depot::where('city', 'Cape Town')->where('active', true)->orderByDesc('is_primary')->orderBy('id')->first();
                $vehicle = new Vehicle;
                $vehicle->demonstration_key = 'light_box_truck_01';
                app(SaveVehicle::class)->execute([
                    'name' => 'Demo Moving Truck 01', 'registration_number' => 'DEMOBOX0001',
                    'vehicle_type_id' => $type->id, 'depot_id' => $depot?->id,
                    'cargo_length_m' => '4.200', 'cargo_width_m' => '2.100', 'cargo_height_m' => '2.200',
                    'usable_volume_m3' => '15.523', 'payload_kg' => '2000.000',
                    'capacity_margin_basis_points' => 1000, 'capacity_verified' => false, 'status' => 'inactive',
                    'notes' => 'Synthetic demonstration vehicle and registration identifier. Estimated usable volume is 80% of the rectangular envelope; a separate 10% screening safety margin applies. Replace with actual specifications and registration before verification and operational activation.',
                ], (int) $actor, $vehicle);
            });
        } finally {
            $context->clear();
        }
    }
}
