<?php

namespace App\Enums;

enum HealthState: string
{
    case Healthy = 'HEALTHY';
    case Warning = 'WARNING';
    case Critical = 'CRITICAL';
    case Unknown = 'UNKNOWN';

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
