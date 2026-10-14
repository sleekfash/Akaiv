# Migration readiness — BLOCKED / PROVISIONAL

2026-10-14. File-based synthetic draft import is tested, but actual judicial archive migration is NOT approved or ready.

## Verified technical baseline

Read-only discovery creates no accounts, domain records, objects or jobs. Explicit authorized staging creates import batches/items. Commit validates required manifest fields, reads source inside root, rechecks hash/size, locks item and organization, detects same-org byte duplicates, writes immutable private files, records provenance/audit and returns committed item on retry. Each item commits separately; a batch may need attention. Malware eligibility and publication are independent.

## Required before live execution

1. Obtain immutable read-only source snapshot and actual schema; produce complete inventory and restricted exception ledger.
2. Approve organization/owner/seal mapping, case identity and suit uniqueness, discarded and duplicate-reference rules; retain original IDs.
3. Extend/verify relational mapping and provenance, full-manifest dry-run, transcript support if needed, restart/checksum/error behavior and storage/worker fault tests.
4. Validate additive migrations against a sanitized copy of existing data, including NOT VALID tenant constraints and old global suit uniqueness; do not drop constraints speculatively.
5. Resolve dependency security gates, prove actual-provider private storage and encrypted off-site backup/restore.
6. Obtain explicit import approval for a bounded source sample, designated actors/tenant, monitoring, and compensating procedure. Then reconcile and separately approve expansion/publication.

## Synthetic rehearsal / restart

Use `app:migrate-legacy-documents --dry-run --legacy-files=/read-only/synthetic --target-org-slug=synthetic` first; then authorized isolated staging with `--actor=ID`; `archive:commit-import BATCH ACTOR ROOT MANIFEST`. Never use production credentials. Retry the same batch/item after correction. Repeating discovery creates another batch; cross-batch checksum dedupe is not preservation of every source reference. Dry-run currently validates discovery, not entire manifest semantics.

## Compensation and post-migration reconciliation

Never mutate source. Item DB rollback and best-effort object cleanup do not guarantee cross-system atomicity after process death. Identify orphan immutable keys against DB/version references; no automatic destructive garbage collection. Compensate incorrect catalogue entries through authorized retained/archived correction and audit, not deletion of audit or source. Do not use forward-only migration down() as rollback. A full coordinated backup may be restored only into an isolated environment for review.

For every source row/file, reconcile disposition and original ID to target ID/hash/size/parent/owner/seal/metadata; independently count source totals and exceptions. Verify malware status, actor separation, audit chain/checkpoint, permissions and restore including history. No real counts, throughput estimates or actual migration results exist yet.
