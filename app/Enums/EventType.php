<?php

namespace App\Enums;

enum EventType: string
{
    case HttpRequest = 'http_request';
    case Exception = 'exception';
    case DatabaseError = 'database_error';
    case SlowRequest = 'slow_request';
    case ApplicationStatus = 'application_status';
    case CustomEvent = 'custom_event';
    case InfrastructureMetric = 'infrastructure_metric';

    /**
     * Event types whose payload carries HTTP request details worth
     * denormalizing into dedicated columns.
     */
    public function isHttpShaped(): bool
    {
        return in_array($this, [self::HttpRequest, self::SlowRequest], true);
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
