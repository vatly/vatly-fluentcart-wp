#!/usr/bin/env bash
#
# Build a distributable release zip of the plugin.
#
# Pipeline:
#   1. Stage a clean copy of the plugin source.
#   2. composer install --no-dev (vendor/ goes into the stage).
#   3. Run humbug/php-scoper to rewrite every namespaced symbol into
#      Vatly\FluentCart\Vendor\… — defends against class collisions when
#      this plugin is installed alongside others that ship overlapping libs
#      (composer/ca-bundle today, anything else vatly-fluent-php picks up
#      later). FluentCart's own namespace is deliberately left unprefixed
#      so our gateway can still extend FluentCart's AbstractPaymentGateway.
#   4. composer dump-autoload --classmap-authoritative against the scoped
#      tree so the new symbol locations resolve.
#   5. Zip the result.
#
# Usage:
#   bin/build-release.sh                  # → build/vatly-for-fluentcart.zip
#   VERSION=0.1.0 bin/build-release.sh    # → build/vatly-for-fluentcart-0.1.0.zip
#
# Requires: php (with phar), composer, curl, zip.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="vatly-for-fluentcart"
BUILD_DIR="${ROOT}/build"
STAGE_DIR="${BUILD_DIR}/stage"
PLUGIN_DIR="${STAGE_DIR}/${SLUG}"
VERSION="${VERSION:-}"
PHP_SCOPER_VERSION="${PHP_SCOPER_VERSION:-0.18.19}"
PHP_SCOPER="${BUILD_DIR}/tools/php-scoper.phar"

if [ -n "${VERSION}" ]; then
	ZIP_NAME="${SLUG}-${VERSION}.zip"
else
	ZIP_NAME="${SLUG}.zip"
fi

echo "==> Cleaning ${BUILD_DIR}"
rm -rf "${BUILD_DIR}"
mkdir -p "${PLUGIN_DIR}" "${BUILD_DIR}/tools"

echo "==> Staging plugin source"
# Tightly-scoped allowlist — anything not strictly needed at runtime stays
# out of the release zip (docker/, tests/, .github/, bin/, dotfiles, ...).
rsync -a \
	--include="vatly-for-fluentcart.php" \
	--include="composer.json" \
	--include="README.md" \
	--include="src/***" \
	--include="assets/***" \
	--exclude="*" \
	"${ROOT}/" "${PLUGIN_DIR}/"

echo "==> Installing production dependencies"
( cd "${PLUGIN_DIR}" && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader )

echo "==> Downloading php-scoper ${PHP_SCOPER_VERSION}"
curl -sSL -o "${PHP_SCOPER}" \
	"https://github.com/humbug/php-scoper/releases/download/${PHP_SCOPER_VERSION}/php-scoper.phar"
chmod +x "${PHP_SCOPER}"

echo "==> Scoping namespaces → Vatly\\FluentCart\\Vendor"
SCOPED_DIR="${BUILD_DIR}/scoped/${SLUG}"
mkdir -p "${SCOPED_DIR}"

# Run php-scoper inside the staged plugin dir; output to scoped/.
( cd "${PLUGIN_DIR}" && php "${PHP_SCOPER}" add-prefix \
	--config="${ROOT}/scoper.inc.php" \
	--output-dir="${SCOPED_DIR}" \
	--force \
	--no-interaction )

# Copy assets that php-scoper doesn't process (composer.json, README, the
# SVG logo, …).
rsync -a \
	--include="composer.json" \
	--include="README.md" \
	--include="assets/***" \
	--exclude="*" \
	"${PLUGIN_DIR}/" "${SCOPED_DIR}/"

echo "==> Rewriting composer.json autoload for the scoped tree"
# php-scoper prefixes every namespace but doesn't touch composer.json
# autoload sections — which still point at the pre-scoping namespaces.
# Easiest fix is to switch the top-level autoload to classmap-only: composer
# then scans every PHP file and builds a complete classmap regardless of
# namespace conventions. With --classmap-authoritative the PSR-4 rules in
# vendor's installed.json are ignored at runtime, so the stale namespaces
# there don't bite us.
php -r '
$path = $argv[1];
$composer = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$composer["autoload"] = [
    "classmap" => ["src/", "vendor/"],
];
unset($composer["autoload-dev"], $composer["require-dev"], $composer["scripts"]);
file_put_contents(
    $path,
    json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);
' "${SCOPED_DIR}/composer.json"

echo "==> Regenerating classmap with scoped symbols"
# Scoper kept Composer's own runtime helpers (`Composer\InstalledVersions`,
# `Composer\Autoload\ClassLoader`, etc.) unprefixed via scoper.inc.php's
# exclude-namespaces rules. `composer dump-autoload` now safely rebuilds the
# classmap against the mix of (mostly) prefixed and (Composer-internals)
# bare classes.
( cd "${SCOPED_DIR}" && composer dump-autoload --no-dev --classmap-authoritative --no-interaction )

# Final cleanup: composer.json is fine to ship but composer.lock is noise.
rm -f "${SCOPED_DIR}/composer.lock"

echo "==> Packaging ${ZIP_NAME}"
( cd "${BUILD_DIR}/scoped" && zip -qr "${BUILD_DIR}/${ZIP_NAME}" "${SLUG}" )

echo
echo "==> Done."
echo "    Output: ${BUILD_DIR}/${ZIP_NAME}"
echo "    Tree:   ${SCOPED_DIR}"
echo "    Bytes:  $(stat -f%z "${BUILD_DIR}/${ZIP_NAME}" 2>/dev/null || stat -c%s "${BUILD_DIR}/${ZIP_NAME}")"
