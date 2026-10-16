# Private browser preview

Based on the Phase 1 security-update candidate; OCR, AI and public sharing remain disabled.
Use only synthetic/sample records. This is a temporary review environment, not production.

Create a Codespace from the preview branch. Setup copies active source into ignored `.preview/app`,
excluding existing environment files, uploaded records and generated caches. It starts isolated
PostgreSQL, Redis, ClamAV and private S3-compatible storage, builds assets, applies additive migrations
only to `akaiv_preview_pilot_test`, and creates two random test logins in `.preview/logins.json`.
Open private port 8000 at `/admin/login`. Never change its visibility to public.
The clerk and reviewer are demonstration administrators; this is not the final judicial role matrix.

Only port 8000 is forwarded. Services bind to loopback. App debug and outbound email are disabled.
The preview limits HTTP uploads to 32 MB to protect the small machine. Files remain private.
Do not upload real archive records. Stop the Codespace when finished; retained storage still counts
toward the account allowance. Deleting it also deletes its test data.

To retry setup: `bash .devcontainer/post-create.sh`.
To resume services: `bash .devcontainer/preview/start.sh`.
To stop app processes: `supervisorctl -c .preview/supervisor.conf shutdown`.
Restarting preserves the generated key, passwords, database and objects; setup never resets permissions.
Lost preview credentials require deliberate disposable-environment recovery, not automatic rotation.

Validation: `.github/workflows/preview-validation.yml` tests installation, both logins, private storage,
hidden environment files and repeat setup. Codespaces authentication, forwarded HTTPS and account quota
must still be checked in the actual Codespace. Known npm audit findings on the base branch are not resolved
by this preview infrastructure; no production-readiness claim is made.
