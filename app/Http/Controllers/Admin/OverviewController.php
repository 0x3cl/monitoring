<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicationStatus;
use App\Enums\ConnectionStatus;
use App\Enums\Environment;
use App\Enums\EventType;
use App\Enums\HealthState;
use App\Enums\ProbeDiagnosis;
use App\Enums\ReachabilityStatus;
use App\Enums\Severity;
use App\Enums\UptimeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\IntegrationTypeResource;
use App\Models\IntegrationType;
use App\Services\MonitoringOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverviewController extends Controller
{
    public function __construct(
        protected MonitoringOverview $overview,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'window_minutes' => ['nullable', 'integer', 'min:5', 'max:43200'],
        ]);

        return new JsonResponse([
            'data' => $this->overview->build($validated['window_minutes'] ?? 1440),
        ]);
    }

    /**
     * The vocabularies the dashboard renders its filters and forms from, so
     * the frontend never hardcodes the event schema.
     */
    public function meta(): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'severities' => $this->describe(Severity::cases()),
                'event_types' => $this->describe(EventType::cases()),
                'environments' => $this->describe(Environment::cases()),
                'application_statuses' => $this->describe(ApplicationStatus::cases()),
                'connection_statuses' => $this->describe(ConnectionStatus::cases()),
                'health_states' => $this->describe(HealthState::cases()),
                'uptime_statuses' => $this->describe(UptimeStatus::cases()),
                'reachability_statuses' => $this->describe(ReachabilityStatus::cases()),
                'probe_diagnoses' => $this->describe(ProbeDiagnosis::cases()),
                'integration_types' => IntegrationTypeResource::collection(
                    IntegrationType::query()->orderBy('name')->get()
                )->resolve(),
            ],
        ]);
    }

    /**
     * @param  array<int, Severity|EventType|Environment|ApplicationStatus|ConnectionStatus|HealthState|UptimeStatus|ReachabilityStatus|ProbeDiagnosis>  $cases
     * @return array<int, array{value: string, label: string}>
     */
    protected function describe(array $cases): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], $cases);
    }
}
