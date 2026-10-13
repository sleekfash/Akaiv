# Repository Guidance

## Repository Layout

- `akaiv-saas/` is the active greenfield application: Laravel 11, PHP 8.3, Filament 3, PostgreSQL, Redis, Meilisearch, and Docker Compose.
- `myarchivesonline.com/` is the legacy Laravel 6 application. Treat it as a compatibility-sensitive reference and do not apply Laravel 11 conventions there unless a task explicitly targets it.
- Root-level `README.md`, `README_QUICKSTART.md`, checkpoints, and roadmap files describe project status and operational context.
- The active app's operational setup is documented in [akaiv-saas/README_QUICKSTART.md](akaiv-saas/README_QUICKSTART.md); consult it instead of duplicating setup details here.

## Working Rules

- Keep changes scoped to the application named by the task. Prefer the existing patterns, services, policies, scopes, and models in that application.
- For the active app, preserve tenant isolation through `App\\Scopes\\OrganizationScope` and organization-aware models. Do not bypass authorization or expose permanent storage URLs.
- Phase 1 disables runtime OCR and AI. Uploads must use ClamAV scanning, queues, manual review, and signed temporary routes; preserve future extension hooks. ClamAV uses `xenolope/quahog`; OCR uses `thiagoalessio/tesseract_ocr`.
- The active app's Filament panel is `App\\Providers\\Filament\\AdminPanelProvider`; keep it registered when changing panel behavior. Shield and package migrations live in `database/migrations/`.
- Avoid changing generated/vendor/build artifacts, secrets, `.env` files, or storage data.
- Do not modernize the legacy app as part of active-app work.
- Do not assume referenced future pipeline jobs exist: document processing currently references OCR, thumbnail, and indexing jobs that may need to be implemented before changing dispatch behavior.

## Validation

From `akaiv-saas/`, preferably through the running PHP container:

- Install dependencies reproducibly: `docker exec akaiv-php composer install --no-interaction --prefer-dist`
- Generate an app key only for a new disposable environment; preserve existing keys and never regenerate a live key as setup remediation.
- Run migrations: `docker exec akaiv-php php artisan migrate --force`
- Run tests: `docker exec akaiv-php php artisan test` (tests now exist)
- Check style: `docker exec akaiv-php vendor/bin/pint --test` (expect existing style issues until the codebase is normalized)
- Inspect routes/config when relevant: `php artisan route:list` and `php artisan config:show`
- Use Docker services for PostgreSQL, Redis, Meilisearch, ClamAV, Horizon, and scheduler when a check needs infrastructure.
- Smoke-test Filament: `curl -k -I https://akaiv.localhost/admin/login`; a healthy login page returns HTTP 200.
- Check service state: `docker compose ps` and `docker compose logs --tail=50 <service>`.

Before submitting changes, run the narrowest relevant test or lint command, then broader tests when the change crosses shared application boundaries.

## Code Style

- Follow Laravel conventions and the surrounding file's style.
- Keep public APIs and database contracts stable unless the task requires a migration.
- Add focused tests for authorization, tenant isolation, uploads, signed URLs, queues, and other security-sensitive behavior.
- Do not add comments that merely narrate obvious code.
