#!/usr/bin/env bash
set -euo pipefail
umask 077
: "${ARCHIVE_OBJECT_REMOTE:?Configure a read-only rclone object source}"
destination=${1:?Provide a new backup directory}
test ! -e "$destination" || { echo 'Refusing to overwrite an existing backup' >&2; exit 1; }
mkdir -p "$destination/objects"
docker compose -f docker-compose.production.yml exec -T pgsql sh -c 'pg_dump -Fc -U "$POSTGRES_USER" "$POSTGRES_DB"' > "$destination/database.dump"
# Immutable object keys and no object garbage collection are required during this copy.
rclone copy "$ARCHIVE_OBJECT_REMOTE" "$destination/objects" --checksum
sha256sum "$destination/database.dump" | cut -d " " -f 1 > "$destination/database.sha256"
printf 'Database and object snapshot prepared. Encrypt and retain off-site; prove an isolated restore before relying on it.\n'
