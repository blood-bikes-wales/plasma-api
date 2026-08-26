# Plasma API

Laravel JSON API for Blood Bikes Wales.

## Requirements

- Docker (development runs via [Laravel Sail](https://laravel.com/docs/sail)) — no local PHP or Composer needed
- On Windows, run the commands below from a WSL2 shell

## Getting started

```sh
./install.sh
```

The script uses a temporary `laravelsail/php84-composer` container to install the Composer
dependencies, creates `.env` and an application key if missing, then boots the app via Sail
and runs the database migrations.

The API is then available at `http://localhost` (health check at `/up`, API routes under `/api`).

Local database is PostgreSQL 17. If you previously ran Sail with MySQL, remove the old volume once: `./vendor/bin/sail down -v`.

### SPA / Google auth

Protected routes expect `Authorization: Bearer <Google ID token>` from the Plasma Controller SPA.
Set the same Web OAuth client ID in `GOOGLE_CLIENT_ID`, restrict Workspace with `GOOGLE_ALLOWED_DOMAIN`,
and allow the SPA origin(s) via `FRONTEND_URL` (CORS). See `.env.example`.

Create the Web OAuth client in GCP Console (OAuth consent Internal, Web application) and paste
the client ID into `.env`. Locally you can use the staging client. Set `FRONTEND_URL` to the
SPA origin(s), comma-separated if needed.

Day-to-day commands run through Sail:

```sh
./vendor/bin/sail up -d     # start
./vendor/bin/sail down      # stop
./vendor/bin/sail artisan … # artisan inside the container
```

## Testing

Tests use PHPUnit against an in-memory SQLite database:

```sh
./vendor/bin/sail test
```

## Code style

PSR-12 code style is enforced with [Laravel Pint](https://laravel.com/docs/pint):

```sh
./vendor/bin/sail composer lint        # fix
./vendor/bin/sail composer lint:check  # check only (CI)
```

## Continuous integration

GitHub Actions ([.github/workflows/ci.yml](.github/workflows/ci.yml)) runs on every push to
`main` and on pull requests: Composer install, Pint, PHPUnit, and Terraform validate.
Pull requests that pass those checks deploy **staging**. Production deploys via
[workflow_dispatch](.github/workflows/deploy.yml) on the `production` GitHub Environment.

## Hosting

Staging and production run on Cloud Run (`europe-west2`) with Cloud SQL PostgreSQL 17.
See [docs/technical/gcp-hosting.md](docs/technical/gcp-hosting.md) and
[docs/technical/cloud-run.md](docs/technical/cloud-run.md) for bootstrap and deploy.

Local Sail uses PostgreSQL 17 (`compose.yaml`). Tests still use in-memory SQLite.

## Architecture conventions

- Controllers stay thin and contain no business logic; all rules live in the service layer.
- Every write endpoint is validated server-side and failures return structured, field-level errors.
- [Laravel Boost](https://laravel.com/docs/boost) is a dev-only dependency and must never ship to production (install with `composer install --no-dev` in production builds).
