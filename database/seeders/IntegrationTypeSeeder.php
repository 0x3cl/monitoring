<?php

namespace Database\Seeders;

use App\Models\IntegrationType;
use Illuminate\Database\Seeder;

/**
 * The integration catalogue. Only Laravel is available in Phase 1; the others
 * are listed so the registration form reflects the roadmap and so adding one
 * later needs no schema change.
 */
class IntegrationTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'slug' => 'laravel',
                'name' => 'Laravel',
                'transport' => 'https',
                'package_name' => 'dost-ptri/sentinel-agent',
                'description' => 'Composer package providing request, exception and database monitoring, plus the health endpoint SENTINEL probes.',
                'is_available' => true,
                'version' => '1.2.0',
            ],
            [
                'slug' => 'generic',
                'name' => 'Generic HTTP',
                'transport' => 'https',
                'package_name' => null,
                'description' => 'Any application that can POST the standardized JSON event format.',
                'is_available' => true,
                'version' => '1.0.0',
            ],
            [
                'slug' => 'nodejs',
                'name' => 'Node.js',
                'transport' => 'https',
                'package_name' => '@dost-ptri/sentinel-agent',
                'description' => 'NPM package for Express and Fastify applications.',
                'is_available' => false,
                'version' => null,
            ],
            [
                'slug' => 'python',
                'name' => 'Python',
                'transport' => 'https',
                'package_name' => 'dost-ptri-sentinel-agent',
                'description' => 'Optional SDK for Django, Flask and FastAPI applications.',
                'is_available' => false,
                'version' => null,
            ],
            [
                'slug' => 'linux-agent',
                'name' => 'Linux server (Fluent Bit)',
                'transport' => 'https',
                'package_name' => null,
                'description' => 'Log shipping and infrastructure metrics from the host.',
                'is_available' => false,
                'version' => null,
            ],
        ];

        foreach ($types as $type) {
            $version = $type['version'];
            unset($type['version']);

            $integrationType = IntegrationType::query()->updateOrCreate(
                ['slug' => $type['slug']],
                $type
            );

            if ($version !== null) {
                $integrationType->versions()->updateOrCreate(
                    ['version' => $version],
                    ['is_current' => true, 'released_at' => now()]
                );
            }
        }
    }
}
