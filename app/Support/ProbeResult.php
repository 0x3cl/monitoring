<?php

namespace App\Support;

use App\Enums\ProbeDiagnosis;
use App\Enums\UptimeStatus;

/**
 * What one heartbeat probe of an application observed.
 *
 * Carries the server verdict alongside the application's own, because the
 * diagnosis is only meaningful as a pair: a refused connection means something
 * different depending on whether the host itself answered.
 */
readonly class ProbeResult
{
    public function __construct(
        public UptimeStatus $status,
        public ProbeDiagnosis $diagnosis,
        public ?string $url = null,
        public ?int $httpStatus = null,
        public ?int $responseTimeMs = null,
        public ?string $error = null,
        public ?ServerProbeResult $server = null,
        public ?string $certificateExpiresAt = null,
        /** @var array<string, mixed> */
        public array $checks = [],
    ) {}

    public function isUp(): bool
    {
        return $this->status === UptimeStatus::Up;
    }

    /**
     * Does this observation count towards the consecutive-failure threshold?
     *
     * Maintenance does not: it is a deliberate state, and an operator who runs
     * `artisan down` should see "maintenance" at once rather than after two
     * cycles of "down".
     */
    public function isFailure(): bool
    {
        return $this->status->isOutage();
    }

    /**
     * The payload stored on the transition event.
     *
     * @return array<string, mixed>
     */
    public function toEventData(): array
    {
        return array_filter([
            'status' => $this->status->value,
            'diagnosis' => $this->diagnosis->value,
            'explanation' => $this->diagnosis->explanation(),
            'probe_url' => $this->url,
            'http_status' => $this->httpStatus,
            'response_time_ms' => $this->responseTimeMs,
            'error' => $this->error,
            'server' => $this->server?->toArray(),
            'tls_expires_at' => $this->certificateExpiresAt,
            'checks' => $this->checks ?: null,
        ], fn ($value) => $value !== null);
    }
}
