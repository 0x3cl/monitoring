<?php

namespace App\Services;

/**
 * Strips credentials and session material out of monitoring event payloads
 * before they are persisted.
 *
 * SENTINEL must never store passwords, tokens, cookies or authorization
 * headers, even when a monitored application mistakenly forwards them, so this
 * runs on the ingestion path rather than at display time.
 */
class EventSanitizer
{
    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function sanitize(array $data): array
    {
        return $this->walk($data, 1);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    protected function walk(array $data, int $depth): array
    {
        $maxDepth = (int) config('sentinel.ingestion.max_data_depth', 8);
        $redaction = (string) config('sentinel.sanitization.redaction', '[REDACTED]');

        $clean = [];

        foreach ($data as $key => $value) {
            if ($this->isBlocked((string) $key)) {
                // Dropped outright rather than redacted: a redaction placeholder
                // still reveals that the field was present.
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $depth >= $maxDepth ? $redaction : $this->walk($value, $depth + 1);

                continue;
            }

            if (is_string($value)) {
                $clean[$key] = $this->sanitizeString($value, $redaction);

                continue;
            }

            if (is_object($value)) {
                $clean[$key] = $redaction;

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    protected function isBlocked(string $key): bool
    {
        $normalized = strtolower(str_replace([' ', '-'], '_', trim($key)));

        foreach ((array) config('sentinel.sanitization.blocked_keys', []) as $blocked) {
            $blocked = strtolower(str_replace([' ', '-'], '_', (string) $blocked));

            if ($normalized === $blocked || str_contains($normalized, $blocked)) {
                return true;
            }
        }

        return false;
    }

    protected function sanitizeString(string $value, string $redaction): string
    {
        $maxLength = (int) config('sentinel.ingestion.max_string_length', 4000);

        // Values that look like bearer tokens or basic auth are redacted no
        // matter which key they arrived under.
        if (preg_match('/^(bearer|basic)\s+\S+/i', trim($value)) === 1) {
            return $redaction;
        }

        if (mb_strlen($value) > $maxLength) {
            return mb_substr($value, 0, $maxLength).'…[truncated]';
        }

        return $value;
    }
}
