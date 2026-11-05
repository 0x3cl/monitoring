<?php

namespace App\Services\Traffic;

use App\Models\Application;
use App\Models\DailyTrafficStat;
use App\Models\MonitoringEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Rolls one application's request events up into one row per day.
 *
 * Everything the dashboard shows is read from those rows, never from the events
 * themselves: the events table is pruned after 30 days and aggregating millions
 * of them on every page load would not survive a second application.
 *
 * A day is always recomputed from scratch rather than incremented, so a batch
 * arriving late from an agent that was offline corrects the day instead of
 * being counted twice.
 */
class TrafficAggregator
{
    /**
     * Recompute a single day for a single application.
     *
     * Returns null when the application reported nothing that day and no row
     * exists, so quiet days cost no storage.
     */
    public function aggregate(Application $application, Carbon $day): ?DailyTrafficStat
    {
        [$from, $to] = $this->dayBounds($day);

        $events = MonitoringEvent::query()
            ->where('application_id', $application->getKey())
            ->traffic()
            ->whereBetween('occurred_at', [$from, $to]);

        $totals = (clone $events)
            ->selectRaw('count(*) as requests')
            ->selectRaw('count(distinct client_ip) as unique_visitors')
            ->selectRaw('count(*) filter (where http_status is null or http_status < 400) as success')
            ->selectRaw('count(*) filter (where http_status between 400 and 499) as client_errors')
            ->selectRaw('count(*) filter (where http_status >= 500) as server_errors')
            ->selectRaw("count(*) filter (where event_type = 'slow_request') as slow_requests")
            ->selectRaw('avg(response_time_ms) as avg_response_time_ms')
            ->selectRaw('max(response_time_ms) as max_response_time_ms')
            // Computed in the database rather than by loading response times
            // into PHP: an average alone hides the tail that users actually
            // notice.
            ->selectRaw('percentile_disc(0.95) within group (order by response_time_ms) as p95_response_time_ms')
            ->first();

        $requests = (int) ($totals->requests ?? 0);

        $existing = DailyTrafficStat::query()
            ->where('application_id', $application->getKey())
            ->whereDate('day', $day->toDateString())
            ->first();

        if ($requests === 0 && $existing === null) {
            return null;
        }

        return DailyTrafficStat::query()->updateOrCreate(
            [
                'application_id' => $application->getKey(),
                'day' => $day->toDateString(),
            ],
            [
                'requests' => $requests,
                'unique_visitors' => (int) ($totals->unique_visitors ?? 0),
                'success' => (int) ($totals->success ?? 0),
                'client_errors' => (int) ($totals->client_errors ?? 0),
                'server_errors' => (int) ($totals->server_errors ?? 0),
                'slow_requests' => (int) ($totals->slow_requests ?? 0),
                'avg_response_time_ms' => $this->intOrNull($totals->avg_response_time_ms ?? null),
                'p95_response_time_ms' => $this->intOrNull($totals->p95_response_time_ms ?? null),
                'max_response_time_ms' => $this->intOrNull($totals->max_response_time_ms ?? null),
                'top_endpoints' => $this->topEndpoints(clone $events),
                'top_clients' => $this->topClients(clone $events),
                'sample_rate' => $this->sampleRate(clone $events),
            ]
        );
    }

    /**
     * Recompute the trailing window for every application that is reporting.
     *
     * Today always moves, and the day or two behind it can still gain late
     * arrivals, so a short window is recomputed rather than only the current
     * day.
     *
     * @return int the number of rows written
     */
    public function aggregateRecent(Application $application, ?int $days = null): int
    {
        $days = max(1, $days ?? (int) config('sentinel.traffic.recompute_days', 2));
        $written = 0;

        for ($offset = 0; $offset < $days; $offset++) {
            if ($this->aggregate($application, Carbon::today()->subDays($offset)) !== null) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * The busiest paths, with how well each one fared.
     *
     * Endpoints are already normalized by the agent into route paths, so
     * /orders/1 and /orders/2 do not each claim a row.
     *
     * @param  Builder<MonitoringEvent>  $events
     * @return array<int, array<string, mixed>>
     */
    protected function topEndpoints($events): array
    {
        return $events
            ->whereNotNull('endpoint')
            ->selectRaw('endpoint, count(*) as requests')
            ->selectRaw('count(*) filter (where http_status >= 400) as errors')
            ->selectRaw('round(avg(response_time_ms)) as avg_ms')
            ->groupBy('endpoint')
            ->orderByDesc('requests')
            ->limit($this->topN())
            ->get()
            ->map(fn ($row) => [
                'endpoint' => $row->endpoint,
                'requests' => (int) $row->requests,
                'errors' => (int) $row->errors,
                'avg_ms' => $this->intOrNull($row->avg_ms),
            ])
            ->all();
    }

    /**
     * The busiest callers. This is the answer to "who is hammering us".
     *
     * @param  Builder<MonitoringEvent>  $events
     * @return array<int, array<string, mixed>>
     */
    protected function topClients($events): array
    {
        return $events
            ->whereNotNull('client_ip')
            ->selectRaw('client_ip, count(*) as requests')
            ->selectRaw('count(*) filter (where http_status >= 400) as errors')
            ->selectRaw('count(distinct endpoint) as endpoints')
            ->groupBy('client_ip')
            ->orderByDesc('requests')
            ->limit($this->topN())
            ->get()
            ->map(fn ($row) => [
                'client_ip' => $row->client_ip,
                'requests' => (int) $row->requests,
                'errors' => (int) $row->errors,
                'endpoints' => (int) $row->endpoints,
            ])
            ->all();
    }

    /**
     * The lowest sample rate the agent reported that day.
     *
     * An application sampling successful traffic at 0.1 sends a tenth of it, so
     * its counts are a sample. Recording that is what lets the dashboard label
     * the number instead of presenting a confidently wrong one.
     *
     * @param  Builder<MonitoringEvent>  $events
     */
    protected function sampleRate($events): float
    {
        $lowest = $events
            ->whereRaw("data->>'sample_rate' is not null")
            ->selectRaw("min((data->>'sample_rate')::numeric) as rate")
            ->value('rate');

        return $lowest === null ? 1.0 : max(0.001, min(1.0, (float) $lowest));
    }

    /**
     * A day in the application's own terms is the server's day: there is one
     * timezone for the whole instance, so a "day" is unambiguous.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function dayBounds(Carbon $day): array
    {
        return [$day->copy()->startOfDay(), $day->copy()->endOfDay()];
    }

    protected function topN(): int
    {
        return max(1, (int) config('sentinel.traffic.top_n', 10));
    }

    protected function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) round((float) $value);
    }
}
