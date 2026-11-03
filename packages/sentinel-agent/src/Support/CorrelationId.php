<?php

namespace DostPtri\SentinelAgent\Support;

use Illuminate\Support\Str;

/**
 * Ties every event produced during one request to a single identifier, so an
 * exception can be traced back to the request that caused it.
 */
class CorrelationId
{
    public const HEADER = 'X-Correlation-Id';

    protected ?string $value = null;

    public function get(): string
    {
        return $this->value ??= (string) Str::uuid();
    }

    /**
     * Adopt an identifier supplied upstream, so a correlation id survives
     * across service boundaries.
     */
    public function set(?string $value): void
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value !== '' && mb_strlen($value) <= 64) {
            $this->value = $value;
        }
    }
}
