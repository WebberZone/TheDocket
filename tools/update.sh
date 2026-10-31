#!/usr/bin/env bash
#
# Docket updater — pull the latest code, rebuild if needed, migrate, done.
#
# Usage:
#   tools/update.sh           (interactive)
#   tools/update.sh --yes     (non-interactive, for the nightly cron)
#
# Safe to run from anywhere: it cd's to the app it lives in. When run as
# root it re-executes itself as the owner of the app directory, so the
# git work and artisan commands run as the right user.
#
set -euo pipefail

ASSUME_YES=0
for arg in "$@"; do
    case "$arg" in
        -y|--yes) ASSUME_YES=1 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

# Re-exec as the app owner when root, so files stay owned correctly.
if [ "$(id -u)" -eq 0 ]; then
    owner="$(stat -c '%U' "$APP_DIR")"
    if [ "$owner" != "root" ]; then
        exec sudo -u "$owner" -- "$0" "$@"
    fi
fi

cd "$APP_DIR"

[ -d .git ] || die "$APP_DIR is not a git checkout — updates need a git clone."
command -v git >/dev/null 2>&1 || die "git is not installed."

if [ -n "$(git status --porcelain)" ]; then
    die "The working tree has local changes — refusing to update. Run 'git status' to see them."
fi

old_commit="$(git rev-parse --short HEAD)"

if [ "$ASSUME_YES" -eq 0 ]; then
    printf 'Update Docket in %s? [y/N]: ' "$APP_DIR"
    read -r answer
    case "$answer" in
        y|Y|yes|YES) ;;
        *) die "Aborted." ;;
    esac
fi

# Decide the branch and check tools up front, so a missing composer/npm
# is caught before the site ever goes into maintenance mode.
branch="$(git rev-parse --abbrev-ref HEAD)"
if [ "$branch" != "dist" ]; then
    command -v composer >/dev/null 2>&1 || die "composer not found (needed on the '$branch' branch)."
    if [ -f package.json ]; then
        command -v npm >/dev/null 2>&1 || die "npm not found (needed to rebuild assets)."
    fi
fi

# Snapshot the database first.
snapshot_dir="storage/app/updates"
mkdir -p "$snapshot_dir"
timestamp="$(date +%Y%m%d-%H%M%S)"
snapshot=""
if [ -f database/database.sqlite ] && command -v sqlite3 >/dev/null 2>&1; then
    snapshot="$snapshot_dir/pre-update-${timestamp}.sqlite"
    sqlite3 database/database.sqlite ".backup $snapshot"
    log "Database snapshot: $snapshot"
    # Keep only the newest 5 snapshots.
    ls -1t "$snapshot_dir"/pre-update-*.sqlite 2>/dev/null | tail -n +6 | while IFS= read -r old; do rm -f "$old"; done
fi

site_down=0
restore_hint() {
    [ "$site_down" -eq 1 ] || return 0
    echo >&2
    echo "The update failed and the site is still in maintenance mode." >&2
    if [ -n "$snapshot" ]; then
        echo "To restore the database snapshot:" >&2
        echo "  cp $snapshot database/database.sqlite" >&2
    fi
    echo "Then: git checkout <previous-commit> ($old_commit) && php artisan up" >&2
}
trap restore_hint ERR

php artisan down --retry=60
site_down=1

git pull --ff-only

# Re-read the branch: the pull may have switched us onto dist.
branch="$(git rev-parse --abbrev-ref HEAD)"
if [ "$branch" != "dist" ]; then
    composer install --no-dev --optimize-autoloader --no-interaction
    if [ -f package.json ]; then
        npm ci
        npm run build
    fi
fi

php artisan migrate --force
php artisan optimize:clear

if grep -q '^APP_ENV=production' .env 2>/dev/null; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

php artisan queue:restart || true
php artisan up

trap - ERR

new_commit="$(git rev-parse --short HEAD)"
log "Updated ${old_commit} → ${new_commit}"
