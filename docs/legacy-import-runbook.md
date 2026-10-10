# Legacy import preparation and format support

Implementation started 2026-10-08. No production import or migration has been run. The original source assessment remains in `LEGACY_MIGRATION_ASSESSMENT.md`.

## Preconditions

Use a consistent, read-only legacy database restore and matching archive snapshot. Keep database exports, source documents and account mappings out of Git. Reconcile available/deleted records, missing paths, orphan files, ownership and folder discrepancies first. The importer deliberately does not recover unreferenced filesystem files or guess their owners.

The target organization, users, memberships and folders must already exist with approved permissions. The command creates no accounts, folders, memberships or invitations. Approve the intended visibility boundary before provisioning them: legacy personal ownership does not automatically translate into privacy inside an organization.

Apply `2026_10_08_000001_create_legacy_document_imports_table.php` through the normal reviewed deployment process; test it first in a disposable database. Never run migration resets or permission resets against the real application to make tests pass.

Configure the existing `legacy_mysql` connection using `LEGACY_DB_*` through the environment's secret configuration. Use a read-only database account. The old DSN/username/password CLI flags now fail explicitly instead of being silently ignored. An unavailable database stops the command; there is no automatic filesystem fallback.

## Approved mapping

Provide a private UTF-8 JSON file with this structure. All IDs below are illustrative, not real assignments:

```json
{
  "source_system": "legacy_archive",
  "organization_slug": "example-court",
  "source_timezone": "UTC",
  "users": { "11": 101 },
  "folders": { "21": 201 }
}
```

Keys under users/folders are legacy primary keys; values are existing target IDs. Every mapped user must belong to the target organization and every mapped folder must belong to that organization. Source folder ownership, active flags and the stored folder name must agree. The organization slug in the file must match the command.

Keep `source_system` stable across reruns of the same source. Changing it to bypass a changed-record error defeats reconciliation. Source timestamps require an explicitly confirmed timezone; UTC is the legacy source-code default, not proof of the hosting database configuration.

## Run sequence

From the active application directory, in the approved runtime:

```sh
php artisan app:migrate-legacy-documents \
  --legacy-files=/private/legacy/documents \
  --mapping=/private/legacy/mapping.json \
  --target-org-slug=example-court \
  --dry-run
```

Preflight validates the whole batch without database/storage writes or jobs. Any unresolved record returns failure and prevents all import writes. Deleted source records are excluded unless previously imported, in which case a subsequent deletion requires explicit reconciliation. Inactive source users/folders, missing mappings, unsafe paths, invalid timestamps, wrong content types and title collisions fail closed.

After resolving exceptions and accepting the plan, repeat without `--dry-run` in staging. Each copied object is private and checksum-verified before persistence. Import records start unscanned in `uploading` state. Virus scanning starts after commit. A stable source-record ledger prevents duplicate reruns; identical binary contents in different records are preserved as separate records.

Import failures after preflight return nonzero and stop the batch. Earlier committed records remain; retrying skips verified unchanged records. Rolled-back inserts trigger cleanup of their copied object. A process crash can still leave an orphaned copied object; compare the ledger and storage inventory before cleanup. Never delete an object simply because queue dispatch failed after commit. Such documents remain pending and need their scan requeued after queue recovery.

Changes to previously imported source content/metadata, corrupt/missing stored objects or removed target records fail rather than overwrite. Existing target edits are not overwritten. Record deletions or physical omissions from subsequent database snapshots require a separate reconciliation; this is an importer, not a bidirectional synchronizer.

## Supported formats

| Family | Extensions | Extraction |
|---|---|---|
| Text | txt, csv, md | Read text directly; UTF-8 and BOM-marked UTF-16 supported |
| Word | doc, docx, dot, dotx | Isolated LibreOffice conversion to PDF, followed by text extraction/OCR |
| Excel | xls, xlsx, xlt, xltx | LibreOffice rendered PDF extraction |
| PowerPoint | ppt, pptx, pps, ppsx, pot, potx | LibreOffice rendered PDF extraction |
| OpenDocument / rich text | odt, ods, odp, rtf | LibreOffice rendered PDF extraction |
| PDF | pdf | All pages; native text per page with OCR fallback for pages without text |
| Images | jpg, jpeg, png, tif, tiff, bmp, gif, webp | Image normalization and English OCR of pages/frames |

