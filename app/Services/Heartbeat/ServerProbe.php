<?php

namespace App\Services\Heartbeat;

use App\Contracts\TcpProber;
use App\Enums\ProbeDiagnosis;
use App\Enums\ReachabilityStatus;
use App\Models\Server;
use App\Support\ServerProbeResult;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Decides whether a server is answering on its monitored port.
 *
 * The result is cached for the length of a probe cycle, which is the single
 * most important measure against bottlenecks here: fifty applications hosted
 * on one server cost one TCP probe per cycle, not fifty.
 */
class ServerProbe
{
    public function __construct(
        protected TcpProber $prober,
        protected Cache $cache,
    ) {}

    /**
     * Probe the server, ignoring any cached verdict.
     */
    public function probe(Server $server): ServerProbeResult
    {
        $host = $this->targetHost($server);

        if ($host === null || ! $server->monitor_enabled) {
            return ServerProbeResult::unknown();
        }

        $port = $server->monitor_port ?: (int) config('sentinel.heartbeat.default_server_port');

        $result = $this->prober->connect(
            $host,
            $port,
            (float) config('sentinel.heartbeat.tcp_timeout'),
        );

        if ($result->connected) {
            return new ServerProbeResult(
                status: ReachabilityStatus::Reachable,
                diagnosis: ProbeDiagnosis::Healthy,
                host: $host,
                port: $port,
                latencyMs: $result->latencyMs,
            );
        }

        // A refused connection still proves the host is up: something answered
        // the packet to say nothing is listening there. That is a reachable
        // server with a dead service, not an unreachable server.
        if ($result->refused) {
            return new ServerProbeResult(
                status: ReachabilityStatus::Reachable,
                diagnosis: ProbeDiagnosis::ConnectionRefused,
                host: $host,
                port: $port,
                error: $result->error,
            );
        }

        return new ServerProbeResult(
            status: ReachabilityStatus::Unreachable,
            diagnosis: $this->diagnose($result->timedOut, $host),
            host: $host,
            port: $port,
            error: $result->error,
        );
    }

    /**
     * The verdict for this server in the current cycle, probing only if no
     * other job has already done so.
     */
    public function cached(Server $server): ServerProbeResult
    {
        $cached = $this->cache->get($this->cacheKey($server));

        if (is_array($cached)) {
            return ServerProbeResult::fromArray($cached);
        }

        $result = $this->probe($server);

        $this->remember($server, $result);

        return $result;
    }

    /**
     * Publish a verdict for the rest of this cycle.
     *
     * The TTL sits just under the probe interval so a stale verdict can never
     * outlive the cycle that produced it.
     */
    public function remember(Server $server, ServerProbeResult $result): void
    {
        $ttl = max(10, (int) config('sentinel.heartbeat.interval_seconds') - 5);

        $this->cache->put($this->cacheKey($server), $result->toArray(), $ttl);
    }

    public function forget(Server $server): void
    {
        $this->cache->forget($this->cacheKey($server));
    }

    /**
     * Explicitly monitored address first, then whatever the server was
     * documented with.
     */
    public function targetHost(Server $server): ?string
    {
        foreach ([$server->monitor_host, $server->hostname, $server->ip_address] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    protected function diagnose(bool $timedOut, string $host): ProbeDiagnosis
    {
        if (! $this->prober->resolves($host)) {
            return ProbeDiagnosis::DnsFailure;
        }

        return $timedOut ? ProbeDiagnosis::ConnectionTimeout : ProbeDiagnosis::ServerUnreachable;
    }

    protected function cacheKey(Server $server): string
    {
        return 'sentinel:server-reachability:'.$server->getKey();
    }
}
