<?php

namespace App\Http\Resources;

use App\Models\ApplicationCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApplicationCredential */
class ApplicationCredentialResource extends JsonResource
{
    /**
     * The token itself is never included: only a masked handle and its state.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'masked_token' => $this->maskedToken(),
            'is_usable' => $this->isUsable(),
            'is_revoked' => $this->isRevoked(),
            'is_expired' => $this->isExpired(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
