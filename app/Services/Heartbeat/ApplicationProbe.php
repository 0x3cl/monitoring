<?php

namespace App\Services\Heartbeat;

use App\Contracts\TcpProber;
use App\Enums\ProbeDiagnosis;
use App\Enums\UptimeStatus;
use App\Models\Application;
use App\Support\ProbeResult;
use App\Support\ServerProbeResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;

/**
 * Probes an application from the outside and names the layer that failed.
 *
 * The layers are tried cheapest first and short-circuit: there is no point
 * spending an HTTP timeout on a host whose name does not resolve. Each failure
 * is then interpreted against the server's own reachability, because the same
 * observation means different things depending on it — a refused connection on
 * a live host means the application died, while the same refusal on a silent
 * host means the host did.
 */
class ApplicationProbe
{
    public function __construct(
        protected Http $http,
        protected TcpProber $prober,
        protected ServerProbe $serverProbe,
    ) {}

    public function probe(Application $application): ProbeResult
    {
        $url = $this->probeUrl($application);

        if ($url === null) {
            return new ProbeResult(
                status: UptimeStatus::Unknown,
                diagnosis: ProbeDiagnosis::NotConfigured,
            );
        }

        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        if (! is_string($host) || $host === '') {
            return new ProbeResult(
                status: UptimeStatus::Unknown,
                diagnosis: ProbeDiagnosis::NotConfigured,
                url: $url,
            );
        }

        $secure = ($parts['scheme'] ?? 'http') === 'https';
        $port = (int) ($parts['port'] ?? ($secure ? 443 : 80));

        // Layer 1 — DNS. Reported on its own because an unresolvable name is an
        // operator error, not an outage.
        if (! $this->prober->resolves($host)) {
            return new ProbeResult(
                status: UptimeStatus::Unreachable,
                diagnosis: ProbeDiagnosis::DnsFailure,
                url: $url,
                server: $this->serverVerdict($application),
            );
        }

        // Layer 2 — TCP. Establishes whether anything is listening at all, and
        // on HTTPS collects the certificate expiry from the same handshake the
        // probe already needs.
        $tcp = $this->prober->connect(
            $host,
            $port,
            (float) config('sentinel.heartbeat.tcp_timeout'),
            $secure,
        );

        if (! $tcp->connected) {
            return $this->fromFailedConnection($application, $url, $tcp->refused, $tcp->timedOut, $tcp->error);
        }

        // Layer 3 — HTTP against the health endpoint.
        return $this->fromHttp($application, $url, $tcp->certificateExpiresAt);
    }

    /**
     * The URL to probe: the application's own health endpoint when it declares
     * one, otherwise the configured default (Laravel serves /up out of the box).
     */
    public function probeUrl(Application $application): ?string
    {
        $base = trim((string) $application->base_url);

        if ($base === '') {
            return null;
        }

        $path = trim((string) ($application->health_endpoint ?: config('sentinel.heartbeat.default_health_path')));

        if ($path === '') {
            return rtrim($base, '/');
        }

        // An absolute health endpoint overrides the base URL entirely, which is
        // how an application behind a different hostname is probed.
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim($base, '/').'/'.ltrim($path, '/');
    }

    /**
     * Nothing accepted the connection. Whether that is the application's fault
     * or the host's is decided by the server probe.
     */
    protected function fromFailedConnection(
        Application $application,
        string $url,
        bool $refused,
        bool $timedOut,
        ?string $error,
    ): ProbeResult {
        $server = $this->serverVerdict($application);

        // The host is silent, so blame the host rather than the application.
        if ($server !== null && ! $server->isReachable()) {
            return new ProbeResult(
                status: UptimeStatus::Unreachable,
                diagnosis: ProbeDiagnosis::ServerUnreachable,
                url: $url,
                error: $error,
                server: $server,
            );
        }

        // A refusal answers the question by itself: something is up on that
        // address and the application is not it.
        if ($refused) {
            return new ProbeResult(
                status: UptimeStatus::Down,
                diagnosis: ProbeDiagnosis::ConnectionRefused,
                url: $url,
                error: $error,
                server: $server,
            );
        }

        // A timeout with no server verdict to lean on is ambiguous between the
        // host and the application, so it is reported as unreachable: the
        // honest answer is that nothing was reached.
        $serverKnownUp = $server !== null && $server->isReachable();

        return new ProbeResult(
            status: $serverKnownUp ? UptimeStatus::Down : UptimeStatus::Unreachable,
            diagnosis: $timedOut ? ProbeDiagnosis::ConnectionTimeout : ProbeDiagnosis::ServerUnreachable,
            url: $url,
            error: $error,
            server: $server,
        );
    }

