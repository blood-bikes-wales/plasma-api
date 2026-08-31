# Project overview

## What this is

Plasma API is the backend for Blood Bikes Wales’ Plasma tools. Signed-in charity accounts call it from the Plasma Controller app. It proves who you are (via Google Workspace), lets controllers log riders on and off duty, and records delivery jobs.

## Who it’s for

- **Controllers and riders** (via the Plasma Controller app) — people who need authenticated access to Plasma features
- **Charity IT / developers** — who run and extend the API
- **Trustees and ops leads** — who care that access is limited to Blood Bikes Wales Google accounts

End users do not use this API directly in a browser; they use the Plasma Controller app, which calls the API on their behalf.

## How it works (simple)

1. Someone from the charity signs in with their Blood Bikes Wales Google account in the Plasma Controller app.
2. The app sends a short-lived Google login token to Plasma API.
3. The API checks the token is genuine and that the person belongs to the allowed Workspace domain.
4. If checks pass, the API recognises them as a user; if not, access is refused and the attempt can be audited.
5. Controllers can log riders on and off shift in Plasma (who is on duty, which bike, mileage). Volunteer names still come from Three Rings; Plasma never updates the Three Rings rota.
6. Controllers can create a delivery job with validated collection and delivery locations. New jobs start in the New state. Controllers can allocate jobs to active riders, record collection and delivery, cancel jobs, or convert a New job into a relay with handover points.
7. Signed-in riders, controllers, and other Plasma roles can list active and completed jobs.
8. All Plasma roles can search the volunteer directory (from Three Rings) and the bike log (Plasma-owned bikes and mileage history).

## What success looks like

- Only Blood Bikes Wales Google accounts can call protected API features
- The Controller app can reliably identify the signed-in person (`/api/me`)
- The Controller app can log riders on and off shift (`/api/shifts/*`)
- The Controller app can create a delivery job (`POST /api/jobs`) in the New state
- Controllers can progress jobs through allocate, collect, deliver, and cancel; relay jobs split into legs at handover points
- The Controller app can list active and completed jobs (`GET /api/jobs/active`, `GET /api/jobs/completed`)
- Plasma roles can search volunteers and bikes in the directory (`GET /api/directory/*`)
- Failed sign-in attempts are recorded for investigation
- Local development and automated tests keep the API safe to change without needing production access

## Risks and limitations

- Staging and production run on Google Cloud Run with a managed PostgreSQL database; a first-time laptop Terraform apply is required before GitHub can deploy
- The volunteer directory depends on Three Rings being reachable; if it is down, directory search returns an error (logon may still use cached data)
- Access depends on correct Google OAuth setup (client ID and allowed domain); misconfiguration blocks everyone or the wrong people
- Three Rings is read-only from this system’s perspective — Plasma API does not update rotas there

## Where to learn more

- Technical overview: [../technical/overview.md](../technical/overview.md)
- Architecture: [../technical/architecture.md](../technical/architecture.md)
- Hosting: [../technical/gcp-hosting.md](../technical/gcp-hosting.md)
- Glossary: [glossary.md](glossary.md)
- Root README (install, Terraform): [../../README.md](../../README.md)
