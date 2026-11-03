<?php

namespace App\Http\Resources;

use App\Models\IntegrationType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IntegrationType */
class IntegrationTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'transport' => $this->transport,
            'package_name' => $this->package_name,
            'description' => $this->description,
            'is_available' => $this->is_available,
        ];
    }
}
