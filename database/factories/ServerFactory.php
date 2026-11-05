<?php

namespace Database\Factories;

use App\Enums\ReachabilityStatus;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Server> */
class ServerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => strtoupper($this->faker->unique()->bothify('??-SERVER-##')),
            'hostname' => $this->faker->domainWord().'.example.com',
            'ip_address' => $this->faker->ipv4(),
            'provider' => $this->faker->randomElement(['Hetzner', 'DigitalOcean', 'AWS', 'On-premise']),
            'location' => $this->faker->city(),
            'is_active' => true,
            'monitor_port' => 443,
            'monitor_enabled' => true,
            'reachability_status' => ReachabilityStatus::Unknown,
        ];
    }

    public function monitored(string $host = 'host.example.com', int $port = 443): static
    {
        return $this->state(fn () => [
            'monitor_host' => $host,
            'monitor_port' => $port,
            'monitor_enabled' => true,
        ]);
    }

    public function reachable(): static
    {
        return $this->state(fn () => [
            'reachability_status' => ReachabilityStatus::Reachable,
            'last_probed_at' => now(),
            'last_reachable_at' => now(),
        ]);
    }

    public function unreachable(): static
    {
        return $this->state(fn () => [
            'reachability_status' => ReachabilityStatus::Unreachable,
            'last_probed_at' => now(),
            'consecutive_failures' => 2,
        ]);
    }
}
