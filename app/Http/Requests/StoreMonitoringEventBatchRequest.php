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
 * Validates a batch of monitoring events, as submitted by an agent flushing
 * its local buffer after SENTINEL was briefly unreachable.
 */
class StoreMonitoringEventBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = (int) config('sentinel.ingestion.max_batch_events', 100);

        return [
            'events' => ['required', 'array', 'min:1', "max:{$max}"],
            'events.*.application_id' => ['required', 'string', 'max:32'],
            'events.*.event_type' => ['required', Rule::enum(EventType::class)],
            'events.*.severity' => ['required', Rule::enum(Severity::class)],
            'events.*.timestamp' => ['required', 'date'],
            'events.*.correlation_id' => ['nullable', 'string', 'max:64'],
            // No nested data.* rules here either: they would narrow
            // validated() and strip the rest of each event's payload.
            'events.*.data' => ['required', 'array'],
        ];
    }

    /**
     * Accept both {"events": [...]} and a bare JSON array, since log shippers
     * commonly post the latter.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('events') && array_is_list($this->all())) {
            $this->merge(['events' => $this->all()]);
        }
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $tolerance = (int) config('sentinel.ingestion.future_tolerance_minutes', 5);
                $cutoff = now()->addMinutes($tolerance);

                foreach ((array) $this->input('events', []) as $index => $event) {
                    $timestamp = $event['timestamp'] ?? null;

                    if (! is_string($timestamp) || $validator->errors()->has("events.{$index}.timestamp")) {
                        continue;
                    }

                    if (strtotime($timestamp) > $cutoff->timestamp) {
                        $validator->errors()->add(
                            "events.{$index}.timestamp",
                            "The event timestamp is more than {$tolerance} minutes in the future."
                        );
                    }
                }
            },
        ];
    }

    public function application(): Application
    {
        return $this->attributes->get(AuthenticateApplication::APPLICATION_ATTRIBUTE);
    }

    /**
     * Every event in the batch must belong to the authenticated application.
     */
    public function foreignApplicationIds(): array
    {
        $own = $this->application()->app_code;

        return array_values(array_unique(array_filter(
            array_map(
                fn (array $event) => $event['application_id'] ?? null,
                (array) $this->input('events', [])
            ),
            fn (?string $code) => $code !== null && $code !== $own
        )));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function events(): array
    {
        return (array) $this->validated()['events'];
    }
}
