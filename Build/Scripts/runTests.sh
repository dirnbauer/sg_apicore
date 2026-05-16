#!/usr/bin/env bash

set -euo pipefail

loadHelp() {
	cat <<'EOF'
TYPO3 extension test runner.

Usage: Build/Scripts/runTests.sh [options] [phpunit-file]

Options:
  -s <suite>   Suite: unit, phpstan, lint, ci, composer, clean
  -p <php>     PHP version selector for CI compatibility. Local runner verifies the current PHP binary.
  -h           Show this help

Examples:
  Build/Scripts/runTests.sh -s unit
  Build/Scripts/runTests.sh -s phpstan
  Build/Scripts/runTests.sh -s ci
EOF
}

TEST_SUITE="unit"
PHP_VERSION="8.3"

while getopts "s:p:h" OPTION; do
	case "${OPTION}" in
		s) TEST_SUITE="${OPTARG}" ;;
		p) PHP_VERSION="${OPTARG}" ;;
		h) loadHelp; exit 0 ;;
		*) loadHelp >&2; exit 1 ;;
	esac
done
shift $((OPTIND - 1))

THIS_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null && pwd)"
ROOT_DIR="$(cd "${THIS_SCRIPT_DIR}/../.." >/dev/null && pwd)"
cd "${ROOT_DIR}"

if [[ ! "${PHP_VERSION}" =~ ^8\.(2|3|4|5)$ ]]; then
	echo "Invalid PHP version '${PHP_VERSION}'. Expected one of: 8.2, 8.3, 8.4, 8.5" >&2
	exit 1
fi

CURRENT_PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
if [[ "${CURRENT_PHP_VERSION}" != "${PHP_VERSION}" ]]; then
	echo "Requested PHP ${PHP_VERSION}, running with local PHP ${CURRENT_PHP_VERSION}." >&2
	echo "Use CI/container matrix for exact multi-version execution." >&2
fi

ensureVendor() {
	if [[ ! -f vendor/autoload.php ]]; then
		composer install --no-interaction
	fi
}

case "${TEST_SUITE}" in
	unit)
		ensureVendor
		vendor/bin/phpunit --configuration=phpunit.xml "$@"
		;;
	phpstan)
		ensureVendor
		vendor/bin/phpstan analyse --configuration=Build/phpstan/phpstan.neon --memory-limit=-1
		;;
	lint)
		find Classes Configuration tests -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
		;;
	ci)
		ensureVendor
		find Classes Configuration tests -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
		vendor/bin/phpstan analyse --configuration=Build/phpstan/phpstan.neon --memory-limit=-1
		vendor/bin/phpunit --configuration=phpunit.xml
		composer audit --format=plain
		;;
	composer)
		composer "$@"
		;;
	clean)
		rm -rf .cache public var vendor
		;;
	*)
		echo "Invalid suite '${TEST_SUITE}'." >&2
		loadHelp >&2
		exit 1
		;;
esac