Both extension and detected content type are checked. ZIP-based Office packages must contain the expected document component; arbitrary renamed ZIPs are rejected. Antivirus remains mandatory. The upload picker uses this format catalog, and newly created/replaced uploads derive size, MIME and checksum from stored content instead of user-entered metadata.

Limits: OCR is English; a PDF page containing both native text and scanned-only regions may require a dedicated full-page OCR workflow for the latter. Office extraction uses rendered/printable content, not every hidden worksheet cell, note or embedded object. Image OCR does not describe photographs. Password-protected, malformed and unsupported documents fail explicitly. More than 300 pages/frames requires a separate workflow rather than silently indexing a truncated result. Per-command timeouts and the existing job timeout also apply. Macro-enabled extensions and active formats such as SVG/HTML are not enabled by this change.

Search indexing follows successful extraction (or an explicit no-extraction setting). Search eligibility requires a published, clean-scanned, nondeleted document. Extraction errors record a failed state and do not claim OCR completion. Scanned originals remain available under existing authorized private-download rules; browser-native preview availability still depends on the format, and this change does not add a universal Office preview viewer.

## Validation and remaining gates

Focused checks to run in the PHP container:

```sh
php artisan test --filter=MigrateLegacyDocumentsCommandTest
php artisan test --filter=DocumentTextExtractorTest
php artisan test --filter=DocumentPipelineTest
php artisan test --filter=TenantIsolationTest
AKAIV_RUN_CONVERTERS=1 php artisan test --filter=DocumentConvertersIntegrationTest
```

The new importer tests cover dry-run side effects, metadata/timezones, tenant and ownership checks, reruns, changed sources, deleted records, missing files/database, traversal, unsupported files, collisions, checksum mismatch and storage cleanup after insert failure. Extraction tests include multi-page mixed PDFs, text without OCR, Office dispatch and fake-format rejection. External converter paths are mocked in these unit tests; real LibreOffice/Tesseract/Poppler/ImageMagick validation is a separate integration gate.

The PHP image already includes LibreOffice, Tesseract, Poppler and ImageMagick. MySQL and SQLite PDO support were added for legacy access and disposable testing. Rebuild the image before running tests. PostgreSQL concurrency/constraints, S3 behavior, real converters, antivirus and representative file-format fixtures still need integration verification before production ingestion.

This host's Docker Linux engine was unavailable during implementation. Test results are recorded separately in the task completion message; the existence of tests is not a claim that they passed.

## Cloud validation prepared 2026-10-10

The dedicated `.github/workflows/document-validation.yml` workflow runs on pushes to `codex/document-validation-*` branches. It uses disposable PostgreSQL 16 and in-memory SQLite, synthetic credentials, generated converter fixtures, and fake storage in the importer tests. It does not deploy the application, import the real archive, or connect to production storage. Focused tests, the full suite, real converters and changed-file formatting report failures without `continue-on-error`.

The real converter suite covers doc/docx/rtf/odt/pdf, xls/xlsx/ods, ppt/pptx/odp, png/jpg/tiff/bmp/gif/webp, and a two-page PDF combining native text with a scanned page. It runs actual LibreOffice, Poppler, Tesseract and ImageMagick on Ubuntu. This is not yet verification of every template extension, the Alpine production image, real S3, antivirus, or concurrent importer execution.

Local validation on 2026-10-10: all 18 changed/added PHP files passed PHP 8.3 syntax checks; `git diff --check` passed. Runtime test results are still pending. Windows Virtual Machine Platform and Windows Subsystem for Linux feature installation completed with a restart required; no restart was performed or scheduled.

The accessible cloud repository is `sleekfash/Akaiv` and is public. Only the selected application changes, tests, workflow and this runbook are intended for the testing branch. Do not upload private mappings, database exports, archive documents, environment files or the local assessment report. Publication requires the user's explicit approval following the automatic review block.
