<?php

namespace DostPtri\SentinelAgent\Http\Middleware;

use Closure;
use DostPtri\SentinelAgent\SentinelAgent;
use DostPtri\SentinelAgent\Support\CorrelationId;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Times every request and reports it after the response has been sent.
 *
 * Reporting happens in terminate(), so the monitored application never waits
 * on SENTINEL to answer a request.
 */
class SentinelRequestMonitor
{
    /**
     * Laravel resolves middleware from the container again for terminate(),
     * so the start time is carried on the request rather than on $this —
     * instance state does not survive between the two calls.
     */
    public const STARTED_AT = 'sentinel_started_at';

    public function __construct(
        protected SentinelAgent $agent,
        protected CorrelationId $correlation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::STARTED_AT, microtime(true));

        // Adopt an upstream correlation id when one is present, so a request
        // crossing several services keeps one identifier.
        $this->correlation->set($request->header(CorrelationId::HEADER));

        $response = $next($request);

        if (method_exists($response, 'header')) {
            $response->headers->set(CorrelationId::HEADER, $this->correlation->get());
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! $this->agent->enabled() || $this->agent->isExcluded($request->path())) {
                return;
            }

            $startedAt = $request->attributes->get(self::STARTED_AT);

            // No start time means handle() never ran for this request, so
            // there is nothing meaningful to measure.
            if (! is_float($startedAt)) {
                return;
            }

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $status = $response->getStatusCode();

            if (! $this->agent->shouldSample($status, $durationMs)) {
                return;
            }

            $this->agent->request([
                'method' => $request->getMethod(),
                'endpoint' => '/'.ltrim($request->path(), '/'),
                'route' => $request->route()?->getName(),
                'http_status' => $status,
                'response_time_ms' => $durationMs,
                'message' => $this->message($status),
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                // Deliberately no request body, headers or session: SENTINEL
                // must never receive credentials or personal data.
            ], $status, $durationMs);
        } catch (Throwable) {
            // Monitoring never breaks the application it monitors.
        }
    }

    protected function message(int $status): string
    {
        return match (true) {
            $status >= 500 => 'Server error',
            $status >= 400 => 'Client error',
            default => 'OK',
        };
    }
}
