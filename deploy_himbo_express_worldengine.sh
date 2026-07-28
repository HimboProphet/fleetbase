#!/usr/bin/env bash
set -euo pipefail

REMOTE_HOST="${REMOTE_HOST:-rhino@20.124.38.43}"
SSH_KEY="${SSH_KEY:-/Users/rhino/.ssh/rhinoos-atlas12-builder}"
REMOTE_ROOT="${REMOTE_ROOT:-/opt/himbo-express/current}"
REMOTE_SECRETS_DIR="${REMOTE_SECRETS_DIR:-/opt/himbo-express/secrets}"
REMOTE_BACKUP_DIR="${REMOTE_BACKUP_DIR:-/root/2rhino-backups}"
SSH_OPTS=(-i "$SSH_KEY" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new)
COMPOSE_FILE="docker-compose.himbo-express.prod.yml"

cd "$(dirname "$0")"

ssh "${SSH_OPTS[@]}" "$REMOTE_HOST" "sudo mkdir -p '$REMOTE_ROOT' '$REMOTE_SECRETS_DIR' '$REMOTE_BACKUP_DIR' && sudo chown -R rhino:rhino /opt/himbo-express"

rsync -az --delete \
  -e "ssh ${SSH_OPTS[*]}" \
  --exclude '.git/' \
  --exclude '.env' \
  --exclude 'api/.env' \
  --exclude 'api/storage/logs/*' \
  --exclude 'api/storage/framework/cache/*' \
  --exclude 'api/storage/framework/sessions/*' \
  --exclude 'api/storage/framework/views/*' \
  --exclude 'console/node_modules/' \
  --exclude 'console/dist/' \
  --exclude 'docker/database/mysql/' \
  ./ "$REMOTE_HOST:$REMOTE_ROOT/"

ssh "${SSH_OPTS[@]}" "$REMOTE_HOST" "bash -s" <<'REMOTE'
set -euo pipefail

REMOTE_ROOT="/opt/himbo-express/current"
REMOTE_SECRETS_DIR="/opt/himbo-express/secrets"
REMOTE_BACKUP_DIR="/root/2rhino-backups"
COMPOSE_FILE="docker-compose.himbo-express.prod.yml"

if ! command -v docker >/dev/null 2>&1; then
  sudo apt-get update
  sudo apt-get install -y ca-certificates curl gnupg
  sudo install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  sudo chmod a+r /etc/apt/keyrings/docker.gpg
  . /etc/os-release
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" | sudo tee /etc/apt/sources.list.d/docker.list >/dev/null
  sudo apt-get update
  sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi

sudo systemctl enable --now docker

if [ ! -f "$REMOTE_SECRETS_DIR/app.env" ]; then
  app_key="base64:$(openssl rand -base64 32)"
  mysql_root_password="$(openssl rand -base64 36 | tr -d '\n')"
  sudo install -m 0700 -d "$REMOTE_SECRETS_DIR"
  sudo tee "$REMOTE_SECRETS_DIR/app.env" >/dev/null <<EOF
APP_KEY=$app_key
HIMBO_MYSQL_ROOT_PASSWORD=$mysql_root_password
EOF
  sudo chmod 0600 "$REMOTE_SECRETS_DIR/app.env"
fi

missing=0
for required_key in HIMBO_MYSQL_ROOT_PASSWORD HIMBO_OSRM_HOST; do
  if ! sudo grep -Eq "^${required_key}=.+" "$REMOTE_SECRETS_DIR/app.env"; then
    echo "Missing required production secret/config in $REMOTE_SECRETS_DIR/app.env: $required_key" >&2
    missing=1
  fi
done
if [ "$missing" -ne 0 ]; then
  exit 2
fi

compose=(sudo docker compose --env-file "$REMOTE_SECRETS_DIR/app.env" -f "$COMPOSE_FILE")
mysql_root_password="$(sudo sed -n 's/^HIMBO_MYSQL_ROOT_PASSWORD=//p' "$REMOTE_SECRETS_DIR/app.env" | tail -n 1)"
mysql_root_password_sql="${mysql_root_password//\'/\'\'}"
osrm_host="$(sudo sed -n 's/^HIMBO_OSRM_HOST=//p' "$REMOTE_SECRETS_DIR/app.env" | tail -n 1)"
self_hosted_routing=0

if [ "$osrm_host" = "http://routing:5000" ]; then
  self_hosted_routing=1
  compose=(sudo docker compose --profile self-hosted-routing --env-file "$REMOTE_SECRETS_DIR/app.env" -f "$COMPOSE_FILE")
  if [ ! -s /opt/himbo-express/routing/himbo-florida.osrm ]; then
    echo "HIMBO_OSRM_HOST is set to self-hosted routing, but /opt/himbo-express/routing/himbo-florida.osrm is missing." >&2
    echo "Run scripts/himbo-express-prepare-osrm.sh before deploying the self-hosted routing lane." >&2
    exit 2
  fi
