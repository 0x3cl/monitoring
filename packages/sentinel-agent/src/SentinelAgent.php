<?php

namespace DostPtri\SentinelAgent;

use DostPtri\SentinelAgent\Support\CorrelationId;
use DostPtri\SentinelAgent\Support\Sanitizer;
use DostPtri\SentinelAgent\Transport\HttpTransport;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;
use Throwable;

/**
 * The agent's public surface: builds standardized SENTINEL events and hands
 * them to the transport.
 *
 * Every entry point is guarded — a monitoring failure must never surface as an
 * error in the application being monitored.
 */
class SentinelAgent
{
    public function __construct(
        protected Config $config,
        protected HttpTransport $transport,
        protected Sanitizer $sanitizer,
        protected CorrelationId $correlation,
    ) {}

    /**
     * Report an arbitrary event. The usual entry point for application code.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(string $eventType, string $severity, array $data): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            return $this->transport->send($this->buildEvent($eventType, $severity, $data));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function custom(string $message, array $data = [], string $severity = 'INFO'): bool
    {
        if (! $this->feature('custom_events')) {
            return false;
        }

        return $this->record('custom_event', $severity, array_merge($data, ['message' => $message]));
    }

    public function exception(Throwable $exception, string $severity = 'ERROR'): bool
    {
        $isDatabase = $this->isDatabaseException($exception);

        if (! $this->feature($isDatabase ? 'database_errors' : 'exceptions')) {
            return false;
        }

        return $this->record(
            $isDatabase ? 'database_error' : 'exception',
            $isDatabase ? 'CRITICAL' : $severity,
            [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $this->trace($exception),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function request(array $data, int $status, int $durationMs): bool
    {
        $slow = $durationMs >= (int) $this->config->get('sentinel.thresholds.slow_request_ms', 3000);

        if ($slow && $this->feature('slow_requests')) {
            return $this->record('slow_request', 'WARNING', $data);
        }

        if (! $this->feature('http_requests')) {
            return false;
        }

        return $this->record('http_request', $this->severityForStatus($status), $data);
    }

    public function correlationId(): string
    {
        return $this->correlation->get();
    }

    public function ping(): bool
    {
        return $this->transport->ping();
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('sentinel.enabled', true)
            && $this->config->get('sentinel.application_id')
            && $this->config->get('sentinel.endpoint')
            && $this->config->get('sentinel.key');
    }

    public function feature(string $name): bool
    {
        return $this->enabled() && (bool) $this->config->get("sentinel.features.{$name}", true);
    }

    /**
     * Successful, fast traffic is mostly noise; errors and slow requests are
     * always kept regardless of the sample rate.
     */
    public function shouldSample(int $status, int $durationMs): bool
    {
        if ($status >= 400 || $durationMs >= (int) $this->config->get('sentinel.thresholds.slow_request_ms', 3000)) {
            return true;
        }

        $rate = (float) $this->config->get('sentinel.thresholds.sample_rate', 1.0);

        if ($rate >= 1.0) {
            return true;
        }

        return $rate > 0 && (mt_rand() / mt_getrandmax()) <= $rate;
    }

    public function isExcluded(string $path): bool
    {
        $path = ltrim($path, '/');

        foreach ((array) $this->config->get('sentinel.excluded_routes', []) as $pattern) {
            if (Str::is(ltrim((string) $pattern, '/'), $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function buildEvent(string $eventType, string $severity, array $data): array
    {
        return [
            'application_id' => (string) $this->config->get('sentinel.application_id'),
            'event_type' => $eventType,
            'severity' => $severity,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'correlation_id' => $this->correlation->get(),
            'data' => $this->sanitizer->clean($data),
        ];
    }

    protected function severityForStatus(int $status): string
    {
        return match (true) {
            $status >= 500 => 'ERROR',
            $status >= 400 => 'WARNING',
            default => 'INFO',
        };
    }

    protected function isDatabaseException(Throwable $exception): bool
    {
        return $exception instanceof \PDOException
            || is_a($exception, 'Illuminate\Database\QueryException')
            || is_a($exception, 'Illuminate\Database\ConnectionException');
    }

    /**
     * A bounded trace: enough to locate the fault, not the whole stack.
     *
     * @return array<int, string>
     */
    protected function trace(Throwable $exception, int $frames = 10): array
    {
        return array_map(
            fn (array $frame) => sprintf(
                '%s:%s %s%s%s()',
                $frame['file'] ?? '[internal]',
                $frame['line'] ?? '?',
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? ''
            ),
            array_slice($exception->getTrace(), 0, $frames)
        );
    }
}
