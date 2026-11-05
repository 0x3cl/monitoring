<?php

namespace App\Models;

use App\Enums\EventType;
use App\Enums\Severity;
use Database\Factories\MonitoringEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringEvent extends Model
{
    /** @use HasFactory<MonitoringEventFactory> */
    use HasFactory;

    /**
     * Events are append-only: there is no updated_at to maintain.
     */
    public $timestamps = false;

    protected $fillable = [
        'application_id',
        'server_id',
        'event_type',
        'severity',
        'occurred_at',
        'received_at',
        'correlation_id',
        'fingerprint',
        'message',
        'http_method',
        'endpoint',
        'http_status',
        'response_time_ms',
        'exception_class',
        'client_ip',
        'user_agent',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => EventType::class,
            'severity' => Severity::class,
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'http_status' => 'integer',
            'response_time_ms' => 'integer',
            'data' => 'array',
        ];
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @param Builder<$this> $query */
    public function scopeSeverityAtLeast(Builder $query, Severity $severity): void
    {
        $query->whereIn('severity', array_values(array_filter(
            Severity::values(),
            fn (string $value) => Severity::from($value)->atLeast($severity)
        )));
    }

    /** @param Builder<$this> $query */
    public function scopeWithin(Builder $query, int $minutes): void
    {
        $query->where('occurred_at', '>=', now()->subMinutes($minutes));
    }

    /**
     * Events that represent a served HTTP request, which is what traffic is
     * counted from. Excludes exceptions and SENTINEL's own status events, which
     * are not requests the application handled.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeTraffic(Builder $query): void
    {
        $query->whereIn('event_type', [
            EventType::HttpRequest->value,
            EventType::SlowRequest->value,
        ]);
    }
}
