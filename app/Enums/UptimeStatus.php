<?php

namespace App\Enums;

/**
 * What an outbound heartbeat probe last observed about an application.
 *
 * Deliberately separate from ConnectionStatus, which answers a different
 * question: whether the application's agent is still pushing events. An
 * application can be reporting happily and still be serving errors, and it can
 * be perfectly healthy while its agent is misconfigured.
 */
enum UptimeStatus: string
{
    case Up = 'up';
    case Degraded = 'degraded';
    case Maintenance = 'maintenance';
    case Down = 'down';
    case Unreachable = 'unreachable';
    case Unknown = 'unknown';

    /**
     * Is the application failing to serve requests at all?
     *
     * Maintenance is excluded: it is a deliberate act by an operator, not a
     * fault, and should not read as an outage on the dashboard.
     */
    public function isOutage(): bool
    {
        return in_array($this, [self::Down, self::Unreachable], true);
    }

    /**
     * How loudly a transition into this status should be recorded.
     */
    public function severity(): Severity
    {
        return match ($this) {
            self::Down, self::Unreachable => Severity::Critical,
            self::Degraded, self::Maintenance => Severity::Warning,
            self::Up => Severity::Info,
            self::Unknown => Severity::Debug,
        };
    }

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
