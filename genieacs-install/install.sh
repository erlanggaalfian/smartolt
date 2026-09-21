#!/bin/bash
# =============================================================================
# GenieACS + MongoDB Install Script for SmartOLT Integration
# Tested on: Debian 12 (bookworm), QEMU CPU (no AVX)
# Usage: sudo bash install.sh
# =============================================================================

set -euo pipefail

echo "=== GenieACS + MongoDB Installer ==="
echo ""

# --- Check root ---
if [[ $EUID -ne 0 ]]; then
    echo "ERROR: Jalankan sebagai root: sudo bash install.sh"
    exit 1
fi

# --- System packages ---
echo "[1/7] Install dependencies..."
apt-get update -qq
apt-get install -y -qq gnupg curl wget

# --- MongoDB 4.4 (last version without AVX requirement) ---
echo "[2/7] Install MongoDB 4.4..."
if command -v mongod &>/dev/null; then
    echo "  MongoDB sudah terinstall: $(mongod --version | head -1)"
else
    # libssl1.1 dependency (not in Debian 12)
    if ! dpkg -l | grep -q libssl1.1; then
        echo "  Install libssl1.1..."
        wget -q http://archive.debian.org/debian/pool/main/o/openssl/libssl1.1_1.1.1w-0+deb11u1_amd64.deb -O /tmp/libssl1.1.deb
        dpkg -i /tmp/libssl1.1.deb
    fi

    # Add MongoDB 4.4 repo
    curl -fsSL https://www.mongodb.org/static/pgp/server-4.4.asc | gpg --dearmor -o /usr/share/keyrings/mongodb-server-4.4.gpg
    echo "deb [ signed-by=/usr/share/keyrings/mongodb-server-4.4.gpg ] http://repo.mongodb.org/apt/debian buster/mongodb-org/4.4 main" > /etc/apt/sources.list.d/mongodb-org-4.4.list
    apt-get update -qq
    apt-get install -y mongodb-org-server=4.4.29 mongodb-org-shell=4.4.29 mongodb-org-tools=4.4.29
fi

# Start MongoDB
systemctl enable mongod
systemctl start mongod
echo "  MongoDB: $(mongosh --eval 'db.version()' --quiet 2>/dev/null || mongo --eval 'db.version()' --quiet)"

# --- GenieACS ---
echo "[3/7] Install GenieACS..."
if command -v genieacs-cwmp &>/dev/null; then
    echo "  GenieACS sudah terinstall"
else
    npm install -g genieacs@1.2.13
fi

# --- Config ---
echo "[4/7] Buat config..."
mkdir -p /opt/genieacs/ext /var/log/genieacs

cat > /opt/genieacs/genieacs.env << 'ENVEOF'
GENIEACS_CWMP_INTERFACE=0.0.0.0
GENIEACS_CWMP_PORT=7547
GENIEACS_NBI_INTERFACE=0.0.0.0
GENIEACS_NBI_PORT=7559
GENIEACS_FS_INTERFACE=0.0.0.0
GENIEACS_FS_PORT=7567
GENIEACS_MONGODB_CONNECTION_URL=mongodb://localhost:27017/genieacs
GENIEACS_EXT_DIR=/opt/genieacs/ext
GENIEACS_DEBUG=false
ENVEOF

# --- Systemd services ---
echo "[5/7] Buat systemd services..."

NODE_BIN=$(which node)
GACS_BIN=$(dirname $(readlink -f $(which genieacs-cwmp)))

for SVC in cwmp nbi fs; do
    cat > /etc/systemd/system/genieacs-${SVC}.service << SVCEOF
[Unit]
Description=GenieACS ${SVC^^}
After=network.target mongod.service
Requires=mongod.service

[Service]
EnvironmentFile=/opt/genieacs/genieacs.env
ExecStart=${NODE_BIN} ${GACS_BIN}/genieacs-${SVC}
Restart=always
RestartSec=5
WorkingDirectory=/opt/genieacs

[Install]
WantedBy=multi-user.target
SVCEOF
done

systemctl daemon-reload
systemctl enable genieacs-cwmp genieacs-nbi genieacs-fs
systemctl restart genieacs-cwmp genieacs-nbi genieacs-fs

echo "[6/7] Tunggu services start..."
sleep 3

# --- Verify ---
echo "[7/7] Verifikasi..."
echo ""
echo "  MongoDB:  $(systemctl is-active mongod) | port 27017"
echo "  CWMP:     $(systemctl is-active genieacs-cwmp) | port 7547 (TR-069)"
echo "  NBI:      $(systemctl is-active genieacs-nbi) | port 7559 (REST API)"
echo "  FS:       $(systemctl is-active genieacs-fs) | port 7567"
echo ""

# Test API
DEVICES=$(curl -s http://localhost:7559/devices 2>/dev/null | python3 -c "import sys,json; print(len(json.load(sys.stdin)))" 2>/dev/null || echo "?")
echo "  Devices terdaftar: $DEVICES"
echo ""
echo "=== Install selesai ==="
echo ""
echo "Next steps:"
echo "  1. Buka firewall port 7547 untuk TR-069 dari ONU"
echo "  2. Set TR069 ACS URL di OLT: http://<IP_SERVER>:7547"
echo "  3. Integrasi dengan SmartOLT via REST API di port 7559"
