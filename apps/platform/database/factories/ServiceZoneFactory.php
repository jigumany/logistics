<?php

namespace Database\Factories;

use App\Domain\Geography\ServiceZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServiceZone> */
class ServiceZoneFactory extends Factory
{
    protected $model = ServiceZone::class;

    public function definition(): array
    {
        return ['name' => 'Development example area — not verified', 'zone_type' => 'both', 'boundary' => ['type' => 'Polygon', 'coordinates' => [[[18.3, -34.1], [18.6, -34.1], [18.6, -33.8], [18.3, -33.8], [18.3, -34.1]]]], 'active' => false, 'priority' => 0, 'manual_review' => true, 'example_only' => true];
    }
}
