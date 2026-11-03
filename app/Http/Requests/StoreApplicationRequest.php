<?php

namespace App\Http\Requests;

use App\Enums\Environment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an administrator registering a new monitored application.
 */
class StoreApplicationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'environment' => ['required', Rule::enum(Environment::class)],
            'integration_type_id' => ['required', 'integer', Rule::exists('integration_types', 'id')],
            'server_id' => ['nullable', 'integer', Rule::exists('servers', 'id')],
            'base_url' => ['nullable', 'url', 'max:255'],
            'health_endpoint' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'monitoring_config' => ['nullable', 'array'],
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
