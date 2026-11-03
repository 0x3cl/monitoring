<?php

namespace App\Enums;

enum Severity: string
{
    case Debug = 'DEBUG';
    case Info = 'INFO';
    case Warning = 'WARNING';
    case Error = 'ERROR';
    case Critical = 'CRITICAL';

    /**
     * Numeric weight, used for "at least this severe" comparisons.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Debug => 10,
            self::Info => 20,
            self::Warning => 30,
            self::Error => 40,
            self::Critical => 50,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->weight() >= $other->weight();
    }

    /**
     * Severities that may escalate into an incident or notification.
     */
    public function isAlertable(): bool
    {
        return $this->atLeast(self::Error);
    }

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
