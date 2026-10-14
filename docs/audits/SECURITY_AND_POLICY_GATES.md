# Security and judicial-policy gates

2026-10-14. No production modifications, real imports, credential rotations or policy changes authorized/executed. Evidence baseline in PHASE1_CURRENT_STATE.md.

## Technical findings

| Severity | Finding / evidence | Required action |
|---|---|---|
| Critical operational gate | Public repository historical tracked environment files; removal does not erase history. Validity of exposed credentials UNKNOWN; no credentials inspected/printed in this audit. | Owner assess/revoke still-valid credentials with access-preserving key plan; separate approval required. Treat potential compromise seriously; do not rotate APP_KEY blindly. |
| High | Current `composer audit --locked --format=json` and npm audit report advisories. Functional CI has no current vulnerability gate. | Record scan evidence; assess runtime vs development reachability, patch supported dependencies in a separate focused candidate and rerun all tests. No clean-security claim. |
| Critical scanner severity / reachability unknown | Disabled worker dependency scan reports3 critical affected nodes (including development Vitest); legacy lockfile34 advisory records. These are not proven active-app exploits. | Keep worker/OCR/AI inactive and tool servers private; remediate toolchain and have owner assess actual legacy deployment separately. |
| High | Locked Laravel11.56.1 is beyond official security-support date 2026-03-12: https://laravel.com/docs/11.x/releases | Plan incremental supported-framework upgrade and compatibility validation; do not assume older dependency report's LOW/LTS claims are valid. |
| High | Tenant/sealed policies exist and fixture tests pass; actual judicial role matrix not approved. | Approve least privilege before provisioning real users. |
| High | production compose uses same DB user for initialization/app; actual runtime non-DDL account separation unverified. | Dedicated runtime/migration credentials and privilege probe in isolated approved provider. Audit triggers can be dropped by DB owner. |
| High | Backup script creates unencrypted local dump/object copy; pilot only proves isolated synthetic restoration. | Encrypt off-site, protect keys/checkpoints independently, retention and alerting; prove actual restore. |
| High | Caddyfile uses localhost/internal TLS and includes `/storage/*` static handling. No actual storage symlink/provider assessment. | Real domain/TLS approval; prohibit sensitive public storage; verify no archive bytes served via static paths. |
| Medium | Case parties field likely discarded by mass assignment; search/filter acceptance missing. | Reproduce then fix/test in integration branch. |
| Medium | Import discovery omits duplicates/unsupported entries and does not enforce legacy deleted/sealed statuses. | Complete inventory/disposition before any live import; retain all legacy identifiers. |
| Medium | Session default 1440 minutes; MFA/Authenticator not verified; brute-force, logout/session-revocation penetration coverage incomplete. | Owner approve session/MFA requirements; add tests and review configured auth protections. |
| Medium | Malware clean/EICAR/outage proven only synthetic; current definitions, resource limits and production monitoring unknown. | Fail closed, monitor availability/definition age and queue failures. |
| Medium | Case lifecycle concurrency and full rollback/interruption scenarios untested. | Focused lock/fault regressions; no production reliability claim. |

## Checks and boundaries

Authentication: baseline browser login/password reset works using synthetic log-mail, not actual email delivery. Authorization: signed controller policy checks, tenant scope and sealed descendants tested. Upload: bounded server-side bytes/MIME/private revision/checksum; ClamAV clean/infected/error is separate from editorial state. SQL: inspected metadata search uses bound parameters and Eloquent; no comprehensive injection penetration test was executed. Sensitive information: actual records/credentials were not read; public audit docs must contain only technical/synthetic evidence. Historical logs and deployed services have not been exhaustively scanned for leakage. Audit: global hash chain plus ordinary-write triggers, not absolute tamper prevention; independently held anchors and least-privilege DB required.

## Decisions requiring owner/judicial approval

| Decision | Proposed safe default (NOT approved policy) | Needed authority |
|---|---|---|
| Role permissions/publication | Clerk drafts/submits; distinct authorized reviewer publishes; reader published-only; no platform sealed bypass | Judicial/system owner |
| Sealed access | Explicit named grants, no inference from absent legacy flag | Judicial confidentiality owner |
| Ownership/organization | Explicit verified mapping; unresolved records held | Archive owner |
| Suit identity | Preserve original; do not assume global or org-only uniqueness; analyze court/division/year | Judicial policy owner |
| Correction/deletion | Retain immutable revisions/audit, no physical purge; controlled correction of published record | Records authority |
| Audit retention/checkpoints | Retain pending formal schedule; independent protected anchors | Records/security owner |
| Session/MFA | Review shorter privileged sessions and MFA, without locking real users out | System/security owner |
| Backup/RPO/RTO | Named operator, encrypted off-site copy, periodic isolated restore | Operational/data owner |
| Real migration/release | Bounded approved sample after all mandatory gates | System owner, separate production/import/merge approvals |

Unrelated safe engineering continues despite these gates. No ambiguous judicial policy will be changed silently.
