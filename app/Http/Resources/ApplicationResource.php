<?php

namespace App\Http\Resources;

use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Application */
class ApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'app_code' => $this->app_code,
            'name' => $this->name,
            'slug' => $this->slug,
            'environment' => $this->environment->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'connection_status' => $this->connection_status->value,
            'health' => $this->healthState()->value,
            'base_url' => $this->base_url,
            'health_endpoint' => $this->health_endpoint,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'last_event_at' => $this->last_event_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'monitoring_config' => $this->monitoringConfig(),
            'server' => ServerResource::make($this->whenLoaded('server')),
            'integration_type' => IntegrationTypeResource::make($this->whenLoaded('integrationType')),
            'integration_version' => $this->whenLoaded(
                'integrationVersion',
                fn () => $this->integrationVersion?->version
            ),
            'credentials' => ApplicationCredentialResource::collection($this->whenLoaded('credentials')),
            'events_count' => $this->whenCounted('monitoringEvents'),
        ];
    }
}
