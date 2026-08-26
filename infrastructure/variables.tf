variable "project_id" {
  description = "GCP project for this workspace (staging or production)."
  type        = string
}

variable "region" {
  description = "GCP region for Artifact Registry, Cloud Run, and Cloud SQL."
  type        = string
  default     = "europe-west2"
}

variable "service_name" {
  description = "Cloud Run service name."
  type        = string
  default     = "plasma-api"
}

variable "artifact_repository_id" {
  description = "Artifact Registry Docker repository ID."
  type        = string
  default     = "plasma-api"
}

variable "container_image" {
  description = "Full container image URI including tag. CI overrides this with a git SHA tag. Do not use latest."
  type        = string
  default     = "us-docker.pkg.dev/cloudrun/container/hello"
}

variable "github_repository" {
  description = "GitHub repository allowed to impersonate the deploy service account (org/name)."
  type        = string
  default     = "blood-bikes-wales/plasma-api"
}

variable "deletion_protection" {
  description = "Prevent Terraform from destroying the Cloud Run service."
  type        = bool
  default     = true
}

variable "tfstate_bucket" {
  description = "GCS bucket holding Terraform state for all workspaces. Created once in staging; both deploy service accounts need object access."
  type        = string
  default     = "plasma-api-tfstate"
}

variable "min_instance_count" {
  description = "Cloud Run minimum instances. Production should be 1 to avoid PHP cold starts."
  type        = number
  default     = 0
}

variable "app_env" {
  description = "Laravel APP_ENV (staging or production)."
  type        = string
  default     = "production"
}

variable "sql_instance_name" {
  description = "Cloud SQL instance name (unique per project; cannot be reused for a week after delete)."
  type        = string
  default     = "plasma-api-pg"
}

variable "sql_tier" {
  description = "Cloud SQL machine tier."
  type        = string
  default     = "db-f1-micro"
}

variable "sql_pitr" {
  description = "Enable Cloud SQL point-in-time recovery (production)."
  type        = bool
  default     = false
}

variable "sql_deletion_protection" {
  description = "Prevent Terraform from destroying the Cloud SQL instance."
  type        = bool
  default     = true
}

variable "db_name" {
  description = "PostgreSQL database name."
  type        = string
  default     = "plasma"
}

variable "db_user" {
  description = "PostgreSQL application user."
  type        = string
  default     = "plasma"
}

variable "google_client_id" {
  description = "Google OAuth Web client ID (same client the Controller SPA uses)."
  type        = string
  sensitive   = true
}

variable "google_allowed_domain" {
  description = "Workspace domain allowed to sign in."
  type        = string
  default     = "bloodbikes.wales"
}

variable "three_rings_api_key" {
  description = "Three Rings API key."
  type        = string
  sensitive   = true
  default     = ""
}

variable "three_rings_base_url" {
  description = "Three Rings API base URL."
  type        = string
  default     = "https://www.3r.org.uk"
}

variable "controller_service_name" {
  description = "Cloud Run service name of Plasma Controller in the same project (used for FRONTEND_URL / CORS)."
  type        = string
  default     = "plasma-controller"
}

variable "frontend_url_override" {
  description = "If set, used as FRONTEND_URL instead of looking up the Controller Cloud Run service."
  type        = string
  default     = ""
}
