<?php

namespace App\Models;

use Database\Factories\IntegrationVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationVersion extends Model
{
    /** @use HasFactory<IntegrationVersionFactory> */
    use HasFactory;

    protected $fillable = [
        'integration_type_id',
        'version',
        'is_current',
        'released_at',
        'changelog',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'released_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<IntegrationType, $this> */
    public function integrationType(): BelongsTo
    {
        return $this->belongsTo(IntegrationType::class);
    }
}
