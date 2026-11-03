<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationCredential;
use App\Models\User;
use App\Support\IssuedCredential;
use Illuminate\Support\Str;

/**
 * Issues and resolves the per-application credentials used to authenticate
 * monitoring event submissions.
 *
 * Only a SHA-256 hash of each token is persisted. The plaintext is returned
 * exactly once, when the credential is issued, and is never logged.
 */
class CredentialService
{
    public function issue(
        Application $application,
        string $name = 'default',
        ?User $creator = null,
        ?\DateTimeInterface $expiresAt = null,
    ): IssuedCredential {
        $token = $this->generateToken();

        $credential = $application->credentials()->create([
            'name' => $name,
            'token_prefix' => $this->prefixOf($token),
            'token_hash' => $this->hash($token),
            'expires_at' => $expiresAt,
            'created_by' => $creator?->id,
        ]);

        return new IssuedCredential($credential, $token);
    }

    /**
     * Revoke the application's current credentials and issue a replacement.
     */
    public function rotate(
        Application $application,
        string $name = 'default',
        ?User $creator = null,
    ): IssuedCredential {
        $application->credentials()->usable()->get()
            ->each(fn (ApplicationCredential $credential) => $this->revoke($credential));

        return $this->issue($application, $name, $creator);
    }

    public function revoke(ApplicationCredential $credential): ApplicationCredential
    {
        if (! $credential->isRevoked()) {
            $credential->forceFill(['revoked_at' => now()])->save();
        }

        return $credential;
    }

    /**
     * Resolve a plaintext token to its credential, or null when it matches
     * nothing. Revoked and expired credentials are returned so the caller can
     * distinguish "unknown key" from "no longer valid".
     */
    public function resolve(?string $token): ?ApplicationCredential
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $hash = $this->hash($token);

        $credential = ApplicationCredential::query()
            ->with('application')
            ->where('token_prefix', $this->prefixOf($token))
            ->get()
            ->first(fn (ApplicationCredential $candidate) => hash_equals($candidate->token_hash, $hash));

        return $credential;
    }

    /**
     * Record usage without writing on every single event.
     */
    public function touch(ApplicationCredential $credential): void
    {
        if ($credential->last_used_at === null || $credential->last_used_at->diffInSeconds(now()) >= 60) {
            $credential->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }

    protected function generateToken(): string
    {
        $bytes = (int) config('sentinel.credentials.token_bytes', 36);

        return config('sentinel.credentials.prefix', 'sentinel_').Str::random($bytes);
    }

    protected function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * A short, non-secret handle stored alongside the hash so lookups can be
     * narrowed to a single row before the constant-time comparison.
     */
    protected function prefixOf(string $token): string
    {
        return substr($token, 0, 16);
    }
}
