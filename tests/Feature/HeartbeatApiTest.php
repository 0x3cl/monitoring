<?php

namespace Tests\Feature;

use App\Enums\HealthState;
use App\Enums\UptimeStatus;
use App\Jobs\ProbeApplication;
use App\Jobs\ProbeServer;
use App\Models\Application;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * What the dashboard can see and do with heartbeat state.
 */
class HeartbeatApiTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_an_application_exposes_its_uptime_status_and_diagnosis(): void
    {
        $application = Application::factory()->down()->create();

        $this->actingAs($this->admin())
            ->getJson("/api/admin/applications/{$application->app_code}")
            ->assertOk()
            ->assertJsonPath('data.uptime_status', UptimeStatus::Down->value)
            ->assertJsonPath('data.uptime_diagnosis', 'connection_refused')
            ->assertJsonPath('data.probe_enabled', true)
            // The sentence an operator reads, rather than a bare enum.
            ->assertJsonPath(
                'data.uptime_explanation',
                'The server is reachable but refused the connection: the web server or application process is not running.'
            );
    }

    public function test_the_overview_reports_uptime_counts_and_current_outages(): void
    {
        Application::factory()->up()->create();
        $down = Application::factory()->down()->create(['name' => 'Orders API']);

        $this->actingAs($this->admin())
            ->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.uptime.up', 1)
            ->assertJsonPath('data.uptime.down', 1)
            ->assertJsonPath('data.outages.0.app_code', $down->app_code)
            ->assertJsonPath('data.outages.0.diagnosis', 'connection_refused');
    }

    public function test_an_application_that_is_down_reads_critical_at_once(): void
    {
        // No error events and a happily reporting agent: without the probe this
        // would read HEALTHY until the 15 minute silence window elapsed.
        $application = Application::factory()->down()->connected()->create();

        $this->assertSame(HealthState::Critical, $application->healthState());

        $this->actingAs($this->admin())
            ->getJson("/api/admin/applications/{$application->app_code}")
            ->assertOk()
            ->assertJsonPath('data.health', HealthState::Critical->value);
    }

    public function test_a_manual_application_check_queues_a_probe_without_waiting_for_it(): void
    {
        Queue::fake();

        $application = Application::factory()->probed()->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/applications/{$application->app_code}/probe")
            ->assertStatus(202);

        Queue::assertPushed(ProbeApplication::class, fn ($job) => $job->applicationId === $application->id);
    }

    public function test_a_manual_server_check_queues_a_probe(): void
    {
        Queue::fake();

        $server = Server::factory()->monitored()->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/servers/{$server->id}/probe")
            ->assertStatus(202);

        Queue::assertPushed(ProbeServer::class, fn ($job) => $job->serverId === $server->id);
    }

    public function test_probing_endpoints_require_an_administrator(): void
    {
        Queue::fake();

        $application = Application::factory()->probed()->create();

        $this->postJson("/api/admin/applications/{$application->app_code}/probe")
            ->assertUnauthorized();

        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->postJson("/api/admin/applications/{$application->app_code}/probe")
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_server_exposes_its_reachability_and_monitoring_target(): void
    {
        Server::factory()->monitored('host.example.com', 22)->unreachable()->create();

        $this->actingAs($this->admin())
            ->getJson('/api/admin/servers')
            ->assertOk()
            ->assertJsonPath('data.0.reachability_status', 'unreachable')
            ->assertJsonPath('data.0.monitor_target', 'host.example.com')
            ->assertJsonPath('data.0.monitor_port', 22);
    }

    public function test_the_probe_interval_is_validated(): void
    {
        $application = Application::factory()->probed()->create();

        // A floor, so an operator cannot configure a probe storm against their
        // own application.
        $this->actingAs($this->admin())
            ->patchJson("/api/admin/applications/{$application->app_code}", ['probe_interval_seconds' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('probe_interval_seconds');

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/applications/{$application->app_code}", ['probe_interval_seconds' => 300])
            ->assertOk();

        $this->assertSame(300, $application->refresh()->probe_interval_seconds);
    }

    public function test_the_new_vocabularies_are_published_to_the_dashboard(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/meta')
            ->assertOk()
            ->assertJsonPath('data.uptime_statuses.0.value', 'up')
            ->assertJsonCount(count(UptimeStatus::cases()), 'data.uptime_statuses')
            ->assertJsonPath('data.reachability_statuses.0.value', 'reachable');
    }
}
