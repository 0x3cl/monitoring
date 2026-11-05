<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\IntegrationType;
use App\Models\User;
use App\Support\RegisteredApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers monitored applications dynamically.
 *
 * Nothing about a monitored application is hardcoded in the engine: a
 * registration row plus its credential is all that is needed for events to
 * start flowing.
 */
class ApplicationRegistrar
{
    public function __construct(
        protected CredentialService $credentials,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function register(array $attributes, ?User $creator = null): RegisteredApplication
    {
        return DB::transaction(function () use ($attributes, $creator) {
            $integrationType = IntegrationType::query()->findOrFail($attributes['integration_type_id']);

            $application = Application::query()->create([
                'app_code' => $this->nextApplicationCode(),
                'name' => $attributes['name'],
                'slug' => $this->uniqueSlug($attributes['name']),
                'environment' => $attributes['environment'],
                'server_id' => $attributes['server_id'] ?? null,
                'integration_type_id' => $integrationType->id,
                'integration_version_id' => $integrationType->currentVersion()?->id,
                'status' => ApplicationStatus::PendingInstallation,
                'base_url' => $attributes['base_url'] ?? null,
                'health_endpoint' => $attributes['health_endpoint'] ?? null,
                'description' => $attributes['description'] ?? null,
                'monitoring_config' => $this->monitoringConfig($attributes['monitoring_config'] ?? []),
                'probe_enabled' => $attributes['probe_enabled'] ?? true,
                'probe_interval_seconds' => $attributes['probe_interval_seconds']
                    ?? config('sentinel.heartbeat.interval_seconds'),
                // Authenticates SENTINEL's heartbeat probe to the agent's health
                // endpoint. Unlike the ingestion credential it is stored
                // encrypted rather than hashed, because it has to be presented
                // on every probe.
                'probe_token' => $this->newProbeToken(),
                // Staggered inside the first interval so a batch of
                // registrations does not all come due in the same cycle.
                'next_probe_at' => now()->addSeconds(
                    random_int(0, max(1, (int) config('sentinel.heartbeat.interval_seconds')))
                ),
            ]);

            // The direct API is always available; file and Fluent Bit sources
            // are added later by the operator.
            $application->logSources()->create(['type' => 'api']);

            $issued = $this->credentials->issue($application, 'default', $creator);

            return new RegisteredApplication($application->fresh(['server', 'integrationType']), $issued);
        });
    }

    /**
     * Issue a fresh probe token, revoking whatever the application had.
     *
     * Rotated alongside the ingestion credential, so one "new key" action
     * leaves nothing stale behind.
     */
    public function rotateProbeToken(Application $application): string
    {
        $token = $this->newProbeToken();

        $application->forceFill(['probe_token' => $token])->save();

        return $token;
    }

    protected function newProbeToken(): string
    {
        return 'probe_'.Str::random(40);
    }

    /**
     * Next sequential application code in APP-00001 form.
     *
     * PostgreSQL draws from a dedicated sequence so two simultaneous
     * registrations cannot be handed the same code. Other drivers (the test
     * suite) fall back to a locked max() lookup.
     */
    public function nextApplicationCode(): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $next = (int) DB::selectOne("SELECT nextval('application_code_seq') AS value")->value;

            return $this->formatCode($next);
        }

        $highest = Application::withTrashed()
            ->where('app_code', 'like', 'APP-%')
            ->orderByDesc('app_code')
            ->lockForUpdate()
            ->value('app_code');

        $next = $highest === null ? 1 : ((int) substr($highest, 4)) + 1;

        return $this->formatCode($next);
    }

    protected function formatCode(int $number): string
    {
        return 'APP-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'application';
        $slug = $base;
        $suffix = 2;

        while (Application::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * Only the operator's overrides are stored; defaults stay in config so
     * changing a default applies everywhere at once.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function monitoringConfig(array $overrides): array
    {
        return array_intersect_key($overrides, config('sentinel.defaults'));
    }
}
