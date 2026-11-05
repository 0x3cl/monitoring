<?php

namespace App\Console\Commands;

use App\Jobs\AggregateTraffic as AggregateTrafficJob;
use App\Models\Application;
use App\Models\DailyTrafficStat;
use App\Services\Traffic\TrafficAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Queues the daily traffic rollups and prunes the rows that have aged out.
 *
 * Like the heartbeat dispatcher, this command only selects and queues: the
 * scans themselves belong on a worker.
 */
class AggregateTraffic extends Command
{
    protected $signature = 'sentinel:aggregate-traffic
                            {--days= : Recompute this many trailing days instead of the configured window}
                            {--application=* : Limit to these application codes}
                            {--sync : Aggregate inline instead of queueing, for one-off backfills}';

    protected $description = 'Roll request events up into per-day traffic statistics';

    public function handle(): int
    {
        if (! config('sentinel.traffic.enabled')) {
            $this->components->warn('Traffic analytics are disabled (SENTINEL_TRAFFIC_ENABLED=false).');

            return self::SUCCESS;
        }

        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;
        $codes = (array) $this->option('application');

        $applications = Application::query()
            ->active()
            ->when($codes !== [], fn ($query) => $query->whereIn('app_code', $codes))
            ->select(['id'])
            ->get();

        foreach ($applications as $application) {
            if ($this->option('sync')) {
                // A backfill over months is a one-off an operator watches, so
                // it reports progress rather than disappearing onto a queue.
                app(TrafficAggregator::class)
                    ->aggregateRecent(Application::find($application->id), $days);

                continue;
            }

            AggregateTrafficJob::dispatch((int) $application->id, $days);
        }

        $pruned = $this->prune();

        $this->components->info(sprintf(
            '%s traffic aggregation for %d application(s)%s.',
            $this->option('sync') ? 'Completed' : 'Queued',
            $applications->count(),
            $pruned > 0 ? ", pruned {$pruned} expired row(s)" : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Daily rows are small, but not free: a year is kept and anything older
     * goes, so the table cannot grow without bound.
     */
    protected function prune(): int
    {
        $days = (int) config('sentinel.retention.traffic_day_days', 365);

        if ($days <= 0) {
            return 0;
        }

        return DailyTrafficStat::query()
            ->where('day', '<', Carbon::today()->subDays($days)->toDateString())
            ->delete();
    }
}
