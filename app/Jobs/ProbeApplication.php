<?php

namespace App\Jobs;

use App\Models\Application;
use App\Services\Heartbeat\ApplicationProbe;
use App\Services\Heartbeat\HeartbeatRecorder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Probes one application and records the outcome.
 *
 * Unique per application: a probe that hangs against a black-holed host must
 * never allow a second one to be queued behind it, or a single unresponsive
 * target would fill the queue at one job per cycle until a worker starves.
 */
class ProbeApplication implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * One attempt only. Retrying would double the load on a target that is
     * already failing; confirmation comes instead from the consecutive-failure
     * threshold, which makes the next scheduled probe the retry.
     */
    public int $tries = 1;

    /**
     * Generous against the sum of the probe's own timeouts (TCP + HTTP), but
     * bounded: a worker is released in seconds, never minutes.
     */
    public int $timeout = 20;

    public bool $failOnTimeout = true;

    public function __construct(public int $applicationId)
    {
        $this->onQueue(config('sentinel.heartbeat.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->applicationId;
    }

    /**
     * Long enough to cover a hung probe, short enough that the lock cannot
     * outlive a crashed worker by more than a cycle or two.
     */
    public function uniqueFor(): int
    {
        return max(60, (int) config('sentinel.heartbeat.interval_seconds') * 2);
    }

    public function handle(ApplicationProbe $probe, HeartbeatRecorder $recorder): void
    {
        $application = Application::query()->with('server')->find($this->applicationId);

        if ($application === null || ! $application->probe_enabled || ! $application->is_active) {
            return;
        }

        $recorder->record($application, $probe->probe($application));
    }
}
