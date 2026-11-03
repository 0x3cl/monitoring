<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Services\CredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComposerRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_serves_a_composer_repository_document(): void
    {
        $response = $this->getJson('/packages.json')->assertOk();

        $package = $response->json('packages.dost-ptri/sentinel-agent.1.0.0');

        $this->assertSame('dost-ptri/sentinel-agent', $package['name']);
        $this->assertSame('1.0.0', $package['version']);
        $this->assertSame('1.0.0.0', $package['version_normalized']);
        $this->assertSame('zip', $package['dist']['type']);

        // The package's own metadata must survive, or Composer cannot resolve
        // its dependencies or register the Laravel provider.
        $this->assertArrayHasKey('illuminate/support', $package['require']);
        $this->assertSame(
            ['DostPtri\\SentinelAgent\\SentinelServiceProvider'],
            $package['extra']['laravel']['providers']
        );
    }

    public function test_the_published_checksum_matches_the_served_archive(): void
    {
        $declared = $this->getJson('/packages.json')
            ->json('packages.dost-ptri/sentinel-agent.1.0.0.dist.shasum');

        $response = $this->get('/packages/dost-ptri/sentinel-agent/1.0.0.zip')->assertOk();

        $this->assertSame('application/zip', $response->headers->get('content-type'));
        // Composer rejects a download whose hash differs from the metadata.
        $this->assertSame($declared, sha1($response->streamedContent()));
    }

    public function test_unknown_packages_and_versions_are_rejected(): void
    {
        $this->get('/packages/dost-ptri/not-a-package/1.0.0.zip')->assertNotFound();
        $this->get('/packages/dost-ptri/sentinel-agent/9.9.9.zip')->assertNotFound();
    }

    public function test_the_repository_can_be_closed_to_registered_applications_only(): void
    {
        config(['sentinel.packages.public' => false]);

        $this->get('/packages.json')->assertUnauthorized();

        $issued = app(CredentialService::class)->issue(Application::factory()->create());

        $this->withHeaders([
            'Authorization' => 'Basic '.base64_encode('sentinel:'.$issued->plainTextToken),
        ])->get('/packages.json')->assertOk();
    }

    public function test_a_revoked_credential_cannot_reach_a_closed_repository(): void
    {
        config(['sentinel.packages.public' => false]);

        $credentials = app(CredentialService::class);
        $issued = $credentials->issue(Application::factory()->create());
        $credentials->revoke($issued->credential);

        $this->withHeaders([
            'Authorization' => 'Basic '.base64_encode('sentinel:'.$issued->plainTextToken),
        ])->get('/packages.json')->assertUnauthorized();
    }

    public function test_the_dashboard_catch_all_does_not_swallow_repository_paths(): void
    {
        // The SPA shell must not answer packages.json, or Composer receives
        // HTML where it expects repository metadata.
        $this->get('/packages.json')->assertHeader('content-type', 'application/json');
    }
}
