# Technical overview

## Purpose

Plasma API is a Laravel JSON API for Blood Bikes Wales. It authenticates Plasma Controller SPA users with Google Workspace ID tokens and exposes a small HTTP surface under `/api`. A Three Rings client exists in the service layer for volunteer/rota data but is not exposed as HTTP routes yet.

## Stack

| Layer | Choice |
|-------|--------|
| Language / runtime | PHP `^8.3` (`composer.json`); local Sail and CI use PHP 8.5 |
| Framework / platform | Laravel 13 |
| Local runtime | Laravel Sail + Docker (`compose.yaml`, `install.sh`) |
| Persistence (dev) | MySQL 8.4 (Sail); tests use in-memory SQLite (`phpunit.xml`) |
| Auth | Google ID token verification via `google/apiclient` |
| Cloud config | Terraform → GCP Secret Manager (`infrastructure/`) |
| CI | GitHub Actions: Composer, Pint, PHPUnit (`.github/workflows/ci.yml`) |

## Entrypoints

| Entrypoint | Path / command |
|------------|----------------|
| HTTP API | `routes/api.php` (prefixed `/api` in `bootstrap/app.php`) |
| Health | `GET /up` (Laravel default) |
| Artisan / Sail | `./vendor/bin/sail artisan …` |
| Bootstrap install | `./install.sh` |
| Terraform | `infrastructure/` (OAuth-related secrets only today) |

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

Deployed app hosting (Cloud Run, managed DB, secret injection into the runtime) is **not** in Terraform yet. Terraform currently enables APIs and stores `google-oauth-client-id` / `google-allowed-domain` in Secret Manager for staging (`plasma-staging-502110`) and production (`plasma-production`). OAuth Web clients are created in GCP Console; see [README Infrastructure](../README.md#infrastructure).

## Key paths

| Path | Role |
|------|------|
| `routes/api.php` | HTTP API routes |
| `app/Http/Middleware/AuthenticateGoogleWorkspace.php` | Bearer Google ID token auth |
| `app/Services/Auth/GoogleApiClientIdTokenVerifier.php` | Token verification |
| `app/Services/ThreeRings/` | Three Rings HTTP client + DTOs (not routed yet) |
| `app/Models/User.php` | Workspace-provisioned users |
| `app/Models/AuthAuditLog.php` | Failed-auth audit rows |
| `config/services.php` | Google + Three Rings config |
| `config/cors.php` | SPA origins from `FRONTEND_URL` |
| `.env.example` | Env var template |
| `infrastructure/` | Terraform root module + GCS backends |

## Pitfalls

- Protected routes need the **same** Web OAuth client ID the SPA uses (`GOOGLE_CLIENT_ID`); domain must match `GOOGLE_ALLOWED_DOMAIN` (e.g. `bloodbikes.wales`).
- `AUTH_DISABLED=true` only works in `local` / `testing`; inert when deployed.
- CORS: set `FRONTEND_URL` to the SPA origin(s), comma-separated.
- Do not invent HTTP routes for Three Rings — the client is internal until routes are added.
- `laravel/boost` is dev-only; production installs must use `--no-dev`.
- Terraform does **not** create OAuth clients (API deprecated); Console + Secret Manager only.
- Install image is `php84-composer` while Sail/CI run PHP 8.5; Composer may use `--ignore-platform-reqs` during bootstrap.
