<?php

namespace Tests\Feature;

use App\Enums\Severity;
use App\Models\Application;
use App\Models\MonitoringEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_an_administrator_can_sign_in_and_out(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@sentinel.test',
            'password' => 'password',
            'is_admin' => true,
        ]);

        $this->postJson('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.is_admin', true);

        $this->assertAuthenticatedAs($admin);

        $this->postJson('/logout')->assertOk();
        $this->assertGuest();
    }

    public function test_bad_sign_in_credentials_are_rejected(): void
    {
        User::factory()->create(['email' => 'admin@sentinel.test', 'password' => 'password']);

        $this->postJson('/login', ['email' => 'admin@sentinel.test', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_the_overview_summarizes_applications_and_events(): void
    {
        $connected = Application::factory()->connected()->create();
        Application::factory()->create();

        MonitoringEvent::factory()->count(3)->severity(Severity::Error)
            ->for($connected)->create(['occurred_at' => now()->subMinutes(5), 'received_at' => now()]);
        MonitoringEvent::factory()->severity(Severity::Critical)
            ->for($connected)->create(['occurred_at' => now()->subMinutes(5), 'received_at' => now()]);
        MonitoringEvent::factory()->severity(Severity::Info)
            ->for($connected)->create(['occurred_at' => now()->subDays(10), 'received_at' => now()->subDays(10)]);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/admin/overview?window_minutes=60')
            ->assertOk();

        $response->assertJsonPath('data.applications.registered', 2)
            ->assertJsonPath('data.applications.connected', 1)
            ->assertJsonPath('data.applications.never_connected', 1)
            ->assertJsonPath('data.events.ERROR', 3)
            ->assertJsonPath('data.events.CRITICAL', 1)
            // Outside the window.
            ->assertJsonPath('data.events.INFO', 0)
            ->assertJsonPath('data.events.total', 4);

        $this->assertCount(2, $response->json('data.health'));
    }

    public function test_events_can_be_filtered_and_paginated(): void
    {
        $first = Application::factory()->create(['app_code' => 'APP-00001']);
        $second = Application::factory()->create(['app_code' => 'APP-00002']);

        MonitoringEvent::factory()->count(5)->for($first)->severity(Severity::Error)->create();
        MonitoringEvent::factory()->count(3)->for($second)->severity(Severity::Info)->create();

        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson('/api/admin/events?application=APP-00001')
            ->assertOk()
            ->assertJsonCount(5, 'data');

        $this->actingAs($admin)
            ->getJson('/api/admin/events?severity=INFO')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->actingAs($admin)
            ->getJson('/api/admin/events?min_severity=ERROR')
            ->assertOk()
            ->assertJsonCount(5, 'data');

        $this->actingAs($admin)
            ->getJson('/api/admin/events?per_page=4')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.total', 8)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_event_payloads_are_visible_to_administrators(): void
    {
        $application = Application::factory()->create();
        $event = MonitoringEvent::factory()->for($application)->create();

        $this->actingAs($this->admin())
            ->getJson("/api/admin/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $event->id)
            ->assertJsonPath('data.application.app_code', $application->app_code);
    }

    public function test_meta_exposes_the_event_vocabularies(): void
    {
        $response = $this->actingAs($this->admin())->getJson('/api/admin/meta')->assertOk();

        $this->assertCount(5, $response->json('data.severities'));
        $this->assertCount(7, $response->json('data.event_types'));
        $this->assertSame('DEBUG', $response->json('data.severities.0.value'));
    }

    public function test_credentials_are_listed_without_ever_exposing_the_token(): void
    {
        $application = Application::factory()->create();

        $issue = $this->actingAs($this->admin())
            ->postJson("/api/admin/applications/{$application->app_code}/credentials", ['name' => 'ci'])
            ->assertCreated();

        $token = $issue->json('credential.token');

        $listing = $this->actingAs($this->admin())
            ->getJson("/api/admin/applications/{$application->app_code}/credentials")
            ->assertOk();

        $this->assertStringNotContainsString($token, $listing->getContent());
        $this->assertStringNotContainsString('token_hash', $listing->getContent());
    }

    public function test_the_integration_bundle_omits_the_credential(): void
    {
        $application = Application::factory()->create();

        $this->actingAs($this->admin())
            ->getJson("/api/admin/applications/{$application->app_code}/integration")
            ->assertOk()
            ->assertJsonPath('data.config.credential', null)
            ->assertJsonPath('data.config.application_id', $application->app_code);
    }

    public function test_an_application_can_be_updated(): void
    {
        $application = Application::factory()->create(['name' => 'Old Name']);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/applications/{$application->app_code}", [
                'name' => 'New Name',
                'status' => 'active',
                'monitoring_config' => ['thresholds' => ['slow_request_ms' => 1500]],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.monitoring_config.thresholds.slow_request_ms', 1500);
    }

    public function test_servers_can_be_managed(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/admin/servers', ['name' => 'PROD-SERVER-02', 'provider' => 'Hetzner'])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/admin/servers', ['name' => 'PROD-SERVER-02'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->actingAs($admin)->getJson('/api/admin/servers')->assertOk()->assertJsonCount(1, 'data');
    }
}
