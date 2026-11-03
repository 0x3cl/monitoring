<?php

namespace App\Http\Middleware;

use App\Services\CredentialService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a monitored application by its own credential.
 *
 * The resolved application is bound onto the request so downstream validation
 * can enforce that an application only ever submits data under its own
 * identity.
 */
class AuthenticateApplication
{
    public const APPLICATION_ATTRIBUTE = 'sentinel_application';

    public const CREDENTIAL_ATTRIBUTE = 'sentinel_credential';

    public function __construct(
        protected CredentialService $credentials,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('sentinel.credentials.header', 'X-Sentinel-Key');
        $token = $request->header($header) ?: $request->bearerToken();

        $credential = $this->credentials->resolve($token);

        if ($credential === null) {
            return $this->deny('Unknown or missing application credential.');
        }

        if (! $credential->isUsable()) {
            return $this->deny($credential->isRevoked()
                ? 'This application credential has been revoked.'
                : 'This application credential has expired.');
        }

        $application = $credential->application;

        if ($application === null || ! $application->canIngest()) {
            return $this->deny('Monitoring is disabled for this application.', 403);
        }

        $request->attributes->set(self::APPLICATION_ATTRIBUTE, $application);
        $request->attributes->set(self::CREDENTIAL_ATTRIBUTE, $credential);

        $this->credentials->touch($credential);

        return $next($request);
    }

    protected function deny(string $message, int $status = 401): JsonResponse
    {
        return new JsonResponse([
            'message' => $message,
        ], $status);
    }
}
