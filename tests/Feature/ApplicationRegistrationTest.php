<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\IntegrationType;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_registering_an_application_issues_a_sequential_code_and_one_time_credential(): void
    {
        $type = IntegrationType::factory()->laravel()->create();
        $server = Server::factory()->create(['name' => 'PROD-SERVER-01']);

        $response = $this->actingAs($this->admin())
            ->postJson('/api/admin/applications', [
                'name' => 'Finance Management System',
                'environment' => 'production',
                'integration_type_id' => $type->id,
                'server_id' => $server->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.app_code', 'APP-00001')
            ->assertJsonPath('data.status', ApplicationStatus::PendingInstallation->value)
            ->assertJsonPath('integration.config.application_id', 'APP-00001');

        $token = $response->json('credential.token');

        $this->assertNotEmpty($token);
        $this->assertStringStartsWith('sentinel_', $token);

        // Only the hash is persisted.
        $this->assertDatabaseHas('application_credentials', [
            'token_hash' => hash('sha256', $token),
        ]);
        $this->assertDatabaseMissing('application_credentials', [
            'token_prefix' => $token,
        ]);
    }

    public function test_application_codes_increment(): void
    {
        $type = IntegrationType::factory()->laravel()->create();
        $admin = $this->admin();

        foreach (['First System', 'Second System', 'Third System'] as $index => $name) {
            $this->actingAs($admin)
                ->postJson('/api/admin/applications', [
                    'name' => $name,
                    'environment' => 'staging',
                    'integration_type_id' => $type->id,
                ])
                ->assertCreated()
                ->assertJsonPath('data.app_code', 'APP-'.str_pad((string) ($index + 1), 5, '0', STR_PAD_LEFT));
        }
    }

    public function test_registration_applies_configured_monitoring_defaults(): void
    {
        $type = IntegrationType::factory()->laravel()->create();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/admin/applications', [
                'name' => 'Inventory System',
                'environment' => 'production',
                'integration_type_id' => $type->id,
            ])->assertCreated();

        $response->assertJsonPath(
            'data.monitoring_config.thresholds.slow_request_ms',
            config('sentinel.defaults.thresholds.slow_request_ms')
        );
        $this->assertTrue($response->json('data.monitoring_config.features.http_requests'));
    }

    public function test_registration_validates_its_input(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/applications', [
                'name' => '',
                'environment' => 'nonsense',
                'integration_type_id' => 9999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'environment', 'integration_type_id']);
    }

    public function test_non_admins_cannot_register_applications(): void
    {
        $type = IntegrationType::factory()->laravel()->create();

        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->postJson('/api/admin/applications', [
                'name' => 'Sneaky System',
                'environment' => 'production',
                'integration_type_id' => $type->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_guests_cannot_reach_the_management_api(): void
    {
        $this->getJson('/api/admin/applications')->assertUnauthorized();
        $this->getJson('/api/admin/overview')->assertUnauthorized();
        $this->getJson('/api/admin/events')->assertUnauthorized();
    }

    public function test_unregistering_revokes_credentials_but_keeps_history(): void
    {
        $application = Application::factory()->create();
        $credential = $application->credentials()->create([
            'name' => 'default',
            'token_prefix' => 'sentinel_abcdefg',
            'token_hash' => hash('sha256', 'whatever'),
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/admin/applications/{$application->app_code}")
            ->assertOk();

        $this->assertNotNull($credential->fresh()->revoked_at);
        $this->assertSoftDeleted($application);
    }
}
