# SENTINEL

Unified application & infrastructure monitoring platform. Monitored applications push
standardized JSON events to a central Laravel engine, which validates, sanitizes, stores
and (in later phases) correlates them into incidents and Telegram alerts.

**Stack:** Laravel 13 · Vue 3 (Vite) · shadcn-vue · PostgreSQL · Tailwind 4

This repository currently implements **Phase 1 — Core Laravel Foundation**: dynamic
application registration, credential issuance, the authenticated ingestion API, and a
dashboard for registering applications and reading received events.

## Requirements

- PHP 8.4 with `pdo_pgsql`
- PostgreSQL 15+
- Node.js 20+
- Composer

## Setup

```sh
# System packages (Debian/Ubuntu)
sudo apt install -y postgresql php8.4-pgsql

sudo -u postgres psql -c "CREATE ROLE sentinel LOGIN PASSWORD 'sentinel';"
sudo -u postgres psql -c "CREATE DATABASE sentinel OWNER sentinel;"
sudo -u postgres psql -c "CREATE DATABASE sentinel_test OWNER sentinel;"

# Application
composer install
npm install
cp .env.example .env            # adjust DB_* if your credentials differ
php artisan key:generate
php artisan migrate --seed
npm run build
```

Run it:

```sh
php artisan serve     # http://localhost:8000
npm run dev           # in a second terminal, for hot reloading
```

The seeder creates the integration catalogue and a development administrator:

| Username | Password |
|---|---|
| `sentinel` | `password` |

`AdminSeeder` refuses to run when `APP_ENV=production`, because a credential committed to
the repository is public knowledge. Create real administrators with:

```sh
php artisan sentinel:create-admin
```

Servers and applications are never seeded — register them through the dashboard.

### Interface

