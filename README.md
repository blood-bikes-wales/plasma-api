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

### SPA / Google auth

Protected routes expect `Authorization: Bearer <Google ID token>` from the Plasma Controller SPA.
Set the same Web OAuth client ID in `GOOGLE_CLIENT_ID`, restrict Workspace with `GOOGLE_ALLOWED_DOMAIN`,
and allow the SPA origin(s) via `FRONTEND_URL` (CORS). See `.env.example`.

For staging/production, create the Web OAuth client in GCP Console and store the values with
Terraform (see [Infrastructure](#infrastructure) below). Locally, paste the staging client ID
into `.env`.

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

## Infrastructure

Terraform under [`infrastructure/`](infrastructure/) configures each GCP project. One root
module is shared across environments; state is separated by GCS prefix (no Terraform
workspaces).

| Environment | GCP project ID | State prefix |
|-------------|----------------|--------------|
| Production | `plasma-production` | `envs/production` |
| Staging | `plasma-staging-502110` | `envs/staging` |

Remote state lives in `gs://plasma-api-terraform-state` (bucket in `plasma-production`).

### Bootstrap state bucket (once)

```sh
./infrastructure/scripts/bootstrap-state-bucket.sh
```

Grant humans (and any future deploy service accounts) `roles/storage.objectAdmin` on that
bucket.

### Google OAuth client (per project, Console)

OAuth Web clients cannot be created via Terraform. In each GCP project:

1. APIs & Services → OAuth consent screen → **Internal** (Workspace-only).
2. Create a **Web application** OAuth client.
3. Set Authorized JavaScript origins to the SPA origin(s) for that environment
   (add `http://localhost:5173` on the staging client if you develop against it locally).
4. Copy the client ID into gitignored `infrastructure/terraform.tfvars` (or
   `TF_VAR_google_oauth_client_id`).

Terraform stores the client ID and allowed Workspace domain in Secret Manager as
`google-oauth-client-id` and `google-allowed-domain`. Those map to the app’s
`GOOGLE_CLIENT_ID` and `GOOGLE_ALLOWED_DOMAIN`.

### Apply

```sh
cd infrastructure
cp terraform.tfvars.example terraform.tfvars
# edit terraform.tfvars: project_id, google_oauth_client_id, google_allowed_domain

# staging
terraform init -reconfigure -backend-config=backends/staging.gcs.tfbackend
# project_id = plasma-staging-502110
terraform apply

# production
terraform init -reconfigure -backend-config=backends/production.gcs.tfbackend
# project_id = plasma-production
terraform apply
```

Sensitive values go in gitignored `terraform.tfvars` or `TF_VAR_*` — never commit them.

## Architecture conventions

- Controllers stay thin and contain no business logic; all rules live in the service layer.
- Every write endpoint is validated server-side and failures return structured, field-level errors.
- [Laravel Boost](https://laravel.com/docs/boost) is a dev-only dependency and must never ship to production (install with `composer install --no-dev` in production builds).
