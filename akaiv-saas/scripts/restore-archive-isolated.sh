#!/usr/bin/env bash
set -euo pipefail
umask 077
source_directory=${1:?Provide a backup directory}
: "${PGHOST:?Set isolated PostgreSQL host}"
: "${PGDATABASE:?Set an empty isolated database ending in _restore_test}"
: "${RESTORE_OBJECT_DIR:?Set a new isolated object directory}"
case "$PGHOST" in localhost|127.0.0.1) ;; *) echo 'Restore accepts local isolated hosts only' >&2; exit 1;; esac
case "$PGDATABASE" in *_restore_test) ;; *) echo 'Invalid isolated database name' >&2; exit 1;; esac
test ! -e "$RESTORE_OBJECT_DIR" || { echo 'Object destination must be new' >&2; exit 1; }
test "$(psql -At -c "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'")" = 0 || { echo 'Database must be empty' >&2; exit 1; }
test "$(cat "$source_directory/database.sha256")" = "$(sha256sum "$source_directory/database.dump" | cut -d " " -f 1)" || { echo 'Database snapshot hash mismatch' >&2; exit 1; }
pg_restore --exit-on-error --no-owner --no-privileges -d "$PGDATABASE" "$source_directory/database.dump"
mkdir -p "$RESTORE_OBJECT_DIR"
cp -a "$source_directory/objects/." "$RESTORE_OBJECT_DIR/"
printf 'Isolated database/object restore completed. Run archive audit verification, hash reconciliation and authorization tests against this isolated environment.\n'
