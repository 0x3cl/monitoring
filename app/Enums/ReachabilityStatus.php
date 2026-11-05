<?php

namespace App\Enums;

/**
 * Whether a server answered a TCP connection on its monitored port.
 *
 * This is the layer below an application: it separates "the host is gone" from
 * "the host is up but the application on it is not answering".
 */
enum ReachabilityStatus: string
{
    case Reachable = 'reachable';
    case Unreachable = 'unreachable';
    case Unknown = 'unknown';

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
