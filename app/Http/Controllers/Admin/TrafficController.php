<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AggregateTraffic;
use App\Models\Application;
use App\Services\Traffic\TrafficReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrafficController extends Controller
{
    public function __construct(
        protected TrafficReport $report,
    ) {}

    /**
     * Traffic across every active application, or one of them.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer'],
            'application' => ['nullable', 'string', 'exists:applications,app_code'],
        ]);

        $application = isset($validated['application'])
            ? Application::query()->where('app_code', $validated['application'])->firstOrFail()
            : null;

        return new JsonResponse([
            'data' => $this->report->build((int) ($validated['days'] ?? 7), $application),
        ]);
    }

    /**
     * Rebuild one application's rollups now.
     *
     * Queued rather than run inline: a backfill scans the events table, which
     * is not something a dashboard request should wait on.
     */
    public function refresh(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        AggregateTraffic::dispatch($application->getKey(), $validated['days'] ?? null);

        return new JsonResponse([
            'message' => 'Traffic aggregation queued.',
            'app_code' => $application->app_code,
        ], 202);
    }
}
