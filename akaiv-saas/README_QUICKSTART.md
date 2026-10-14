> Phase 1 instructions and release gates are authoritative in [REMEDIATION_RUNBOOK.md](REMEDIATION_RUNBOOK.md). The scaffold instructions below describe an empty development environment; do not run fresh permission setup or regenerate keys against an existing system. OCR/AI are disabled and the historical Spatie log is unchained.

# AKAIV Archives SaaS — Scaffold Quickstart

This folder (`akaiv-saas/`) is the **GREENFIELD Laravel 11 + Filament 3 + PostgreSQL 16** rebuild of `myarchivesonline.com`.

## Architecture Recap

| Component | Technology | Purpose |
|---|---|---|
| App | **Laravel 11 + PHP 8.3 FPM** | Core platform |
| Admin Panel | **Filament 3 with Filament Shield** | Role-based CRUD for Organizations, Users, Documents, Folders, Tags |
| Multi-Tenancy | `organizations.tenant_id` + Global Scope `App\Scopes\OrganizationScope` | Single-DB multi-tenant |
| Permissions | `spatie/laravel-permission` + Shield | 6 roles + custom permissions |
| File Storage | S3 / R2 / Spaces + `league/flysystem-aws-s3-v3` | Signed TEMPORARY URLs only |
| Search | Meilisearch 1.8 + Laravel Scout | Full-text across name, extracted text, tags |
| Queues | Redis + Laravel Horizon | Virus scan, OCR, Thumbnail, Meilisearch re-index, Email |
| Anti-Virus | ClamAV (TCP socket) via `rogervila/php-clamav-scan` | Scans every upload before publish |
| OCR | Tesseract 5 + `thiagoprz/eloquent-tesseract-ocr` | Extracts text from scanned PDFs |
| Billing | Laravel Cashier (Stripe) | Freemium → Pro → Enterprise |
| Audit | `spatie/laravel-activitylog` | Immutable action log for compliance |
| Observability | Laravel Pulse, Sentry | Health, errors, resource usage |
| Web | Caddy 2 with auto-HTTPS | HTTP/2 + strict CSP + security headers |

---

## How to bring this scaffold up first time

### 1. Install dependencies

```bash
cd akaiv-saas/

composer install --no-interaction --prefer-dist

# If vendor doesn't exist yet and PHP is not on host:
docker run --rm -v $(pwd):/app -w /app composer:2.7 composer install --no-interaction --prefer-dist
```

### 2. First-run setup

```bash
cp .env.example .env
php artisan key:generate --force
php artisan storage:link
```

### 3. Docker up (all services in one command)

```bash
docker compose up -d --build
```

This starts `caddy`, `php`, `pgsql`, `redis`, `meilisearch`, `clamav`, `horizon`, `scheduler`.

### 4. Run migrations

```bash
docker exec akaiv-php php artisan migrate --force
docker exec akaiv-php php artisan shield:install --fresh
docker exec akaiv-php php artisan vendor:publish --tag=activitylog-migrations
docker exec akaiv-php php artisan vendor:publish --tag=permission-migrations
docker exec akaiv-php php artisan migrate --force
```

### 5. Create super admin

```bash
docker exec -it akaiv-php php artisan make:filament-user
# Use your email; this user becomes Platform Super Admin
```

### 6. Access

- **Admin Panel (Filament)**: https://akaiv.localhost/admin
- **Certs**: trust Caddy CA (https://caddyserver.com/docs/running#local-https-with-docker)
- **Meilisearch dashboard**: http://localhost:7700  (MASTER_KEY from .env)
- **Horizon dashboard**: https://akaiv.localhost/horizon  (protected by auth gate)
- **Pulse dashboard**: https://akaiv.localhost/pulse

---

## Legacy Data Migration Command

```bash
docker exec akaiv-php php artisan app:migrate-legacy-documents \
  --legacy-db=mysql://earlvzhc_archive:NEW_PASSWORD@legacy-host/earlvzhc_archive \
  --legacy-files=/mnt/legacy-public/documents \
  --target-org-slug=default-court \
  --dry-run
```

This command:
1. Connects to legacy MySQL
2. Reads `users`, `folders`, `documents`
3. Matches each user → Organization membership
4. Re-hashes storage paths → `org_{id}/docs/{uuid4}.{ext}`
5. Copies each file → S3/R2 bucket via Storage facade
6. Computes SHA-256 → finds duplicates within org
7. Queues ClamAV → OCR → Meilisearch index jobs as batches
8. Generates CSV report of failures + warnings

---

## Next Development Milestones (after scaffold boot)

1. **Create Filament Resources** — Organization, User, Folder, Document, Tag, Case, Workspace, Share, Subscription
2. **Write Document Policy** — gate every download/view/edit/delete
3. **Implement VirusScanJob + middleware** — reject bad uploads before publish
4. **Implement Signed URL Controller** — document/blob/{uuid} route with 5-min TTL
5. **Wire up AutoTag rule engine** — DocumentSaved observer → call AutoTaggingService
6. **Wire Case/Matter workspace views** — link documents to a case
7. **Add OCR + Thumbnail Jobs + pipelines**
8. **Add Share resource + external guest page** (password, IP, expiration)
9. **Cashier subscription + org plan enforcement middleware**
10. **Add 2FA enforcement for owners + admins**
