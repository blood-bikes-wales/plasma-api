# Normal-form references

Authoritative and practical sources for schema work under this skill. Prefer primary/academic definitions for 5NF; use the practical pieces when deciding exceptions.

## Fifth normal form (5NF / PJ/NF)

| Source | Why |
|--------|-----|
| [Fagin, “Normal Forms and Relational Database Operators” (SIGMOD 1979)](https://dl.acm.org/doi/10.1145/582095.582120) | Defines **projection-join normal form (PJ/NF)** — every join dependency implied by candidate keys. Ultimate NF when only projection and join are allowed. ([PDF](https://dl.acm.org/doi/pdf/10.1145/582095.582120)) |
| [Wikipedia: Fifth normal form](https://en.wikipedia.org/wiki/Fifth_normal_form) | Short definition: every non-trivial join dependency is implied by the candidate keys; overview and examples. |
| [Silberschatz, Korth & Sudarshan — *Database System Concepts*, Advanced Relational Database appendix (PJNF)](https://www.db-book.com/db6/appendices-dir/b.pdf) | Textbook treatment of join dependencies and **project-join normal form** (called 5NF in some literature). |

## Broader normal forms (1NF → BCNF / 4NF)

| Source | Why |
|--------|-----|
| [Aalto OpenCS — Normalization: From Anomalies to Normal Forms](https://opencs.aalto.fi/en/courses/databases/part-8/4-normalization) | Clear FD-driven walkthrough of anomalies, 1NF–3NF, and the “list dependencies then fix keys” workflow. |
| [C.J. Date — *Database Design and Relational Theory*](https://link.springer.com/book/10.1007/978-1-4842-5540-7) | Deep relational-theory treatment of normal forms (including less common ones) and design principles such as orthogonality. |

## Best practices and deliberate denormalization

| Source | Why |
|--------|-----|
| [Aalto OpenCS — Denormalization as a Deliberate Trade-Off](https://opencs.aalto.fi/en/courses/databases/part-8/5-denormalization-as-a-deliberate-trade-off) | Matches Plasma’s stance: redundancy only when named (historical snapshot, cached external data, measured read path) with a consistency plan. |
| [PostgreSQL SME Cookbook — Normalization vs Denormalization](https://postgresql.codeguides.io/normalization-denormalization/normalization-vs-denormalization/) | OLTP default = one home per fact; denormalize after a measured problem; prefer indexes / views / materialized views first. |
| [Appwrite docs — Data modeling and normalization](https://appwrite.io/docs/products/databases/postgresql/concepts/data-modeling) | Compact 1NF–BCNF table; good note that historical `unit_price`-style snapshots are distinct facts, not accidental copies. |

## Project canon

| Source | Why |
|--------|-----|
| [`docs/technical/5nf-target-schema.md`](../../../docs/technical/5nf-target-schema.md) | Plasma’s landed predicates, allowed exceptions (`content_label_snapshot`, place geocode cache, audit JSON), and API composition boundary. |
