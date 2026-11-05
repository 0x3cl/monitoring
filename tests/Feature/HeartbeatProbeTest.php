<?php

namespace Tests\Feature;

use App\Contracts\TcpProber;
use App\Enums\ProbeDiagnosis;
use App\Enums\UptimeStatus;
use App\Models\Application;
use App\Models\Server;
use App\Services\Heartbeat\ApplicationProbe;
use App\Support\ProbeResult;
use App\Support\TcpResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeTcpProber;
use Tests\TestCase;

/**
 * The probe's decision table: every observation it can make, and the status and
 * diagnosis it must reach. This is the heart of the feature, so each row is a
 * test of its own.
 */
class HeartbeatProbeTest extends TestCase
{
    use RefreshDatabase;

    protected FakeTcpProber $prober;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prober = new FakeTcpProber;
        $this->app->instance(TcpProber::class, $this->prober);

        config()->set('sentinel.heartbeat.slow_response_ms', 2000);
        config()->set('sentinel.heartbeat.failure_threshold', 2);
    }

    public function test_a_successful_response_is_up(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $result = $this->probe();

        $this->assertSame(UptimeStatus::Up, $result->status);
        $this->assertSame(ProbeDiagnosis::Healthy, $result->diagnosis);
        $this->assertSame(200, $result->httpStatus);
    }

    public function test_an_unresolvable_hostname_is_a_dns_failure(): void
    {
        $this->prober->cannotResolve('app.example.com');

        $result = $this->probe();

        $this->assertSame(UptimeStatus::Unreachable, $result->status);
        $this->assertSame(ProbeDiagnosis::DnsFailure, $result->diagnosis);
    }

    public function test_a_refused_connection_on_a_reachable_server_blames_the_application(): void
    {
        $server = Server::factory()->monitored('host.example.com', 22)->create();

        // The host answers on its monitored port, so it is up; the application
        // port is what refused.
        $this->prober
            ->answer('host.example.com:22', TcpResult::connected(5))
            ->answer('app.example.com:443', TcpResult::refused('Connection refused'));

        $result = $this->probe($server);

        $this->assertSame(UptimeStatus::Down, $result->status);
        $this->assertSame(ProbeDiagnosis::ConnectionRefused, $result->diagnosis);
        $this->assertTrue($result->server->isReachable());
    }

    public function test_a_refused_connection_on_a_silent_server_blames_the_server(): void
    {
        $server = Server::factory()->monitored('host.example.com', 22)->create();

        $this->prober->answerEverything(TcpResult::timedOut('Timed out'));

        $result = $this->probe($server);

        $this->assertSame(UptimeStatus::Unreachable, $result->status);
        $this->assertSame(ProbeDiagnosis::ServerUnreachable, $result->diagnosis);
        $this->assertFalse($result->server->isReachable());
    }

    public function test_a_server_error_is_down(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $result = $this->probe();

        $this->assertSame(UptimeStatus::Down, $result->status);
        $this->assertSame(ProbeDiagnosis::HttpServerError, $result->diagnosis);
    }

    public function test_a_signposted_503_is_read_as_maintenance(): void
    {
        // What `php artisan down --retry=60` serves to an application without
        // the agent installed.
        Http::fake(['*' => Http::response('Be right back.', 503, ['Retry-After' => '60'])]);

        $result = $this->probe();

        $this->assertSame(UptimeStatus::Maintenance, $result->status);
        $this->assertSame(ProbeDiagnosis::MaintenanceMode, $result->diagnosis);
    }

    public function test_maintenance_is_caught_even_when_the_health_route_is_exempt_from_it(): void
    {
        // Laravel exempts its own /up route from maintenance mode, so a health
        // probe alone reports 200 throughout `artisan down`. The root is not
        // exempt, and that is what gives the real answer.
        Http::fake([
            'app.example.com/up' => Http::response('ok', 200),
            'app.example.com/' => Http::response('Be right back.', 503, ['Retry-After' => '60']),
        ]);

        $result = $this->probe();

        $this->assertSame(UptimeStatus::Maintenance, $result->status);
        $this->assertSame(ProbeDiagnosis::MaintenanceMode, $result->diagnosis);
        // The maintenance response's code, not the 200 the exempt route gave.
        $this->assertSame(503, $result->httpStatus);
    }

    public function test_a_healthy_application_is_not_mistaken_for_maintenance(): void
    {
        Http::fake([
            'app.example.com/up' => Http::response('ok', 200),
            'app.example.com/' => Http::response('Welcome', 200),
        ]);

        $this->assertSame(UptimeStatus::Up, $this->probe()->status);
    }

    public function test_the_extra_maintenance_check_is_skipped_for_an_agented_application(): void
    {
        // The agent's payload is authoritative, so there is nothing to verify
        // and no second request to pay for.
        Http::fake([
            'app.example.com/up' => Http::response(['maintenance' => false, 'checks' => ['database' => 'ok']], 200),
        ]);

        $this->assertSame(UptimeStatus::Up, $this->probe()->status);

        Http::assertSentCount(1);
    }

    public function test_the_extra_maintenance_check_can_be_switched_off(): void
    {
        config()->set('sentinel.heartbeat.verify_maintenance', false);

        Http::fake([
            'app.example.com/up' => Http::response('ok', 200),
            'app.example.com/' => Http::response('Be right back.', 503, ['Retry-After' => '60']),
        ]);

        $this->assertSame(UptimeStatus::Up, $this->probe()->status);

        Http::assertSentCount(1);
    }

    public function test_a_client_error_is_degraded_rather_than_down(): void
    {
        // The application answered, so it is alive: the probe URL is wrong.
        Http::fake(['*' => Http::response('Not Found', 404)]);

        $result = $this->probe();

        $this->assertSame(UptimeStatus::Degraded, $result->status);
        $this->assertSame(ProbeDiagnosis::HttpClientError, $result->diagnosis);
    }

    public function test_a_slow_but_successful_response_is_degraded(): void
    {
        config()->set('sentinel.heartbeat.slow_response_ms', 0);

        Http::fake(['*' => Http::response('ok', 200)]);

        $result = $this->probe();

        $this->assertSame(UptimeStatus::Degraded, $result->status);
        $this->assertSame(ProbeDiagnosis::SlowResponse, $result->diagnosis);
    }

    public function test_the_agent_payload_states_maintenance_definitively(): void
    {
        // The agent's endpoint stays reachable during maintenance, so it
        // answers 200 while reporting that the application is down.
        Http::fake(['*' => Http::response([
            'status' => 'maintenance',
            'maintenance' => true,
            'checks' => ['database' => 'ok'],
        ], 200)]);

        $result = $this->probe();

        $this->assertSame(UptimeStatus::Maintenance, $result->status);
        $this->assertSame(ProbeDiagnosis::MaintenanceMode, $result->diagnosis);
    }

    public function test_a_failing_dependency_reported_by_the_agent_is_degraded(): void
    {
        // Serving pages perfectly well with no database behind it: invisible
        // from the outside, which is the reason the agent endpoint exists.
        Http::fake(['*' => Http::response([
            'status' => 'degraded',
            'maintenance' => false,
            'checks' => ['database' => 'fail', 'cache' => 'ok'],
        ], 200)]);

        $result = $this->probe();

        $this->assertSame(UptimeStatus::Degraded, $result->status);
        $this->assertSame(ProbeDiagnosis::DependencyFailure, $result->diagnosis);
        $this->assertStringContainsString('database', (string) $result->error);
    }

    public function test_an_application_without_a_base_url_is_not_probed(): void
    {
        $application = Application::factory()->create(['base_url' => null]);

        $result = app(ApplicationProbe::class)->probe($application);

        $this->assertSame(UptimeStatus::Unknown, $result->status);
        $this->assertSame(ProbeDiagnosis::NotConfigured, $result->diagnosis);
    }

    public function test_the_health_endpoint_is_appended_to_the_base_url(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $application = Application::factory()->probed('https://app.example.com')->create([
            'health_endpoint' => 'internal/health',
        ]);

        app(ApplicationProbe::class)->probe($application);

        Http::assertSent(fn ($request) => $request->url() === 'https://app.example.com/internal/health');
    }

    public function test_the_probe_token_is_sent_so_the_agent_will_answer(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $application = Application::factory()->probed()->create(['probe_token' => 'probe_secret']);

        app(ApplicationProbe::class)->probe($application);

        Http::assertSent(fn ($request) => $request->hasHeader('X-Sentinel-Probe', 'probe_secret'));
    }

    protected function probe(?Server $server = null): ProbeResult
    {
        $application = Application::factory()
            ->probed('https://app.example.com')
            ->create(['server_id' => $server?->getKey()]);

        return app(ApplicationProbe::class)->probe($application->load('server'));
    }
}
