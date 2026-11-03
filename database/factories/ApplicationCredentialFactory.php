<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ApplicationCredential;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ApplicationCredential> */
class ApplicationCredentialFactory extends Factory
{
    public function definition(): array
    {
        $token = 'sentinel_'.Str::random(36);

        return [
            'application_id' => Application::factory(),
            'name' => 'default',
            'token_prefix' => substr($token, 0, 16),
            'token_hash' => hash('sha256', $token),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()->subDay()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
