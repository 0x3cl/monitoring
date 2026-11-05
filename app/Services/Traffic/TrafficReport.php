<?php

namespace App\Services\Traffic;

use App\Models\Application;
use App\Models\DailyTrafficStat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Shapes the daily rollups into what the traffic dashboard draws.
 *
 * The deliberate restraint here is that it returns four headline numbers, one
 * dense series and two short lists. Everything else a person might want is one
 * click away on an application's own page, because a screen of forty numbers
 * gets read as wallpaper.
 */
class TrafficReport
{
    /**
     * @return array<string, mixed>
     */
    public function build(int $days = 7, ?Application $application = null): array
    {
        $days = $this->clampDays($days);

        $to = Carbon::today();
        $from = $to->copy()->subDays($days - 1);

        $rows = DailyTrafficStat::query()
            ->between($from->toDateString(), $to->toDateString())
            ->when($application, fn ($query) => $query->where('application_id', $application->getKey()))
            ->when(
                ! $application,
                fn ($query) => $query->whereIn(
                    'application_id',
                    Application::query()->active()->select('id')
                )
            )
            ->get();

        return [
            'range' => [
                'days' => $days,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'application' => $application?->app_code,
            'totals' => $this->totals($rows, $days),
            'series' => $this->series($rows, $from, $to),
            'top_endpoints' => $this->mergeTop($rows, 'top_endpoints', 'endpoint'),
            'top_clients' => $this->mergeTop($rows, 'top_clients', 'client_ip'),
            'applications' => $application ? [] : $this->perApplication($rows),
            // Below 1.0 the totals are a sample rather than a census, and the
            // dashboard says so instead of quietly understating traffic.
            'sample_rate' => $rows->isEmpty() ? 1.0 : (float) $rows->min('sample_rate'),
            'ranges' => config('sentinel.traffic.ranges'),
        ];
    }

    /**
     * Four headline numbers, each with how it moved against the preceding
     * window of the same length. A number without a direction is not yet
     * information.
     *
     * @param  Collection<int, DailyTrafficStat>  $rows
     * @return array<string, mixed>
     */
    protected function totals(Collection $rows, int $days): array
    {
        $requests = (int) $rows->sum('requests');
        $errors = (int) $rows->sum('client_errors') + (int) $rows->sum('server_errors');

        // Unique visitors cannot be summed across days without double counting
        // anyone who returned, so the headline is the busiest single day. It is
        // an honest figure with a clear meaning, unlike a sum.
        $peakVisitors = (int) $rows->max('unique_visitors');

        $weighted = $rows->filter(fn (DailyTrafficStat $row) => $row->avg_response_time_ms !== null);
        $avgMs = $weighted->sum('requests') > 0
            ? (int) round(
                $weighted->sum(fn (DailyTrafficStat $row) => $row->avg_response_time_ms * $row->requests)
                / $weighted->sum('requests')
            )
            : null;

        return [
            'requests' => $requests,
            'peak_visitors' => $peakVisitors,
            'error_rate' => $requests > 0 ? round(($errors / $requests) * 100, 2) : 0.0,
            'avg_response_time_ms' => $avgMs,
            'p95_response_time_ms' => $rows->max('p95_response_time_ms'),
            'server_errors' => (int) $rows->sum('server_errors'),
            'change' => $this->change($days, $requests),
        ];
    }

    /**
     * How request volume compares with the window before this one.
     *
     * @return array<string, mixed>
     */
    protected function change(int $days, int $requests): array
    {
        $previousTo = Carbon::today()->subDays($days);
        $previousFrom = $previousTo->copy()->subDays($days - 1);

        $previous = (int) DailyTrafficStat::query()
            ->between($previousFrom->toDateString(), $previousTo->toDateString())
            ->sum('requests');

        return [
            'previous_requests' => $previous,
            // Null rather than 100% when there is nothing to compare against:
            // growth from zero is not a percentage.
            'requests_percent' => $previous > 0
                ? round((($requests - $previous) / $previous) * 100, 1)
                : null,
        ];
    }

    /**
     * One entry per day in the range, including days nothing was recorded.
     *
     * Gaps are filled with zeroes rather than skipped so the chart's bars stay
     * on a real calendar: a missing Sunday must read as a quiet Sunday, not
     * squeeze the week into six columns.
     *
     * @param  Collection<int, DailyTrafficStat>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function series(Collection $rows, Carbon $from, Carbon $to): array
    {
        $byDay = $rows->groupBy(fn (DailyTrafficStat $row) => $row->day->toDateString());

        $series = [];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $forDay = $byDay->get($key, collect());

            $requests = (int) $forDay->sum('requests');
            $weighted = $forDay->filter(fn (DailyTrafficStat $row) => $row->avg_response_time_ms !== null);

            $series[] = [
                'day' => $key,
                'requests' => $requests,
                'success' => (int) $forDay->sum('success'),
                'client_errors' => (int) $forDay->sum('client_errors'),
                'server_errors' => (int) $forDay->sum('server_errors'),
                'slow_requests' => (int) $forDay->sum('slow_requests'),
                // Across applications this is the busiest one's count, not a
                // sum: the same visitor may appear in several.
                'unique_visitors' => (int) $forDay->max('unique_visitors'),
                'avg_response_time_ms' => $weighted->sum('requests') > 0
                    ? (int) round(
                        $weighted->sum(fn (DailyTrafficStat $row) => $row->avg_response_time_ms * $row->requests)
                        / $weighted->sum('requests')
                    )
                    : null,
            ];
        }

        return $series;
    }

    /**
     * Fold each day's top-N lists into one ranking for the whole range.
     *
     * This is an approximation by construction: an endpoint that sat just below
     * the cut every day contributes nothing. That is the price of bounded daily
     * rows, and for "what is busiest" the error is immaterial.
     *
     * @param  Collection<int, DailyTrafficStat>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function mergeTop(Collection $rows, string $field, string $key): array
    {
        $merged = [];

        foreach ($rows as $row) {
            foreach ((array) $row->{$field} as $entry) {
                $identity = $entry[$key] ?? null;

                if ($identity === null) {
                    continue;
                }

                $merged[$identity] ??= [$key => $identity, 'requests' => 0, 'errors' => 0];
                $merged[$identity]['requests'] += (int) ($entry['requests'] ?? 0);
                $merged[$identity]['errors'] += (int) ($entry['errors'] ?? 0);
            }
        }

        usort($merged, fn (array $a, array $b) => $b['requests'] <=> $a['requests']);

        return array_slice(array_values($merged), 0, (int) config('sentinel.traffic.top_n', 10));
    }

    /**
     * Per-application totals, so the combined page can say which application
     * the traffic belongs to without a second request.
     *
     * @param  Collection<int, DailyTrafficStat>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function perApplication(Collection $rows): array
    {
        $applications = Application::query()
            ->whereIn('id', $rows->pluck('application_id')->unique())
            ->get(['id', 'app_code', 'name'])
            ->keyBy('id');

        return $rows
            ->groupBy('application_id')
            ->map(function (Collection $group, int|string $applicationId) use ($applications) {
                $application = $applications->get($applicationId);
                $requests = (int) $group->sum('requests');
                $errors = (int) $group->sum('client_errors') + (int) $group->sum('server_errors');

                return [
                    'app_code' => $application?->app_code,
                    'name' => $application?->name,
                    'requests' => $requests,
                    'error_rate' => $requests > 0 ? round(($errors / $requests) * 100, 2) : 0.0,
                    'server_errors' => (int) $group->sum('server_errors'),
                    'peak_visitors' => (int) $group->max('unique_visitors'),
                ];
            })
            ->filter(fn (array $row) => $row['app_code'] !== null)
            ->sortByDesc('requests')
            ->values()
            ->all();
    }

    /**
     * Only the offered ranges are honoured, so a crafted request cannot ask for
     * ten years of rows.
     */
    protected function clampDays(int $days): int
    {
        $ranges = (array) config('sentinel.traffic.ranges', [7, 30, 90]);

        return in_array($days, $ranges, true) ? $days : (int) ($ranges[0] ?? 7);
    }
}
