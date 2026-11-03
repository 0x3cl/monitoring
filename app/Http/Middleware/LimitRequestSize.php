<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects oversized ingestion payloads before they are parsed, so a
 * misconfigured agent cannot exhaust the engine's memory.
 */
class LimitRequestSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('sentinel.ingestion.max_body_bytes', 262144);

        $declared = (int) $request->header('Content-Length', '0');
        $actual = strlen((string) $request->getContent());

        if (max($declared, $actual) > $limit) {
            return new JsonResponse([
                'message' => "Request body exceeds the {$limit} byte ingestion limit.",
            ], 413);
        }

        return $next($request);
    }
}
