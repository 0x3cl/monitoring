<?php

namespace App\Jobs;

use App\Models\Application;
use App\Services\Traffic\TrafficAggregator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rolls one application's recent days up into daily traffic rows.
 *
 * Queued for the same reason the probes are: aggregation is a handful of
 * grouped scans over a large table, and running it on the scheduler would hold
 * up every other scheduled task behind the busiest application.
 *
 * Unique per application, so a long run on a high-traffic application cannot
 * accumulate duplicates behind itself at one per cycle.
 */
class AggregateTraffic implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Aggregation is read-heavy and recomputes a bounded window, so it is given
     * real room — but still bounded, so a runaway query releases the worker.
     */
    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $applicationId,
        public ?int $days = null,
    ) {
        $this->onQueue(config('sentinel.traffic.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->applicationId;
    }

    public function uniqueFor(): int
    {
        return 900;
    }

    public function handle(TrafficAggregator $aggregator): void
    {
        $application = Application::query()->find($this->applicationId);

        if ($application === null) {
            return;
        }

        $aggregator->aggregateRecent($application, $this->days);
    }
}
