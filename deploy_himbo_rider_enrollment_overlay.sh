#!/usr/bin/env bash
set -euo pipefail

REMOTE_HOST="${REMOTE_HOST:-rhino@20.124.38.43}"
SSH_KEY="${SSH_KEY:-/Users/rhino/.ssh/rhinoos-atlas12-builder}"
REMOTE_ROOT="${REMOTE_ROOT:-/opt/himbo-express/current}"
REMOTE_SECRETS_DIR="${REMOTE_SECRETS_DIR:-/opt/himbo-express/secrets}"
REMOTE_BACKUP_DIR="${REMOTE_BACKUP_DIR:-/root/2rhino-backups}"
SSH_OPTS=(-i "$SSH_KEY" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new)

cd "$(dirname "$0")"

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
stage="/tmp/himbo-rider-enrollment-$stamp"

ssh "${SSH_OPTS[@]}" "$REMOTE_HOST" "mkdir -p '$stage'"
scp "${SSH_OPTS[@]}" \
  api/app/Providers/RouteServiceProvider.php \
  docker-compose.himbo-express.prod.yml \
  "$REMOTE_HOST:$stage/"

ssh "${SSH_OPTS[@]}" "$REMOTE_HOST" \
  REMOTE_ROOT="$REMOTE_ROOT" \
  REMOTE_SECRETS_DIR="$REMOTE_SECRETS_DIR" \
  REMOTE_BACKUP_DIR="$REMOTE_BACKUP_DIR" \
  STAGE="$stage" \
  STAMP="$stamp" \
  'bash -s' <<'REMOTE'
set -euo pipefail

compose_file="$REMOTE_ROOT/docker-compose.himbo-express.prod.yml"
provider_file="$REMOTE_ROOT/api/app/Providers/RouteServiceProvider.php"
env_file="$REMOTE_SECRETS_DIR/app.env"
backup_image="himbo-express-api:pre-rhinoverify-$STAMP"

sudo mkdir -p "$REMOTE_BACKUP_DIR"
sudo test -f "$compose_file"
sudo test -f "$provider_file"
sudo test -f "$env_file"

sudo cp "$compose_file" "$REMOTE_BACKUP_DIR/himbo-compose-pre-rhinoverify-$STAMP.yml"
sudo cp "$provider_file" "$REMOTE_BACKUP_DIR/RouteServiceProvider-pre-rhinoverify-$STAMP.php"
sudo cp "$env_file" "$REMOTE_BACKUP_DIR/himbo-app-env-pre-rhinoverify-$STAMP.env"
sudo chmod 0600 "$REMOTE_BACKUP_DIR/himbo-app-env-pre-rhinoverify-$STAMP.env"
sudo docker image tag himbo-express-api:prod "$backup_image"

rollback() {
  code=$?
  if [ "$code" -eq 0 ]; then
    return
  fi

  echo "Overlay failed; restoring the prior HIMBO API image and source files." >&2
  sudo docker image tag "$backup_image" himbo-express-api:prod || true
  sudo cp "$REMOTE_BACKUP_DIR/himbo-compose-pre-rhinoverify-$STAMP.yml" "$compose_file" || true
  sudo cp "$REMOTE_BACKUP_DIR/RouteServiceProvider-pre-rhinoverify-$STAMP.php" "$provider_file" || true
  sudo cp "$REMOTE_BACKUP_DIR/himbo-app-env-pre-rhinoverify-$STAMP.env" "$env_file" || true
  (
    cd "$REMOTE_ROOT"
    sudo docker compose --env-file "$env_file" -f "$compose_file" up -d --no-build --force-recreate application httpd
  ) || true
  exit "$code"
}
trap rollback EXIT

sudo install -m 0644 "$STAGE/RouteServiceProvider.php" "$provider_file"
sudo install -m 0644 "$STAGE/docker-compose.himbo-express.prod.yml" "$compose_file"

if sudo grep -q '^HIMBO_COURIER_ENROLLMENT_ENABLED=' "$env_file"; then
  sudo sed -i 's/^HIMBO_COURIER_ENROLLMENT_ENABLED=.*/HIMBO_COURIER_ENROLLMENT_ENABLED=false/' "$env_file"
else
  echo 'HIMBO_COURIER_ENROLLMENT_ENABLED=false' | sudo tee -a "$env_file" >/dev/null
fi

sudo tee "$STAGE/Dockerfile" >/dev/null <<EOF
FROM $backup_image
COPY RouteServiceProvider.php /fleetbase/api/app/Providers/RouteServiceProvider.php
RUN chown www-data:www-data /fleetbase/api/app/Providers/RouteServiceProvider.php \
    && php -l /fleetbase/api/app/Providers/RouteServiceProvider.php
EOF

sudo docker build --pull=false -t himbo-express-api:prod "$STAGE"

cd "$REMOTE_ROOT"
sudo docker compose --env-file "$env_file" -f "$compose_file" up -d --no-build --force-recreate application httpd
sudo docker compose --env-file "$env_file" -f "$compose_file" exec -T application php artisan cache:clear
sudo docker compose --env-file "$env_file" -f "$compose_file" exec -T application php artisan route:clear
sudo docker compose --env-file "$env_file" -f "$compose_file" exec -T application php artisan config:cache
sudo docker compose --env-file "$env_file" -f "$compose_file" exec -T application \
  php artisan route:list --path=int/v1/couriers/enrollments --json | grep -q 'int/v1/couriers/enrollments'

for attempt in 1 2 3 4 5 6; do
  status="$(curl -sS -o "$STAGE/enrollment-response.json" -w '%{http_code}' \
    -H 'Accept: application/json' \
    -H 'Content-Type: application/json' \
    --data '{}' \
    http://127.0.0.1:18080/int/v1/couriers/enrollments || true)"
  if [ "$status" = "503" ] && grep -q '"courier_enrollment_not_active"' "$STAGE/enrollment-response.json"; then
    break
  fi
  if [ "$attempt" -eq 6 ]; then
    echo "Fail-closed enrollment smoke failed with HTTP $status." >&2
    exit 1
  fi
  sleep 2
done

trap - EXIT
sudo rm -rf "$STAGE"
echo "HIMBO rider enrollment bridge deployed fail-closed."
echo "Rollback image: $backup_image"
echo "Backup files: $REMOTE_BACKUP_DIR/*-pre-rhinoverify-$STAMP.*"
REMOTE
