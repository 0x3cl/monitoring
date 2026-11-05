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
    | Heartbeat probing
    |--------------------------------------------------------------------------
    |
    | Connectivity inferred from silence cannot tell a planned maintenance
    | window apart from a dead server, and says nothing at all until the
    | silence window has elapsed. These settings govern the outbound probes
    | that answer both questions directly.
    |
    | Every probe runs as a queued job on its own queue: probing is latency
    | bound, so it must never sit behind, or starve, the queue that processes
    | ingested events.
    |
    */

    'heartbeat' => [
        'enabled' => (bool) env('SENTINEL_HEARTBEAT_ENABLED', true),
        'queue' => env('SENTINEL_HEARTBEAT_QUEUE', 'heartbeat'),

        // Default cadence for a newly registered application. Each one may
        // override it, so a staging app need not be probed as often as
        // production.
        'interval_seconds' => (int) env('SENTINEL_HEARTBEAT_INTERVAL', 60),

        // Consecutive failures required before an outage is declared. A single
        // dropped packet is not an incident, and this is cheaper than retrying
        // the job: the retry is simply the next scheduled probe.
        'failure_threshold' => (int) env('SENTINEL_HEARTBEAT_FAILURE_THRESHOLD', 2),

        // Spread probes across the interval instead of firing every one at the
        // top of the minute.
        'jitter_percent' => (int) env('SENTINEL_HEARTBEAT_JITTER_PERCENT', 10),
        'dispatch_chunk' => (int) env('SENTINEL_HEARTBEAT_DISPATCH_CHUNK', 500),

        // Kept short on purpose: a black-holed host should occupy a worker for
        // seconds, not minutes.
        'connect_timeout' => (float) env('SENTINEL_HEARTBEAT_CONNECT_TIMEOUT', 3),
        'timeout' => (float) env('SENTINEL_HEARTBEAT_TIMEOUT', 5),
        'tcp_timeout' => (float) env('SENTINEL_HEARTBEAT_TCP_TIMEOUT', 3),

        // A successful but sluggish response is reported as degraded rather
        // than healthy.
        'slow_response_ms' => (int) env('SENTINEL_HEARTBEAT_SLOW_RESPONSE_MS', 2000),

        // Laravel exempts its own /up route from maintenance mode, so a health
        // endpoint answers 200 even while `artisan down` is in effect. For an
        // application with no agent to state the truth, one extra request to a
        // non-exempt path is the only way to see maintenance at all.
        'verify_maintenance' => (bool) env('SENTINEL_HEARTBEAT_VERIFY_MAINTENANCE', true),

        // Used when an application registers no health_endpoint of its own.
        // Laravel serves this route out of the box.
        'default_health_path' => env('SENTINEL_HEARTBEAT_HEALTH_PATH', '/up'),
        'default_server_port' => (int) env('SENTINEL_HEARTBEAT_SERVER_PORT', 443),

        // Where the Laravel agent serves its own health endpoint. Handed to
        // monitored applications as SENTINEL_HEALTH_PATH, so both ends agree
        // on it from one setting.
        'default_agent_health_path' => env('SENTINEL_HEARTBEAT_AGENT_HEALTH_PATH', 'sentinel/health'),

        // Header carrying the probe token to the agent's health endpoint.
        'probe_header' => 'X-Sentinel-Probe',
    ],

    /*
    |--------------------------------------------------------------------------
    | Traffic analytics
    |--------------------------------------------------------------------------
    |
    | Request events carry the caller's address, which is personal data. It is
    | stored so an operator can identify the source of an attack or trace one
    | caller's path; set SENTINEL_TRAFFIC_MASK_IP=true to drop the last octet
    | (or the last 80 bits of an IPv6 address) at ingestion instead, which still
    | counts visitors and spots subnets but can never identify one caller.
    |
    | Masking is applied before the row is written, so nothing unmasked is ever
    | persisted and the setting cannot be undone retroactively.
    |
    */

    'traffic' => [
        'enabled' => (bool) env('SENTINEL_TRAFFIC_ENABLED', true),
        'queue' => env('SENTINEL_TRAFFIC_QUEUE', 'aggregates'),

        'store_client_ip' => (bool) env('SENTINEL_TRAFFIC_STORE_IP', true),
        'mask_client_ip' => (bool) env('SENTINEL_TRAFFIC_MASK_IP', false),

        // How many entries each daily row keeps for its top-endpoint and
        // top-client lists. Ten is what a person will actually read.
        'top_n' => (int) env('SENTINEL_TRAFFIC_TOP_N', 10),

        // Days recomputed on each aggregation run. Today always moves; a day or
        // two behind can still gain late arrivals from an agent draining its
        // offline spool.
        'recompute_days' => (int) env('SENTINEL_TRAFFIC_RECOMPUTE_DAYS', 2),

        // Ranges the dashboard offers, in days.
        'ranges' => [7, 30, 90],
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

        // Daily rollups outlive the events they were built from: one row per
        // application per day is small enough to keep a year of trend.
        'traffic_day_days' => (int) env('SENTINEL_RETENTION_TRAFFIC_DAYS', 365),
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
