<?php

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Enums\EventType;
use App\Enums\ProbeDiagnosis;
use App\Enums\Severity;
use App\Enums\UptimeStatus;
use App\Models\Application;
use App\Models\MonitoringEvent;
use App\Services\Heartbeat\HeartbeatRecorder;
use App\Support\ProbeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How probe outcomes become stored state and events.
 *
 * Two properties matter most here: a single failure must never declare an
 * outage, and a transition must write exactly one event - the events table has
 * to grow with incidents, not with the number of probes.
 */
class HeartbeatRecorderTest extends TestCase
{
    use RefreshDatabase;

    protected HeartbeatRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sentinel.heartbeat.failure_threshold', 2);

        $this->recorder = app(HeartbeatRecorder::class);
    }

    public function test_a_single_failure_does_not_declare_an_outage(): void
    {
        $application = Application::factory()->up()->create();

        $event = $this->recorder->record($application, $this->down());

        $application->refresh();

        // Still up: one blip is not an incident.
        $this->assertSame(UptimeStatus::Up, $application->uptime_status);
        $this->assertSame(1, $application->consecutive_failures);
        $this->assertNull($event);
        $this->assertSame(0, MonitoringEvent::query()->count());
    }

    public function test_the_second_consecutive_failure_declares_the_outage_once(): void
    {
        $application = Application::factory()->up()->create();

        $this->recorder->record($application, $this->down());
        $event = $this->recorder->record($application->refresh(), $this->down());

        $application->refresh();

        $this->assertSame(UptimeStatus::Down, $application->uptime_status);
        $this->assertSame(ProbeDiagnosis::ConnectionRefused, $application->uptime_diagnosis);
        $this->assertNotNull($event);
        $this->assertSame(EventType::ApplicationStatus, $event->event_type);
        $this->assertSame(Severity::Critical, $event->severity);
        $this->assertSame(1, MonitoringEvent::query()->count());
    }

    public function test_a_continuing_outage_writes_no_further_events(): void
    {
        $application = Application::factory()->up()->create();

        foreach (range(1, 5) as $ignored) {
            $this->recorder->record($application->refresh(), $this->down());
        }

        // One event for the transition, and nothing for the four probes that
        // merely confirmed it.
        $this->assertSame(1, MonitoringEvent::query()->count());
    }

    public function test_recovery_writes_a_single_informational_event(): void
    {
        $application = Application::factory()->down()->create();

        $event = $this->recorder->record($application, $this->up());

        $application->refresh();

        $this->assertSame(UptimeStatus::Up, $application->uptime_status);
        $this->assertSame(0, $application->consecutive_failures);
        $this->assertNotNull($event);
        $this->assertSame(Severity::Info, $event->severity);
        $this->assertStringContainsString('recovered', $event->message);
        // How long it was out, derived from the transition timestamps rather
        // than from a per-probe history table.
        $this->assertArrayHasKey('downtime_seconds', $event->data);
    }

    public function test_maintenance_is_recorded_immediately_rather_than_as_an_outage(): void
    {
        $application = Application::factory()->up()->create();

        $event = $this->recorder->record($application, $this->maintenance());

        $application->refresh();

        // A deliberate act by an operator: no threshold to clear.
        $this->assertSame(UptimeStatus::Maintenance, $application->uptime_status);
        $this->assertSame(0, $application->consecutive_failures);
        $this->assertSame(Severity::Warning, $event->severity);
    }

    public function test_a_probe_never_makes_a_silent_application_look_like_it_reported_in(): void
    {
        // The guard against routing these events through EventIngestor, whose
        // ingest() finishes with markConnected().
        $application = Application::factory()->up()->create([
            'connection_status' => ConnectionStatus::Disconnected,
            'last_event_at' => null,
        ]);

        $this->recorder->record($application, $this->down());
        $this->recorder->record($application->refresh(), $this->down());

        $application->refresh();

        $this->assertNull($application->last_event_at);
        $this->assertSame(ConnectionStatus::Disconnected, $application->connection_status);
    }

    public function test_the_next_probe_is_scheduled_with_jitter_inside_the_interval(): void
    {
        config()->set('sentinel.heartbeat.jitter_percent', 10);

        $application = Application::factory()->up()->create(['probe_interval_seconds' => 60]);

        $this->recorder->record($application, $this->up());

        $seconds = now()->diffInSeconds($application->refresh()->next_probe_at);

        // 60s +/- 10%, so a fleet spreads across the interval instead of
        // stampeding at the top of every minute.
        $this->assertGreaterThanOrEqual(53, $seconds);
        $this->assertLessThanOrEqual(67, $seconds);
    }

    public function test_a_changed_reason_for_the_same_status_is_still_recorded(): void
    {
        $application = Application::factory()->down()->create([
            'uptime_diagnosis' => ProbeDiagnosis::HttpServerError,
        ]);

        $this->recorder->record($application, $this->down());

        // Still down, but for a different reason now - worth storing, not worth
        // a second event.
        $this->assertSame(ProbeDiagnosis::ConnectionRefused, $application->refresh()->uptime_diagnosis);
        $this->assertSame(0, MonitoringEvent::query()->count());
    }

    protected function down(): ProbeResult
    {
        return new ProbeResult(
            status: UptimeStatus::Down,
            diagnosis: ProbeDiagnosis::ConnectionRefused,
            url: 'https://app.example.com/up',
        );
    }

    protected function up(): ProbeResult
    {
        return new ProbeResult(
            status: UptimeStatus::Up,
            diagnosis: ProbeDiagnosis::Healthy,
            url: 'https://app.example.com/up',
            httpStatus: 200,
            responseTimeMs: 42,
        );
    }

    protected function maintenance(): ProbeResult
    {
        return new ProbeResult(
            status: UptimeStatus::Maintenance,
            diagnosis: ProbeDiagnosis::MaintenanceMode,
            url: 'https://app.example.com/up',
            httpStatus: 503,
        );
    }
}
