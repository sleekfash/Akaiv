#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
[[ "${CODESPACES:-}" == true || "${AKAIV_PREVIEW_CI:-}" == 1 ]] || { echo "Disposable preview required" >&2; exit 1; }
[[ -f "$root/.preview/ready" ]] || exit 0
umask 077
cd "$root/.preview/app"
set -a
source .env
set +a
docker compose --env-file .env -f "$root/.devcontainer/preview/compose.yml" up -d --wait --wait-timeout 900
python3 "$root/.devcontainer/preview/render.py" "$root"
if ! supervisorctl -c "$root/.preview/supervisor.conf" status >/dev/null 2>&1; then
    supervisord -c "$root/.preview/supervisor.conf"
fi
for attempt in $(seq 1 60); do
    if curl -fsS http://127.0.0.1:8000/admin/login >/dev/null; then exit 0; fi
    sleep 2
done
echo "Preview login did not become healthy; check .preview logs." >&2
exit 1
