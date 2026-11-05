<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Enums\ConnectionStatus;
use App\Enums\Environment;
use App\Enums\ProbeDiagnosis;
use App\Enums\UptimeStatus;
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
            'probe_enabled' => true,
            'probe_interval_seconds' => 60,
            'uptime_status' => UptimeStatus::Unknown,
        ];
    }

    /**
     * Registered with a probe target, so heartbeat probing applies to it.
     */
    public function probed(string $baseUrl = 'https://app.example.com'): static
    {
        return $this->state(fn () => [
            'base_url' => $baseUrl,
            'probe_enabled' => true,
            'probe_token' => 'probe_'.Str::random(40),
            'next_probe_at' => now()->subMinute(),
        ]);
    }

    public function up(): static
    {
        return $this->probed()->state(fn () => [
            'uptime_status' => UptimeStatus::Up,
            'uptime_diagnosis' => ProbeDiagnosis::Healthy,
            'last_probe_at' => now(),
            'last_up_at' => now(),
            'status_changed_at' => now()->subHour(),
        ]);
    }

    public function down(): static
    {
        return $this->probed()->state(fn () => [
            'uptime_status' => UptimeStatus::Down,
            'uptime_diagnosis' => ProbeDiagnosis::ConnectionRefused,
            'last_probe_at' => now(),
            'status_changed_at' => now()->subMinutes(5),
            'consecutive_failures' => 2,
        ]);
    }

    public function maintenance(): static
    {
        return $this->probed()->state(fn () => [
            'uptime_status' => UptimeStatus::Maintenance,
            'uptime_diagnosis' => ProbeDiagnosis::MaintenanceMode,
            'last_probe_at' => now(),
            'status_changed_at' => now()->subMinutes(2),
        ]);
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
