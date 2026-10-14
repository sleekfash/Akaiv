# Migration exceptions — PROVISIONAL

No real exception counts available as of 2026-10-14. All categories below are planned, not observed real-data findings.

| Code | Condition | Disposition |
|---|---|---|
| OWNER_UNKNOWN | Missing/ambiguous user→organization map | Hold; judicial owner resolves, no account creation. |
| CASE_UNRESOLVED | Unknown case/title/suit/subject | Hold draft/catalogue candidate for manual review; no fabricated metadata. |
| SUIT_CONFLICT | Collision under current or proposed scope | Hold; compare original punctuation/court/year; approve policy before schema changes. |
| DATE_AMBIGUOUS | Missing/ambiguous delivery date | Hold applicable publication; preserve raw value, no upload-date substitution. |
| SEALED_UNKNOWN | Confidentiality unresolved | Hold outside normal archive visibility; authorized reviewer assigns restrictions before import. |
| DISCARDED | Deleted/inactive/trash/recycled | Inventory separately; do not import as active absent approval. |
| FILE_MISSING / ORPHAN | Broken row/path relationship | Hold; preserve both references; do not attach arbitrary bytes. |
| BYTE_DUPLICATE | Same SHA, multiple source references | Preserve all original IDs in reconciliation; owner decides target linkage. Current importer skips duplicate payloads. |
| MIME_INVALID / CORRUPT | Unsupported/mismatched/unreadable bytes | Quarantine review; no executable conversion/OCR. |
| MALWARE / SCANNER_ERROR | Infected or unavailable scanner | Remain ineligible; investigate/retry through controlled workflow. |
| SOURCE_CHANGED | Hash/size differs from discovery | Stop affected item; create fresh inventory from approved snapshot, never update original. |
| COMMIT_FAILURE | DB/storage/authorization failure | Record item ID and redacted exception class; resume same batch after correction. |

Restricted exception ledger: source system/ID, path, hash, category, observed raw value, target candidate, responsible reviewer, approval reference, resolution/date. Public reports contain aggregate counts only. No exception is silently dropped from reconciliation totals.
