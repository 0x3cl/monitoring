<?php

namespace App\Support;

/**
 * The outcome of a single TCP connection attempt.
 *
 * Distinguishing "refused" from "timed out" matters: a refusal proves
 * something answered on that address, so the host is up and the listening
 * process is not.
 */
readonly class TcpResult
{
    public function __construct(
        public bool $connected,
        public ?int $latencyMs = null,
        public bool $refused = false,
        public bool $timedOut = false,
        public ?string $error = null,
        public ?string $certificateExpiresAt = null,
    ) {}

    public static function connected(int $latencyMs, ?string $certificateExpiresAt = null): self
    {
        return new self(
            connected: true,
            latencyMs: $latencyMs,
            certificateExpiresAt: $certificateExpiresAt,
        );
    }

    public static function refused(?string $error = null): self
    {
        return new self(connected: false, refused: true, error: $error);
    }

    public static function timedOut(?string $error = null): self
    {
        return new self(connected: false, timedOut: true, error: $error);
    }

    public static function failed(?string $error = null): self
    {
        return new self(connected: false, error: $error);
    }
}
