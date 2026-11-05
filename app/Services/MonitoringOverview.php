<?php

namespace App\Services;

use App\Enums\ConnectionStatus;
use App\Enums\Severity;
use App\Enums\UptimeStatus;
use App\Models\Application;
use App\Models\MonitoringEvent;

/**
 * Builds the dashboard summary: how many applications are registered and
 * reporting, how many events arrived by severity, and each application's
 * current health.
 */
class MonitoringOverview
{
    /**
     * @return array<string, mixed>
     */
    public function build(int $windowMinutes = 1440): array
    {
        return [
            'window_minutes' => $windowMinutes,
            'applications' => $this->applicationCounts(),
            'uptime' => $this->uptimeCounts(),
            'outages' => $this->currentOutages(),
            'events' => $this->eventCounts($windowMinutes),
            'health' => $this->applicationHealth($windowMinutes),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function applicationCounts(): array
    {
        $byConnection = Application::query()
            ->selectRaw('connection_status, count(*) as aggregate')
            ->groupBy('connection_status')
            ->pluck('aggregate', 'connection_status')
            ->all();

        $connected = (int) ($byConnection[ConnectionStatus::Connected->value] ?? 0);
        $disconnected = (int) ($byConnection[ConnectionStatus::Disconnected->value] ?? 0);
        $neverConnected = (int) ($byConnection[ConnectionStatus::NeverConnected->value] ?? 0);

        return [
            'registered' => $connected + $disconnected + $neverConnected,
            'connected' => $connected,
            'disconnected' => $disconnected,
            'never_connected' => $neverConnected,
        ];
    }

    /**
     * What outbound probes currently see, by status.
     *
     * @return array<string, int>
     */
    public function uptimeCounts(): array
    {
        $counts = Application::query()
            ->active()
            ->selectRaw('uptime_status, count(*) as aggregate')
            ->groupBy('uptime_status')
            ->pluck('aggregate', 'uptime_status')
            ->all();

        $result = [];

        foreach (UptimeStatus::values() as $status) {
            $result[$status] = (int) ($counts[$status] ?? 0);
        }

        return $result;
    }

    /**
     * Applications that are not answering right now, with the reason.
     *
     * Maintenance is included: an operator looking at this list wants to know
     * what is unavailable, whether or not it was deliberate.
     *
     * @return array<int, array<string, mixed>>
     */
    public function currentOutages(): array
    {
        return Application::query()
            ->active()
            ->with('server')
            ->whereIn('uptime_status', [
                UptimeStatus::Down->value,
                UptimeStatus::Unreachable->value,
                UptimeStatus::Maintenance->value,
                UptimeStatus::Degraded->value,
            ])
            ->orderBy('uptime_status')
            ->orderBy('name')
            ->get()
            ->map(fn (Application $application) => [
                'app_code' => $application->app_code,
                'name' => $application->name,
                'environment' => $application->environment->value,
                'server' => $application->server?->name,
                'uptime_status' => $application->uptime_status->value,
                'diagnosis' => $application->uptime_diagnosis?->value,
                'explanation' => $application->uptime_diagnosis?->explanation(),
                'since' => $application->status_changed_at?->toIso8601String(),
                'last_probe_at' => $application->last_probe_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    public function eventCounts(int $windowMinutes = 1440): array
    {
        $counts = MonitoringEvent::query()
            ->within($windowMinutes)
            ->selectRaw('severity, count(*) as aggregate')
            ->groupBy('severity')
            ->pluck('aggregate', 'severity')
            ->all();

        $result = [];

        foreach (Severity::values() as $severity) {
            $result[$severity] = (int) ($counts[$severity] ?? 0);
        }

        $result['total'] = array_sum($result);

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function applicationHealth(int $windowMinutes = 60): array
    {
        return Application::query()
            ->active()
            ->with('server')
            ->orderBy('name')
            ->get()
            ->map(fn (Application $application) => [
                'app_code' => $application->app_code,
                'name' => $application->name,
                'environment' => $application->environment->value,
                'server' => $application->server?->name,
                'connection_status' => $application->connection_status->value,
                'uptime_status' => $application->uptime_status?->value,
                'diagnosis' => $application->uptime_diagnosis?->value,
                'explanation' => $application->uptime_diagnosis?->explanation(),
                'health' => $application->healthState($windowMinutes)->value,
                'last_event_at' => $application->last_event_at?->toIso8601String(),
                'last_probe_at' => $application->last_probe_at?->toIso8601String(),
                'last_probe_time_ms' => $application->last_probe_time_ms,
            ])
            ->all();
    }
}
