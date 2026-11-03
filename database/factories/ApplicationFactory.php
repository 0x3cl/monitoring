<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Enums\ConnectionStatus;
use App\Enums\Environment;
use App\Models\Application;
use App\Models\IntegrationType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Application> */
class ApplicationFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->company().' System';

        return [
            'app_code' => 'APP-'.str_pad((string) $this->faker->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'environment' => Environment::Production,
            'integration_type_id' => IntegrationType::factory()->laravel(),
            'status' => ApplicationStatus::Active,
            'connection_status' => ConnectionStatus::NeverConnected,
            'is_active' => true,
        ];
    }

    public function pendingInstallation(): static
    {
        return $this->state(fn () => [
            'status' => ApplicationStatus::PendingInstallation,
            'connection_status' => ConnectionStatus::NeverConnected,
        ]);
    }

    public function connected(): static
    {
        return $this->state(fn () => [
            'status' => ApplicationStatus::Active,
            'connection_status' => ConnectionStatus::Connected,
            'last_event_at' => now(),
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => [
            'status' => ApplicationStatus::Disabled,
            'is_active' => false,
        ]);
    }
}
