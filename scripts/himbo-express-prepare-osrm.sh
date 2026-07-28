#!/usr/bin/env bash
set -euo pipefail

REMOTE_HOST="${REMOTE_HOST:-rhino@20.124.38.43}"
SSH_KEY="${SSH_KEY:-/Users/rhino/.ssh/rhinoos-atlas12-builder}"
REMOTE_ROUTING_DIR="${REMOTE_ROUTING_DIR:-/opt/himbo-express/routing}"
REMOTE_BACKUP_DIR="${REMOTE_BACKUP_DIR:-/root/2rhino-backups}"
OSRM_IMAGE="${OSRM_IMAGE:-osrm/osrm-backend:v5.25.0}"
PBF_URL="${PBF_URL:-https://download.geofabrik.de/north-america/us/florida-latest.osm.pbf}"
PBF_NAME="${PBF_NAME:-himbo-florida.osm.pbf}"
OSRM_BASENAME="${OSRM_BASENAME:-himbo-florida}"
SSH_OPTS=(-i "$SSH_KEY" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new)

ssh "${SSH_OPTS[@]}" "$REMOTE_HOST" "bash -s" <<REMOTE
set -euo pipefail

REMOTE_ROUTING_DIR="$REMOTE_ROUTING_DIR"
REMOTE_BACKUP_DIR="$REMOTE_BACKUP_DIR"
OSRM_IMAGE="$OSRM_IMAGE"
PBF_URL="$PBF_URL"
PBF_NAME="$PBF_NAME"
OSRM_BASENAME="$OSRM_BASENAME"

sudo mkdir -p "\$REMOTE_ROUTING_DIR" "\$REMOTE_BACKUP_DIR"
sudo chown -R rhino:rhino "\$REMOTE_ROUTING_DIR"
cd "\$REMOTE_ROUTING_DIR"

stamp="\$(date -u +%Y%m%dT%H%M%SZ)"
if ls "\${OSRM_BASENAME}.osrm"* >/dev/null 2>&1; then
  sudo tar -czf "\$REMOTE_BACKUP_DIR/himbo-express-osrm-pre-\$stamp.tar.gz" "\${OSRM_BASENAME}.osrm"* 2>/dev/null || true
fi

if [ ! -s "\$PBF_NAME" ]; then
  curl -fL --retry 3 --retry-delay 5 -o "\$PBF_NAME.tmp" "\$PBF_URL"
  mv "\$PBF_NAME.tmp" "\$PBF_NAME"
fi

sudo docker pull "\$OSRM_IMAGE"
sudo docker run --rm -t -v "\$REMOTE_ROUTING_DIR:/data" "\$OSRM_IMAGE" osrm-extract -p /opt/car.lua "/data/\$PBF_NAME"
sudo docker run --rm -t -v "\$REMOTE_ROUTING_DIR:/data" "\$OSRM_IMAGE" osrm-partition "/data/\${OSRM_BASENAME}.osrm"
sudo docker run --rm -t -v "\$REMOTE_ROUTING_DIR:/data" "\$OSRM_IMAGE" osrm-customize "/data/\${OSRM_BASENAME}.osrm"

test -s "\${OSRM_BASENAME}.osrm"
test -s "\${OSRM_BASENAME}.osrm.partition"
test -s "\${OSRM_BASENAME}.osrm.cells"

echo "prepared_osrm_data dir=\$REMOTE_ROUTING_DIR basename=\$OSRM_BASENAME image=\$OSRM_IMAGE source=\$PBF_URL"
echo "next: set HIMBO_OSRM_HOST=http://routing:5000 in /opt/himbo-express/secrets/app.env, then run ./deploy_himbo_express_worldengine.sh"
REMOTE
