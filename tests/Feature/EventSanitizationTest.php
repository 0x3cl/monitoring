<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\MonitoringEvent;
use App\Services\CredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventSanitizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_credentials_and_session_material_never_reach_the_database(): void
    {
        $application = Application::factory()->create(['app_code' => 'APP-00001']);
        $issued = app(CredentialService::class)->issue($application);

        $this->postJson('/api/v1/events', [
            'application_id' => 'APP-00001',
            'event_type' => 'exception',
            'severity' => 'CRITICAL',
            'timestamp' => now()->toIso8601ZuluString(),
            'data' => [
                'message' => 'Login failed',
                'password' => 'hunter2',
                'password_confirmation' => 'hunter2',
                'authorization' => 'Bearer secret-token',
                'cookie' => 'laravel_session=abc',
                'api_key' => 'sk_live_123',
                'remember_token' => 'xyz',
                'request' => [
                    'headers' => [
                        'Authorization' => 'Bearer nested-secret',
                        'X-CSRF-TOKEN' => 'abc',
                        'User-Agent' => 'curl/8.0',
                    ],
                    'body' => [
                        'email' => 'admin@example.test',
                        'secret' => 'do-not-store',
                    ],
                ],
                'raw_header' => 'Bearer looks-like-a-token',
            ],
        ], [config('sentinel.credentials.header') => $issued->plainTextToken])->assertStatus(202);

        $event = MonitoringEvent::query()->sole();
        $encoded = json_encode($event->data);

        foreach (['hunter2', 'secret-token', 'laravel_session', 'sk_live_123', 'xyz', 'nested-secret', 'do-not-store'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }

        // Harmless context survives.
        $this->assertSame('Login failed', $event->message);
        $this->assertSame('curl/8.0', $event->data['request']['headers']['User-Agent']);
        $this->assertSame('admin@example.test', $event->data['request']['body']['email']);

        // A bearer-looking value is redacted regardless of its key.
        $this->assertSame(config('sentinel.sanitization.redaction'), $event->data['raw_header']);
    }

    public function test_long_strings_are_truncated(): void
    {
        $application = Application::factory()->create(['app_code' => 'APP-00001']);
        $issued = app(CredentialService::class)->issue($application);

        config(['sentinel.ingestion.max_string_length' => 50]);

        $this->postJson('/api/v1/events', [
            'application_id' => 'APP-00001',
            'event_type' => 'custom_event',
            'severity' => 'INFO',
            'timestamp' => now()->toIso8601ZuluString(),
            'data' => ['note' => str_repeat('a', 400)],
        ], [config('sentinel.credentials.header') => $issued->plainTextToken])->assertStatus(202);

        $event = MonitoringEvent::query()->sole();

        $this->assertStringEndsWith('[truncated]', $event->data['note']);
        $this->assertLessThan(400, mb_strlen($event->data['note']));
    }
}
