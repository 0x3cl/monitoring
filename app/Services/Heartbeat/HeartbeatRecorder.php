<?php

namespace App\Services\Heartbeat;

use App\Enums\EventType;
use App\Enums\ProbeDiagnosis;
use App\Enums\ReachabilityStatus;
use App\Enums\UptimeStatus;
use App\Models\Application;
use App\Models\MonitoringEvent;
use App\Models\Server;
use App\Support\ProbeResult;
use App\Support\ServerProbeResult;
use Illuminate\Support\Carbon;

/**
 * Applies a probe outcome to the stored state.
 *
 * Two things keep this cheap at scale. An unchanged status writes only the
 * probe bookkeeping columns, so a quiet application costs one narrow UPDATE
 * per cycle and nothing else. Only a *confirmed transition* writes to
 * monitoring_events, so the events table grows with incidents rather than with
 * the number of probes.
 */
class HeartbeatRecorder
{
    /**
     * Record an application probe, declaring an outage only once the failure
     * threshold has been met.
     *
     * Returns the event written for a transition, or null when nothing changed.
     */
    public function record(Application $application, ProbeResult $result): ?MonitoringEvent
    {
        $now = Carbon::now();
        $previous = $application->uptime_status;

        $failures = $result->isFailure() ? $application->consecutive_failures + 1 : 0;
        $threshold = max(1, (int) config('sentinel.heartbeat.failure_threshold'));

        // A single failure is not an outage. Until the threshold is met the
        // stored status is left alone, so a blip never pages anyone, while the
        // last-probe columns still update and the dashboard shows the flapping.
        $confirmed = ! $result->isFailure() || $failures >= $threshold;
        $status = $confirmed ? $result->status : $previous;

        $attributes = [
            'consecutive_failures' => $failures,
            'last_probe_at' => $now,
            'last_probe_status_code' => $result->httpStatus,
            'last_probe_time_ms' => $result->responseTimeMs,
            'next_probe_at' => $this->nextProbeAt($application, $now),
        ];

        if ($result->isUp()) {
            $attributes['last_up_at'] = $now;
        }

        $changed = $status !== $previous;

        if ($changed) {
            $attributes['uptime_status'] = $status;
            $attributes['uptime_diagnosis'] = $result->diagnosis;
            $attributes['status_changed_at'] = $now;
        } elseif ($confirmed) {
            // Same status, but the reason may have moved (a 500 that became a
            // refused connection is still "down" and still worth recording).
            $attributes['uptime_diagnosis'] = $result->diagnosis;
        }

        $application->forceFill($attributes)->saveQuietly();

        return $changed
            ? $this->recordTransition($application, $result, $previous, $now)
            : null;
    }

    /**
     * Record a server probe. Server reachability has no transition event of its
     * own: the applications on the host report the outage, and a server with no
     * applications has nothing to be down for.
     */
    public function recordServer(Server $server, ServerProbeResult $result): void
    {
        $now = Carbon::now();
        $failed = $result->status === ReachabilityStatus::Unreachable;

        $attributes = [
            'reachability_status' => $result->status,
            'last_probed_at' => $now,
            'consecutive_failures' => $failed ? $server->consecutive_failures + 1 : 0,
        ];

        if ($result->isReachable()) {
            $attributes['last_reachable_at'] = $now;
        }

        $server->forceFill($attributes)->saveQuietly();
    }

    /**
     * Write the one event that marks this transition.
     *
     * Deliberately not routed through EventIngestor: ingest() finishes with
     * markConnected(), which sets last_event_at and flips connection_status to
     * connected. A SENTINEL-generated outage event must never make a silent
     * application look like it just reported in.
     */
    protected function recordTransition(
        Application $application,
        ProbeResult $result,
        UptimeStatus $previous,
        Carbon $now,
    ): MonitoringEvent {
        $status = $result->status;

        // Returning to service is INFO however bad the outage was; the outage
        // itself carries the severity.
        $severity = $status === UptimeStatus::Up && $previous !== UptimeStatus::Unknown
            ? UptimeStatus::Up->severity()
            : $status->severity();

        $downtimeSeconds = $status === UptimeStatus::Up && $application->status_changed_at !== null
            ? max(0, (int) $application->status_changed_at->diffInSeconds($now))
            : null;

        return MonitoringEvent::create([
            'application_id' => $application->getKey(),
            'server_id' => $application->server_id,
            'event_type' => EventType::ApplicationStatus,
            'severity' => $severity,
            'occurred_at' => $now,
            'received_at' => $now,
            // Groups repeated outages of the same kind, so the incident phase
            // can correlate them without another signature scheme.
            'fingerprint' => hash('sha256', implode('|', [
                $application->getKey(),
                EventType::ApplicationStatus->value,
                $status->value,
                $result->diagnosis->value,
            ])),
            'message' => $this->message($status, $previous, $result->diagnosis),
            'endpoint' => $result->url,
            'http_status' => $result->httpStatus,
            'response_time_ms' => $result->responseTimeMs,
            'data' => array_filter([
                ...$result->toEventData(),
                'previous_status' => $previous->value,
                'source' => 'heartbeat',
                'downtime_seconds' => $downtimeSeconds,
            ], fn ($value) => $value !== null),
        ]);
    }

    protected function message(UptimeStatus $status, UptimeStatus $previous, ProbeDiagnosis $diagnosis): string
    {
        $headline = match ($status) {
            UptimeStatus::Up => $previous->isOutage()
                ? 'Application recovered'
                : 'Application is responding',
            UptimeStatus::Degraded => 'Application is degraded',
            UptimeStatus::Maintenance => 'Application is in maintenance mode',
            UptimeStatus::Down => 'Application is down',
            UptimeStatus::Unreachable => 'Application is unreachable',
            UptimeStatus::Unknown => 'Application status is unknown',
        };

        return $headline.': '.$diagnosis->explanation();
    }

    /**
     * Schedule the next probe with a little jitter, so a large fleet spreads
     * across the interval instead of stampeding at the top of every minute.
     */
    protected function nextProbeAt(Application $application, Carbon $now): Carbon
    {
        $interval = max(10, (int) ($application->probe_interval_seconds
            ?: config('sentinel.heartbeat.interval_seconds')));

        $jitterPercent = max(0, min(50, (int) config('sentinel.heartbeat.jitter_percent')));
        $spread = (int) round($interval * $jitterPercent / 100);

        return $now->copy()->addSeconds($interval + random_int(-$spread, $spread));
    }
}
