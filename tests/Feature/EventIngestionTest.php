<?php

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Models\Application;
use App\Services\CredentialService;
use App\Support\IssuedCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected Application $application;

    protected IssuedCredential $issued;

    protected function setUp(): void
    {
        parent::setUp();

        $this->application = Application::factory()->create(['app_code' => 'APP-00001']);
        $this->issued = app(CredentialService::class)->issue($this->application);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_replace([
            'application_id' => 'APP-00001',
            'event_type' => 'http_request',
            'severity' => 'ERROR',
            'timestamp' => now()->subSecond()->toIso8601ZuluString(),
            'data' => [
                'method' => 'POST',
                'endpoint' => '/api/orders',
                'http_status' => 500,
                'response_time_ms' => 3200,
                'message' => 'Internal Server Error',
            ],
        ], $overrides);
    }

    /**
     * @return array<string, string>
     */
    protected function headers(?string $token = null): array
    {
        return [config('sentinel.credentials.header') => $token ?? $this->issued->plainTextToken];
    }

    public function test_an_authenticated_application_can_submit_an_event(): void
    {
        $response = $this->postJson('/api/v1/events', $this->payload(), $this->headers());

        $response->assertStatus(202)->assertJsonPath('accepted', true);

        $this->assertDatabaseHas('monitoring_events', [
            'application_id' => $this->application->id,
            'event_type' => 'http_request',
            'severity' => 'ERROR',
            'endpoint' => '/api/orders',
            'http_status' => 500,
            'response_time_ms' => 3200,
            'http_method' => 'POST',
        ]);
    }

    public function test_ingestion_marks_the_application_connected(): void
    {
        $this->assertSame(ConnectionStatus::NeverConnected, $this->application->connection_status);

        $this->postJson('/api/v1/events', $this->payload(), $this->headers())->assertStatus(202);

        $fresh = $this->application->fresh();

        $this->assertSame(ConnectionStatus::Connected, $fresh->connection_status);
        $this->assertNotNull($fresh->last_event_at);
    }

    public function test_requests_without_a_credential_are_rejected(): void
    {
        $this->postJson('/api/v1/events', $this->payload())->assertUnauthorized();
        $this->postJson('/api/v1/events', $this->payload(), $this->headers('sentinel_bogus-key'))
            ->assertUnauthorized();

        $this->assertDatabaseCount('monitoring_events', 0);
    }

    public function test_revoked_credentials_are_rejected(): void
    {
        app(CredentialService::class)->revoke($this->issued->credential);

        $this->postJson('/api/v1/events', $this->payload(), $this->headers())
            ->assertUnauthorized()
            ->assertJsonPath('message', 'This application credential has been revoked.');
    }

    public function test_expired_credentials_are_rejected(): void
    {
        $this->issued->credential->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->postJson('/api/v1/events', $this->payload(), $this->headers())
            ->assertUnauthorized()
            ->assertJsonPath('message', 'This application credential has expired.');
    }

    public function test_disabled_applications_cannot_submit_events(): void
    {
        $this->application->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/events', $this->payload(), $this->headers())->assertForbidden();
    }

    public function test_an_application_cannot_submit_events_for_another_application(): void
    {
        Application::factory()->create(['app_code' => 'APP-00002']);

        $this->postJson('/api/v1/events', $this->payload(['application_id' => 'APP-00002']), $this->headers())
            ->assertForbidden();

        $this->assertDatabaseCount('monitoring_events', 0);
    }

    public function test_malformed_events_are_rejected(): void
    {
        $this->postJson('/api/v1/events', $this->payload(['event_type' => 'teleportation']), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event_type');

        $this->postJson('/api/v1/events', $this->payload(['severity' => 'VERY_BAD']), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('severity');

        $this->postJson('/api/v1/events', $this->payload(['timestamp' => 'not-a-date']), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timestamp');

        $this->postJson('/api/v1/events', $this->payload(['data' => 'a string']), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('data');
    }

    public function test_events_far_in_the_future_are_rejected(): void
    {
        $this->postJson(
            '/api/v1/events',
            $this->payload(['timestamp' => now()->addHours(3)->toIso8601ZuluString()]),
            $this->headers()
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timestamp');
    }

    public function test_oversized_payloads_are_rejected(): void
    {
        config(['sentinel.ingestion.max_body_bytes' => 512]);

        $this->postJson(
            '/api/v1/events',
            $this->payload(['data' => ['message' => str_repeat('x', 4000)]]),
            $this->headers()
        )->assertStatus(413);

        $this->assertDatabaseCount('monitoring_events', 0);
    }

    public function test_ingestion_is_rate_limited_per_credential(): void
    {
        config(['sentinel.ingestion.rate_limit_per_minute' => 2]);

        $this->postJson('/api/v1/events', $this->payload(), $this->headers())->assertStatus(202);
        $this->postJson('/api/v1/events', $this->payload(), $this->headers())->assertStatus(202);
        $this->postJson('/api/v1/events', $this->payload(), $this->headers())->assertStatus(429);
    }

    public function test_a_batch_of_events_can_be_submitted(): void
    {
        $response = $this->postJson('/api/v1/events/batch', [
            'events' => [
                $this->payload(),
                $this->payload(['severity' => 'INFO', 'data' => ['endpoint' => '/api/invoices', 'http_status' => 200]]),
            ],
        ], $this->headers());

        $response->assertStatus(202)->assertJsonPath('accepted_count', 2);

        $this->assertDatabaseCount('monitoring_events', 2);
    }

    public function test_a_batch_containing_another_applications_events_is_rejected(): void
    {
        $this->postJson('/api/v1/events/batch', [
            'events' => [
                $this->payload(),
                $this->payload(['application_id' => 'APP-00002']),
            ],
        ], $this->headers())->assertForbidden();

        $this->assertDatabaseCount('monitoring_events', 0);
    }

    public function test_the_ping_endpoint_confirms_the_connection(): void
    {
        $this->getJson('/api/v1/ping', $this->headers())
            ->assertOk()
            ->assertJsonPath('application_id', 'APP-00001');

        $this->assertSame(ConnectionStatus::Connected, $this->application->fresh()->connection_status);
    }

    public function test_the_whole_data_payload_is_stored(): void
    {
        // Regression: adding nested data.* validation rules narrows
        // validated() to only those keys, silently dropping the rest of the
        // payload. Every field the agent sends must survive.
        $this->postJson('/api/v1/events', $this->payload(['data' => [
            'method' => 'GET',
            'endpoint' => '/api/invoices',
            'route' => 'invoices.index',
            'http_status' => 200,
            'response_time_ms' => 42,
            'message' => 'OK',
            'ip' => '127.0.0.1',
            'user_agent' => 'curl/8.0',
        ]]), $this->headers())->assertStatus(202);

        $event = $this->application->monitoringEvents()->sole();

        foreach (['method', 'endpoint', 'route', 'http_status', 'response_time_ms', 'message', 'ip', 'user_agent'] as $key) {
            $this->assertArrayHasKey($key, $event->data, "[{$key}] was dropped from the stored payload.");
        }

        $this->assertSame('GET', $event->http_method);
        $this->assertSame('/api/invoices', $event->endpoint);
        $this->assertSame('OK', $event->message);
    }

    public function test_out_of_range_measurements_are_rejected_rather_than_crashing(): void
    {
        // A miscalculating agent once sent a duration measured from the epoch,
        // overflowing the column and turning its bad event into a 500.
        $this->postJson('/api/v1/events', $this->payload(['data' => [
            'endpoint' => '/api/orders',
            'response_time_ms' => 1793683083053,
        ]]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('data.response_time_ms');

        $this->postJson('/api/v1/events', $this->payload(['data' => [
            'endpoint' => '/api/orders',
            'http_status' => 99999,
        ]]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('data.http_status');

        $this->assertDatabaseCount('monitoring_events', 0);
    }

    public function test_identical_faults_share_a_fingerprint(): void
    {
        $first = $this->payload(['data' => [
            'endpoint' => '/api/orders/17',
            'http_status' => 500,
            'message' => 'Order 17 could not be processed',
        ]]);

        $second = $this->payload(['data' => [
            'endpoint' => '/api/orders/4213',
            'http_status' => 500,
            'message' => 'Order 4213 could not be processed',
        ]]);

        $this->postJson('/api/v1/events', $first, $this->headers())->assertStatus(202);
        $this->postJson('/api/v1/events', $second, $this->headers())->assertStatus(202);

        $fingerprints = $this->application->monitoringEvents()->pluck('fingerprint')->unique();

        $this->assertCount(1, $fingerprints);
    }
}
