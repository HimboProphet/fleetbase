#!/bin/zsh
set -euo pipefail

project_root="$(cd "$(dirname "$0")/.." && pwd)"
keychain_account="${USER:-rhino}"

keychain_value() {
  local service_name="$1"
  local value

  value="$(security find-generic-password -a "$keychain_account" -s "$service_name" -w 2>/dev/null || true)"
  if [[ -z "$value" ]]; then
    print -u2 "Missing required Keychain service: $service_name"
    return 1
  fi
  print -r -- "$value"
}

export FLEETBASE_MYSQL_ROOT_PASSWORD
FLEETBASE_MYSQL_ROOT_PASSWORD="$(keychain_value codex.fleetbase.local.mysql_root_password)"

export FLEETBASE_MYSQL_APP_PASSWORD
FLEETBASE_MYSQL_APP_PASSWORD="$(keychain_value codex.fleetbase.local.mysql_app_password)"

cd "$project_root"
exec docker compose "$@"
