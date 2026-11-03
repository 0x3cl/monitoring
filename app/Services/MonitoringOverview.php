<?php

namespace App\Services;

use App\Enums\ConnectionStatus;
use App\Enums\Severity;
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
                'health' => $application->healthState($windowMinutes)->value,
                'last_event_at' => $application->last_event_at?->toIso8601String(),
            ])
            ->all();
    }
}
