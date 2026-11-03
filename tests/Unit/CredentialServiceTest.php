<?php

namespace Tests\Unit;

use App\Models\Application;
use App\Services\CredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CredentialService $credentials;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credentials = app(CredentialService::class);
    }

    public function test_issued_tokens_are_stored_only_as_hashes(): void
    {
        $application = Application::factory()->create();

        $issued = $this->credentials->issue($application);

        $this->assertSame(hash('sha256', $issued->plainTextToken), $issued->credential->token_hash);
        $this->assertNotSame($issued->plainTextToken, $issued->credential->token_hash);
        $this->assertStringStartsWith($issued->credential->token_prefix, $issued->plainTextToken);
    }

    public function test_tokens_resolve_to_their_credential(): void
    {
        $application = Application::factory()->create();
        $issued = $this->credentials->issue($application);

        $resolved = $this->credentials->resolve($issued->plainTextToken);

        $this->assertNotNull($resolved);
        $this->assertSame($issued->credential->id, $resolved->id);
        $this->assertSame($application->id, $resolved->application->id);
    }

    public function test_unknown_and_empty_tokens_resolve_to_nothing(): void
    {
        Application::factory()->create();

        $this->assertNull($this->credentials->resolve('sentinel_not-a-real-key'));
        $this->assertNull($this->credentials->resolve(''));
        $this->assertNull($this->credentials->resolve(null));
    }

    public function test_revoking_marks_the_credential_unusable(): void
    {
        $issued = $this->credentials->issue(Application::factory()->create());

        $this->credentials->revoke($issued->credential);

        $this->assertFalse($issued->credential->fresh()->isUsable());
        $this->assertTrue($issued->credential->fresh()->isRevoked());
    }

    public function test_rotating_revokes_the_previous_credentials(): void
    {
        $application = Application::factory()->create();
        $first = $this->credentials->issue($application);

        $second = $this->credentials->rotate($application);

        $this->assertTrue($first->credential->fresh()->isRevoked());
        $this->assertTrue($second->credential->fresh()->isUsable());
        $this->assertNotSame($first->plainTextToken, $second->plainTextToken);
    }

    public function test_expired_credentials_are_not_usable(): void
    {
        $issued = $this->credentials->issue(
            Application::factory()->create(),
            'default',
            null,
            now()->subMinute()->toDateTimeImmutable()
        );

        $this->assertFalse($issued->credential->isUsable());
        $this->assertTrue($issued->credential->isExpired());
    }
}
