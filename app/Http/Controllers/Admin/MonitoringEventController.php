<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Severity;
use App\Http\Controllers\Controller;
use App\Http\Resources\MonitoringEventResource;
use App\Models\MonitoringEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MonitoringEventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'application' => ['nullable', 'string', 'max:32'],
            'severity' => ['nullable', 'string', 'in:'.implode(',', Severity::values())],
            'min_severity' => ['nullable', 'string', 'in:'.implode(',', Severity::values())],
            'event_type' => ['nullable', 'string', 'max:40'],
            'endpoint' => ['nullable', 'string', 'max:255'],
            'http_status' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:160'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $events = MonitoringEvent::query()
            ->with('application')
            ->when(isset($validated['application']), fn ($query) => $query->whereHas(
                'application',
                fn ($query) => $query->where('app_code', $validated['application'])
            ))
            ->when(isset($validated['severity']), fn ($query) => $query->where('severity', $validated['severity']))
            ->when(isset($validated['min_severity']), fn ($query) => $query->severityAtLeast(
                Severity::from($validated['min_severity'])
            ))
            ->when(isset($validated['event_type']), fn ($query) => $query->where('event_type', $validated['event_type']))
            ->when(isset($validated['endpoint']), fn ($query) => $query->where('endpoint', 'like', '%'.$validated['endpoint'].'%'))
            ->when(isset($validated['http_status']), fn ($query) => $query->where('http_status', $validated['http_status']))
            ->when(isset($validated['search']), fn ($query) => $query->whereRaw(
                'lower(message) like ?',
                ['%'.strtolower($validated['search']).'%']
            ))
            ->when(isset($validated['from']), fn ($query) => $query->where('occurred_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($query) => $query->where('occurred_at', '<=', $validated['to']))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 50)
            ->withQueryString();

        return MonitoringEventResource::collection($events);
    }

    public function show(MonitoringEvent $event): MonitoringEventResource
    {
        return MonitoringEventResource::make($event->load('application'));
    }
}
