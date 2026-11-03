<?php

namespace DostPtri\SentinelAgent;

use DostPtri\SentinelAgent\Console\SentinelFlushCommand;
use DostPtri\SentinelAgent\Console\SentinelTestCommand;
use DostPtri\SentinelAgent\Http\Middleware\SentinelRequestMonitor;
use DostPtri\SentinelAgent\Support\CorrelationId;
use DostPtri\SentinelAgent\Support\Sanitizer;
use DostPtri\SentinelAgent\Transport\EventBuffer;
use DostPtri\SentinelAgent\Transport\HttpTransport;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Throwable;

class SentinelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sentinel.php', 'sentinel');

        $this->app->singleton(CorrelationId::class);

        $this->app->singleton(Sanitizer::class, fn ($app) => new Sanitizer(
            (array) $app['config']->get('sentinel.redact', []),
        ));

        $this->app->singleton(EventBuffer::class, fn ($app) => new EventBuffer(
            Storage::disk($app['config']->get('sentinel.buffer.disk', 'local')),
            (string) $app['config']->get('sentinel.buffer.path', 'sentinel/buffer.ndjson'),
            (int) $app['config']->get('sentinel.buffer.max_events', 1000),
        ));

        $this->app->singleton(HttpTransport::class, fn ($app) => new HttpTransport(
            $app->make(Factory::class),
            $app['config']->get('sentinel.buffer.enabled', true) ? $app->make(EventBuffer::class) : null,
            (string) $app['config']->get('sentinel.endpoint'),
            (string) $app['config']->get('sentinel.key'),
            'X-Sentinel-Key',
            (float) $app['config']->get('sentinel.timeout', 2.0),
            (int) $app['config']->get('sentinel.retries', 2),
            (int) $app['config']->get('sentinel.retry_delay_ms', 200),
        ));

        $this->app->singleton(SentinelAgent::class, fn ($app) => new SentinelAgent(
            $app['config'],
            $app->make(HttpTransport::class),
            $app->make(Sanitizer::class),
            $app->make(CorrelationId::class),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/sentinel.php' => config_path('sentinel.php'),
        ], 'sentinel-config');

        if ($this->app->runningInConsole()) {
            $this->commands([SentinelTestCommand::class, SentinelFlushCommand::class]);
        }

        $this->registerMiddleware();
        $this->registerExceptionReporting();
    }

    /**
     * Push the request monitor onto the global stack so no route file has to
     * be edited in the monitored application.
     */
    protected function registerMiddleware(): void
    {
        if (! $this->app['config']->get('sentinel.features.http_requests', true)) {
            return;
        }

        try {
            $this->app->make(Kernel::class)->pushMiddleware(SentinelRequestMonitor::class);
        } catch (Throwable) {
            // Console-only applications have no HTTP kernel; nothing to do.
        }
    }

    /**
     * Report exceptions by listening for what the application already logs.
     *
     * This avoids replacing the host's exception handler, so a monitored
     * application keeps whatever error handling it already had.
     */
    protected function registerExceptionReporting(): void
    {
        Event::listen(function (MessageLogged $message) {
            try {
                $exception = $message->context['exception'] ?? null;

                if (! $exception instanceof Throwable) {
                    return;
                }

                if (! in_array($message->level, ['error', 'critical', 'alert', 'emergency'], true)) {
                    return;
                }

                $this->app->make(SentinelAgent::class)->exception(
                    $exception,
                    $message->level === 'error' ? 'ERROR' : 'CRITICAL'
                );
            } catch (Throwable) {
                // Never let monitoring turn a logged error into a second one.
            }
        });
    }
}
