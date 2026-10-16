#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[[ "${CODESPACES:-}" == true || "${AKAIV_PREVIEW_CI:-}" == 1 ]] || { echo "This setup requires a disposable Codespace or CI runner." >&2; exit 1; }
umask 077
python3 "$root/.devcontainer/preview/prepare.py" "$root"
cd "$root/.preview/app"
set -a
source .env
set +a
docker compose --env-file .env -f "$root/.devcontainer/preview/compose.yml" up -d --build --wait --wait-timeout 900
composer install --no-interaction --prefer-dist --no-progress
npm ci --no-audit --no-fund
npm run build
php "$root/.devcontainer/preview/guard.php"
php artisan migrate --force
php "$root/.devcontainer/preview/seed.php"
touch "$root/.preview/ready"
bash "$root/.devcontainer/preview/start.sh"
echo "Preview ready. Open private port 8000, then /admin/login."
echo "Test logins are in .preview/logins.json inside this Codespace."
