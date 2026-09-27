#!/usr/bin/env bash
#
# Downloads the official KoSIT validator and its XRechnung/EN 16931
# configuration in a fixed version, refuses both unless their SHA-256 matches,
# and lays them out for tests/Feature/KositValidationTest.php:
#
#   <directory>/validator.jar
#   <directory>/configuration/scenarios.xml (+ resources)
#
# Usage: bash .github/kosit/fetch.sh [directory]   (default: build/kosit)
# Then:  GOBD_KOSIT_DIR=<directory> vendor/bin/pest --filter=KositValidationTest

set -euo pipefail

VALIDATOR_VERSION="1.6.3"
VALIDATOR_URL="https://github.com/itplr-kosit/validator/releases/download/v${VALIDATOR_VERSION}/validator-${VALIDATOR_VERSION}-standalone.jar"
VALIDATOR_SHA256="799e64befca97d4080e03608c80b85dd5a5ecc5f4ae4f35d1116ec2855b9a7c9"

CONFIGURATION_RELEASE="v2026-08-31"
CONFIGURATION_URL="https://github.com/itplr-kosit/validator-configuration-xrechnung/releases/download/${CONFIGURATION_RELEASE}/xrechnung-3.0.2-validator-configuration-2026-08-31.zip"
CONFIGURATION_SHA256="2530cd107c414511c5d0462ec10f886910395abfca820db82e83d70bf01221a8"

directory="${1:-build/kosit}"
mkdir -p "$directory"
directory="$(cd "$directory" && pwd)"

sha256() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | cut -d ' ' -f 1
  else
    shasum -a 256 "$1" | cut -d ' ' -f 1
  fi
}

fetch() {
  local url="$1" target="$2" expected="$3" actual

  curl --fail --silent --show-error --location --retry 3 --output "$target.download" "$url"
  actual="$(sha256 "$target.download")"

  if [ "$actual" != "$expected" ]; then
    rm -f "$target.download"
    echo "Checksum mismatch for $url: expected $expected, got $actual" >&2
    exit 1
  fi

  mv "$target.download" "$target"
  echo "$(basename "$target"): sha256 $actual ok"
}

fetch "$VALIDATOR_URL" "$directory/validator.jar" "$VALIDATOR_SHA256"
fetch "$CONFIGURATION_URL" "$directory/configuration.zip" "$CONFIGURATION_SHA256"

rm -rf "$directory/configuration" "$directory/reports"
mkdir -p "$directory/configuration"
unzip -q "$directory/configuration.zip" -d "$directory/configuration"

echo "KoSIT validator ${VALIDATOR_VERSION}, configuration ${CONFIGURATION_RELEASE} in $directory"
