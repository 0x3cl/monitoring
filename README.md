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

## Configuration

`config/sentinel.php` holds every tunable: ingestion limits and rate limits, default
monitoring features and thresholds, the silence window after which an application counts
as disconnected, processing intervals, and retention. All are `env()`-backed — no
monitored application is ever configured in code.

## Commands

```sh
php artisan sentinel:create-admin                # create a dashboard administrator
php artisan sentinel:refresh-connection-status   # flag applications that went quiet
php artisan test                                 # runs against sentinel_test
vendor/bin/pint                                  # code style
```

`sentinel:refresh-connection-status` is scheduled in `routes/console.php`; run
`php artisan schedule:work` in development or install the usual cron entry in production.

## Layout

| Path | Contents |
|---|---|
| `app/Enums` | Event vocabulary: severities, event types, statuses |
| `app/Services` | Registration, credentials, sanitization, ingestion, overview |
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

Phases 1 and 3 are complete (central engine + Laravel agent). Next: queued event
processing (Phase 2), the full monitoring dashboard with charts (Phase 4), incident
detection (Phase 5) and Telegram notifications (Phase 6).
