<?php

namespace Database\Seeders;

use App\Domain\Fleet\Depot;
use App\Domain\Fleet\SaveVehicle;
use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleType;
use App\Domain\Geography\ServiceZone;
use App\Domain\Identity\Membership;
use App\Domain\Resources\SaveStaffMember;
use App\Domain\Resources\StaffMember;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

final class OperationalSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Operational example data is for development only.');
        }
        $context = app(TenantContext::class);
        foreach (Tenant::lazyById(100) as $tenant) {
            $context->set($tenant);
            try {
                $actor = Membership::where('tenant_id', $tenant->id)->where('role', 'owner')->value('user_id');
                if (! $actor) {
                    continue;
                }
                $depot = Depot::firstOrCreate(['name' => 'Development example depot'], ['address_line' => 'Example only — replace with verified operating address', 'city' => 'Cape Town', 'country_code' => 'ZA', 'latitude' => -33.9249, 'longitude' => 18.4241, 'coordinate_source' => 'development_example', 'coordinates_verified' => false, 'active' => true, 'is_primary' => false, 'timezone' => 'Africa/Johannesburg']);
                if (! Vehicle::where('registration_number', 'DEMO0001')->exists()) {
                    app(SaveVehicle::class)->execute(['name' => 'Development example van', 'registration_number' => 'DEMO0001', 'vehicle_type_id' => VehicleType::firstOrFail()->id, 'status' => 'inactive', 'depot_id' => $depot->id, 'capacity_margin_basis_points' => 1000], $actor);
                }
                if (! StaffMember::where('email', 'development-mover@example.test')->exists()) {
                    app(SaveStaffMember::class)->execute(['full_name' => 'Development example mover', 'email' => 'development-mover@example.test', 'role' => 'mover', 'engagement_status' => 'contractor', 'active' => false, 'depot_id' => $depot->id, 'eligible_vehicle_classes' => []], $actor);
                }
                ServiceZone::firstOrCreate(['name' => 'Development example area — NOT verified coverage'], ['zone_type' => 'both', 'boundary' => ['type' => 'Polygon', 'coordinates' => [[[18.3, -34.1], [18.6, -34.1], [18.6, -33.8], [18.3, -33.8], [18.3, -34.1]]]], 'active' => false, 'priority' => 0, 'manual_review' => true, 'example_only' => true]);
            } finally {
                $context->clear();
            }
        }
    }
}
