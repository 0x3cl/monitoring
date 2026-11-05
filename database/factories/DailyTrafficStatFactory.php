<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\DailyTrafficStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DailyTrafficStat> */
class DailyTrafficStatFactory extends Factory
{
    public function definition(): array
    {
        $success = $this->faker->numberBetween(500, 5000);
        $clientErrors = $this->faker->numberBetween(0, 200);
        $serverErrors = $this->faker->numberBetween(0, 50);

        return [
            'application_id' => Application::factory(),
            'day' => now()->toDateString(),
            'requests' => $success + $clientErrors + $serverErrors,
            'unique_visitors' => $this->faker->numberBetween(10, 400),
            'success' => $success,
            'client_errors' => $clientErrors,
            'server_errors' => $serverErrors,
            'slow_requests' => $this->faker->numberBetween(0, 30),
            'avg_response_time_ms' => $this->faker->numberBetween(40, 900),
            'p95_response_time_ms' => $this->faker->numberBetween(900, 4000),
            'max_response_time_ms' => $this->faker->numberBetween(4000, 9000),
            'top_endpoints' => [],
            'top_clients' => [],
            'sample_rate' => 1,
        ];
    }

    public function forDay(string $day): static
    {
        return $this->state(fn () => ['day' => $day]);
    }

    /**
     * A day whose counts are a sample rather than a census.
     */
    public function sampled(float $rate = 0.1): static
    {
        return $this->state(fn () => ['sample_rate' => $rate]);
    }
}
