<?php

namespace App\Console\Commands;

use App\Enums\ApplicationStatus;
use App\Jobs\ProbeApplication;
use App\Jobs\ProbeServer;
use App\Models\Application;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Queues the heartbeat probes that have come due.
 *
 * The command itself does no network work at all: it runs on the scheduler,
 * where a slow probe would delay every other scheduled task, so it only ever
 * selects and dispatches. Three things keep that selection cheap as the number
 * of monitored applications grows:
 *
 *  - only applications whose next_probe_at has passed are considered, over a
 *    dedicated (probe_enabled, next_probe_at) index;
 *  - ids alone are selected and chunked, so no models are hydrated;
 *  - servers are dispatched once per cycle rather than once per application.
 */
class DispatchHeartbeats extends Command
{
    protected $signature = 'sentinel:dispatch-heartbeats
                            {--application=* : Probe only these application codes, ignoring their schedule}
                            {--all : Probe every enabled application, ignoring their schedule}';

    protected $description = 'Queue outbound heartbeat probes for applications and their servers';

    public function handle(): int
    {
        if (! config('sentinel.heartbeat.enabled')) {
            $this->components->warn('Heartbeat probing is disabled (SENTINEL_HEARTBEAT_ENABLED=false).');

            return self::SUCCESS;
        }

        $servers = [];
        $applications = 0;

        $this->dueApplications()->chunkById(
            max(1, (int) config('sentinel.heartbeat.dispatch_chunk')),
            function (Collection $chunk) use (&$servers, &$applications) {
                // Servers first, so their verdict is already cached by the time
                // the applications on them interpret their own failures.
                foreach ($chunk->pluck('server_id')->filter()->unique() as $serverId) {
                    if (! isset($servers[$serverId])) {
                        $servers[$serverId] = true;
                        ProbeServer::dispatch((int) $serverId);
                    }
                }

                foreach ($chunk as $application) {
                    ProbeApplication::dispatch((int) $application->id);
                    $applications++;
                }
            }
        );

        $this->components->info(sprintf(
            'Queued %d application probe(s) and %d server probe(s).',
            $applications,
            count($servers),
        ));

        return self::SUCCESS;
    }

    /**
     * @return Builder<Application>
     */
    protected function dueApplications()
    {
        $codes = (array) $this->option('application');

        $query = Application::query()
            // Only the columns the dispatch loop reads: no model is hydrated
            // beyond its id and host.
            ->select(['id', 'server_id'])
            ->where('probe_enabled', true)
            ->where('is_active', true)
            ->where('status', '!=', ApplicationStatus::Disabled->value)
            ->whereNotNull('base_url');

        if ($codes !== []) {
            return $query->whereIn('app_code', $codes);
        }

        if ($this->option('all')) {
            return $query;
        }

        // A null next_probe_at means the application has never been probed.
        return $query->where(fn ($due) => $due
            ->whereNull('next_probe_at')
            ->orWhere('next_probe_at', '<=', now()));
    }
}
