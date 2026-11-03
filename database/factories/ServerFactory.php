<?php

namespace Database\Factories;

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
        ];
    }
}
