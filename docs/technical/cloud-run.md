# Cloud Run deploy

## When to use

Bootstrap GCP, apply Terraform for staging or production, or diagnose a failed GitHub Actions deploy of Plasma API to Cloud Run.

## Preconditions

- `gcloud` authenticated as someone who can create buckets and enable APIs on `plasma-staging-502110` and `plasma-production`
- Terraform >= 1.6
- GitHub repo `blood-bikes-wales/plasma-api`
- Google OAuth Web client ID (same one the Controller SPA uses); created in Cloud Console, not Terraform
- Always pair a workspace with its tfvars file: `staging` + `environments/staging.tfvars`, `production` + `environments/production.tfvars`

| Workspace | GCP project | tfvars |
|-----------|-------------|--------|
| `staging` | `plasma-staging-502110` | `infrastructure/environments/staging.tfvars` |
| `production` | `plasma-production` | `infrastructure/environments/production.tfvars` |

Copy `infrastructure/terraform.tfvars.example` to `infrastructure/terraform.tfvars` (gitignored) and set `google_client_id` (and `three_rings_api_key` when you have one).

If Plasma Controller is not yet on Cloud Run in the same project, set `frontend_url_override` to the SPA origin so CORS still works.

## Steps

### 1. Create the Terraform state bucket (once)

State for both workspaces lives in one bucket (GCS prefixes isolate `staging` / `production`). Create it in staging:

```bash
gcloud storage buckets create gs://plasma-api-tfstate \
  --project=plasma-staging-502110 \
  --location=europe-west2 \
  --uniform-bucket-level-access

gcloud storage buckets update gs://plasma-api-tfstate --versioning
```

If the name is taken, change `bucket` in `infrastructure/backend.tf` and `tfstate_bucket` in tfvars to a unique name and use that everywhere below.

### 2. First apply from a laptop (each workspace)

Creates APIs, Artifact Registry, Cloud SQL, Secret Manager secrets, Cloud Run (placeholder hello image), the migrate job, public invoker IAM, GitHub Workload Identity Federation, and `roles/storage.admin` on `gs://plasma-api-tfstate` for the deploy service account. That grant is required for CI `terraform init` (state objects) and for refreshing this IAM binding (`storage.buckets.getIamPolicy`). Apply it from a laptop; the deploy SA cannot grant itself bucket IAM.

Cloud SQL takes several minutes to provision.

```bash
cd infrastructure
gcloud auth application-default login
terraform init

terraform workspace new staging   # skip if it already exists
terraform workspace select staging
terraform apply -var-file=environments/staging.tfvars
```

Repeat with `production` / `environments/production.tfvars` (`terraform workspace new production`).

Copy outputs into GitHub **Environments** named `staging` and `production`:

| GitHub Environment variable / secret | Terraform output / value |
|--------------------------------------|--------------------------|
| `WIF_PROVIDER` (variable) | `workload_identity_provider` |
| `WIF_SERVICE_ACCOUNT` (variable) | `deploy_service_account_email` |
| `GCP_PROJECT_ID` (variable) | `plasma-staging-502110` or `plasma-production` |
| `GOOGLE_CLIENT_ID` (secret) | OAuth Web client ID (same as Terraform `google_client_id`) |
| `THREE_RINGS_API_KEY` (secret) | Three Rings API key (optional until that client is used) |

On the production GitHub Environment, require a reviewer so `workflow_dispatch` cannot ship live unattended.

### 3. Ongoing deploys

- **Staging:** a PR targeting `main` deploys after Pint, PHPUnit, and Terraform validate succeed ([`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) calls [`.github/workflows/deploy.yml`](../../.github/workflows/deploy.yml)).
- **Production:** Actions → Deploy → Run workflow → `production` (uses the `production` environment gate).

Each deploy builds the FrankenPHP image, `terraform apply` with that image, then `gcloud run jobs execute plasma-api-migrate --wait`.

Bootstrap must have created the Artifact Registry repository and Cloud SQL instance before the first Actions push.

### 4. After the first real image

1. Open `service_url` (Terraform output / Cloud Run console). Confirm `GET /up` returns 200.
2. Point Controller `VITE_API_BASE_URL` at this origin (plus `/api` if the SPA expects that path).
3. Confirm Google Sign-In on the hosted SPA can call `/api/me`.

No custom domain in this setup; the API uses the `*.run.app` URL until a later change.

## Verification

```bash
cd infrastructure
terraform workspace select staging
terraform output service_url
curl -sI "$(terraform output -raw service_url)/up"
```

Expect HTTP 200. `terraform fmt -check` and `terraform validate` run on PRs ([`.github/workflows/terraform.yml`](../../.github/workflows/terraform.yml)).

## Rollback / recovery

Cloud Run keeps previous revisions. In Console (or `gcloud run services update-traffic`), route 100% traffic to the last good revision.

To pin Terraform at an older image:

```bash
terraform workspace select staging
terraform apply -var-file=environments/staging.tfvars \
  -var="container_image=europe-west2-docker.pkg.dev/plasma-staging-502110/plasma-api/plasma-api:<git-sha>"
```

Do not `terraform destroy` production while `deletion_protection` / `sql_deletion_protection` are true (they are, in `production.tfvars`).

If a deploy ships code before migrations finish, re-run:

```bash
gcloud run jobs execute plasma-api-migrate --region=europe-west2 --wait
```

## Related

- [GCP hosting](gcp-hosting.md) — options comparison; this repo implements Cloud Run + Cloud SQL
- [Technical overview](overview.md)
- `infrastructure/` — Terraform root
- [PLM-19](https://bloodbikeswales.atlassian.net/browse/PLM-19) — API serverless on `europe-west2`
