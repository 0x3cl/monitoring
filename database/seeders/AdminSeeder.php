<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds the default development administrator: sentinel / password.
 *
 * Refuses to run in production: a credential committed to the repository is
 * public knowledge, so it must never exist on a deployed instance. Create
 * real administrators with `php artisan sentinel:create-admin`.
 */
class AdminSeeder extends Seeder
{
    public const USERNAME = 'sentinel';

    public const PASSWORD = 'password';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Skipping the default administrator: never seed shared credentials in production.');

            return;
        }

        $user = User::query()->firstOrNew(['email' => self::USERNAME]);

        $user->forceFill([
            'name' => 'SENTINEL Administrator',
            'email' => self::USERNAME,
            'password' => self::PASSWORD,
            'is_admin' => true,
        ])->save();

        $this->command?->info('Default administrator ready — sentinel / password.');
    }
}
