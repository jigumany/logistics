<?php

namespace Database\Factories;

use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vehicle> */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return ['name' => 'Development vehicle', 'registration_number' => strtoupper($this->faker->unique()->bothify('??######')), 'vehicle_type_id' => VehicleType::firstOrFail()->id, 'status' => 'available', 'usable_volume_m3' => 10, 'payload_kg' => 1000, 'cargo_length_m' => 3, 'cargo_width_m' => 1.7, 'cargo_height_m' => 2, 'capacity_margin_basis_points' => 1000];
    }
}
