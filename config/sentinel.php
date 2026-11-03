<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ingestion
    |--------------------------------------------------------------------------
    |
    | Limits applied to every monitoring event submitted by a monitored
    | application. They protect the central engine from oversized or abusive
    | payloads without requiring changes to any monitored application.
    |
    */

    'ingestion' => [
        'max_body_bytes' => (int) env('SENTINEL_MAX_BODY_BYTES', 262144),
        'max_batch_events' => (int) env('SENTINEL_MAX_BATCH_EVENTS', 100),
        'max_data_depth' => (int) env('SENTINEL_MAX_DATA_DEPTH', 8),
        'max_string_length' => (int) env('SENTINEL_MAX_STRING_LENGTH', 4000),
        'rate_limit_per_minute' => (int) env('SENTINEL_RATE_LIMIT_PER_MINUTE', 600),
        'future_tolerance_minutes' => (int) env('SENTINEL_FUTURE_TOLERANCE_MINUTES', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    */

    'credentials' => [
        'prefix' => 'sentinel_',
        'token_bytes' => 36,
        'header' => 'X-Sentinel-Key',
    ],

    /*
    |--------------------------------------------------------------------------
    | Package repository
    |--------------------------------------------------------------------------
    |
    | SENTINEL serves its integration agents as a Composer repository, so
    | monitored applications install them from this instance directly. The
    | agent source carries no secrets, so it is readable by default; set
    | SENTINEL_PACKAGES_PUBLIC=false to require an application credential.
    |
    */

    'packages' => [
        'public' => (bool) env('SENTINEL_PACKAGES_PUBLIC', true),
        'url' => env('SENTINEL_PACKAGES_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default monitoring configuration
    |--------------------------------------------------------------------------
    |
    | Applied to newly registered applications and handed to their integration
    | package as configuration. Each application may override these values.
    |
    */

    'defaults' => [
        'features' => [
            'http_requests' => true,
            'exceptions' => true,
            'database_errors' => true,
            'slow_requests' => true,
            'custom_events' => true,
            'infrastructure_metrics' => false,
        ],
        'thresholds' => [
            'slow_request_ms' => (int) env('SENTINEL_SLOW_REQUEST_MS', 3000),
            'error_rate_percent' => (int) env('SENTINEL_ERROR_RATE_PERCENT', 5),
            'sample_rate' => (float) env('SENTINEL_SAMPLE_RATE', 1.0),
        ],
        'excluded_routes' => [
            'horizon/*',
            'telescope/*',
            '_debugbar/*',
            'up',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Health
    |--------------------------------------------------------------------------
    |
    | An application is considered disconnected once no event has been received
    | within this window.
    |
    */

    'health' => [
        'disconnect_after_minutes' => (int) env('SENTINEL_DISCONNECT_AFTER_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing intervals (minutes, unless noted)
    |--------------------------------------------------------------------------
    |
    | Consumed by the scheduler in later phases. Kept here so operators can
    | tune cadence without touching code.
    |
    */

    'intervals' => [
        'error_aggregation' => (int) env('SENTINEL_INTERVAL_ERROR_AGGREGATION', 1),
        'incident_evaluation' => (int) env('SENTINEL_INTERVAL_INCIDENT_EVALUATION', 1),
        'performance_analysis' => (int) env('SENTINEL_INTERVAL_PERFORMANCE_ANALYSIS', 5),
        'availability_check' => (int) env('SENTINEL_INTERVAL_AVAILABILITY_CHECK', 1),
        'ssl_check_hours' => (int) env('SENTINEL_INTERVAL_SSL_CHECK_HOURS', 6),
        'daily_summary_hours' => (int) env('SENTINEL_INTERVAL_DAILY_SUMMARY_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    */

    'retention' => [
        'event_days' => (int) env('SENTINEL_RETENTION_EVENT_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sanitization
    |--------------------------------------------------------------------------
    |
    | Keys matching these patterns are stripped from every event before it is
    | persisted. SENTINEL must never store credentials or session material.
    |
    */

    'sanitization' => [
        'blocked_keys' => [
            'password',
            'password_confirmation',
            'passwd',
            'secret',
            'token',
            'access_token',
            'refresh_token',
            'api_key',
            'apikey',
            'authorization',
            'auth',
            'cookie',
            'set-cookie',
            'session',
            'csrf',
            'xsrf',
            'remember_token',
            'private_key',
            'credit_card',
            'card_number',
            'cvv',
            'ssn',
        ],
        'redaction' => '[REDACTED]',
    ],

];
