<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\ConnectionStatus;
use App\Enums\Environment;
use App\Enums\HealthState;
use App\Enums\ProbeDiagnosis;
use App\Enums\Severity;
use App\Enums\UptimeStatus;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'app_code',
        'name',
        'slug',
        'environment',
        'server_id',
        'integration_type_id',
        'integration_version_id',
        'status',
        'connection_status',
        'base_url',
        'health_endpoint',
        'probe_enabled',
        'probe_interval_seconds',
        'uptime_status',
        'last_event_at',
        'monitoring_config',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'status' => ApplicationStatus::class,
            'connection_status' => ConnectionStatus::class,
            'last_event_at' => 'immutable_datetime',
            'monitoring_config' => 'array',
            'is_active' => 'boolean',
            'probe_enabled' => 'boolean',
            'uptime_status' => UptimeStatus::class,
            'uptime_diagnosis' => ProbeDiagnosis::class,
            // Sent on every probe, so unlike the ingestion credential it has
            // to be recoverable rather than hashed.
            'probe_token' => 'encrypted',
            'last_probe_at' => 'immutable_datetime',
            'last_up_at' => 'immutable_datetime',
            'status_changed_at' => 'immutable_datetime',
            'next_probe_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<IntegrationType, $this> */
    public function integrationType(): BelongsTo
    {
        return $this->belongsTo(IntegrationType::class);
    }

    /** @return BelongsTo<IntegrationVersion, $this> */
    public function integrationVersion(): BelongsTo
    {
        return $this->belongsTo(IntegrationVersion::class);
    }

    /** @return HasMany<ApplicationCredential, $this> */
    public function credentials(): HasMany
    {
        return $this->hasMany(ApplicationCredential::class);
    }

    /** @return HasMany<LogSource, $this> */
    public function logSources(): HasMany
    {
        return $this->hasMany(LogSource::class);
    }

    /** @return HasMany<MonitoringEvent, $this> */
    public function monitoringEvents(): HasMany
    {
        return $this->hasMany(MonitoringEvent::class);
    }

    /** @param Builder<$this> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)
            ->where('status', '!=', ApplicationStatus::Disabled->value);
    }

    /**
     * Applications that have connected before but have gone quiet.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeStale(Builder $query, ?int $minutes = null): void
    {
        $minutes ??= config('sentinel.health.disconnect_after_minutes');

        $query->whereNotNull('last_event_at')
            ->where('last_event_at', '<', now()->subMinutes($minutes));
    }

    /**
     * May this application submit monitoring events right now?
     */
    public function canIngest(): bool
    {
        return $this->is_active && $this->status->canIngest();
    }

    /**
     * Severity counts for this application over the given window.
     *
     * @return array<string, int>
     */
    public function eventCountsBySeverity(int $minutes = 1440): array
    {
        $counts = $this->monitoringEvents()
            ->where('occurred_at', '>=', now()->subMinutes($minutes))
            ->selectRaw('severity, count(*) as aggregate')
            ->groupBy('severity')
            ->pluck('aggregate', 'severity')
            ->all();

        return array_map(
            fn (string $severity) => (int) ($counts[$severity] ?? 0),
            array_combine(Severity::values(), Severity::values())
        );
    }

    /**
     * Applications whose next heartbeat probe has come due.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDueForProbe(Builder $query): void
    {
        $query->where('probe_enabled', true)
            ->whereNotNull('base_url')
            ->where(fn (Builder $due) => $due
                ->whereNull('next_probe_at')
                ->orWhere('next_probe_at', '<=', now()));
    }

    /**
     * Health is derived from recent severity counts and connectivity rather
     * than stored, so it can never drift out of date.
     */
    public function healthState(int $minutes = 60): HealthState
    {
        // What an outbound probe saw outranks everything below it. An
        // application that is not answering is critical now, rather than in
        // fifteen minutes once the silence window has elapsed - and it stays
        // critical even while its agent is still cheerfully reporting.
        if ($this->uptime_status?->isOutage()) {
            return HealthState::Critical;
        }

        if (in_array($this->uptime_status, [UptimeStatus::Degraded, UptimeStatus::Maintenance], true)) {
            return HealthState::Warning;
        }

        if ($this->connection_status === ConnectionStatus::NeverConnected) {
            return HealthState::Unknown;
        }

        if ($this->connection_status === ConnectionStatus::Disconnected) {
            return HealthState::Critical;
        }

        $counts = $this->eventCountsBySeverity($minutes);

        return match (true) {
            $counts[Severity::Critical->value] > 0 => HealthState::Critical,
            $counts[Severity::Error->value] > 0 => HealthState::Warning,
            $counts[Severity::Warning->value] > 0 => HealthState::Warning,
            default => HealthState::Healthy,
        };
    }

    /**
     * Enabled feature flags merged over the configured defaults.
     *
     * @return array<string, mixed>
     */
    public function monitoringConfig(): array
    {
        $defaults = config('sentinel.defaults');

        return array_replace_recursive($defaults, $this->monitoring_config ?? []);
    }

    public function getRouteKeyName(): string
    {
        return 'app_code';
    }
}