    /**
     * The connection succeeded, so the application is reachable and the
     * question becomes what it said.
     */
    protected function fromHttp(Application $application, string $url, ?string $certificateExpiresAt): ProbeResult
    {
        $header = (string) config('sentinel.heartbeat.probe_header');
        $token = $application->probe_token;

        $request = $this->http
            ->connectTimeout((float) config('sentinel.heartbeat.connect_timeout'))
            ->timeout((float) config('sentinel.heartbeat.timeout'))
            ->withoutRedirecting()
            ->withHeaders(array_filter([
                'Accept' => 'application/json',
                'User-Agent' => 'SENTINEL-Heartbeat/1.0',
                $header => $token,
            ]));

        $startedAt = microtime(true);

        try {
            $response = $request->get($url);
        } catch (ConnectionException $exception) {
            $message = $exception->getMessage();

            // The TCP handshake had already succeeded, so the host is up and
            // the application accepted the connection without finishing the
            // response: it is running but hung.
            return new ProbeResult(
                status: UptimeStatus::Down,
                diagnosis: str_contains(strtolower($message), 'ssl') || str_contains(strtolower($message), 'certificate')
                    ? ProbeDiagnosis::TlsFailure
                    : ProbeDiagnosis::HttpTimeout,
                url: $url,
                responseTimeMs: (int) round((microtime(true) - $startedAt) * 1000),
                error: $message,
                server: $this->serverVerdict($application),
                certificateExpiresAt: $certificateExpiresAt,
            );
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        return $this->interpret($application, $response, $url, $elapsedMs, $certificateExpiresAt);
    }

    /**
     * Turn a response into a status.
     *
     * The agent's own health payload is consulted first: it is the only source
     * that can state maintenance mode and internal dependency health as fact
     * rather than inference.
     */
    protected function interpret(
        Application $application,
        Response $response,
        string $url,
        int $elapsedMs,
        ?string $certificateExpiresAt,
    ): ProbeResult {
        $payload = $this->agentPayload($response);
        $server = $this->serverVerdict($application);

        $common = [
            'url' => $url,
            'httpStatus' => $response->status(),
            'responseTimeMs' => $elapsedMs,
            'server' => $server,
            'certificateExpiresAt' => $certificateExpiresAt,
        ];

        if ($payload !== null) {
            $checks = (array) Arr::get($payload, 'checks', []);

            if (Arr::get($payload, 'maintenance') === true || Arr::get($payload, 'status') === 'maintenance') {
                return new ProbeResult(
                    ...$common,
                    status: UptimeStatus::Maintenance,
                    diagnosis: ProbeDiagnosis::MaintenanceMode,
                    checks: $checks,
                );
            }

            $failing = array_keys(array_filter(
                $checks,
                fn ($state) => in_array($state, ['fail', 'failed', false], true)
            ));

            if ($failing !== []) {
                return new ProbeResult(
                    ...$common,
                    status: UptimeStatus::Degraded,
                    diagnosis: ProbeDiagnosis::DependencyFailure,
                    error: 'Failing checks: '.implode(', ', $failing),
                    checks: $checks,
                );
            }
        }

        if ($response->successful()) {
            // A 2xx from a health route is not proof that the application is
            // serving traffic. Laravel exempts its own /up route from
            // maintenance mode (and so does this agent's endpoint), so an
            // application that is deliberately down still answers 200 there.
            // Without an agent payload to state the truth, the only way to see
            // maintenance is to ask for a path that is not exempt.
            if ($payload === null && ($maintenance = $this->verifyMaintenance($application, $url)) !== null) {
                return new ProbeResult(
                    // The maintenance response's own status code, not the 200
                    // the exempt health route returned.
                    ...[...$common, 'httpStatus' => $maintenance],
                    status: UptimeStatus::Maintenance,
                    diagnosis: ProbeDiagnosis::MaintenanceMode,
                );
            }

            $slowAfter = (int) config('sentinel.heartbeat.slow_response_ms');

            return new ProbeResult(
                ...$common,
                status: $elapsedMs > $slowAfter ? UptimeStatus::Degraded : UptimeStatus::Up,
                diagnosis: $elapsedMs > $slowAfter ? ProbeDiagnosis::SlowResponse : ProbeDiagnosis::Healthy,
                checks: $payload !== null ? (array) Arr::get($payload, 'checks', []) : [],
            );
        }

        // Laravel's maintenance response is a 503. It only carries Retry-After
        // when `artisan down --retry=` was used, so for an application without
        // the agent this is a heuristic and not proof. Applications running the
        // agent never reach this branch: their payload said so above.
        if ($response->status() === 503 && $this->looksLikeMaintenance($response)) {
            return new ProbeResult(
                ...$common,
                status: UptimeStatus::Maintenance,
                diagnosis: ProbeDiagnosis::MaintenanceMode,
            );
        }

        if ($response->serverError()) {
            return new ProbeResult(
                ...$common,
                status: UptimeStatus::Down,
                diagnosis: ProbeDiagnosis::HttpServerError,
                error: 'HTTP '.$response->status(),
            );
        }

        // A 4xx means the application is alive and rejected the probe URL,
        // which is a misconfigured health endpoint rather than an outage.
        return new ProbeResult(
            ...$common,
            status: UptimeStatus::Degraded,
            diagnosis: ProbeDiagnosis::HttpClientError,
            error: 'HTTP '.$response->status(),
        );
    }

    /**
     * Ask a path that maintenance mode does *not* exempt, and report the status
     * code when it says the application is unavailable.
     *
     * Only reached for an application with no agent installed, and only after
     * its health endpoint already answered, so this costs one extra request per
     * probe for unagented applications and none at all for the rest. Returns
     * null when the application is genuinely serving traffic.
     */
    protected function verifyMaintenance(Application $application, string $probedUrl): ?int
    {
        if (! config('sentinel.heartbeat.verify_maintenance', true)) {
            return null;
        }

        $root = rtrim((string) $application->base_url, '/').'/';

        // The health endpoint *is* the root, so it was not exempt and the 2xx
        // already settled the question.
        if ($root === $probedUrl || rtrim($probedUrl, '/') === rtrim($root, '/')) {
            return null;
        }

        try {
            $response = $this->http
                ->connectTimeout((float) config('sentinel.heartbeat.connect_timeout'))
                ->timeout((float) config('sentinel.heartbeat.timeout'))
                ->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'SENTINEL-Heartbeat/1.0'])
                ->head($root);
        } catch (ConnectionException) {
            // The health endpoint answered, so the application is up; this
            // extra probe failing proves nothing on its own.
            return null;
        }

        return $response->status() === 503 && $this->looksLikeMaintenance($response)
            ? $response->status()
            : null;
    }

    /**
     * The agent's health payload, when this response is one.
     *
     * @return array<string, mixed>|null
     */
    protected function agentPayload(Response $response): ?array
    {
        if (! str_contains((string) $response->header('Content-Type'), 'json')) {
            return null;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        // Only a payload that speaks the agent's vocabulary is trusted to
        // override the status code; any other JSON body is just a body.
        return array_key_exists('maintenance', $payload) || array_key_exists('checks', $payload)
            ? $payload
            : null;
    }

    protected function looksLikeMaintenance(Response $response): bool
    {
        if ($response->header('Retry-After') !== '') {
            return true;
        }

        $body = strtolower(mb_substr($response->body(), 0, 2000));

        foreach (['be right back', 'maintenance', 'service unavailable'] as $signature) {
            if (str_contains($body, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The server's verdict for this cycle, shared with every other application
     * on the same host.
     */
    protected function serverVerdict(Application $application): ?ServerProbeResult
    {
        if ($application->server === null) {
            return null;
        }

        return $this->serverProbe->cached($application->server);
    }
}
