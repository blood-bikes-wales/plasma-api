# Technical overview

## Purpose

Plasma API is a Laravel JSON API for Blood Bikes Wales. It authenticates Plasma Controller SPA users with Google Workspace ID tokens and exposes a small HTTP surface under `/api`, including operational shift logon/logoff. A Three Rings client exists in the service layer for volunteer/planned-rota data (read-only) and is used to identify riders at logon.

## Stack

| Layer | Choice |
|-------|--------|
| Language / runtime | PHP `^8.3` (`composer.json`); local Sail and CI use PHP 8.5 |
| Framework / platform | Laravel 13 |
| Local runtime | Laravel Sail + Docker (`compose.yaml`, `install.sh`) |
| Persistence (dev) | PostgreSQL 17 (Sail); tests use in-memory SQLite (`phpunit.xml`) |
| Persistence (cloud) | Cloud SQL PostgreSQL 17 |
| Auth | Google ID token verification via `google/apiclient` |
| Cloud hosting | Cloud Run + Artifact Registry + Secret Manager (`infrastructure/`) |
| CI | GitHub Actions: Composer, Pint, PHPUnit, Terraform validate; PRs deploy staging |

## Entrypoints

| Entrypoint | Path / command |
|------------|----------------|
| HTTP API | `routes/api.php` (prefixed `/api` in `bootstrap/app.php`) |
| Health | `GET /up` (Laravel default) |
| Artisan / Sail | `./vendor/bin/sail artisan …` |
| Bootstrap install | `./install.sh` |
| Terraform | `infrastructure/` (Cloud Run, Cloud SQL, secrets, WIF) |

## How to run

### Prerequisites

- Docker (and WSL2 on Windows)
- No host PHP or Composer required for day-to-day Sail use

### Build

```sh
./install.sh
```

Installs Composer dependencies via a temporary `laravelsail/php84-composer` container, creates `.env` / `APP_KEY` if missing, starts Sail, and runs migrations. See [README.md](../README.md).

### Test

```sh
./vendor/bin/sail test
./vendor/bin/sail composer lint:check
```

### Local / deploy

Local: `./vendor/bin/sail up -d` → `http://localhost` (`/up`, `/api`).

Deployed hosting is Cloud Run in `europe-west2` on `plasma-staging-502110` / `plasma-production`, with Cloud SQL PostgreSQL and Secret Manager. See [GCP hosting](gcp-hosting.md) and [Cloud Run deploy](cloud-run.md). OAuth Web clients are still created in GCP Console (the API is deprecated).

## Key paths

| Path | Role |
|------|------|
| `routes/api.php` | HTTP API routes |
| `app/Http/Middleware/AuthenticateGoogleWorkspace.php` | Bearer Google ID token auth |
| `app/Services/Auth/GoogleApiClientIdTokenVerifier.php` | Token verification |
| `app/Services/ThreeRings/` | Three Rings HTTP client + DTOs (directory lookup at logon; rota not routed) |
| `app/Services/Shifts/` | Operational shift logon/logoff and mileage history |
| `app/Models/User.php` | Workspace-provisioned users |
| `app/Models/AuthAuditLog.php` | Failed-auth audit rows |
| `config/services.php` | Google + Three Rings config |
| `config/cors.php` | SPA origins from `FRONTEND_URL` |
| `.env.example` | Env var template |
| `Dockerfile` | FrankenPHP production image |
| `infrastructure/` | Terraform: Cloud Run, Cloud SQL, secrets, WIF |

## Pitfalls

- Protected routes need the **same** Web OAuth client ID the SPA uses (`GOOGLE_CLIENT_ID`); domain must match `GOOGLE_ALLOWED_DOMAIN` (e.g. `bloodbikes.wales`).
- `AUTH_DISABLED=true` only works in `local` / `testing`; inert when deployed.
- CORS: set `FRONTEND_URL` to the SPA origin(s), comma-separated.
- Do not invent HTTP routes for Three Rings — the client is internal until routes are added.
- `laravel/boost` is dev-only; production installs must use `--no-dev`.
- Terraform does **not** create OAuth clients (API deprecated); Console + Secret Manager only.
- Cloud Run logs must use stderr (`LOG_STACK=stderr`, `LOG_AUTH_CHANNEL=stderr`); file channels do not persist.
- Install image is `php84-composer` while Sail/CI run PHP 8.5; Composer may use `--ignore-platform-reqs` during bootstrap.
