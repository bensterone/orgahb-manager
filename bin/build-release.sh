#!/usr/bin/env bash
# build-release.sh — build and package orgahb-manager for WordPress deployment.
#
# Usage:
#   bash bin/build-release.sh [version]
#
# Output:
#   dist/orgahb-manager-{version}.zip
#
# The zip contains a single top-level directory `orgahb-manager/` suitable
# for direct upload via WP Admin → Plugins → Add New → Upload Plugin,
# or extraction into wp-content/plugins/.
#
# What is excluded from the release zip:
#   - node_modules/
#   - src/ (source JS — compiled output is in assets/dist/)
#   - .github/, .claude/, specs_old/
#   - Dev tooling: vite.config.js, package*.json, composer.json (lock kept)
#   - bin/ itself
#   - orgahb_manager_spec.md, README.md (wp.org readme.txt is kept)
#   - wp-cli.phar

set -euo pipefail

PLUGIN_SLUG="orgahb-manager"
VERSION="${1:-$(grep "Version:" orgahb-manager.php | head -1 | sed 's/.*Version: *//')}"
OUT_DIR="dist"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
TMP_DIR="/tmp/${PLUGIN_SLUG}-release"

echo "==> Building release: ${PLUGIN_SLUG} v${VERSION}"

# ── 1. JS build ───────────────────────────────────────────────────────────────
echo "--> npm ci + build"
npm ci --silent
npm run build --silent

# ── 2. Composer (production, no dev dependencies) ─────────────────────────────
echo "--> composer install --no-dev"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --quiet

# ── 3. Prepare output directory ────────────────────────────────────────────────
rm -rf "${TMP_DIR}"
mkdir -p "${TMP_DIR}/${PLUGIN_SLUG}"

rsync -a \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.claude' \
  --exclude='node_modules' \
  --exclude='src' \
  --exclude='bin' \
  --exclude='specs_old' \
  --exclude='dist' \
  --exclude='vite.config.js' \
  --exclude='package.json' \
  --exclude='package-lock.json' \
  --exclude='composer.json' \
  --exclude='orgahb_manager_spec.md' \
  --exclude='README.md' \
  --exclude='wp-cli.phar' \
  --exclude='*.log' \
  --exclude='.env' \
  --exclude='.gitignore' \
  --exclude='.gitattributes' \
  . "${TMP_DIR}/${PLUGIN_SLUG}/"

# ── 4. Restore production composer autoloader ──────────────────────────────────
# (already copied by rsync above, but re-run to ensure optimised classmap)
echo "--> optimise autoloader in temp dir"
(cd "${TMP_DIR}/${PLUGIN_SLUG}" && composer dump-autoload --no-dev --optimize --quiet)

# ── 5. Remove composer.json from the release (lock is enough for auditing) ─────
rm -f "${TMP_DIR}/${PLUGIN_SLUG}/composer.json"

# ── 6. Create zip ─────────────────────────────────────────────────────────────
mkdir -p "${OUT_DIR}"
(cd "${TMP_DIR}" && zip -r "${OLDPWD}/${OUT_DIR}/${ZIP_NAME}" "${PLUGIN_SLUG}/" -q)
rm -rf "${TMP_DIR}"

echo "==> Release zip: ${OUT_DIR}/${ZIP_NAME}"
echo "    $(du -sh "${OUT_DIR}/${ZIP_NAME}" | cut -f1)"
