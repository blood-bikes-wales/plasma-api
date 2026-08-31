resource "google_sql_database_instance" "main" {
  project             = var.project_id
  name                = var.sql_instance_name
  database_version    = "POSTGRES_17"
  region              = var.region
  deletion_protection = var.sql_deletion_protection

  settings {
    tier              = var.sql_tier
    edition           = "ENTERPRISE"
    availability_type = "ZONAL"
    disk_type         = "PD_SSD"
    disk_size         = 10
    disk_autoresize   = true

    backup_configuration {
      enabled                        = true
      point_in_time_recovery_enabled = var.sql_pitr
      start_time                     = "03:00"
      backup_retention_settings {
        retained_backups = 7
      }
    }

    ip_configuration {
      ipv4_enabled = true
      # Cloud Run connects via the built-in Cloud SQL connector (encrypted Unix
      # socket). ENCRYPTED_ONLY blocks unencrypted TCP to the public IP.
      ssl_mode = "ENCRYPTED_ONLY"
    }

    maintenance_window {
      day          = 7
      hour         = 3
      update_track = "stable"
    }
  }

  depends_on = [google_project_service.required]
}

resource "google_sql_database" "plasma" {
  project  = var.project_id
  name     = var.db_name
  instance = google_sql_database_instance.main.name
}

resource "google_sql_user" "plasma" {
  project  = var.project_id
  name     = var.db_user
  instance = google_sql_database_instance.main.name
  password = random_password.db.result

  deletion_policy = "ABANDON"
}
