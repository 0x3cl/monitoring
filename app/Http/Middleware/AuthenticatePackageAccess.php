<?php

namespace App\Http\Middleware;

use App\Services\CredentialService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optionally restricts the Composer repository to registered applications.
 *
 * The agent source carries no secrets, so the repository is readable by
 * default. Closed instances set SENTINEL_PACKAGES_PUBLIC=false, and Composer
 * then authenticates with the application's own credential over HTTP Basic.
 */
class AuthenticatePackageAccess
{
    public function __construct(
        protected CredentialService $credentials,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('sentinel.packages.public', true)) {
            return $next($request);
        }

        $credential = $this->credentials->resolve($request->getPassword());

        if ($credential === null || ! $credential->isUsable()) {
            return response('Authentication required.', 401, [
                'WWW-Authenticate' => 'Basic realm="SENTINEL packages"',
            ]);
        }

        return $next($request);
    }
}
