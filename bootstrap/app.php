<?php

use App\Http\Middleware\AuthenticateApplication;
use App\Http\Middleware\AuthenticatePackageAccess;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\LimitRequestSize;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'sentinel.app' => AuthenticateApplication::class,
            'sentinel.size' => LimitRequestSize::class,
            'admin' => EnsureUserIsAdmin::class,
            'sentinel.packages' => AuthenticatePackageAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
