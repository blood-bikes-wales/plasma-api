# Project overview

## What this is

Plasma API is the backend for Blood Bikes Wales’ Plasma tools. It lets signed-in charity accounts talk securely to a central service that will serve volunteer and rota information. Today it mainly proves who you are (via Google Workspace) and returns a simple “who am I” response; more features will build on that foundation.

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

## What success looks like

- Only Blood Bikes Wales Google accounts can call protected API features
- The Controller app can reliably identify the signed-in person (`/api/me`)
- The Controller app can log riders on and off shift (`/api/shifts/*`)
- Failed sign-in attempts are recorded for investigation
- Local development and automated tests keep the API safe to change without needing production access

## Risks and limitations

- Staging and production run on Google Cloud Run with a managed PostgreSQL database; a first-time laptop Terraform apply is required before GitHub can deploy
- Volunteer directory search is not yet a public API endpoint; logon looks volunteers up internally
- Access depends on correct Google OAuth setup (client ID and allowed domain); misconfiguration blocks everyone or the wrong people
- Three Rings is read-only from this system’s perspective — Plasma API does not update rotas there

## Where to learn more

- Technical overview: [../technical/overview.md](../technical/overview.md)
- Architecture: [../technical/architecture.md](../technical/architecture.md)
- Hosting: [../technical/gcp-hosting.md](../technical/gcp-hosting.md)
- Glossary: [glossary.md](glossary.md)
- Root README (install, Terraform): [../../README.md](../../README.md)
