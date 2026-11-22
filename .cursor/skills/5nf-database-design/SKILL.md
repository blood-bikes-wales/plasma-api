---
name: 5nf-database-design
description: >-
  Design or refactor Plasma Postgres schema toward fifth normal form (5NF).
  Use when creating or changing migrations, Eloquent models, ERDs, table
  predicates, normalizing wide tables, splitting request vs leg facts, parties,
  subtypes, mileage storage, or when the user mentions 5NF, PJ/NF, normalize,
  or database design.
---

# 5NF database design

## When this skill applies

Use for any schema design or migration work in this repo. Default goal: **5NF**, not “3NF is enough.”

Canonical project doc: [`docs/technical/5nf-target-schema.md`](../../../docs/technical/5nf-target-schema.md).

Theory and practice links (Fagin PJ/NF, Silberschatz PJNF appendix, Date, Aalto, Postgres cookbook): [`references.md`](references.md).

## Checklist

1. **Predicate** — Write one sentence: “A row in `T` means …”. Reject tables that need “or” / “and also sometimes”.
2. **Keys** — Natural candidate keys and FKs; unique constraints that match the predicate.
3. **Join dependencies** — If a wide row reconstructs as a lossless join of smaller facts, split those facts into separate tables.
4. **Subtypes** — Optional/specialized attributes go in a 1:1 (or 1:0..1) detail table, not nullable columns on the parent.
5. **Associations** — M:N only via pivot tables; do not duplicate association data onto endpoints.
6. **Single store** — Each business fact has one authoritative table (e.g. mileage readings, not shift columns + readings).
7. **Exceptions** — Only with an explicit reason (immutable snapshot, audit event JSON, external geocode cache). Record them next to the change or in the 5NF doc.
8. **API** — Prefer composing camelCase resources from normalized tables; do not widen tables to mirror JSON.

## Workflow

1. Read current models/migrations for the area you are changing.
2. Sketch target predicates and keys (update or add a short note under `docs/technical/` if the design is non-trivial).
3. Migrate with backfill + down path; keep Eloquent relations aligned with the predicates.
4. Keep factories/tests on the new shape; do not reintroduce wide-row shortcuts in tests without comment.

## Anti-patterns in this codebase

| Avoid | Prefer |
|-------|--------|
| Wide “job” row with request + hop + milk + sender | `delivery_requests` + `delivery_legs` + `parties` + `milk_job_details` |
| Milk columns nullable on every request | Subtype row only when `job_type = milk` |
| Odometer on `operational_shifts` and `mileage_readings` | `mileage_readings.kind` = `start` \| `end` |
| Copying request fields onto every relay leg | Request owns shared facts; legs own hop facts |
