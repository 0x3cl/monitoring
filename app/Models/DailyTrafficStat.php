<?php

namespace App\Models;

use Database\Factories\DailyTrafficStatFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One application's traffic for one day.
 *
 * Rebuilt rather than incremented, so a late-arriving batch from an agent
 * draining its offline spool corrects the day instead of double counting it.
 */
class DailyTrafficStat extends Model
{
    /** @use HasFactory<DailyTrafficStatFactory> */
    use HasFactory;

    protected $fillable = [
        'application_id',
        'day',
        'requests',
        'unique_visitors',
        'success',
        'client_errors',
        'server_errors',
        'slow_requests',
        'avg_response_time_ms',
        'p95_response_time_ms',
        'max_response_time_ms',
        'top_endpoints',
        'top_clients',
        'sample_rate',
    ];

    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'requests' => 'integer',
            'unique_visitors' => 'integer',
            'success' => 'integer',
            'client_errors' => 'integer',
            'server_errors' => 'integer',
            'slow_requests' => 'integer',
            'avg_response_time_ms' => 'integer',
            'p95_response_time_ms' => 'integer',
            'max_response_time_ms' => 'integer',
            'top_endpoints' => 'array',
            'top_clients' => 'array',
            'sample_rate' => 'float',
        ];
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeBetween(Builder $query, string $from, string $to): void
    {
        $query->whereBetween('day', [$from, $to]);
    }

    /**
     * Share of requests that failed, as a percentage.
     *
     * Client errors count: a flood of 404s or 401s is a real signal, and
     * lumping only 5xx into "errors" hides a scraper or a broken deploy.
     */
    public function errorRate(): float
    {
        if ($this->requests === 0) {
            return 0.0;
        }

        return round((($this->client_errors + $this->server_errors) / $this->requests) * 100, 2);
    }
}
