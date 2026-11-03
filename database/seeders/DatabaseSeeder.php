<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds the integration catalogue SENTINEL needs to function, plus a
     * development administrator. Servers and applications are never seeded:
     * they are registered through the dashboard.
     */
    public function run(): void
    {
        $this->call([
            IntegrationTypeSeeder::class,
            AdminSeeder::class,
        ]);
    }
}
