<?php

namespace App\Models;

use Database\Factories\LogSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogSource extends Model
{
    /** @use HasFactory<LogSourceFactory> */
    use HasFactory;

    protected $fillable = [
        'application_id',
        'type',
        'path',
        'is_enabled',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
