<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\IntegrationType;
use App\Models\User;
use App\Services\CredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    protected function laravelApplication(): Application
    {
        return Application::factory()->create([
            'app_code' => 'APP-00001',
            'integration_type_id' => IntegrationType::factory()->laravel(),
        ]);
    }

    public function test_setup_instructions_can_be_viewed_again_without_the_key(): void
    {
        $application = $this->laravelApplication();

        $response = $this->actingAs($this->admin())
            ->getJson("/api/admin/applications/{$application->app_code}/integration")
            ->assertOk();

        $response->assertJsonPath('data.config.credential', null)
            ->assertJsonPath('data.has_download', true)
            ->assertJsonPath('data.package_name', 'dost-ptri/sentinel-agent');

        // The env block still reads correctly, with a placeholder in place of
        // the unrecoverable key.
        $this->assertStringContainsString('<your-application-key>', $response->json('data.env'));
    }

    public function test_the_install_step_references_an_obtainable_package(): void
    {
        $application = $this->laravelApplication();

        $instructions = $this->actingAs($this->admin())
            ->getJson("/api/admin/applications/{$application->app_code}/integration")
            ->json('data.instructions');

        $install = collect($instructions)->firstWhere('title', 'Install the agent package');

        $this->assertNotNull($install);
        // The package is installed from the downloadable archive, not from a
        // registry it was never published to.
        $this->assertStringContainsString('composer config repositories.sentinel path', $install['body']);
        $this->assertStringContainsString('composer require dost-ptri/sentinel-agent', $install['body']);
    }

    public function test_the_agent_package_can_be_downloaded(): void
    {
        $application = $this->laravelApplication();

        $response = $this->actingAs($this->admin())
            ->get("/api/admin/applications/{$application->app_code}/integration/download");

        $response->assertOk();
        $this->assertSame('application/zip', $response->headers->get('content-type'));
        $this->assertStringContainsString('sentinel-agent.zip', (string) $response->headers->get('content-disposition'));
    }

    public function test_integration_types_without_a_package_have_no_download(): void
    {
        $application = Application::factory()->create([
            'integration_type_id' => IntegrationType::factory()->create(['slug' => 'generic']),
        ]);

        $this->actingAs($this->admin())
            ->getJson("/api/admin/applications/{$application->app_code}/integration")
            ->assertOk()
            ->assertJsonPath('data.has_download', false);

        $this->actingAs($this->admin())
            ->get("/api/admin/applications/{$application->app_code}/integration/download")
            ->assertNotFound();
    }

    public function test_reissuing_fills_the_setup_in_and_revokes_the_previous_key(): void
    {
        $application = $this->laravelApplication();
        $original = app(CredentialService::class)->issue($application);

        $response = $this->actingAs($this->admin())
            ->postJson("/api/admin/applications/{$application->app_code}/integration/reissue")
            ->assertOk();

        $token = $response->json('credential.token');

        $this->assertNotSame($original->plainTextToken, $token);
        $this->assertStringContainsString($token, $response->json('data.env'));

        // Rotation, not recovery: the old key stops working immediately.
        $this->assertTrue($original->credential->fresh()->isRevoked());

        $this->postJson('/api/v1/events', [
            'application_id' => $application->app_code,
            'event_type' => 'custom_event',
            'severity' => 'INFO',
            'timestamp' => now()->toIso8601ZuluString(),
            'data' => ['message' => 'after rotation'],
        ], [config('sentinel.credentials.header') => $original->plainTextToken])
            ->assertUnauthorized();

        $this->postJson('/api/v1/events', [
            'application_id' => $application->app_code,
            'event_type' => 'custom_event',
            'severity' => 'INFO',
            'timestamp' => now()->toIso8601ZuluString(),
            'data' => ['message' => 'after rotation'],
        ], [config('sentinel.credentials.header') => $token])
            ->assertStatus(202);
    }

    public function test_non_admins_cannot_download_the_agent_or_reissue_keys(): void
    {
        $application = $this->laravelApplication();
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get("/api/admin/applications/{$application->app_code}/integration/download")
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson("/api/admin/applications/{$application->app_code}/integration/reissue")
            ->assertForbidden();
    }
}
