<?php

namespace App\Models;

use Database\Factories\IntegrationTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationType extends Model
{
    /** @use HasFactory<IntegrationTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'transport',
        'package_name',
        'description',
        'is_available',
    ];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
        ];
    }

    /** @return HasMany<IntegrationVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(IntegrationVersion::class);
    }

    /** @return HasMany<Application, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function currentVersion(): ?IntegrationVersion
    {
        return $this->versions()->where('is_current', true)->first();
    }
}
