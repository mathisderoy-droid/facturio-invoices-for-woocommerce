#!/usr/bin/env bash
#
# build.sh — produce the WordPress.org-ready, dependency-scoped plugin zip.
#
# WHY THIS EXISTS
#   We ship Composer dependencies (TCPDF, horstoeko/zugferd, Symfony, …).
#   If another active plugin bundles the same libraries, version clashes can
#   break the merchant's site. Strauss rewrites every shipped dependency into
#   the `Mathis\FacturX\Vendor\` namespace so collisions are impossible.
#
#   Strauss does NOT run on Windows (path-resolution bug, confirmed 2026-05).
#   This script is therefore meant to run on Linux/macOS — typically a CI
#   runner or a Linux box — at release time. Day-to-day development on
#   Windows keeps using the unscoped vendor/ and works fine.
#
# USAGE
#   bash bin/build.sh            # builds dist/factur-x-for-woocommerce.zip
#
# REQUIREMENTS (on the build machine)
#   - php >= 8.0 with ext-zip, ext-fileinfo, ext-gd
#   - composer
#   - curl, zip, rsync
#
set -euo pipefail

STRAUSS_VERSION="0.27.2"
SLUG="factur-x-for-woocommerce"

# Resolve repo root (this script lives in bin/).
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT}/build/${SLUG}"
DIST_DIR="${ROOT}/dist"

echo "==> Cleaning previous build"
rm -rf "${ROOT}/build" "${DIST_DIR}"
mkdir -p "${BUILD_DIR}" "${DIST_DIR}"

echo "==> Copying source into build dir (excluding dev-only files)"
rsync -a --delete \
    --exclude '.git' \
    --exclude '.gitignore' \
    --exclude '.github' \
    --exclude 'build' \
    --exclude 'dist' \
    --exclude 'bin' \
    --exclude 'node_modules' \
    --exclude 'tests' \
    --exclude 'vendor' \
    --exclude 'vendor-prefixed' \
    --exclude 'strauss.phar' \
    --exclude 'phpunit.xml.dist' \
    --exclude 'phpcs.xml.dist' \
    --exclude 'CLAUDE.md' \
    --exclude 'DECISIONS.md' \
    --exclude '*.log' \
    "${ROOT}/" "${BUILD_DIR}/"

cd "${BUILD_DIR}"

echo "==> Installing production dependencies"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Fetching Strauss ${STRAUSS_VERSION}"
curl -fsSL -o strauss.phar \
    "https://github.com/BrianHenryIE/strauss/releases/download/${STRAUSS_VERSION}/strauss.phar"

echo "==> Scoping dependencies into Mathis\\FacturX\\Vendor\\ + rewriting call sites"
# Strauss spins up Composer internally, which validates any stored GitHub
# OAuth token. On CI the runner injects one (to dodge API rate limits) and
# Strauss rejects its format — even though it needs no network at this step.
# Composer install already ran above, so the token is no longer needed:
# clear it (stored config + env) just for the scoping step.
composer config --global --unset github-oauth.github.com 2>/dev/null || true
COMPOSER_AUTH='{}' php strauss.phar --updateCallSites=includes

echo "==> Regenerating the (own classes) autoloader"
composer dump-autoload --optimize --no-dev --no-interaction

echo "==> Removing build-only tooling from the package"
rm -f strauss.phar composer.json composer.lock

echo "==> Zipping"
cd "${BUILD_DIR}/.."
zip -rq "${DIST_DIR}/${SLUG}.zip" "${SLUG}" \
    -x "*/.DS_Store" -x "*/Thumbs.db"

echo "==> Done: ${DIST_DIR}/${SLUG}.zip"
echo "    Validate one generated invoice from this build against"
echo "    https://services.fnfe-mpe.org/ before submitting to WordPress.org."
