resource "random_password" "db" {
  length  = 32
  special = false
}

resource "random_bytes" "app_key" {
  length = 32
}

resource "google_secret_manager_secret" "app_key" {
  project   = var.project_id
  secret_id = "${var.service_name}-app-key"

  replication {
    auto {}
  }

  depends_on = [google_project_service.required]
}

resource "google_secret_manager_secret_version" "app_key" {
  secret      = google_secret_manager_secret.app_key.id
  secret_data = "base64:${random_bytes.app_key.base64}"
}

resource "google_secret_manager_secret" "db_password" {
  project   = var.project_id
  secret_id = "${var.service_name}-db-password"

  replication {
    auto {}
  }

  depends_on = [google_project_service.required]
}

resource "google_secret_manager_secret_version" "db_password" {
  secret      = google_secret_manager_secret.db_password.id
  secret_data = random_password.db.result
}

resource "google_secret_manager_secret" "google_client_id" {
  project   = var.project_id
  secret_id = "${var.service_name}-google-client-id"

  replication {
    auto {}
  }

  depends_on = [google_project_service.required]
}

resource "google_secret_manager_secret_version" "google_client_id" {
  secret      = google_secret_manager_secret.google_client_id.id
  secret_data = var.google_client_id
}

resource "google_secret_manager_secret" "three_rings_api_key" {
  project   = var.project_id
  secret_id = "${var.service_name}-three-rings-api-key"

  replication {
    auto {}
  }

  depends_on = [google_project_service.required]
}

resource "google_secret_manager_secret_version" "three_rings_api_key" {
  secret      = google_secret_manager_secret.three_rings_api_key.id
  secret_data = var.three_rings_api_key
}

resource "google_secret_manager_secret_iam_member" "runtime" {
  for_each = {
    app_key             = google_secret_manager_secret.app_key.secret_id
    db_password         = google_secret_manager_secret.db_password.secret_id
    google_client_id    = google_secret_manager_secret.google_client_id.secret_id
    three_rings_api_key = google_secret_manager_secret.three_rings_api_key.secret_id
  }

  project   = var.project_id
  secret_id = each.value
  role      = "roles/secretmanager.secretAccessor"
  member    = "serviceAccount:${google_service_account.runtime.email}"
}