The dashboard is built from [shadcn-vue](https://www.shadcn-vue.com) components in
`resources/js/components/ui`, which are source files in this repository rather than a
dependency: edit them directly. Add more with:

```sh
./node_modules/.bin/shadcn-vue add <component>
```

Light, dark and system themes are available from the header toggle. The choice is stored
per browser and resolved in `resources/views/app.blade.php` before first paint, so there
is no flash of the wrong theme on load.

## Registering an application

1. Sign in and open **Applications → Register application**.
2. Pick the technology, environment and hosting server.
3. SENTINEL assigns an `APP-00001`-style code and issues a credential. The key is shown
   **once**: only a SHA-256 hash is stored, so it cannot be recovered afterwards.
4. Copy the generated `.env` block into the monitored application.

### Viewing the setup again

Open the application and choose the **Integration** tab. It regenerates the same install
steps at any time, and names the Composer repository this instance serves.

The key is the one thing it cannot show again, since only a hash is stored — the steps
carry a `<your-application-key>` placeholder instead. **New key & full setup** issues a
replacement with the key filled in; that revokes the current key, so reconfigure the
monitored application afterwards.

## The Laravel agent

`packages/sentinel-agent` is a real Composer package (`dost-ptri/sentinel-agent`) living in
this repository.

**SENTINEL hosts it itself.** The instance serves a Composer repository at
`/packages.json` and the dist archive alongside it, so there is nothing to upload, no
Packagist account and no separate VCS host. A monitored application installs it with two
commands:

```sh
composer config repositories.sentinel composer https://sentinel.example.com
composer require dost-ptri/sentinel-agent
php artisan sentinel:test --event
```

Agent updates reach every monitored application through `composer update` — bump
`version` in the package's `composer.json` and the repository serves the new release.

The repository is readable by default, since the agent source carries no secrets. Set
`SENTINEL_PACKAGES_PUBLIC=false` to require an application credential over HTTP Basic:

```sh
composer config http-basic.sentinel.example.com sentinel <application-key>
```

Set `SENTINEL_PACKAGES_URL` when the publicly reachable URL differs from `APP_URL`; the
dist URLs in `packages.json` are built from it. The dashboard also offers a plain zip
download as a fallback for air-gapped installs.

It reports requests, slow requests, exceptions and database failures; adds correlation
IDs; redacts secrets before they leave the application; and buffers events locally when
SENTINEL is unreachable (`php artisan sentinel:flush` drains the spool). Full details in
[packages/sentinel-agent/README.md](packages/sentinel-agent/README.md).

Verify the connection from the monitored host:

```sh
curl -s http://localhost:8000/api/v1/ping -H 'X-Sentinel-Key: <key>'
```

## Ingestion API

All routes are authenticated with the application's own credential in the
`X-Sentinel-Key` header, size-limited, and rate limited per credential.

| Method | Route | Purpose |
|---|---|---|
| `GET` | `/api/v1/ping` | Connection test; marks the application connected |
| `POST` | `/api/v1/events` | One monitoring event |
| `POST` | `/api/v1/events/batch` | Up to 100 buffered events |

```jsonc
{
  "application_id": "APP-00001",
  "event_type": "http_request",      // exception | database_error | slow_request |
                                     // application_status | custom_event |
                                     // infrastructure_metric
  "severity": "ERROR",               // DEBUG | INFO | WARNING | ERROR | CRITICAL
  "timestamp": "2026-11-03T09:30:00Z",
  "data": { "method": "POST", "endpoint": "/api/orders", "http_status": 500,
            "response_time_ms": 3200, "message": "Internal Server Error" }
}
```

An application may only submit events under its own `application_id`; anything else is
rejected with `403`.

### What is never stored

Passwords, authorization headers, cookies, session identifiers, API keys and
bearer-looking values are stripped from every payload *before* it is written, so a
mis-instrumented application cannot leak secrets into the monitoring database. The
blocked-key list lives in `config/sentinel.php`.

## Heartbeat monitoring

Pushed events can only describe an application that is running. They say nothing when
one is stopped, in maintenance, or sitting on a host that fell off the network — and a
silence window cannot tell those three apart. SENTINEL therefore also probes
applications from the outside, on the queue.

Each probe works through the layers cheapest first and stops at the one that fails,
then reads that failure against the server's own reachability:

| Observation | Status | Diagnosis |
|---|---|---|
| Health endpoint answers normally | `up` | Healthy |
| Health endpoint answers, but the root returns a maintenance 503 | `maintenance` | Planned maintenance |
| Answers, but slower than `slow_response_ms` | `degraded` | Slow response |
| Agent reports maintenance mode | `maintenance` | Planned maintenance |
| Agent reports a failing dependency | `degraded` | Dependency failure |
| 5xx | `down` | The application is running and failing |
| 4xx | `degraded` | The probe URL is misconfigured, not an outage |
| Connection refused, host answers | `down` | The web server or application process is not running |
| Connection times out after connecting | `down` | The application is running but hung |
| Host does not answer at all | `unreachable` | The host or the network, not the application |
| Hostname does not resolve | `unreachable` | DNS |

An outage is only declared after `failure_threshold` consecutive failures, so a single
dropped packet never pages anyone. Recovery is immediate. A confirmed change writes one
`application_status` event — CRITICAL for an outage, INFO on recovery — and nothing is
written for the probes in between, so the events table grows with incidents rather than
with the probe rate.

**Cost stays proportional to hosts, not applications.** Servers are probed once per
cycle and the verdict is cached for every application on them; probes are spread across
the interval with jitter; each job is unique per target, so a hung probe cannot pile up;
and the scheduler only ever selects and queues, never waits on a socket.

### Telling maintenance apart

This is the case a health check most often gets wrong. **Laravel exempts its own `/up`
route from maintenance mode**, so an application under `php artisan down` keeps answering
`200` there — a naive uptime check sees nothing at all. Meanwhile a plain `503` from
anywhere else is indistinguishable from an ordinary server error.

SENTINEL handles both ends of that:

- **With the agent installed** the answer is authoritative. The agent serves
  `GET /sentinel/health`, **exempts that one path from maintenance mode itself**, and
  reports `maintenance: true` outright — along with database and cache health, which
  catches an application serving pages perfectly well with no database behind it.
  Nothing in the monitored application's `bootstrap/app.php` has to change.
- **Without it**, a health endpoint that answers `2xx` is not taken as proof. SENTINEL
  makes one additional `HEAD` request to the application root, which maintenance mode
  does *not* exempt, and reads the `503` there. That costs one extra request per probe
  for unagented applications and none for the rest; set
  `SENTINEL_HEARTBEAT_VERIFY_MAINTENANCE=false` to switch it off.

Once the agent is installed, pointing the application's **health endpoint** at
`sentinel/health` gives the richer diagnosis and skips the extra request. The default
`/up` is used until then, because it works on any Laravel application with nothing
installed.

The endpoint is authenticated with its own `SENTINEL_HEALTH_TOKEN`, separate from the
ingestion credential because SENTINEL stores only a hash of that one and so could never
present it. Its payload carries nothing secret.

### Servers

Register a probe host and port per server (**Servers**, "Probe host"). A TCP connection
needs no root and is not blocked the way ICMP usually is; port 22 or 443 is normally
enough. A refused connection is the most useful answer the probe can get: it proves the
host is up and the service on it is not.

## Traffic analytics

Every request the agent reports carries the caller's address, the path, the status and
how long it took. SENTINEL rolls those into **one row per application per day** and the
dashboard reads only those rows.

That rollup is the whole design. Monitoring events are pruned after 30 days, so without
it every trend longer than a month would quietly disappear; and answering "requests per
day for the last 90 days" from raw events means aggregating millions of rows on every
page load. The daily rows are tiny and kept for a year.

The **Traffic** page covers every application, and each application has its own Traffic
tab. Both show the same four headline numbers, one chart and two short lists:

| | |
|---|---|
| **Requests** | volume, with the change against the preceding period of equal length |
| **Busiest day** | the peak day's unique visitors — never a sum, which would double count everyone who came back |
| **Error rate** | 4xx and 5xx together, because a flood of 404s is a real signal |
| **Response time** | the weighted average, with the 95th percentile beside it |

The chart is requests per day, stacked by outcome: success (2xx and 3xx — a redirect is
a request the application handled), client errors, server errors. Errors sit on top of
the stack, where a handful of 5xx among a hundred thousand 2xx is still visible against
open space. Every value in it is also available as a table, from the toggle above the
chart.

Below that: the busiest endpoints and the busiest callers, each with its error count.
Together those answer "what is being hit" and "who is hitting us".

### Client addresses

Addresses are stored in full by default, so an operator can identify the source of an
attack. Set `SENTINEL_TRAFFIC_MASK_IP=true` to drop the host part instead
(`203.0.113.7` becomes `203.0.113.0`), which still counts visitors and still shows a
subnet hammering an endpoint, but can never identify one caller. **Masking is applied at
ingestion**, before the row is written, so nothing unmasked ever reaches the database and
the setting cannot be undone retroactively. `SENTINEL_TRAFFIC_STORE_IP=false` refuses
them outright.

A monitored application has the final say regardless: `SENTINEL_CAPTURE_IP=false` in its
own `.env` means the address is never sent. Addresses honour the monitored
application's trusted-proxy configuration, so behind a load balancer the real caller is
recorded rather than the balancer.

### Sampling

An application with `SENTINEL_SAMPLE_RATE` below 1.0 sends only a fraction of its
*successful* requests (errors and slow requests are always sent in full). The agent
reports the rate alongside those events, the daily row records the lowest rate seen, and
the dashboard labels the figures as a sample rather than presenting a confidently
understated total. Leave the rate at 1.0 on any application whose traffic numbers need
to be exact.

### Aggregation

`sentinel:aggregate-traffic` queues one job per application onto the `aggregates` queue
and prunes rows past the retention window. It is scheduled on the
`performance_analysis` interval (5 minutes by default) and recomputes a trailing window
rather than only today, so a batch arriving late from an agent that was offline corrects
the day instead of being counted twice. Days are **rebuilt, never incremented**, so
re-running it is always safe.

To backfill after importing history, or to widen the window once:

```sh
php artisan sentinel:aggregate-traffic --days=90 --sync
```

## Configuration

`config/sentinel.php` holds every tunable: ingestion limits and rate limits, default
monitoring features and thresholds, the silence window after which an application counts
as disconnected, heartbeat probing (cadence, failure threshold, timeouts, jitter, queue),
traffic analytics (address handling, top-N size, recompute window), processing intervals,
and retention. All are `env()`-backed — no monitored application is
ever configured in code.

## Commands

```sh
php artisan sentinel:create-admin                # create a dashboard administrator
php artisan sentinel:refresh-connection-status   # flag applications that went quiet
php artisan sentinel:dispatch-heartbeats         # queue the probes that are due
php artisan sentinel:aggregate-traffic           # roll request events into per-day stats
php artisan test                                 # runs against sentinel_test
vendor/bin/pint                                  # code style
```

Both `sentinel:` commands are scheduled in `routes/console.php`; run
`php artisan schedule:work` in development or install the usual cron entry in production.

Heartbeat probes and traffic aggregation run on their own queues, so a worker has to
consume them:

```sh
php artisan queue:work --queue=heartbeat,aggregates,default --tries=1
```

`php artisan dev` starts the scheduler and a worker on every queue already, so nothing
extra is needed in development.

## Layout

| Path | Contents |
|---|---|
| `app/Enums` | Event vocabulary: severities, event types, statuses |
| `app/Services` | Registration, credentials, sanitization, ingestion, overview |
| `app/Services/Heartbeat` | Outbound probing: TCP, server reachability, diagnosis |
| `app/Services/Traffic` | Per-day rollups, reporting, client-address handling |
| `resources/js/components/charts` | Hand-drawn SVG charts, on the project's own tokens |
| `app/Jobs` | Queued probes, one per application and one per server per cycle |
| `app/Http/Controllers/Api` | The ingestion API used by monitored applications |
| `app/Http/Controllers/Admin` | The management API used by the dashboard |
| `app/Http/Middleware` | Credential authentication, body size limit, admin gate |
| `resources/js/views` | One component per route |
| `resources/js/components` | Shared pieces: status, stat cards, data states, pagination |
| `resources/js/components/ui` | shadcn-vue primitives (editable source, not a dependency) |
| `resources/js/stores` | Pinia stores, including the theme |
| `packages/sentinel-agent` | The Laravel monitoring agent, served to monitored apps |
| `app/Services/ComposerRepository.php` | Serves that agent as a Composer repository |

## Roadmap

Phases 1 and 3 are complete (central engine + Laravel agent), along with queued
heartbeat probing with outage diagnosis, and per-day traffic analytics. Next: queued event processing (Phase 2), the
full monitoring dashboard with charts (Phase 4), incident detection (Phase 5) and
Telegram notifications (Phase 6).

Heartbeat history is kept as transition events rather than a row per probe, so
availability is derived from the intervals between them. A per-probe time series would be
needed to chart probe latency over time; the probe result object is shaped so that writer
can be added without touching the probing logic.
