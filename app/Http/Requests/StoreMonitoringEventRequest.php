<?php

namespace App\Http\Requests;

use App\Enums\EventType;
use App\Enums\Severity;
use App\Http\Middleware\AuthenticateApplication;
use App\Models\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a single monitoring event against the standardized event format.
 */
class StoreMonitoringEventRequest extends FormRequest
{
    /**
     * Credential authentication is handled by middleware; tenancy is enforced
     * in after() so the failure is reported as a 403 rather than a 422.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'application_id' => ['required', 'string', 'max:32'],
            'event_type' => ['required', Rule::enum(EventType::class)],
            'severity' => ['required', Rule::enum(Severity::class)],
            'timestamp' => ['required', 'date'],
            'correlation_id' => ['nullable', 'string', 'max:64'],
            // Deliberately no nested data.* rules: adding them would narrow
            // validated() to just those keys and silently drop the rest of
            // the payload. Numeric bounds are enforced in after().
            'data' => ['required', 'array'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $this->rejectImplausibleTimestamp($validator);
                $this->rejectOutOfRangeMeasurements($validator);
            },
        ];
    }

    /**
     * The authenticated application, as resolved by the credential.
     */
    public function application(): Application
    {
        return $this->attributes->get(AuthenticateApplication::APPLICATION_ATTRIBUTE);
    }

    /**
     * Does the submitted application_id match the credential's application?
     *
     * An application must never be able to attribute events to another one.
     */
    public function matchesAuthenticatedApplication(): bool
    {
        return $this->string('application_id')->value() === $this->application()->app_code;
    }

    /**
     * A miscalculating agent must not be able to overflow the denormalized
     * columns and turn its own bad event into a 500 from the engine.
     */
    protected function rejectOutOfRangeMeasurements(Validator $validator): void
    {
        $bounds = [
            'http_status' => [100, 599],
            'response_time_ms' => [0, 4294967295],
        ];

        foreach ($bounds as $key => [$min, $max]) {
            $value = $this->input("data.{$key}");

            if ($value === null || $value === '') {
                continue;
            }

            if (! is_numeric($value) || $value < $min || $value > $max) {
                $validator->errors()->add("data.{$key}", "The data.{$key} field must be between {$min} and {$max}.");
            }
        }
    }

    protected function rejectImplausibleTimestamp(Validator $validator): void
    {
        if ($validator->errors()->has('timestamp')) {
            return;
        }

        $tolerance = (int) config('sentinel.ingestion.future_tolerance_minutes', 5);
        $timestamp = $this->date('timestamp');

        if ($timestamp !== null && $timestamp->isAfter(now()->addMinutes($tolerance))) {
            $validator->errors()->add(
                'timestamp',
                "The event timestamp is more than {$tolerance} minutes in the future."
            );
        }
    }
}
