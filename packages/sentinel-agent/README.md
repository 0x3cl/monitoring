# dost-ptri/sentinel-agent

The SENTINEL monitoring agent for Laravel applications. Install it in an
application you want monitored; it reports requests, exceptions, database
failures and slow endpoints to a central SENTINEL instance.

It is designed to be invisible to the application it monitors: every failure
path is swallowed, delivery happens after the response is sent, and an outage
is buffered rather than lost.

## Install

SENTINEL generates the exact instructions for each registered application —
open the application in the dashboard and read the **Integration** tab. The
short version:

```sh
# 1. Make the package available (from the archive the dashboard provides)
unzip sentinel-agent.zip -d packages/
composer config repositories.sentinel path packages/sentinel-agent
composer require dost-ptri/sentinel-agent

# 2. Optional: publish the config to customise it
php artisan vendor:publish --tag=sentinel-config
```

Then add the credentials from the dashboard to `.env`:

```ini
SENTINEL_ENABLED=true
SENTINEL_APPLICATION_ID=APP-00001
SENTINEL_ENDPOINT=https://sentinel.example.com/api/v1/events
SENTINEL_KEY=sentinel_…
```

Verify:

```sh
php artisan sentinel:test --event
```

## What it reports

| Event | When |
|---|---|
| `http_request` | Every request, after the response is sent |
| `slow_request` | Requests over `SENTINEL_SLOW_REQUEST_MS` (default 3000) |
| `exception` | Any exception the application logs at error level or above |
| `database_error` | `QueryException` and `PDOException`, as CRITICAL |
| `custom_event` | Whatever you report yourself |

Report your own events anywhere in the application:

```php
use DostPtri\SentinelAgent\Facades\Sentinel;

Sentinel::custom('Nightly export finished', ['records' => 10432]);
Sentinel::record('custom_event', 'WARNING', ['message' => 'Queue backlog growing']);
```

## What it sends about a caller

Each reported request carries the caller's address and user agent, which is what lets
SENTINEL count traffic, show unique visitors per day, and name the source of an attack.
The address honours this application's trusted-proxy settings, so behind a load balancer
it is the real caller rather than the balancer.

This application has the final say. `SENTINEL_CAPTURE_IP=false` means the address is
never sent at all, whatever SENTINEL is configured to store — useful where addresses may
not leave the host. SENTINEL can additionally mask what it stores.

## What it never sends

Request bodies, headers, cookies and sessions are not collected. Any key
matching the `redact` list in `config/sentinel.php` — passwords, tokens, API
keys, authorization, cookies — is stripped before the event leaves the
application, and values that look like bearer credentials are redacted
whatever key they arrived under.

## Outages

If SENTINEL cannot be reached, events are appended to a capped local spool
(`storage/app/sentinel/buffer.ndjson`) instead of being lost. Drain it on a
schedule:

```php
// routes/console.php
Schedule::command('sentinel:flush')->everyMinute();
```

A 4xx response is treated as a rejected payload and is *not* buffered, since
retrying it would fail forever.

## The health endpoint

The agent serves `GET /sentinel/health`, which SENTINEL probes from the outside
to decide whether this application is up.

**It keeps answering while the application is down for maintenance.** The agent
exempts that one path from Laravel's maintenance middleware itself, so nothing in
your `bootstrap/app.php` has to change — and SENTINEL can tell a deliberate
`php artisan down` apart from a crash instead of guessing from a 503. It also
reports database and cache health, which catches an application that is serving
pages perfectly well with no database behind it.

```jsonc
{
  "status": "ok",            // ok | maintenance | degraded
  "maintenance": false,
  "app": { "environment": "production", "debug": false },
  "checks": { "database": "ok", "cache": "ok" }
}
```

It is authenticated with `SENTINEL_HEALTH_TOKEN`, which SENTINEL issues at
registration and sends in an `X-Sentinel-Probe` header. That is a different
credential from `SENTINEL_KEY`: SENTINEL stores only a hash of the ingestion key
and so could never present it. The payload carries nothing secret. Leave the
token empty to answer any caller, which discloses no more than Laravel's own
`/up` route.

Verify it from the monitored host — including while the application is down:

```sh
curl -s http://localhost/sentinel/health -H 'X-Sentinel-Probe: <token>'
```

Set `SENTINEL_HEALTH_ENABLED=false` to remove the route entirely.

## Configuration

Everything is env-driven; see `config/sentinel.php`. The most useful knobs:

| Variable | Default | Purpose |
|---|---|---|
| `SENTINEL_ENABLED` | `true` | Master switch |
| `SENTINEL_SLOW_REQUEST_MS` | `3000` | Slow-request threshold |
| `SENTINEL_SAMPLE_RATE` | `1.0` | Fraction of *successful, fast* requests kept; errors and slow requests are always reported |
| `SENTINEL_TIMEOUT` | `2.0` | Seconds before giving up on SENTINEL |
| `SENTINEL_BUFFER_ENABLED` | `true` | Spool events during an outage |
| `SENTINEL_HEALTH_ENABLED` | `true` | Serve the health endpoint SENTINEL probes |
| `SENTINEL_HEALTH_PATH` | `sentinel/health` | Where that endpoint lives |
| `SENTINEL_HEALTH_TOKEN` | — | Shared secret for the probe; empty answers anyone |
| `SENTINEL_CAPTURE_IP` | `true` | Send the caller's address, which SENTINEL counts traffic by |
| `SENTINEL_CAPTURE_USER_AGENT` | `true` | Send the caller's user agent |

Routes in `excluded_routes` are never reported — by default Horizon,
Telescope, Debugbar, Laravel's `up` route and the agent's own health endpoint, so
SENTINEL's probing never shows up as application traffic.
