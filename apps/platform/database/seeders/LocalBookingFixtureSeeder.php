<?php

namespace Database\Seeders;

use App\Domain\Availability\WorkingSchedule;
use App\Domain\Bookings\BookingPolicy;
use App\Domain\Fleet\SaveVehicle;
use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleType;
use App\Domain\Identity\Membership;
use App\Domain\Quotes\Quote;
use App\Domain\Resources\Resource;
use App\Domain\Resources\SaveStaffMember;
use App\Domain\Resources\StaffMember;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\AuditEntry;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LocalBookingFixtureSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Booking fixtures may only be seeded in the local environment.');
        }

        $tenant = Tenant::where('slug', 'cape-town-movers')->firstOrFail();
        app(TenantContext::class)->set($tenant);
        try {
            DB::transaction(function () use ($tenant): void {
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $actorId = Membership::where('tenant_id', $tenant->id)->where('role', 'owner')->value('user_id');
                $quote = Quote::where('status', 'accepted')->orderByDesc('id')->firstOrFail();
                $verification = AuditEntry::where('action', 'quote.inventory.verify')
                    ->where('subject_public_id', $quote->public_id)->orderByDesc('id')->first()?->details;
                $type = VehicleType::where('key', $verification['vehicle_type_key'] ?? '')->where('active', true)->firstOrFail();
                if (! $actorId || ! isset($verification['crew_size']) || (int) $verification['crew_size'] !== 1) {
                    throw new RuntimeException('The accepted local quote must have an owner and a verified one-person crew.');
                }

                $vehicle = Vehicle::where('registration_number', 'TESTBOOKING01')->first();
                if (! $vehicle) {
                    $vehicle = app(SaveVehicle::class)->execute([
                        'name' => 'LOCAL TEST Booking Truck',
                        'registration_number' => 'TESTBOOKING01',
                        'vehicle_type_id' => $type->id,
                        'status' => 'available',
                        'capacity_verified' => true,
                        'capacity_verification_note' => 'Synthetic local booking test fixture; no physical vehicle verified.',
                        'capacity_margin_basis_points' => 1000,
                        'notes' => 'Local booking test fixture only. Remove before using real customer bookings.',
                    ], (int) $actorId);
                } elseif ($vehicle->vehicle_type_id !== $type->id) {
                    throw new RuntimeException('The existing local test truck has a different vehicle class.');
                }

                $driver = StaffMember::where('email', 'booking-driver@example.test')->first();
                if (! $driver) {
                    $driver = app(SaveStaffMember::class)->execute([
                        'full_name' => 'LOCAL TEST Booking Driver',
                        'email' => 'booking-driver@example.test',
                        'role' => 'driver',
                        'engagement_status' => 'employed',
                        'active' => true,
                        'licence_category' => 'TEST-C1',
                        'licence_expires_on' => now()->addYear()->toDateString(),
                        'eligible_vehicle_classes' => [$type->vehicle_class],
                        'notes' => 'Synthetic local booking test fixture; no real driving licence verified.',
                    ], (int) $actorId);
                }

                $resourceIds = [Resource::where('vehicle_id', $vehicle->id)->value('id'), Resource::where('staff_id', $driver->id)->value('id')];
                foreach ([null, ...$resourceIds] as $resourceId) {
                    for ($day = 0; $day < 7; $day++) {
                        WorkingSchedule::firstOrCreate(['resource_id' => $resourceId, 'weekday' => $day], [
                            'opens_at' => '00:00', 'closes_at' => '23:59', 'ends_next_day' => false,
                            'timezone' => 'Africa/Johannesburg', 'active' => true,
                        ]);
                    }
                }

                $settings = CompanySettings::firstOrFail();
                $settings->update([
                    'booking_enabled' => true,
                    'booking_notice_minutes' => 0,
                    'payment_terms' => 'invoice',
                    'deposit_basis_points' => 0,
                    'booking_policy' => [...BookingPolicy::DEFAULTS, 'reviewed' => true, 'manual_confirmation' => false,
                        'conservative_job_minutes' => 300, 'reposition_minutes' => 60],
                ]);
                TenantService::whereKey($quote->service_id)->firstOrFail()->update([
                    'publicly_bookable' => true,
                    'notice_minutes' => 0,
                    'booking_rules' => ['weekdays' => [0, 1, 2, 3, 4, 5, 6], 'opens_at' => null,
                        'closes_at' => null, 'vehicles' => 1, 'minimum_movers' => 0, 'depot_public_id' => null],
                ]);
            });
        } finally {
            app(TenantContext::class)->clear();
        }
    }
}
