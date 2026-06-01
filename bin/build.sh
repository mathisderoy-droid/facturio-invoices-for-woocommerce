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

echo "==> Rewriting zugferd YAML metadata namespace (Strauss skips *.yml)"
# horstoeko/zugferd ships 267 *.yml metadata files that jms/serializer reads at
# runtime to map classes to XML. Strauss rewrites PHP class names but NOT the
# strings inside these YAML files, so after scoping the serializer looks for
# `Mathis\FacturX\Vendor\horstoeko\zugferd\...` while the YAML still says
# `horstoeko\zugferd\...` and throws "Expected metadata for class ... to be
# defined in ...". That makes the BUILT plugin unable to generate any invoice
# (works in dev only because dev uses the unscoped vendor/). Patch the YAML to
# match, then assert nothing was missed.
ZUGFERD_YAML="${BUILD_DIR}/vendor-prefixed/horstoeko/zugferd/src/yaml"
if [ -d "${ZUGFERD_YAML}" ]; then
    python3 "${ROOT}/bin/fix-zugferd-yaml-namespace.py" "${ZUGFERD_YAML}"
    python3 "${ROOT}/bin/fix-zugferd-yaml-namespace.py" "${ZUGFERD_YAML}" --check
fi

echo "==> Trimming bundled dependencies (unused TCPDF fonts + dependency tests/docs)"
# TCPDF ships ~25 MB of fonts; this plugin only ever uses the base-14 'helvetica'
# (TCPDF substitutes its embeddable 'pdfahelvetica' variant in PDF/A mode). Keep
# the base-14 cores + every PDF/A embeddable font, and drop the rest (DejaVu,
# FreeFont, CJK CID maps, …) — about 24 MB saved, with no effect on output.
FONTS_DIR="${BUILD_DIR}/vendor-prefixed/tecnickcom/tcpdf/fonts"
if [ -d "${FONTS_DIR}" ]; then
    find "${FONTS_DIR}" -type f \
        ! -iname 'helvetica*' ! -iname 'courier*' ! -iname 'times*' \
        ! -iname 'symbol*'    ! -iname 'zapfdingbats*' \
        ! -iname 'pdfa*' \
        -delete
fi
# Dependency test/doc/example folders never run in production.
find "${BUILD_DIR}/vendor-prefixed" -type d \
    \( -name 'tests' -o -name 'Tests' -o -name 'test' -o -name 'docs' -o -name 'doc' -o -name 'examples' \) \
    -prune -exec rm -rf {} + 2>/dev/null || true

echo "==> Regenerating the (own classes) autoloader"
composer dump-autoload --optimize --no-dev --no-interaction

echo "==> Removing build-only tooling from the package"
# Keep composer.json in the shipped zip. Plugin Check raises the
# 'missing_composer_json_file' warning when a vendor/ directory ships WITHOUT a
# composer.json; the documented fix is to INCLUDE composer.json, not to rename
# the folder. Just as importantly, Plugin Check skips the *contents* of any
# directory literally named "vendor" (and "vendor-prefixed"), which is exactly
# what keeps Composer's generated autoloader files out of the PHPCS scan.
# Renaming vendor/ would expose all that infra and trigger dozens of bogus
# escaping/heredoc/file-access errors. composer.lock and strauss.phar are
# build-only and removed.
rm -f strauss.phar composer.lock

echo "==> Zipping"
cd "${BUILD_DIR}/.."
zip -rq "${DIST_DIR}/${SLUG}.zip" "${SLUG}" \
    -x "*/.DS_Store" -x "*/Thumbs.db"

echo "==> Done: ${DIST_DIR}/${SLUG}.zip"
du -h "${DIST_DIR}/${SLUG}.zip" | awk '{print "    Package size: " $1}'
echo "    Validate one generated invoice from this build against"
echo "    https://services.fnfe-mpe.org/ before submitting to WordPress.org."
