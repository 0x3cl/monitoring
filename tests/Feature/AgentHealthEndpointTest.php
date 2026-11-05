<?php

namespace Tests\Feature;

use DostPtri\SentinelAgent\Http\Controllers\HealthController;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The agent's health endpoint, which is what lets SENTINEL state that an
 * application is in maintenance rather than inferring it from a 503.
 *
 * The controller is exercised directly rather than over HTTP: the agent is a
 * package for monitored applications, not a dependency of SENTINEL itself, so
 * there is no host application here to route through. Its logic is what matters
 * - what it reports, and that it never throws.
 */
class AgentHealthEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sentinel.health.token', 'probe_secret');
        config()->set('sentinel.health.header', 'X-Sentinel-Probe');
        config()->set('sentinel.health.checks', ['database' => false, 'cache' => false]);
    }

    public function test_it_rejects_a_caller_without_the_probe_token(): void
    {
        $response = $this->call(new Request);

        $this->assertSame(401, $response->getStatusCode());
        // Nothing about the application is disclosed to an unauthenticated
        // caller.
        $this->assertSame(['message' => 'Unauthorized.'], $response->getData(true));
    }

    public function test_it_rejects_a_wrong_probe_token(): void
    {
        $this->assertSame(401, $this->call($this->authenticated('wrong'))->getStatusCode());
    }

    public function test_it_reports_ok_for_a_healthy_application(): void
    {
        $response = $this->call($this->authenticated());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getData(true)['status']);
        $this->assertFalse($response->getData(true)['maintenance']);
    }

    public function test_it_reports_maintenance_while_the_application_is_down(): void
    {
        // The whole reason this endpoint exists: it stays reachable during
        // `artisan down` and says so outright.
        $response = $this->call($this->authenticated(), downForMaintenance: true);

        $data = $response->getData(true);

        $this->assertSame('maintenance', $data['status']);
        $this->assertTrue($data['maintenance']);
    }

    public function test_a_failing_dependency_degrades_the_response_without_throwing(): void
    {
        config()->set('sentinel.health.checks', ['database' => true, 'cache' => false]);
        // Point the default connection at nothing, so getPdo() throws.
        config()->set('database.default', 'nonexistent-connection');

        $response = $this->call($this->authenticated());

        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('degraded', $data['status']);
        $this->assertSame('fail', $data['checks']['database']);
    }

    public function test_it_answers_any_caller_when_no_token_is_configured(): void
    {
        // No more disclosure than Laravel's own /up route.
        config()->set('sentinel.health.token', null);

        $this->assertSame(200, $this->call(new Request)->getStatusCode());
    }

    protected function authenticated(string $token = 'probe_secret'): Request
    {
        $request = new Request;
        $request->headers->set('X-Sentinel-Probe', $token);

        return $request;
    }

    protected function call(Request $request, bool $downForMaintenance = false)
    {
        $app = \Mockery::mock(ApplicationContract::class);
        $app->shouldReceive('isDownForMaintenance')->andReturn($downForMaintenance);

        return (new HealthController)($request, $app);
    }
}
