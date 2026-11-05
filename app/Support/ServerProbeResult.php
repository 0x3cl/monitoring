<?php

namespace App\Support;

use App\Enums\ProbeDiagnosis;
use App\Enums\ReachabilityStatus;

/**
 * Whether a server answered, and why not when it did not.
 *
 * Cached per server for the length of a probe cycle, so every application on a
 * host shares one TCP probe instead of each opening its own.
 */
readonly class ServerProbeResult
{
    public function __construct(
        public ReachabilityStatus $status,
        public ProbeDiagnosis $diagnosis,
        public ?string $host = null,
        public ?int $port = null,
        public ?int $latencyMs = null,
        public ?string $error = null,
    ) {}

    public function isReachable(): bool
    {
        return $this->status === ReachabilityStatus::Reachable;
    }

    /**
     * No server is registered, or probing it is switched off: the probe cannot
     * say anything about the host either way.
     */
    public static function unknown(): self
    {
        return new self(
            status: ReachabilityStatus::Unknown,
            diagnosis: ProbeDiagnosis::Unknown,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'diagnosis' => $this->diagnosis->value,
            'host' => $this->host,
            'port' => $this->port,
            'latency_ms' => $this->latencyMs,
            'error' => $this->error,
        ];
    }

    /**
     * Rehydrated from the cache, which stores the array form.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            status: ReachabilityStatus::from($data['status']),
            diagnosis: ProbeDiagnosis::from($data['diagnosis']),
            host: $data['host'] ?? null,
            port: $data['port'] ?? null,
            latencyMs: $data['latency_ms'] ?? null,
            error: $data['error'] ?? null,
        );
    }
}
