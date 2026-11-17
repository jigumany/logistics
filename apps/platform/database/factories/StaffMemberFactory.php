<?php

namespace Database\Factories;

use App\Domain\Resources\StaffMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffMember> */
class StaffMemberFactory extends Factory
{
    protected $model = StaffMember::class;

    public function definition(): array
    {
        return ['full_name' => $this->faker->name(), 'email' => $this->faker->safeEmail(), 'role' => 'mover', 'engagement_status' => 'employed', 'active' => true];
    }
}
