# Legacy inventory — PROVISIONAL / BLOCKED

2026-10-14. No real source dataset inspected. Historical 1,832 files / 183.95 MB is REPORTED in CODEX_HANDOVER.md, not verified. Actual record/file counts, formats, sizes, duplicates, metadata missingness, suit/date anomalies, relationships, ownership, confidentiality and corruption are UNKNOWN. Zero tracked files under the expected legacy document path is not proof that the actual archive is empty.

Available evidence: legacy Laravel6 source migrations define documents(id,user_id,folder_id,name,folio_number,folder,description,file,status,created_by,timestamps) and folders(id,user_id,name,active,created_by,timestamps). The actual deployed schema may differ; obtain it read-only.

## Exact access required

Provide a read-only snapshot/mount of original documents (including inactive/trash/recycled areas, without making them importable by default), a consistent read-only MySQL export of schema plus documents/folders/users relationships, an approved source-system identifier, authorized organization/owner map, and a sealed/confidential classification supplied by the responsible judicial owner. Use approved secure transfer; do not post credentials or confidential content in the public repository/CI.

## Inventory protocol

Record snapshot timestamp and hash; enumerate all entries, including unsupported formats, links, unreadable/zero/oversized files. Use read-only streams for byte counts, SHA256, MIME, safe parse checks and malware results; do not execute or extract active content. Account separately for source rows, files, missing referenced files, unreferenced files, duplicates and excluded entries. Match each legacy row ID/path to exactly one reconciliation disposition. Never infer owners from directory names or infer delivery dates from upload timestamps.

Output a restricted inventory with per-entry legacy ID/path/hash/size/MIME, relationship IDs, status, ownership resolution, metadata exceptions and confidentiality disposition. Publish only aggregate/redacted findings in this public repository. Archive original source snapshots unchanged. No scanner/parser result is currently available for real files.
