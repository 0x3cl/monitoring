<?php

namespace App\Models;

use App\Enums\ReachabilityStatus;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'hostname',
        'ip_address',
        'provider',
        'location',
        'notes',
        'is_active',
        'monitor_host',
        'monitor_port',
        'monitor_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'monitor_enabled' => 'boolean',
            'reachability_status' => ReachabilityStatus::class,
            'last_probed_at' => 'immutable_datetime',
            'last_reachable_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<Application, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /** @return HasMany<MonitoringEvent, $this> */
    public function monitoringEvents(): HasMany
    {
        return $this->hasMany(MonitoringEvent::class);
    }

    /**
     * The address heartbeat probes connect to.
     *
     * Kept separate from hostname and ip_address so a host documented under one
     * name can be probed at another, falling back to whichever is recorded.
     */
    public function monitorTarget(): ?string
    {
        foreach ([$this->monitor_host, $this->hostname, $this->ip_address] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }
}
