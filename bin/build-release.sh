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
# npm ci runs the postinstall script which copies the PDF.js worker to
# assets/pdfjs/pdf.worker.min.mjs — required for inline PDF viewing.
echo "--> npm ci + build"
npm ci --silent
npm run build --silent

# ── 2. Composer (production, no dev dependencies) ─────────────────────────────
echo "--> composer install --no-dev"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --quiet

# ── 3. Prepare output directory ────────────────────────────────────────────────
rm -rf "${TMP_DIR}"
mkdir -p "${TMP_DIR}/${PLUGIN_SLUG}"

# Excluded paths (no rsync on Windows — use cp + selective delete)
EXCLUDES=(
  '.git' '.github' '.claude' 'node_modules' 'src' 'bin' 'specs_old' 'dist'
  'vite.config.js' 'package.json' 'package-lock.json' 'composer.json'
  'orgahb_manager_spec.md' 'README.md' 'wp-cli.phar'
  '.env' '.gitignore' '.gitattributes'
)

cp -r . "${TMP_DIR}/${PLUGIN_SLUG}/"

# ── 4. Optimise autoloader before stripping dev files ─────────────────────────
echo "--> optimise autoloader in temp dir"
(cd "${TMP_DIR}/${PLUGIN_SLUG}" && composer dump-autoload --no-dev --optimize --quiet)

# ── 5. Strip excluded paths and composer.json from the release ────────────────
for exc in "${EXCLUDES[@]}"; do
  rm -rf "${TMP_DIR}/${PLUGIN_SLUG}/${exc}"
done
rm -f "${TMP_DIR}/${PLUGIN_SLUG}/composer.json"

# Remove any leftover *.log files
find "${TMP_DIR}/${PLUGIN_SLUG}" -name "*.log" -delete

# ── 6. Create zip (Python — guarantees forward-slash entry paths on Linux) ─────
mkdir -p "${OUT_DIR}"
ABS_OUT="$(pwd)/${OUT_DIR}/${ZIP_NAME}"
python -c "
import zipfile, os, sys

src  = sys.argv[1]   # /tmp/orgahb-manager-release/orgahb-manager
dest = sys.argv[2]   # dist/orgahb-manager-1.0.0.zip
slug = os.path.basename(src)

with zipfile.ZipFile(dest, 'w', compression=zipfile.ZIP_DEFLATED) as zf:
    for dirpath, dirnames, filenames in os.walk(src):
        for filename in filenames:
            abs_path  = os.path.join(dirpath, filename)
            # Always use forward slashes — required for correct extraction on Linux
            arc_name  = slug + '/' + os.path.relpath(abs_path, src).replace(os.sep, '/')
            zf.write(abs_path, arc_name)
print('zip ok:', dest)
" "${TMP_DIR}/${PLUGIN_SLUG}" "${ABS_OUT}"
rm -rf "${TMP_DIR}"

echo "==> Release zip: ${OUT_DIR}/${ZIP_NAME}"
ls -lh "${OUT_DIR}/${ZIP_NAME}"
