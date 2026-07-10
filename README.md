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
`main` and on pull requests: a `build` job installs and caches the Composer dependencies,
then `pint` (code style) and `phpunit` (tests) reuse that build and block the merge on failure.

## Architecture conventions

- Controllers stay thin and contain no business logic; all rules live in the service layer.
- Every write endpoint is validated server-side and failures return structured, field-level errors.
- [Laravel Boost](https://laravel.com/docs/boost) is a dev-only dependency and must never ship to production (install with `composer install --no-dev` in production builds).