fi

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
sudo mkdir -p "$REMOTE_BACKUP_DIR"
sudo tar -czf "$REMOTE_BACKUP_DIR/himbo-express-pre-dispatch-$stamp.tar.gz" \
  /home/wehimboexpress/htdocs/himbo.express \
  /etc/nginx/sites-enabled/himbo.express.conf \
  /etc/nginx/sites-available/himbo.express.conf 2>/dev/null || true

cd "$REMOTE_ROOT"
if [ "$self_hosted_routing" -eq 1 ]; then
  "${compose[@]}" up -d routing
fi
"${compose[@]}" up -d --build database cache socket
if "${compose[@]}" exec -T database mysql -uroot -e "SELECT 1" >/dev/null 2>&1; then
  "${compose[@]}" exec -T database mysql -uroot -e "ALTER USER 'root'@'%' IDENTIFIED BY '$mysql_root_password_sql'; ALTER USER 'root'@'localhost' IDENTIFIED BY '$mysql_root_password_sql'; FLUSH PRIVILEGES;" || true
fi
"${compose[@]}" up -d --build application httpd console
"${compose[@]}" exec -T application php artisan mysql:createdb
"${compose[@]}" exec -T application php artisan migrate --force
"${compose[@]}" exec -T application php artisan sandbox:migrate --force
"${compose[@]}" exec -T application php artisan fleetbase:seed
"${compose[@]}" exec -T application php artisan fleetbase:create-permissions
"${compose[@]}" exec -T application php artisan queue:restart
"${compose[@]}" exec -T application php artisan schedule-monitor:sync || true
"${compose[@]}" exec -T application php artisan cache:clear
"${compose[@]}" exec -T application php artisan route:clear
"${compose[@]}" exec -T application php artisan config:cache
# Route caching currently exhausts the WORLDENGINE VM PHP memory limit on this
# Fleetbase bundle. Leave routes uncached; route:clear keeps the live bridge fresh.
"${compose[@]}" exec -T application php artisan registry:init || true
"${compose[@]}" up -d queue scheduler

sudo tee /etc/nginx/sites-enabled/himbo.express.conf >/dev/null <<'NGINX'
server {
  listen 80;
  listen [::]:80;
  listen 443 quic;
  listen 443 ssl;
  listen [::]:443 quic;
  listen [::]:443 ssl;
  http2 on;
  http3 off;
  ssl_certificate_key /etc/nginx/ssl-certificates/himbo.express.key;
  ssl_certificate /etc/nginx/ssl-certificates/himbo.express.crt;
  server_name www.himbo.express;
  return 301 https://himbo.express$request_uri;
}

server {
  listen 80;
  listen [::]:80;
  listen 443 quic;
  listen 443 ssl;
  listen [::]:443 quic;
  listen [::]:443 ssl;
  http2 on;
  http3 off;
  ssl_certificate_key /etc/nginx/ssl-certificates/himbo.express.key;
  ssl_certificate /etc/nginx/ssl-certificates/himbo.express.crt;
  server_name himbo.express www1.himbo.express;

  access_log /home/wehimboexpress/logs/nginx/access.log main;
  error_log /home/wehimboexpress/logs/nginx/error.log;

  include /etc/nginx/global_settings;

  root /opt/himbo-express/current/public-himbo;
  index index.html;

  location ^~ /.well-known/ {
    root /home/wehimboexpress/htdocs/himbo.express;
    allow all;
  }

  location ^~ /auth/rhino-id {
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_pass http://127.0.0.1:18080;
  }

  location ^~ /int/v1 {
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_pass http://127.0.0.1:18080;
  }

  location = /login {
    return 302 https://id.2rhino.com/login?next=%2Flaunch%2Fhimbo-express;
  }

  location = /ops {
    return 302 /console;
  }

  location ^~ /dispatch/rhino-id {
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_pass http://127.0.0.1:14200;
  }

  location ^~ /console {
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_pass http://127.0.0.1:14200;
  }

  location ^~ /assets/ {
    proxy_set_header Host $host;
    proxy_pass http://127.0.0.1:14200;
  }

  location ^~ /engines-dist/ {
    proxy_set_header Host $host;
    proxy_pass http://127.0.0.1:14200;
  }

  location ^~ /favicon/ {
    proxy_set_header Host $host;
    proxy_pass http://127.0.0.1:14200;
  }

  location = /fleetbase.config.json {
    proxy_set_header Host $host;
    proxy_pass http://127.0.0.1:14200;
  }

  location ^~ /socketcluster/ {
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_pass http://127.0.0.1:18100;
  }

  location / {
    try_files $uri $uri/ /index.html;
  }
}
NGINX

if [ -d /etc/nginx/sites-available ]; then
  sudo cp /etc/nginx/sites-enabled/himbo.express.conf /etc/nginx/sites-available/himbo.express.conf
fi
sudo nginx -t
sudo systemctl reload nginx
REMOTE
