<?php

namespace App\Http\Requests;

use App\Enums\ApplicationStatus;
use App\Enums\Environment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates changes to an already registered application. The application code
 * and slug are immutable: integrations are configured with them.
 */
class UpdateApplicationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'environment' => ['sometimes', Rule::enum(Environment::class)],
            'status' => ['sometimes', Rule::enum(ApplicationStatus::class)],
            'server_id' => ['nullable', 'integer', Rule::exists('servers', 'id')],
            'base_url' => ['nullable', 'url', 'max:255'],
            'health_endpoint' => ['nullable', 'string', 'max:255'],
            'probe_enabled' => ['sometimes', 'boolean'],
            // Floor of 30s so an operator cannot accidentally configure a
            // probe storm against their own application.
            'probe_interval_seconds' => ['sometimes', 'integer', 'min:30', 'max:86400'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'monitoring_config' => ['sometimes', 'array'],
            'monitoring_config.features' => ['nullable', 'array'],
            'monitoring_config.features.*' => ['boolean'],
            'monitoring_config.thresholds' => ['nullable', 'array'],
            'monitoring_config.thresholds.slow_request_ms' => ['nullable', 'integer', 'min:1', 'max:600000'],
            'monitoring_config.thresholds.error_rate_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'monitoring_config.thresholds.sample_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'monitoring_config.excluded_routes' => ['nullable', 'array'],
            'monitoring_config.excluded_routes.*' => ['string', 'max:255'],
        ];
    }
}
