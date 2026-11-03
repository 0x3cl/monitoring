<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationCredentialResource;
use App\Models\Application;
use App\Models\ApplicationCredential;
use App\Services\CredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CredentialController extends Controller
{
    public function __construct(
        protected CredentialService $credentials,
    ) {}

    public function index(Application $application): JsonResponse
    {
        return new JsonResponse([
            'data' => ApplicationCredentialResource::collection(
                $application->credentials()->latest()->get()
            )->resolve(),
        ]);
    }

    /**
     * Issue an additional credential, optionally revoking the existing ones
     * (a rotation).
     */
    public function store(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:60'],
            'rotate' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $name = $validated['name'] ?? 'default';

        $issued = ($validated['rotate'] ?? false)
            ? $this->credentials->rotate($application, $name, $request->user())
            : $this->credentials->issue(
                $application,
                $name,
                $request->user(),
                isset($validated['expires_at']) ? new \DateTimeImmutable($validated['expires_at']) : null
            );

        return new JsonResponse([
            'data' => ApplicationCredentialResource::make($issued->credential)->resolve(),
            'credential' => [
                'token' => $issued->plainTextToken,
                'warning' => 'Copy this key now — it cannot be displayed again.',
            ],
        ], 201);
    }

    public function destroy(ApplicationCredential $credential): JsonResponse
    {
        $this->credentials->revoke($credential);

        return new JsonResponse([
            'data' => ApplicationCredentialResource::make($credential->fresh())->resolve(),
        ]);
    }
}
