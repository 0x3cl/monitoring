<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProbeApplication;
use App\Jobs\ProbeServer;
use App\Models\Application;
use App\Models\Server;
use App\Services\Heartbeat\ServerProbe;
use Illuminate\Http\JsonResponse;

/**
 * On-demand heartbeat probes from the dashboard.
 *
 * Both actions queue the probe and return at once rather than probing inline:
 * the whole point of a probe is that the target may be unreachable, and a
 * dashboard request must never hang for the length of a TCP timeout.
 */
class HeartbeatController extends Controller
{
    public function __construct(
        protected ServerProbe $serverProbe,
    ) {}

    public function application(Application $application): JsonResponse
    {
        // Clear the cycle's cached server verdict so a manual check genuinely
        // re-examines the host rather than repeating a stale answer.
        if ($application->server !== null) {
            $this->serverProbe->forget($application->server);
        }

        ProbeApplication::dispatch($application->getKey());

        return new JsonResponse([
            'message' => 'Heartbeat probe queued.',
            'app_code' => $application->app_code,
        ], 202);
    }

    public function server(Server $server): JsonResponse
    {
        $this->serverProbe->forget($server);

        ProbeServer::dispatch($server->getKey());

        return new JsonResponse([
            'message' => 'Server probe queued.',
            'server_id' => $server->getKey(),
        ], 202);
    }
}
