<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApplication;
use App\Http\Requests\StoreMonitoringEventBatchRequest;
use App\Http\Requests\StoreMonitoringEventRequest;
use App\Models\Application;
use App\Services\EventIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The ingestion API. Monitored applications of any technology submit events
 * here using the standardized event format.
 */
class EventIngestionController extends Controller
{
    public function __construct(
        protected EventIngestor $ingestor,
    ) {}

    public function store(StoreMonitoringEventRequest $request): JsonResponse
    {
        if (! $request->matchesAuthenticatedApplication()) {
            return $this->tenancyViolation();
        }

        $event = $this->ingestor->ingest($request->application(), $request->validated());

        return new JsonResponse([
            'accepted' => true,
            'event_id' => $event->id,
        ], 202);
    }

    public function storeBatch(StoreMonitoringEventBatchRequest $request): JsonResponse
    {
        if ($request->foreignApplicationIds() !== []) {
            return $this->tenancyViolation();
        }

        $application = $request->application();
        $ids = [];

        foreach ($request->events() as $event) {
            $ids[] = $this->ingestor->ingest($application, $event)->id;
        }

        return new JsonResponse([
            'accepted' => true,
            'accepted_count' => count($ids),
            'event_ids' => $ids,
        ], 202);
    }

    /**
     * Connection test used by every integration package after installation.
     */
    public function ping(Request $request): JsonResponse
    {
        /** @var Application $application */
        $application = $request->attributes->get(AuthenticateApplication::APPLICATION_ATTRIBUTE);

        $this->ingestor->markConnected($application);

        return new JsonResponse([
            'message' => 'SENTINEL received your connection test.',
            'application_id' => $application->app_code,
            'application_name' => $application->name,
            'environment' => $application->environment->value,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    protected function tenancyViolation(): JsonResponse
    {
        return new JsonResponse([
            'message' => 'The submitted application_id does not match the authenticated application.',
        ], 403);
    }
}
