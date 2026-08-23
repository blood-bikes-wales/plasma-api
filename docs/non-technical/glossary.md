# Glossary

Terms used when talking about this project. Keep definitions short and free of implementation detail unless needed for clarity.

| Term | Meaning |
|------|---------|
| Plasma API | This backend service: the Laravel JSON API for Blood Bikes Wales Plasma tools |
| Plasma Controller | The frontend app controllers use; it signs in with Google and calls Plasma API |
| Blood Bikes Wales | The charity this system serves |
| Google Workspace | The charity’s Google organisation (email domain such as `bloodbikes.wales`) used for sign-in |
| ID token | A short-lived Google login token the Controller app sends to the API to prove who the user is |
| OAuth client | Google Cloud setting that identifies the Controller app for sign-in; its ID must match what the API expects |
| Three Rings (3R) | External system of record for volunteers, roles, and shifts (`3r.org.uk`) |
| Volunteer | A person recorded in Three Rings who may take part in blood bike duties |
| Role | A Three Rings permission or duty type (e.g. rider, controller) |
| Shift / rota | Scheduled duty entries from Three Rings |
| Sail | Docker-based local development environment for the API (no need to install PHP on the laptop) |
| Auth audit log | Record of failed sign-in attempts kept for security review |
| Staging | Non-production Google Cloud project used to try changes safely (`plasma-staging-502110`) |
| Production | Live Google Cloud project (`plasma-production`) |
| Cloud Run | Google Cloud service that runs the API containers |
| Cloud SQL | Google Cloud managed PostgreSQL database used by staging and production |
| Secret Manager | Google Cloud store for sensitive config such as the app key and OAuth client ID |
| CORS / frontend URL | Setting that lists which website origins (the Controller app) may call the API from a browser |
| Fresh / stale cache | Two levels of remembered Three Rings data: prefer recent data; if Three Rings is unavailable, older cached data may still be used |
| BR-005 / BR-008 | Internal business rules referenced in code: Three Rings access is read-only (GET); degrade gracefully using cached data when needed |
