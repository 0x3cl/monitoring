<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    case NeverConnected = 'never_connected';
    case Connected = 'connected';
    case Disconnected = 'disconnected';

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
