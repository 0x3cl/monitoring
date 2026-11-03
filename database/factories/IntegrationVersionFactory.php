<?php

namespace Database\Factories;

use App\Models\IntegrationType;
use App\Models\IntegrationVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IntegrationVersion> */
class IntegrationVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'integration_type_id' => IntegrationType::factory(),
            'version' => $this->faker->numerify('1.#.#'),
            'is_current' => true,
            'released_at' => now(),
        ];
    }
}
