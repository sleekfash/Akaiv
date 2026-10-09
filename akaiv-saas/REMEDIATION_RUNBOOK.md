# Judicial Phase 1 remediation

This change continues Laravel/Filament and preserves the legacy files. No live migration, deployment, archive import, credential rotation or history rewrite has been executed.

## Safe review sequence

1. Review the diff and CI evidence. Test only in disposable infrastructure with synthetic documents.
2. Back up PostgreSQL and immutable objects together before any shared-environment migration. Disable object garbage collection during snapshot/restore validation.
3. Run additive migrations in isolation first. Existing cross-tenant references and legacy status values must be audited before validating the new PostgreSQL `NOT VALID` constraints. Normalized suit numbers are populated on subsequent case saves; no unreviewed bulk rewrite occurs. Existing global raw suit-number uniqueness remains until a court-specific mapping is approved.
4. Provision an EXISTING user explicitly with `php artisan archive:provision-role email@example.test Administrator`. No first registration receives administrator status. Platform SuperAdmin is limited to platform account/organization administration; it has no automatic sealed-case access. Organization membership and its write/read role must also be supplied through authorized administration.
5. Test login/password reset, cases, proceedings, private file upload, clean/error antivirus outcomes, submit/review/publication, sealed descendants, trash restoration and audit viewing. Publication requires a separate reviewer. Replacing a file preserves immutable old versions and resets scan eligibility.
6. Complete an isolated import proof of concept and recover the data from an independent backup. Deploy or import real files only after review and explicit approval.

## Import

`app:migrate-legacy-documents --dry-run --legacy-files=/read-only/source --target-org-slug=court` is strictly read-only: it creates no accounts, documents, storage objects, queued jobs or audit records.

Remove `--dry-run` and supply `--actor=ID` to create a staged manifest. The actor must have `archive.import` and a writable organization membership. Files remain in the source directory; staged rows are not authoritative records. Source hierarchy is retained in `source_path`; no user identity is inferred from folder names.

Use a JSON manifest mapping source-relative filenames to manually approved metadata, for example:

```json
{
  "Judge Example/Judgments/example.txt": {
    "case_id": 123,
    "friendly_name": "Example judgment",
    "judicial_document_type": "judgment",
    "date_delivered": "2026-10-01"
  }
}
```

`archive:commit-import BATCH_ID ACTOR_ID /read-only/source manifest.json` commits per-file transactions into drafts, verifies source hashes/sizes, detects duplicates, reports failures and supports retries. Cases and user ownership must be approved beforehand. Hash matching is exact-content matching, not judicial identity inference. No import automatically publishes.

## Audit and export

`archive:audit-verify` verifies the full chain against its locked head and outputs a checkpoint. Retain checkpoints independently off-site; `--checkpoint=/independent/checkpoint.json` additionally detects chain rewriting/truncation relative to that anchor. Historical Spatie activity entries remain unchained and must not be relabeled as cryptographically verified.

The PostgreSQL audit table rejects ordinary UPDATE, DELETE and TRUNCATE. A database owner capable of dropping controls can still rewrite it; use separate migration/runtime database roles and independently protected checkpoints. Runtime credentials must not own the schema or have DDL privileges.

`archive:export ORGANIZATION_ID ACTOR_ID /new/export.zip` exports authorized published current files, case/proceeding metadata and a checkpoint. `archive:verify-export /new/export.zip` checks hashes, sizes and relationships. This is a portable published-record export, not a full disaster-recovery backup: drafts, inaccessible sealed records, historical file bytes, grants, user credentials and the complete audit history require the coordinated database/object backup.

The backup/isolated-restore scripts are prepared for operator review. They require Docker, pg_dump/pg_restore, rclone configuration, encrypted off-site retention and an empty local test database. Set recovery objectives and retention with the archive owner; these cannot be inferred from code. No successful real PostgreSQL/object restore has yet been demonstrated by this change.

## Production profile

Use `docker compose -f docker-compose.production.yml` only after review. It excludes the development web server and Meilisearch, publishes only web ports, requires explicit credentials and keeps database/Redis/ClamAV internal. Build frontend assets, install Composer dependencies and create writable storage/framework/cache, storage/framework/sessions, storage/framework/views and storage/logs directories before mounting the app read-only. Configure ownership for the PHP container user. Configure the Caddy domain/TLS, object-store credentials, mail and queue monitoring separately for the actual deployment environment.

OCR, AI analysis, public sharing and document-content indexing remain disabled in Phase 1. Metadata search stays inside authorized database queries. Transactional `file.uploaded` and `document.status_changed` outbox entries are future extension hooks; no AI consumer is enabled.

## Remaining release gates

- Validate PostgreSQL migrations, constraints and concurrency with real PostgreSQL, including audit append concurrency and malformed relationship inserts.
- Run actual ClamAV clean/infected/error integration tests; current automated service tests use synthetic bytes and a scanner test double.
- Run full browser workflows and object-store integration with isolated accounts.
- Reconcile legacy metadata/status/tenant references before migration validation or full import.
- Approve the judicial sealed-access matrix and suit-number uniqueness/normalization policy. Current sealed behavior grants no implicit administrator access and preserves punctuation while normalizing case/whitespace.
- Demonstrate encrypted off-site backup, isolated PostgreSQL/object restoration, hash reconciliation and audit-anchor verification.
- Review and rotate any still-valid credentials historically exposed by the public repository. Removing tracked environment files does not erase old Git history; application-key changes require an encrypted-data recovery plan.

Do not declare the application production-ready while any release gate above remains unverified.

## Implementation validation (2026-10-09)

Prepared against main commit `eee74aa0cd24fe79174ae1b89f5b595f946011ab` on `remediation/judicial-phase-one`.
Local checks: 31 PHP tests / 89 assertions (SQLite, synthetic files and fake storage/queues), 3 frontend tests, production frontend build, PHP style checks, worker TypeScript checks and 5 worker containment tests. Worker tests disable storage snapshot isolation because the reserved disabled worker is stateless; the harness runtime falls back to its supported compatibility date. This does not validate a deployed worker.

These checks do not establish production readiness. Before release, prove PostgreSQL migrations/constraints and concurrent writes, real ClamAV and private object-store behavior, browser workflows, existing-record normalization and scan-state mapping, and an isolated offsite backup restore. Configure the production domain/TLS and independent audit checkpoints. Previously committed credentials still require owner-controlled rotation; removing tracked environment files does not remove repository history. No deployment, live migration, real-file import, credential rotation, or history rewrite was performed.
