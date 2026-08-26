terraform {
  backend "gcs" {
    bucket = "plasma-api-tfstate"
    prefix = "plasma-api"
  }
}
