<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ignore = $this->route('server');

        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('servers', 'name')->ignore($ignore),
            ],
            'hostname' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'],
            'provider' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            // Probing target. Left blank, the probe falls back to hostname and
            // then ip_address.
            'monitor_host' => ['nullable', 'string', 'max:255'],
            'monitor_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'monitor_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
