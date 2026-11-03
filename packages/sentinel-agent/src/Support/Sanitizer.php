<?php

namespace DostPtri\SentinelAgent\Support;

/**
 * Strips secrets out of an event payload before it leaves this application.
 *
 * SENTINEL sanitizes again on ingestion, but a credential should never travel
 * over the wire in the first place.
 */
class Sanitizer
{
    /**
     * @param  array<string, mixed>  $blockedKeys
     */
    public function __construct(
        protected array $blockedKeys = [],
        protected int $maxStringLength = 2000,
        protected int $maxDepth = 6,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function clean(array $data): array
    {
        return $this->walk($data, 1);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    protected function walk(array $data, int $depth): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if ($this->isBlocked((string) $key)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $depth >= $this->maxDepth ? '[TRUNCATED]' : $this->walk($value, $depth + 1);

                continue;
            }

            if (is_object($value)) {
                $clean[$key] = '[OBJECT]';

                continue;
            }

            if (is_string($value)) {
                $clean[$key] = $this->cleanString($value);

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    protected function isBlocked(string $key): bool
    {
        $normalized = strtolower(str_replace([' ', '-'], '_', trim($key)));

        foreach ($this->blockedKeys as $blocked) {
            $blocked = strtolower(str_replace([' ', '-'], '_', (string) $blocked));

            if ($blocked !== '' && str_contains($normalized, $blocked)) {
                return true;
            }
        }

        return false;
    }

    protected function cleanString(string $value): string
    {
        // A bearer or basic credential is redacted whatever key it arrived under.
        if (preg_match('/^(bearer|basic)\s+\S+/i', trim($value)) === 1) {
            return '[REDACTED]';
        }

        return mb_strlen($value) > $this->maxStringLength
            ? mb_substr($value, 0, $this->maxStringLength).'…[truncated]'
            : $value;
    }
}
