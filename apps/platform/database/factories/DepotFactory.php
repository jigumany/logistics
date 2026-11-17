<?php

namespace Database\Factories;

use App\Domain\Fleet\Depot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Depot> */
class DepotFactory extends Factory
{
    protected $model = Depot::class;

    public function definition(): array
    {
        return ['name' => $this->faker->company().' depot', 'address_line' => 'Development example address', 'city' => 'Cape Town', 'country_code' => 'ZA', 'latitude' => -33.9249, 'longitude' => 18.4241, 'coordinates_verified' => false, 'active' => true, 'is_primary' => false, 'timezone' => 'Africa/Johannesburg'];
    }
}
