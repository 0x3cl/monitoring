<?php

namespace Tests\Feature;

use App\Contracts\TcpProber;
use App\Jobs\ProbeApplication;
use App\Jobs\ProbeServer;
use App\Models\Application;
use App\Models\Server;
use App\Services\Heartbeat\ServerProbe;
use App\Support\TcpResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeTcpProber;
use Tests\TestCase;

/**
 * The dispatcher's job is to stay cheap no matter how many applications are
 * registered: it must select only what is due, and it must never dispatch a
 * server probe per application.
 */
class HeartbeatDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_applications_that_are_due_are_dispatched(): void
    {
        Queue::fake();

        $due = Application::factory()->probed()->create(['next_probe_at' => now()->subMinute()]);
        $neverProbed = Application::factory()->probed()->create(['next_probe_at' => null]);
        $notYetDue = Application::factory()->probed()->create(['next_probe_at' => now()->addMinutes(5)]);

        $this->artisan('sentinel:dispatch-heartbeats')->assertSuccessful();

        Queue::assertPushed(ProbeApplication::class, 2);
        Queue::assertPushed(ProbeApplication::class, fn ($job) => $job->applicationId === $due->id);
        Queue::assertPushed(ProbeApplication::class, fn ($job) => $job->applicationId === $neverProbed->id);
        Queue::assertNotPushed(ProbeApplication::class, fn ($job) => $job->applicationId === $notYetDue->id);
    }

    public function test_applications_without_a_probe_target_or_with_probing_off_are_skipped(): void
    {
        Queue::fake();

        Application::factory()->create(['base_url' => null, 'next_probe_at' => null]);
        Application::factory()->probed()->create(['probe_enabled' => false]);
        Application::factory()->probed()->disabled()->create();

        $this->artisan('sentinel:dispatch-heartbeats')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_one_server_probe_is_dispatched_per_server_not_per_application(): void
    {
        Queue::fake();

        $server = Server::factory()->monitored()->create();

        Application::factory()->count(10)->probed()->create([
            'server_id' => $server->id,
            'next_probe_at' => now()->subMinute(),
        ]);

        $this->artisan('sentinel:dispatch-heartbeats')->assertSuccessful();

        // Ten applications on one host, one server probe: this is what keeps
        // the feature's cost proportional to hosts rather than applications.
        Queue::assertPushed(ProbeApplication::class, 10);
        Queue::assertPushed(ProbeServer::class, 1);
    }

    public function test_probes_are_queued_on_their_own_queue(): void
    {
        Queue::fake();

        Application::factory()->probed()->create(['next_probe_at' => null]);

        $this->artisan('sentinel:dispatch-heartbeats')->assertSuccessful();

        // Probing is latency bound, so it must not share a queue with event
        // processing.
        Queue::assertPushed(
            ProbeApplication::class,
            fn ($job) => $job->queue === config('sentinel.heartbeat.queue')
        );
    }

    public function test_dispatching_can_be_switched_off_entirely(): void
    {
        Queue::fake();
        config()->set('sentinel.heartbeat.enabled', false);

        Application::factory()->probed()->create(['next_probe_at' => null]);

        $this->artisan('sentinel:dispatch-heartbeats')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_a_named_application_is_probed_regardless_of_its_schedule(): void
    {
        Queue::fake();

        $application = Application::factory()->probed()->create([
            'app_code' => 'APP-09999',
            'next_probe_at' => now()->addHour(),
        ]);

        $this->artisan('sentinel:dispatch-heartbeats', ['--application' => ['APP-09999']])
            ->assertSuccessful();

        Queue::assertPushed(ProbeApplication::class, fn ($job) => $job->applicationId === $application->id);
    }

    public function test_a_shared_server_is_probed_once_per_cycle_not_once_per_application(): void
    {
        $prober = new FakeTcpProber;
        $prober->answerEverything(TcpResult::connected(8));
        $this->app->instance(TcpProber::class, $prober);

        $server = Server::factory()->monitored('host.example.com', 22)->create();
        $probe = app(ServerProbe::class);

        // Every application on the host asks for the verdict; only the first
        // one pays for it.
        foreach (range(1, 5) as $ignored) {
            $probe->cached($server);
        }

        $this->assertSame(1, $prober->callCount('host.example.com'));
    }
}
