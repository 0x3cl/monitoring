<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\LogSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LogSource> */
class LogSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'type' => 'api',
            'is_enabled' => true,
        ];
    }
}
