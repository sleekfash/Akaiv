import base64
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import sys

root = Path(sys.argv[1]).resolve()
if os.environ.get("CODESPACES") != "true" and os.environ.get("AKAIV_PREVIEW_CI") != "1":
    raise SystemExit("Disposable preview environment required")
state = root / ".preview"
state.mkdir(mode=0o700, exist_ok=True)
app = state / "app"
app.mkdir(exist_ok=True)
# Preserve runtime state; never copy existing credentials, records, or cached configuration.
subprocess.run(["rsync", "-a", "--exclude=.env*", "--exclude=storage/", "--exclude=vendor/",
                "--exclude=node_modules/", "--exclude=bootstrap/cache/", "--exclude=public/storage",
                "--exclude=public/build/", "--exclude=public/hot", str(root / "akaiv-saas") + "/", str(app) + "/"], check=True)
for name in ["bootstrap/cache", "storage/framework/cache/data", "storage/framework/sessions",
             "storage/framework/views", "storage/logs", "storage/app/private"]:
    (app / name).mkdir(parents=True, exist_ok=True)
env_path = app / ".env"
codespace = os.environ.get("CODESPACE_NAME")
domain = os.environ.get("GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN", "app.github.dev")
url = f"https://{codespace}-8000.{domain}" if codespace else "http://127.0.0.1:8000"
if env_path.exists():
    values = dict(line.split("=", 1) for line in env_path.read_text().splitlines() if "=" in line)
    if values.get("DB_DATABASE") != "akaiv_preview_pilot_test" or values.get("APP_ENV") != "pilot":
        raise SystemExit("Existing environment is not a disposable preview")
else:
    if (state / "initialized").exists():
        raise SystemExit("Preview key is missing; refusing to replace credentials for existing records")
    values = dict(APP_NAME="AKAIV-Preview", APP_ENV="pilot", APP_DEBUG="false",
                  APP_KEY="base64:" + base64.b64encode(secrets.token_bytes(32)).decode(),
                  DB_CONNECTION="pgsql", DB_HOST="127.0.0.1", DB_PORT="5432",
                  DB_DATABASE="akaiv_preview_pilot_test", DB_USERNAME="preview", DB_PASSWORD=secrets.token_hex(24),
                  REDIS_HOST="127.0.0.1", REDIS_PASSWORD=secrets.token_hex(24), QUEUE_CONNECTION="redis",
                  SESSION_DRIVER="file", CACHE_STORE="array", CACHE_DRIVER="array", MAIL_MAILER="array",
                  SCOUT_DRIVER="null", PULSE_ENABLED="false", CLAMAV_HOST="127.0.0.1", CLAMAV_PORT="3310",
                  AWS_ENDPOINT="http://127.0.0.1:9000", AWS_DEFAULT_REGION="us-east-1",
                  AWS_BUCKET="akaiv-preview", AWS_ACCESS_KEY_ID="preview",
                  AWS_SECRET_ACCESS_KEY=secrets.token_hex(24), AWS_USE_PATH_STYLE_ENDPOINT="true",
                  FILESYSTEM_DISK="s3", PILOT_TEST_MODE="1")
values.update(APP_URL=url, ASSET_URL=url, SESSION_SECURE_COOKIE="true" if codespace else "false")
env_path.write_text("".join(f"{key}={value}\n" for key, value in values.items()))
env_path.chmod(0o600)
(state / "initialized").touch(mode=0o600)
logins = state / "logins.json"
if not logins.exists():
    logins.write_text(json.dumps({role: {"email": f"{role}@example.test", "password": secrets.token_urlsafe(24)}
                                 for role in ["clerk", "reviewer"]}, indent=2))
    logins.chmod(0o600)
