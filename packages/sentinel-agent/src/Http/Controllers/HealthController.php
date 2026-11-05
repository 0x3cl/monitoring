<?php

namespace DostPtri\SentinelAgent\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Answers SENTINEL's heartbeat probe from inside the application.
 *
 * A probe from the outside can only see status codes. This endpoint reports
 * what no external observer can establish: whether a 503 is a deliberate
 * maintenance window or a fault, and whether an application that is serving
 * pages perfectly well has actually lost its database.
 *
 * It stays reachable while the application is down for maintenance, so it must
 * be as close to inert as possible: every check is isolated, and the endpoint
 * never throws.
 */
class HealthController
{
    public function __invoke(Request $request, Application $app): JsonResponse
    {
        if (! $this->authorized($request)) {
            // Deliberately terse: an unauthenticated caller learns nothing
            // about the application from this response.
            return new JsonResponse(['message' => 'Unauthorized.'], 401);
        }

        $maintenance = $app->isDownForMaintenance();
        $checks = $this->checks();
        $failing = array_keys(array_filter($checks, fn (string $state) => $state !== 'ok'));

        return new JsonResponse([
            'status' => match (true) {
                $maintenance => 'maintenance',
                $failing !== [] => 'degraded',
                default => 'ok',
            },
            'maintenance' => $maintenance,
            'app' => [
                'environment' => (string) config('app.env'),
                'debug' => (bool) config('app.debug'),
            ],
            'checks' => $checks,
            'time' => gmdate('c'),
        ]);
    }

    /**
     * Compared in constant time against the token SENTINEL issued.
     *
     * The ingestion credential cannot be reused here: SENTINEL stores only a
     * hash of it and so could never present it. Hence a separate probe token,
     * which is why this payload carries nothing secret.
     */
    protected function authorized(Request $request): bool
    {
        $expected = (string) config('sentinel.health.token');

        if ($expected === '') {
            // No token configured: the endpoint reports liveness to anyone who
            // asks, which is no more than the application's own /up route does.
            return true;
        }

        $presented = (string) $request->header(
            (string) config('sentinel.health.header', 'X-Sentinel-Probe'),
            ''
        );

        return $presented !== '' && hash_equals($expected, $presented);
    }

    /**
     * Each dependency is checked in isolation, because the whole value of this
     * endpoint is that it answers even when parts of the application do not.
     *
     * @return array<string, string>
     */
    protected function checks(): array
    {
        $checks = [];

        if (config('sentinel.health.checks.database', true)) {
            $checks['database'] = $this->attempt(fn () => DB::connection()->getPdo());
        }

        if (config('sentinel.health.checks.cache', true)) {
            $checks['cache'] = $this->attempt(function () {
                Cache::put('sentinel:health', 1, 10);

                return Cache::get('sentinel:health') === 1 ? true : throw new \RuntimeException('Cache round trip failed.');
            });
        }

        return $checks;
    }

    protected function attempt(callable $check): string
    {
        try {
            $check();

            return 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }
}
