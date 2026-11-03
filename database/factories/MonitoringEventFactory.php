<?php

namespace Database\Factories;

use App\Enums\EventType;
use App\Enums\Severity;
use App\Models\Application;
use App\Models\MonitoringEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MonitoringEvent> */
class MonitoringEventFactory extends Factory
{
    public function definition(): array
    {
        $occurredAt = $this->faker->dateTimeBetween('-1 day', 'now');
        $status = $this->faker->randomElement([200, 201, 302, 404, 422, 500, 503]);
        $endpoint = '/'.$this->faker->randomElement(['api/orders', 'api/invoices', 'dashboard', 'api/users']);

        return [
            'application_id' => Application::factory(),
            'event_type' => EventType::HttpRequest,
            'severity' => $status >= 500 ? Severity::Error : Severity::Info,
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt,
            'message' => $status >= 500 ? 'Internal Server Error' : 'OK',
            'http_method' => $this->faker->randomElement(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']),
            'endpoint' => $endpoint,
            'http_status' => $status,
            'response_time_ms' => $this->faker->numberBetween(20, 4200),
            'fingerprint' => hash('sha256', $endpoint.$status),
            'data' => [
                'method' => 'GET',
                'endpoint' => $endpoint,
                'http_status' => $status,
            ],
        ];
    }

    public function severity(Severity $severity): static
    {
        return $this->state(fn () => ['severity' => $severity]);
    }

    public function exception(): static
    {
        return $this->state(fn () => [
            'event_type' => EventType::Exception,
            'severity' => Severity::Critical,
            'exception_class' => 'Illuminate\\Database\\QueryException',
            'message' => 'SQLSTATE[08006] could not connect to server',
        ]);
    }
}
