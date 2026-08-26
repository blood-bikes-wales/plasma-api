resource "google_artifact_registry_repository" "api" {
  project       = var.project_id
  location      = var.region
  repository_id = var.artifact_repository_id
  description   = "Plasma API container images"
  format        = "DOCKER"

  depends_on = [google_project_service.required]
}
