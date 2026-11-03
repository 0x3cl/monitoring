<?php

namespace App\Http\Resources;

use App\Models\MonitoringEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MonitoringEvent */
class MonitoringEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type->value,
            'event_type_label' => $this->event_type->label(),
            'severity' => $this->severity->value,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'received_at' => $this->received_at->toIso8601String(),
            'correlation_id' => $this->correlation_id,
            'fingerprint' => $this->fingerprint,
            'message' => $this->message,
            'http_method' => $this->http_method,
            'endpoint' => $this->endpoint,
            'http_status' => $this->http_status,
            'response_time_ms' => $this->response_time_ms,
            'exception_class' => $this->exception_class,
            'data' => $this->data,
            'application' => $this->whenLoaded('application', fn () => [
                'app_code' => $this->application->app_code,
                'name' => $this->application->name,
                'environment' => $this->application->environment->value,
            ]),
        ];
    }
}
