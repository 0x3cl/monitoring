<?php

namespace DostPtri\SentinelAgent\Facades;

use DostPtri\SentinelAgent\SentinelAgent;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool record(string $eventType, string $severity, array $data)
 * @method static bool custom(string $message, array $data = [], string $severity = 'INFO')
 * @method static bool exception(\Throwable $exception, string $severity = 'ERROR')
 * @method static bool ping()
 * @method static bool enabled()
 * @method static string correlationId()
 *
 * @see SentinelAgent
 */
class Sentinel extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SentinelAgent::class;
    }
}
