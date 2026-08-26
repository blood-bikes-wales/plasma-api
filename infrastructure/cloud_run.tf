resource "google_service_account" "runtime" {
  project      = var.project_id
  account_id   = "${var.service_name}-run"
  display_name = "Plasma API Cloud Run runtime"
}

data "google_cloud_run_v2_service" "controller" {
  count    = var.frontend_url_override == "" ? 1 : 0
  name     = var.controller_service_name
  location = var.region
  project  = var.project_id
}

locals {
  frontend_url = var.frontend_url_override != "" ? var.frontend_url_override : data.google_cloud_run_v2_service.controller[0].uri

  laravel_env = {
    APP_ENV               = var.app_env
    APP_DEBUG             = "false"
    APP_NAME              = "PlasmaAPI"
    LOG_CHANNEL           = "stack"
    LOG_STACK             = "stderr"
    LOG_AUTH_CHANNEL      = "stderr"
    LOG_LEVEL             = "info"
    DB_CONNECTION         = "pgsql"
    DB_HOST               = "/cloudsql/${google_sql_database_instance.main.connection_name}"
    DB_PORT               = "5432"
    DB_DATABASE           = var.db_name
    DB_USERNAME           = var.db_user
    DB_SSLMODE            = "disable"
    SESSION_DRIVER        = "array"
    QUEUE_CONNECTION      = "database"
    CACHE_STORE           = "database"
    FRONTEND_URL          = local.frontend_url
    GOOGLE_ALLOWED_DOMAIN = var.google_allowed_domain
    THREE_RINGS_BASE_URL  = var.three_rings_base_url
    AUTH_DISABLED         = "false"
  }

  laravel_secrets = {
    APP_KEY             = google_secret_manager_secret.app_key.secret_id
    DB_PASSWORD         = google_secret_manager_secret.db_password.secret_id
    GOOGLE_CLIENT_ID    = google_secret_manager_secret.google_client_id.secret_id
    THREE_RINGS_API_KEY = google_secret_manager_secret.three_rings_api_key.secret_id
  }
}

resource "google_cloud_run_v2_service" "api" {
  project             = var.project_id
  name                = var.service_name
  location            = var.region
  ingress             = "INGRESS_TRAFFIC_ALL"
  deletion_protection = var.deletion_protection

  template {
    service_account = google_service_account.runtime.email
    timeout         = "60s"

    scaling {
      min_instance_count = var.min_instance_count
      max_instance_count = 5
    }

    volumes {
      name = "cloudsql"
      cloud_sql_instance {
        instances = [google_sql_database_instance.main.connection_name]
      }
    }

    containers {
      image = var.container_image
      name  = "app"

      ports {
        container_port = 8080
      }

      resources {
        limits = {
          cpu    = "1"
          memory = "512Mi"
        }
        cpu_idle          = true
        startup_cpu_boost = true
      }

      volume_mounts {
        name       = "cloudsql"
        mount_path = "/cloudsql"
      }

      dynamic "env" {
        for_each = local.laravel_env
        content {
          name  = env.key
          value = env.value
        }
      }

      dynamic "env" {
        for_each = local.laravel_secrets
        content {
          name = env.key
          value_source {
            secret_key_ref {
              secret  = env.value
              version = "latest"
            }
          }
        }
      }
    }
  }

  depends_on = [
    google_project_service.required,
    google_secret_manager_secret_iam_member.runtime,
    google_project_iam_member.runtime_sql,
    google_sql_database.plasma,
    google_sql_user.plasma,
  ]
}

resource "google_cloud_run_v2_job" "migrate" {
  project             = var.project_id
  name                = "${var.service_name}-migrate"
  location            = var.region
  deletion_protection = false

  template {
    template {
      service_account = google_service_account.runtime.email
      timeout         = "600s"
      max_retries     = 1

      volumes {
        name = "cloudsql"
        cloud_sql_instance {
          instances = [google_sql_database_instance.main.connection_name]
        }
      }

      containers {
        image   = var.container_image
        name    = "migrate"
        command = ["php"]
        args    = ["artisan", "migrate", "--force"]

        resources {
          limits = {
            cpu    = "1"
            memory = "512Mi"
          }
        }

        volume_mounts {
          name       = "cloudsql"
          mount_path = "/cloudsql"
        }

        dynamic "env" {
          for_each = local.laravel_env
          content {
            name  = env.key
            value = env.value
          }
        }

        dynamic "env" {
          for_each = local.laravel_secrets
          content {
            name = env.key
            value_source {
              secret_key_ref {
                secret  = env.value
                version = "latest"
              }
            }
          }
        }
      }
    }
  }

  depends_on = [
    google_project_service.required,
    google_secret_manager_secret_iam_member.runtime,
    google_project_iam_member.runtime_sql,
    google_sql_database.plasma,
    google_sql_user.plasma,
  ]
}
