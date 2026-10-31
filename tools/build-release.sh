#!/usr/bin/env bash
#
# Build a distributable Docket release: build/docket-<version>/ plus a
# zip of it. The tree has vendor/ and compiled assets committed in, so it
# is what ships on the 'dist' branch and in the release zip — upload it
# to a host, point the document root at public/, open /install.
#
# Usage:
#   tools/build-release.sh <version>
#
set -euo pipefail

version="${1:-}"
[ -n "$version" ] || { echo "Usage: $0 <version>" >&2; exit 2; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"

cd "$REPO_DIR"
[ -d .git ] || { echo "must run inside the git repository" >&2; exit 1; }

out_dir="build/docket-${version}"
archive="build/docket-${version}.zip"

rm -rf "$out_dir" "$archive"
mkdir -p "$out_dir"

# Clean export of HEAD — no .git, no ignored files' cruft... git archive
# only exports tracked files, and .gitattributes export-ignore strips
# .github and the changelog.
git archive HEAD | tar -x -C "$out_dir"

cd "$out_dir"

composer install --no-dev --optimize-autoloader --no-interaction --quiet

if [ -f package.json ]; then
    npm ci --silent
    npm run build
fi

# Strip everything not needed at runtime. composer.json stays: vendor/
# ships prebuilt, but the manifest documents the runtime requirements.
rm -rf node_modules tests .github
rm -f .env .env.backup .env.production .npmrc .editorconfig .phpactor.json \
      .gitattributes .gitignore .styleci.yml .mcp.json phpcs.xml.dist phpstan.neon \
      pint.json phpunit.xml CLAUDE.md AGENTS.md composer.lock package.json \
      package-lock.json vite.config.js

# Keep the storage/ skeleton (its .gitignore files came along with the
# archive; nothing else under storage/ is tracked anyway).

cd "$REPO_DIR"
(cd build && zip -qr "docket-${version}.zip" "docket-${version}")

echo "Built $out_dir and $archive"
