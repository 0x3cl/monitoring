<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | Issued by SENTINEL when this application was registered. The key is an
    | application-scoped credential: it may only submit events under this
    | application's identity.
    |
    */

    'enabled' => env('SENTINEL_ENABLED', true),
    'application_id' => env('SENTINEL_APPLICATION_ID'),
    'endpoint' => env('SENTINEL_ENDPOINT'),
    'key' => env('SENTINEL_KEY'),
    'timeout' => (float) env('SENTINEL_TIMEOUT', 2.0),
    'retries' => (int) env('SENTINEL_RETRIES', 2),
    'retry_delay_ms' => (int) env('SENTINEL_RETRY_DELAY_MS', 200),

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    */

    'features' => [
        'http_requests' => env('SENTINEL_FEATURE_HTTP_REQUESTS', true),
        'exceptions' => env('SENTINEL_FEATURE_EXCEPTIONS', true),
        'database_errors' => env('SENTINEL_FEATURE_DATABASE_ERRORS', true),
        'slow_requests' => env('SENTINEL_FEATURE_SLOW_REQUESTS', true),
        'custom_events' => env('SENTINEL_FEATURE_CUSTOM_EVENTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    |
    | Successful, fast requests are usually noise. `sample_rate` keeps a
    | fraction of them for traffic volume; errors and slow requests are always
    | reported regardless of the sample rate.
    |
    */

    'thresholds' => [
        'slow_request_ms' => (int) env('SENTINEL_SLOW_REQUEST_MS', 3000),
        'sample_rate' => (float) env('SENTINEL_SAMPLE_RATE', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health endpoint
    |--------------------------------------------------------------------------
    |
    | SENTINEL probes this application from the outside to detect outages. A
    | probe can only read status codes, so this endpoint reports what no
    | external observer can establish: whether a 503 is a deliberate
    | maintenance window or a fault, and whether the application has lost one
    | of its own dependencies while still serving pages.
    |
    | The route stays reachable while `artisan down` is in effect - the agent
    | exempts it from the maintenance middleware itself, so nothing in this
    | application's bootstrap has to change. It carries no secrets.
    |
    */

    'health' => [
        'enabled' => env('SENTINEL_HEALTH_ENABLED', true),
        'path' => env('SENTINEL_HEALTH_PATH', 'sentinel/health'),
        'header' => 'X-Sentinel-Probe',

        // Issued by SENTINEL at registration. Leave it empty to answer any
        // caller, which reveals no more than Laravel's own /up route.
        'token' => env('SENTINEL_HEALTH_TOKEN'),

        'checks' => [
            'database' => env('SENTINEL_HEALTH_CHECK_DATABASE', true),
            'cache' => env('SENTINEL_HEALTH_CHECK_CACHE', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Request attributes
    |--------------------------------------------------------------------------
    |
    | What each reported request carries about its caller. SENTINEL counts
    | traffic and unique visitors from the address, and can identify the source
    | of an attack from it.
    |
    | This application has the final say: switch either off and it is never sent
    | at all, whatever SENTINEL is configured to store. The address honours this
    | application's trusted-proxy settings, so behind a load balancer it is the
    | real caller rather than the balancer.
    |
    */

    'capture' => [
        'ip' => env('SENTINEL_CAPTURE_IP', true),
        'user_agent' => env('SENTINEL_CAPTURE_USER_AGENT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded routes
    |--------------------------------------------------------------------------
    |
    | Request paths that are never reported. Wildcards are matched with
    | Str::is(), so 'horizon/*' excludes the whole dashboard.
    |
    */

    'excluded_routes' => [
        'horizon/*',
        'telescope/*',
        '_debugbar/*',
        'up',
        // SENTINEL's own probe is not application traffic.
        'sentinel/health',
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | 'queue' hands events to the application's queue so monitoring never adds
    | latency to a response. 'sync' sends inline after the response has been
    | delivered to the browser.
    |
    */

    'delivery' => env('SENTINEL_DELIVERY', 'sync'),
    'queue_connection' => env('SENTINEL_QUEUE_CONNECTION'),
    'queue_name' => env('SENTINEL_QUEUE', null),

    /*
    |--------------------------------------------------------------------------
    | Buffering
    |--------------------------------------------------------------------------
    |
    | When SENTINEL cannot be reached, events are appended to a local file and
    | retried later by `php artisan sentinel:flush`. Nothing is lost to a brief
    | outage, and nothing is retried forever.
    |
    */

    'buffer' => [
        'enabled' => env('SENTINEL_BUFFER_ENABLED', true),
        'path' => env('SENTINEL_BUFFER_PATH', 'sentinel/buffer.ndjson'),
        'max_events' => (int) env('SENTINEL_BUFFER_MAX_EVENTS', 1000),
        'batch_size' => (int) env('SENTINEL_BUFFER_BATCH_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    |
    | Keys stripped before an event leaves this application. SENTINEL sanitizes
    | again on ingestion, but secrets should never travel over the wire.
    |
    */

    'redact' => [
        'password',
        'password_confirmation',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'api_key',
        'authorization',
        'cookie',
        'session',
        'csrf',
        'credit_card',
        'cvv',
    ],

];
