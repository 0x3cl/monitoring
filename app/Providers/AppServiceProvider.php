<?php

namespace App\Providers;

use App\Contracts\TcpProber;
use App\Http\Middleware\AuthenticateApplication;
use App\Services\Heartbeat\SocketTcpProber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Behind an interface so probing services can be tested without
        // opening real sockets.
        $this->app->singleton(TcpProber::class, SocketTcpProber::class);
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureDevCommands();
    }

    /**
     * Heartbeat probes run on their own queue, which `artisan dev` would not
     * otherwise consume - its default worker listens on the default queue only,
     * and it runs no scheduler at all, so probes would silently never fire in
     * development.
     */
    protected function configureDevCommands(): void
    {
        if (! $this->app->runningInConsole() || ! class_exists(DevCommands::class)) {
            return;
        }

        $queues = implode(',', array_unique([
            (string) config('sentinel.heartbeat.queue', 'heartbeat'),
            (string) config('sentinel.traffic.queue', 'aggregates'),
            'default',
        ]));

        // Registered under a different name than the one being excluded:
        // except() filters by name when the list is resolved, so reusing
        // 'queue' would drop the replacement along with the original.
        DevCommands::except('queue');
        DevCommands::artisan("queue:listen --queue={$queues} --tries=1 --timeout=0", 'worker');
        DevCommands::artisan('schedule:work', 'scheduler');
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
