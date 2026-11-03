<?php

namespace Database\Factories;

use App\Models\IntegrationType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<IntegrationType> */
class IntegrationTypeFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->word();

        return [
            'slug' => Str::slug($name),
            'name' => ucfirst($name),
            'transport' => 'https',
            'is_available' => true,
        ];
    }

    public function laravel(): static
    {
        return $this->state(fn () => [
            'slug' => 'laravel',
            'name' => 'Laravel',
            'package_name' => 'dost-ptri/sentinel-agent',
            'is_available' => true,
        ]);
    }
}
