<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateApplication;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Ingestion is limited per credential rather than per IP: many monitored
     * applications can share an outbound address, and a single noisy agent
     * should not throttle the others.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('sentinel-ingest', function (Request $request) {
            $perMinute = (int) config('sentinel.ingestion.rate_limit_per_minute', 600);

            $credential = $request->attributes->get(AuthenticateApplication::CREDENTIAL_ATTRIBUTE);

            $key = $credential !== null
                ? 'credential:'.$credential->getKey()
                : 'ip:'.$request->ip();

            return Limit::perMinute($perMinute)->by($key);
        });

        RateLimiter::for('sentinel-login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);
    }
}
