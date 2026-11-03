<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_development_administrator(): void
    {
        $this->seed(AdminSeeder::class);

        $user = User::query()->where('email', AdminSeeder::USERNAME)->sole();

        $this->assertTrue($user->isAdmin());
    }

    public function test_the_seeded_administrator_can_sign_in_with_a_username(): void
    {
        $this->seed(AdminSeeder::class);

        $this->postJson('/login', [
            'email' => AdminSeeder::USERNAME,
            'password' => AdminSeeder::PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_admin', true);

        $this->assertAuthenticated();
    }

    public function test_seeding_is_idempotent(): void
    {
        $this->seed(AdminSeeder::class);
        $this->seed(AdminSeeder::class);

        $this->assertSame(1, User::query()->where('email', AdminSeeder::USERNAME)->count());
    }

    public function test_it_refuses_to_seed_shared_credentials_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->seed(AdminSeeder::class);

        $this->assertDatabaseMissing('users', ['email' => AdminSeeder::USERNAME]);
    }
}
