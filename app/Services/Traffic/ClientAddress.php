<?php

namespace App\Services\Traffic;

/**
 * Normalizes, validates and optionally masks a caller's address.
 *
 * Masking happens here, at ingestion, rather than on read: a setting that only
 * hid addresses in the dashboard would still have written them to disk, where a
 * backup or a database dump would carry them anyway.
 */
class ClientAddress
{
    /**
     * Returns null when nothing storable was supplied, or when storing client
     * addresses is switched off entirely.
     */
    public function normalize(mixed $value): ?string
    {
        if (! config('sentinel.traffic.store_client_ip', true)) {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $address = trim($value);

        // An agent behind a proxy may forward a chain; the first entry is the
        // original caller.
        if (str_contains($address, ',')) {
            $address = trim(explode(',', $address)[0]);
        }

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return config('sentinel.traffic.mask_client_ip', false)
            ? $this->mask($address)
            : $address;
    }

    /**
     * Drop the host part: the last octet of an IPv4 address, or the last 80
     * bits of an IPv6 one.
     *
     * The result still groups a subnet together, so visitor counts and
     * "one source is hammering us" both survive, while a single household or
     * device is no longer identifiable.
     */
    public function mask(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $address);
            $parts[3] = '0';

            return implode('.', $parts);
        }

        $groups = explode(':', $address);
        $kept = array_slice($groups, 0, 3);

        return implode(':', $kept).'::';
    }
}
