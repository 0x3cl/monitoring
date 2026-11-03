<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApplicationRequest;
use App\Http\Requests\UpdateApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Services\ApplicationRegistrar;
use App\Services\CredentialService;
use App\Services\IntegrationConfigBuilder;
use App\Services\IntegrationPackager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ApplicationController extends Controller
{
    public function __construct(
        protected ApplicationRegistrar $registrar,
        protected IntegrationConfigBuilder $integrations,
        protected IntegrationPackager $packager,
        protected CredentialService $credentials,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $applications = Application::query()
            ->with(['server', 'integrationType', 'integrationVersion'])
            ->withCount('monitoringEvents')
            ->when($request->filled('environment'), fn ($query) => $query->where('environment', $request->string('environment')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('connection_status'), fn ($query) => $query->where('connection_status', $request->string('connection_status')))
            ->when($request->filled('server_id'), fn ($query) => $query->where('server_id', $request->integer('server_id')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->lower().'%';

                $query->where(function ($query) use ($term) {
                    $query->whereRaw('lower(name) like ?', [$term])
                        ->orWhereRaw('lower(app_code) like ?', [$term]);
                });
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ApplicationResource::collection($applications);
    }

    /**
     * Register an application and hand back its one-time credential together
     * with the integration bundle the administrator needs to install it.
     */
    public function store(StoreApplicationRequest $request): JsonResponse
    {
        $registered = $this->registrar->register($request->validated(), $request->user());

        $application = $registered->application->load([
            'server', 'integrationType', 'integrationVersion', 'credentials',
        ]);

        return new JsonResponse([
            'data' => ApplicationResource::make($application)->resolve(),
            // Shown once. SENTINEL stores only a hash and cannot reveal it again.
            'credential' => [
                'token' => $registered->credential->plainTextToken,
                'warning' => 'Copy this key now — it cannot be displayed again.',
            ],
            'integration' => $this->integrations->bundleFor(
                $application,
                $registered->credential->plainTextToken
            ),
        ], 201);
    }

    public function show(Application $application): ApplicationResource
    {
        return ApplicationResource::make(
            $application->load(['server', 'integrationType', 'integrationVersion', 'credentials'])
                ->loadCount('monitoringEvents')
        );
    }

    public function update(UpdateApplicationRequest $request, Application $application): ApplicationResource
    {
        $attributes = $request->validated();

        if (array_key_exists('monitoring_config', $attributes)) {
            $attributes['monitoring_config'] = array_intersect_key(
                $attributes['monitoring_config'],
                config('sentinel.defaults')
            );
        }

        $application->update($attributes);

        return ApplicationResource::make(
            $application->fresh(['server', 'integrationType', 'integrationVersion', 'credentials'])
        );
    }

    /**
     * Soft deletes the registration and revokes its credentials, so a
     * decommissioned application stops being able to submit events while its
     * historical monitoring data remains queryable.
     */
    public function destroy(Application $application): JsonResponse
    {
        $application->credentials()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $application->delete();

        return new JsonResponse(['message' => 'Application unregistered and credentials revoked.']);
    }

    /**
     * The integration bundle for an existing application. The credential is
     * omitted: SENTINEL stores only a hash, so it is shown at issue time and
     * never again.
     */
    public function integration(Application $application): JsonResponse
    {
        return new JsonResponse([
            'data' => $this->integrations->bundleFor(
                $application->load(['integrationType', 'integrationVersion'])
            ),
        ]);
    }

    /**
     * Re-issue the credential and return the full setup with the key filled
     * in. The previous keys are revoked, so this is a rotation rather than a
     * recovery: an existing key is never recoverable.
     */
    public function reissueIntegration(Request $request, Application $application): JsonResponse
    {
        $issued = $this->credentials->rotate($application, 'default', $request->user());

        return new JsonResponse([
            'data' => $this->integrations->bundleFor(
                $application->load(['integrationType', 'integrationVersion']),
                $issued->plainTextToken
            ),
            'credential' => [
                'token' => $issued->plainTextToken,
                'warning' => 'Previous keys were revoked. Copy this one now — it cannot be displayed again.',
            ],
        ]);
    }

    /**
     * The agent source itself, so the install instructions reference
     * something an administrator can actually obtain.
     */
    public function downloadIntegration(Application $application): BinaryFileResponse
    {
        $slug = $application->integrationType->slug;

        abort_unless($this->packager->hasPackage($slug), 404, 'No downloadable package for this integration type.');

        return response()
            ->download($this->packager->archive($slug), $this->packager->fileName($slug))
            ->deleteFileAfterSend();
    }
}
