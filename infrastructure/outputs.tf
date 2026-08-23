output "service_url" {
  description = "HTTPS URL of the Cloud Run API. Point the Controller SPA VITE_API_BASE_URL here (including /api if required by the SPA)."
  value       = google_cloud_run_v2_service.api.uri
}

output "artifact_registry_repository" {
  description = "Artifact Registry repository resource name."
  value       = google_artifact_registry_repository.api.id
}

output "container_image_base" {
  description = "Image URI prefix; CI appends :<git-sha>."
  value       = "${var.region}-docker.pkg.dev/${var.project_id}/${var.artifact_repository_id}/${var.service_name}"
}

output "workload_identity_provider" {
  description = "Full WIF provider resource name for google-github-actions/auth."
  value       = google_iam_workload_identity_pool_provider.github.name
}

output "deploy_service_account_email" {
  description = "Service account GitHub Actions impersonates."
  value       = google_service_account.github_deploy.email
}

output "sql_connection_name" {
  description = "Cloud SQL instance connection name (project:region:instance)."
  value       = google_sql_database_instance.main.connection_name
}

output "migrate_job_name" {
  description = "Cloud Run Job that runs php artisan migrate --force."
  value       = google_cloud_run_v2_job.migrate.name
}
