<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Heartbeat\HeartbeatRecorder;
use App\Services\Heartbeat\ServerProbe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Probes one server's TCP reachability and publishes the verdict for the cycle.
 *
 * Dispatched once per server per cycle, never once per application: the
 * applications hosted on it read the cached verdict instead of each opening
 * their own connection. That is what keeps the cost of this feature
 * proportional to the number of hosts rather than the number of applications.
 */
class ProbeServer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public bool $failOnTimeout = true;

    public function __construct(public int $serverId)
    {
        $this->onQueue(config('sentinel.heartbeat.queue'));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // dontRelease: a probe already in flight has the answer, so a second
        // one should be dropped rather than queued behind it.
        return [
            (new WithoutOverlapping((string) $this->serverId))
                ->dontRelease()
                ->expireAfter(60),
        ];
    }

    public function handle(ServerProbe $probe, HeartbeatRecorder $recorder): void
    {
        $server = Server::query()->find($this->serverId);

        if ($server === null || ! $server->monitor_enabled || ! $server->is_active) {
            return;
        }

        $result = $probe->probe($server);

        // Published before the applications on this host are probed, so they
        // interpret their own failures against a fresh server verdict.
        $probe->remember($server, $result);

        $recorder->recordServer($server, $result);
    }
}
