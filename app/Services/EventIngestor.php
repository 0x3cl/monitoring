<?php

namespace App\Services;

use App\Enums\ConnectionStatus;
use App\Enums\EventType;
use App\Enums\Severity;
use App\Models\Application;
use App\Models\MonitoringEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * Turns a validated monitoring event payload into a stored row.
 *
 * Hot fields are lifted out of the JSON payload into dedicated columns so the
 * dashboard can filter and aggregate without scanning JSON on every query.
 */
class EventIngestor
{
    public function __construct(
        protected EventSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ingest(Application $application, array $payload): MonitoringEvent
    {
        $eventType = EventType::from($payload['event_type']);
        $severity = Severity::from($payload['severity']);
        $data = $this->sanitizer->sanitize((array) ($payload['data'] ?? []));

        $occurredAt = CarbonImmutable::parse($payload['timestamp'])->utc();

        $event = $application->monitoringEvents()->create([
            'server_id' => $application->server_id,
            'event_type' => $eventType,
            'severity' => $severity,
            'occurred_at' => $occurredAt,
            'received_at' => now(),
            'correlation_id' => $this->stringOrNull($payload['correlation_id'] ?? Arr::get($data, 'correlation_id'), 64),
            'fingerprint' => $this->fingerprint($application, $eventType, $data),
            'message' => $this->message($data),
            'http_method' => $this->httpMethod($data),
            'endpoint' => $this->stringOrNull(Arr::get($data, 'endpoint') ?? Arr::get($data, 'uri'), 255),
            'http_status' => $this->intOrNull(Arr::get($data, 'http_status') ?? Arr::get($data, 'status'), 0, 65535),
            'response_time_ms' => $this->intOrNull(Arr::get($data, 'response_time_ms'), 0, 4294967295),
            'exception_class' => $this->stringOrNull(Arr::get($data, 'exception') ?? Arr::get($data, 'exception_class'), 255),
            'data' => $data,
        ]);

        $this->markConnected($application, $occurredAt);

        return $event;
    }

    /**
     * Record that the application is alive and reporting.
     */
    public function markConnected(Application $application, ?CarbonImmutable $at = null): void
    {
        $application->forceFill([
            'last_event_at' => $at && $at->isFuture() ? now() : ($at ?? now()),
            'connection_status' => ConnectionStatus::Connected,
        ])->save();
    }

    /**
     * Stable signature for grouping recurring errors.
     *
     * Deliberately excludes volatile details (timestamps, ids, response times)
     * so the same fault produces the same fingerprint across occurrences.
     *
     * @param  array<array-key, mixed>  $data
     */
    protected function fingerprint(Application $application, EventType $eventType, array $data): string
    {
        $parts = [
            $application->id,
            $eventType->value,
            (string) (Arr::get($data, 'exception') ?? Arr::get($data, 'exception_class') ?? ''),
            $this->normalizeEndpoint($this->stringOrNull(Arr::get($data, 'endpoint') ?? Arr::get($data, 'uri'), 255)),
            (string) ($this->intOrNull(Arr::get($data, 'http_status')) ?? ''),
            $this->normalizeMessage($this->message($data)),
        ];

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Replace numeric and UUID path segments so /api/orders/1 and
     * /api/orders/2 share a signature.
     */
    protected function normalizeEndpoint(?string $endpoint): string
    {
        if ($endpoint === null) {
            return '';
        }

        $endpoint = preg_replace('/\?.*$/', '', $endpoint) ?? $endpoint;
        $endpoint = preg_replace('#/[0-9]+(?=/|$)#', '/{id}', $endpoint) ?? $endpoint;

        return preg_replace(
            '#/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?=/|$)#i',
            '/{uuid}',
            $endpoint
        ) ?? $endpoint;
    }

    protected function normalizeMessage(?string $message): string
    {
        if ($message === null) {
            return '';
        }

        // Strip digits and quoted values, which usually carry the varying part
        // of an otherwise identical message.
        $message = preg_replace('/\d+/', 'N', $message) ?? $message;
        $message = preg_replace('/"[^"]*"|\'[^\']*\'/', 'S', $message) ?? $message;

        return mb_substr(trim($message), 0, 200);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function message(array $data): ?string
    {
        $message = Arr::get($data, 'message') ?? Arr::get($data, 'error');

        return $this->stringOrNull($message);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function httpMethod(array $data): ?string
    {
        $method = $this->stringOrNull(Arr::get($data, 'method'));

        return $method === null ? null : strtoupper(mb_substr($method, 0, 10));
    }

    /**
     * Normalize to a trimmed string, clipped to the column's width so a
     * verbose payload can never fail the insert.
     */
    protected function stringOrNull(mixed $value, ?int $limit = null): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $limit === null ? $value : mb_substr($value, 0, $limit);
    }

    /**
     * Clamped to the column's range: a nonsensical measurement is stored at
     * its bound rather than failing the insert.
     */
    protected function intOrNull(mixed $value, ?int $min = null, ?int $max = null): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        if ($min !== null) {
            $value = max($min, $value);
        }

        return $max === null ? $value : min($max, $value);
    }
}
