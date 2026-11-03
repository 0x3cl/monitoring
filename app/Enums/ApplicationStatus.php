<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case PendingInstallation = 'pending_installation';
    case Active = 'active';
    case Disabled = 'disabled';

    public function canIngest(): bool
    {
        return $this !== self::Disabled;
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
