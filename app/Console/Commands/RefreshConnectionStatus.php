<?php

namespace App\Console\Commands;

use App\Enums\ConnectionStatus;
use App\Models\Application;
use Illuminate\Console\Command;

/**
 * Marks applications disconnected once they stop reporting.
 *
 * Connectivity is inferred from event arrival rather than polling, so a quiet
 * application is detected without any outbound request.
 */
class RefreshConnectionStatus extends Command
{
    protected $signature = 'sentinel:refresh-connection-status
                            {--minutes= : Override the configured silence window}';

    protected $description = 'Flag registered applications that have stopped reporting events';

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes') ?: config('sentinel.health.disconnect_after_minutes'));

        $disconnected = Application::query()
            ->stale($minutes)
            ->where('connection_status', ConnectionStatus::Connected->value)
            ->update(['connection_status' => ConnectionStatus::Disconnected->value]);

        $this->info("Marked {$disconnected} application(s) as disconnected (silent for {$minutes} minute(s)).");

        return self::SUCCESS;
    }
}
