#!/bin/bash

# ==============================================================================
# Script Instalasi SmartOLT (GPON/EPON Management System) - PHP + Apache2
# ==============================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "${BLUE}======================================================================${NC}"
echo -e "${BLUE}                      MEMULAI INSTALASI SMARTOLT                      ${NC}"
echo -e "${BLUE}======================================================================${NC}"

if [ "$EUID" -ne 0 ]; then
  echo -e "${RED}[ERROR] Harap jalankan script ini sebagai root (sudo ./install.sh).${NC}"
  exit 1
fi

CURRENT_DIR=$(pwd)

# ==============================================================================
# DETEKSI BACKUP SQL YANG ADA
# ==============================================================================
LOGIN_USER="${SUDO_USER:-$USER}"
[ "$LOGIN_USER" = "root" ] && LOGIN_USER="root"
BACKUP_BASE_DIR="/home/${LOGIN_USER}/smartolt_backup"

echo -e "\n${CYAN}Mencari backup SQL di ${BACKUP_BASE_DIR}...${NC}"
BACKUP_FILES=()
if [ -d "$BACKUP_BASE_DIR" ]; then
  while IFS= read -r -d '' file; do
    BACKUP_FILES+=("$file")
  done < <(find "$BACKUP_BASE_DIR" -name "smartolt_*.sql" -type f -print0 2>/dev/null | sort -z)
fi

DETECTED_IMPORT=""
if [ ${#BACKUP_FILES[@]} -gt 0 ]; then
  echo -e "   ${GREEN}Ditemukan ${#BACKUP_FILES[@]} file backup:${NC}"
  for i in "${!BACKUP_FILES[@]}"; do
    FSIZE=$(du -h "${BACKUP_FILES[$i]}" | cut -f1)
    FDATE=$(stat -c '%y' "${BACKUP_FILES[$i]}" 2>/dev/null | cut -d'.' -f1)
    echo -e "      ${BLUE}$((i+1))${NC}. $(basename "${BACKUP_FILES[$i]}") (${FSIZE}, ${FDATE})"
  done
  echo -e "      ${BLUE}0${NC}. Install baru tanpa backup"
  echo ""
  read -p "   Pilih nomor backup untuk di-restore [0 = install baru]: " BACKUP_CHOICE
  BACKUP_CHOICE=${BACKUP_CHOICE:-0}

  if [[ "$BACKUP_CHOICE" =~ ^[0-9]+$ ]] && [ "$BACKUP_CHOICE" -gt 0 ] && [ "$BACKUP_CHOICE" -le ${#BACKUP_FILES[@]} ]; then
    DETECTED_IMPORT="${BACKUP_FILES[$((BACKUP_CHOICE-1))]}"
    echo -e "   ${GREEN}[OK] Akan merestore dari: $(basename "$DETECTED_IMPORT")${NC}"
  else
    echo -e "   ${BLUE}[INFO] Install baru tanpa restore backup.${NC}"
  fi
else
  echo -e "   ${BLUE}[INFO] Tidak ada backup SQL ditemukan.${NC}"
fi

# ==============================================================================
# KONFIGURASI PARAMETER
# ==============================================================================
echo -e "\n${CYAN}>>> KONFIGURASI PARAMETER INSTALASI:${NC}"
echo -e "--------------------------------------------------"

read -p "   * Domain Web / IP Publik [default: localhost]: " APP_DOMAIN
APP_DOMAIN=${APP_DOMAIN:-"localhost"}
TARGET_DIR="/var/www/${APP_DOMAIN}"

read -p "   * Aktifkan HTTPS? (y/N): " choice_https
if [[ "$choice_https" =~ ^[Yy]$ ]]; then
  if [ "$APP_DOMAIN" = "localhost" ] || [ "$APP_DOMAIN" = "127.0.0.1" ]; then
    echo -e "     ${YELLOW}! Let's Encrypt butuh domain publik. HTTPS dinonaktifkan.${NC}"
    APP_PROTO="http"; DEFAULT_EXT_PORT="80"
  else
    APP_PROTO="https"; DEFAULT_EXT_PORT="443"
    read -p "   * Email untuk sertifikat Let's Encrypt: " CERTBOT_EMAIL
    CERTBOT_EMAIL=${CERTBOT_EMAIL:-""}
  fi
else
  APP_PROTO="http"; DEFAULT_EXT_PORT="80"
fi

read -p "   * Port Web Server [default: ${DEFAULT_EXT_PORT}]: " EXT_PORT
EXT_PORT=${EXT_PORT:-$DEFAULT_EXT_PORT}

if ! command -v mysql &> /dev/null; then
  echo -e "\n${YELLOW}Menginstal client MySQL...${NC}"
  apt-get update &> /dev/null && apt-get install -y default-mysql-client &> /dev/null
fi

echo -e "\n   Koneksi Database MySQL:"
read -p "   Host [localhost]: " DB_HOST; DB_HOST=${DB_HOST:-"localhost"}
read -p "   Port [3306]: " DB_PORT; DB_PORT=${DB_PORT:-"3306"}
read -p "   Database Name [smartoltdb]: " DB_NAME; DB_NAME=${DB_NAME:-"smartoltdb"}
read -p "   Database User [smartoltuser]: " DB_USER; DB_USER=${DB_USER:-"smartoltuser"}

read -s -p "   Password database (kosong = auto generate): " DB_PASS; echo ""
if [ -z "$DB_PASS" ]; then
  DB_PASS=$(head /dev/urandom | tr -dc A-Za-z0-9 | head -c 24)
  echo -e "   Password auto-generated: ${GREEN}${DB_PASS}${NC}"
fi

echo -e "\n   Password root MariaDB/MySQL (kosong = auth socket):"
read -s -p "   MySQL root password: " MYSQL_ROOT_PASS; echo ""

if [ -n "$MYSQL_ROOT_PASS" ]; then
  mysql -h "${DB_HOST}" -P "${DB_PORT}" -u root -p"${MYSQL_ROOT_PASS}" -e "QUIT" &> /dev/null
  [ $? -ne 0 ] && { echo -e "      ${RED}[ERROR] Koneksi root MySQL gagal.${NC}"; exit 1; }
  MYSQL_ROOT_CMD="mysql -h ${DB_HOST} -P ${DB_PORT} -u root -p${MYSQL_ROOT_PASS}"
else
  mysql -h "${DB_HOST}" -P "${DB_PORT}" -u root -e "QUIT" &> /dev/null
  [ $? -ne 0 ] && { echo -e "      ${RED}[ERROR] Koneksi root MySQL gagal.${NC}"; exit 1; }
  MYSQL_ROOT_CMD="mysql -h ${DB_HOST} -P ${DB_PORT} -u root"
fi
echo -e "      [OK] Koneksi root MySQL terverifikasi."

# Jika ada backup terdeteksi, tawarkan juga input manual
IMPORT_SQL_PATH="$DETECTED_IMPORT"
if [ -z "$IMPORT_SQL_PATH" ]; then
  read -p "   Path file SQL dump manual [kosongkan jika tidak ada]: " MANUAL_SQL
  if [ -n "$MANUAL_SQL" ]; then
    if [[ "$MANUAL_SQL" != /* ]]; then
      MANUAL_SQL="${CURRENT_DIR}/${MANUAL_SQL}"
    fi
    if [ -f "$MANUAL_SQL" ]; then
      IMPORT_SQL_PATH="$MANUAL_SQL"
    else
      echo -e "   ${RED}[WARNING] File '${MANUAL_SQL}' tidak ditemukan.${NC}"
    fi
  fi
fi

# Auto-detect port (mulai dari base, +1 jika dipakai)
find_free_port() {
  local port=$1
  while ss -tlnp | grep -q ":${port} " 2>/dev/null; do
    port=$((port+1))
  done
  echo $port
}

ENGINE_PORT=$(find_free_port 8000)
JS_API_PORT=$(find_free_port 3002)

# Ringkasan
APACHE_CONF_PATH="/etc/apache2/sites-available/${APP_DOMAIN}.conf"
echo -e "\n${CYAN}┌──────────────────────────────────────────────────────────────────────┐${NC}"
echo -e "${CYAN}│                      RINGKASAN KONFIGURASI                           │${NC}"
echo -e "${CYAN}├──────────────────────────────────────────────────────────────────────┤${NC}"
printf "${CYAN}│${NC}  %-24s : ${BLUE}%-38s${NC}${CYAN}│${NC}\n" "Domain Web" "${APP_DOMAIN}"
printf "${CYAN}│${NC}  %-24s : ${BLUE}%-38s${NC}${CYAN}│${NC}\n" "Protokol & Port" "${APP_PROTO}://${APP_DOMAIN}:${EXT_PORT}"
printf "${CYAN}│${NC}  %-24s : ${BLUE}%-38s${NC}${CYAN}│${NC}\n" "Web Root (DocumentRoot)" "${TARGET_DIR}/frontend"
printf "${CYAN}│${NC}  %-24s : ${BLUE}%-38s${NC}${CYAN}│${NC}\n" "Backend Dir" "${TARGET_DIR}/backend"
printf "${CYAN}│${NC}  %-24s : ${BLUE}%-38s${NC}${CYAN}│${NC}\n" "Apache Virtual Host" "${APACHE_CONF_PATH}"
printf "${CYAN}│${NC}  %-24s : ${BLUE}%-38s${NC}${CYAN}│${NC}\n" "Config File (.env)" "${TARGET_DIR}/.env"
printf "${CYAN}│${NC}  %-24s : ${BLUE}%-38s${NC}${CYAN}│${NC}\n" "Python Engine Dir" "${TARGET_DIR}/backend/python_engine"
printf "${CYAN}│${NC}  %-24s : ${BLUE}%-38s${NC}${CYAN}│${NC}\n" "Engine Port" "127.0.0.1:${ENGINE_PORT}"
printf "${CYAN}│${NC}  %-24s : ${BLUE}%-38s${NC}${CYAN}│${NC}\n" "JS API Port" "127.0.0.1:${JS_API_PORT}"
printf "${CYAN}│${NC}  %-24s : ${BLUE}%-38s${NC}${CYAN}│${NC}\n" "Systemd Service" "smartolt-python.service, smartolt-js-api.service"
printf "${CYAN}│${NC}  %-24s : ${BLUE}%-38s${NC}${CYAN}│${NC}\n" "Cron Job" "/etc/cron.d/smartolt-sync"
printf "${CYAN}│${NC}  %-24s : ${BLUE}%-38s${NC}${CYAN}│${NC}\n" "Database" "${DB_NAME} @ ${DB_HOST}:${DB_PORT}"
printf "${CYAN}│${NC}  %-24s : ${BLUE}%-38s${NC}${CYAN}│${NC}\n" "DB User" "${DB_USER}"
if [ -n "$IMPORT_SQL_PATH" ]; then
  printf "${CYAN}│${NC}  %-24s : ${GREEN}%-38s${NC}${CYAN}│${NC}\n" "Restore Backup" "$(basename "$IMPORT_SQL_PATH")"
  printf "${CYAN}│${NC}  %-24s : ${GREEN}%-38s${NC}${CYAN}│${NC}\n" "Backup Path" "${IMPORT_SQL_PATH}"
else
  printf "${CYAN}│${NC}  %-24s : ${BLUE}%-38s${NC}${CYAN}│${NC}\n" "Database Mode" "Install Baru (Skema Kosong)"
fi
echo -e "${CYAN}└──────────────────────────────────────────────────────────────────────┘${NC}"

read -p "   Konfigurasi sudah sesuai? (Y/n): " confirm_config
[[ "$confirm_config" =~ ^[Nn]$ ]] && { echo -e "${RED}Instalasi dibatalkan.${NC}"; exit 0; }

# ==============================================================================
# 1/6 — INSTAL DEPENDENSI SISTEM
# ==============================================================================
echo -e "\n${YELLOW}[1/6] Menginstal dependensi sistem...${NC}"
apt-get update &> /dev/null
apt-get install -y apache2 php libapache2-mod-php php-mysql php-ssh2 php-snmp snmp sshpass default-mysql-client rsync &> /dev/null
echo -e "      ${GREEN}[OK] PHP, Apache2, SNMP, SSHPass terpasang.${NC}"

# ==============================================================================
# 2/6 — SALIN BERKAS
# ==============================================================================
echo -e "\n${YELLOW}[2/6] Menyalin berkas ke ${TARGET_DIR}...${NC}"
mkdir -p "${TARGET_DIR}"

rsync -a \
  --exclude='.git' \
  --exclude='.vscode' \
  --exclude='__pycache__' \
  --exclude='*.pyc' \
  --exclude='.env' \
  --exclude='smartolt_db_backup_*.sql' \
  --exclude='memory.txt' \
  --exclude='plan.txt' \
  --exclude='deskripsi.txt' \
  --exclude='hasildebug.txt' \
  --exclude='backup_olt.txt' \
  --exclude='next_update.txt' \
  --exclude='hybrid_architecture_plan.txt' \
  --exclude='migration_plan_all_pages.txt' \
  --exclude='update_security.txt' \
  "${CURRENT_DIR}/" "${TARGET_DIR}/"

chown -R www-data:www-data "${TARGET_DIR}"
chmod -R 755 "${TARGET_DIR}"
chmod -R 700 "${TARGET_DIR}/backend"
chown -R www-data:www-data "${TARGET_DIR}/backend"
[ -f "${TARGET_DIR}/update.sh" ] && chmod +x "${TARGET_DIR}/update.sh"
[ -f "${TARGET_DIR}/install.sh" ] && chmod +x "${TARGET_DIR}/install.sh"
[ -f "${TARGET_DIR}/uninstall.sh" ] && chmod +x "${TARGET_DIR}/uninstall.sh"
echo -e "      ${GREEN}[OK] Berkas berhasil disalin.${NC}"

# ==============================================================================
# 3/6 — SETUP PYTHON ENGINE
# ==============================================================================
echo -e "\n${YELLOW}[3/6] Mengonfigurasi Python Engine...${NC}"
if ! command -v python3 &> /dev/null; then
  apt-get install -y python3 python3-pip python3-venv &> /dev/null
fi
! dpkg -l | grep -q "python3-venv" && apt-get install -y python3-venv &> /dev/null

PYTHON_DIR="${TARGET_DIR}/backend/python_engine"
echo -e "      Python Engine port: ${ENGINE_PORT}"

if [ -d "$PYTHON_DIR" ]; then
  [ ! -d "${PYTHON_DIR}/venv" ] && python3 -m venv "${PYTHON_DIR}/venv" &> /dev/null
  "${PYTHON_DIR}/venv/bin/pip" install --upgrade pip &> /dev/null
  "${PYTHON_DIR}/venv/bin/pip" install netmiko fastapi uvicorn pydantic cryptography requests pymysql &> /dev/null
  chown -R www-data:www-data "$PYTHON_DIR"
  chmod -R 700 "$PYTHON_DIR"

  cat <<EOF > /etc/systemd/system/smartolt-python.service
[Unit]
Description=SmartOLT Python Network Automation Engine
After=network.target

[Service]
User=www-data
EnvironmentFile=${TARGET_DIR}/.env
WorkingDirectory=${PYTHON_DIR}
ExecStart=${PYTHON_DIR}/venv/bin/uvicorn app:app --host 127.0.0.1 --port ${ENGINE_PORT}
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
  chmod 644 /etc/systemd/system/smartolt-python.service
  systemctl daemon-reload
  systemctl enable smartolt-python &> /dev/null
  systemctl restart smartolt-python &> /dev/null
  echo -e "      ${GREEN}[OK] Python Engine dijalankan.${NC}"
else
  echo -e "      ${YELLOW}[WARNING] Direktori Python engine tidak ditemukan.${NC}"
fi

# =============================================================================
# 3b/6 — SETUP JS API (Express)
# =============================================================================
echo -e "\n${YELLOW}[3b/6] Mengonfigurasi JS API (Express)...${NC}"
if ! command -v node &> /dev/null; then
  echo -e "      ${RED}[ERROR] Node.js tidak ditemukan. Instal Node.js terlebih dahulu.${NC}"
else
  JS_API_DIR="${TARGET_DIR}/backend/js-api"
  echo -e "      JS API port: ${JS_API_PORT}"

  if [ -d "$JS_API_DIR" ] && [ -f "$JS_API_DIR/package.json" ]; then
    cd "$JS_API_DIR"
    npm install --production &> /dev/null
    chown -R www-data:www-data "$JS_API_DIR"

    cat <<EOF > /etc/systemd/system/smartolt-js-api.service
[Unit]
Description=SmartOLT JS API (Express)
After=network.target

[Service]
User=www-data
EnvironmentFile=${TARGET_DIR}/.env
WorkingDirectory=${JS_API_DIR}
ExecStart=/usr/bin/node server.js
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
    chmod 644 /etc/systemd/system/smartolt-js-api.service
    systemctl daemon-reload
    systemctl enable smartolt-js-api &> /dev/null
    systemctl restart smartolt-js-api &> /dev/null
    echo -e "      ${GREEN}[OK] JS API dijalankan.${NC}"
  else
    echo -e "      ${YELLOW}[WARNING] Direktori JS API tidak ditemukan.${NC}"
  fi
fi

# ==============================================================================
# 4/6 — KONFIGURASI DATABASE
# ==============================================================================
echo -e "\n${YELLOW}[4/6] Mengonfigurasi database MySQL...${NC}"

$MYSQL_ROOT_CMD -e "
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
" &> /dev/null

if [ -n "$IMPORT_SQL_PATH" ] && [ -f "$IMPORT_SQL_PATH" ]; then
  # Restore dari backup: import SQL (skema + data sekaligus)
  echo -e "      Merestore database dari: $(basename "$IMPORT_SQL_PATH")..."
  $MYSQL_ROOT_CMD "${DB_NAME}" < "$IMPORT_SQL_PATH" &> /dev/null
  [ $? -eq 0 ] && echo -e "      ${GREEN}[OK] Database berhasil direstore.${NC}" || echo -e "      ${RED}[ERROR] Gagal merestore database.${NC}"
else
  # Install baru: buat skema tabel
  echo -e "      Membuat skema tabel..."
  $MYSQL_ROOT_CMD "${DB_NAME}" -e "
CREATE TABLE IF NOT EXISTS olts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    ip              VARCHAR(45)  NOT NULL UNIQUE,
    ssh_port        SMALLINT     NOT NULL DEFAULT 22,
    username        VARCHAR(64)  NOT NULL,
    password        VARCHAR(128) NOT NULL,
    protocol        VARCHAR(10)  NOT NULL DEFAULT 'SSH',
    snmp_port       SMALLINT     NOT NULL DEFAULT 161,
    snmp_community  VARCHAR(64)  NOT NULL DEFAULT 'public',
    snmp_community_rw VARCHAR(64) NOT NULL DEFAULT 'public',
    type            VARCHAR(50)  NOT NULL DEFAULT 'GPON',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS onus (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    olt_id          INT          NOT NULL,
    pon_port        VARCHAR(16)  NOT NULL,
    onu_id          SMALLINT     NOT NULL,
    name            VARCHAR(128) NOT NULL,
    serial_number   VARCHAR(32)  NOT NULL UNIQUE,
    vlan            SMALLINT,
    status          ENUM('online','offline') NOT NULL DEFAULT 'offline',
    last_rx_power   DECIMAL(6,2),
    pppoe_username  VARCHAR(128) NULL,
    pppoe_password  VARCHAR(128) NULL,
    onu_type        VARCHAR(64) NULL DEFAULT 'ALL-ONT',
    zone            VARCHAR(64) NULL,
    splitter        VARCHAR(128) NULL,
    address         TEXT NULL,
    contact         VARCHAR(64) NULL,
    onu_mode        VARCHAR(32) NULL DEFAULT 'Routing',
    wan_mode        VARCHAR(32) NULL DEFAULT 'PPPoE',
    config_method   VARCHAR(32) NULL DEFAULT 'OMCI',
    ip_protocol     VARCHAR(32) NULL DEFAULT 'IPv4',
    wan_remote_access VARCHAR(10) NULL DEFAULT 'no',
    mgmt_ip_mode    VARCHAR(32) NULL DEFAULT 'Inactive',
    mgmt_ip         VARCHAR(45) NULL,
    mgmt_vlan       SMALLINT NULL,
    allow_remote_mgmt VARCHAR(10) NULL DEFAULT 'no',
    pppoe_ip        VARCHAR(45) NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (olt_id) REFERENCES olts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    olt_id      INT,
    action      VARCHAR(64)  NOT NULL,
    message     TEXT         NOT NULL,
    timestamp   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_olt_id (olt_id),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(64)  NOT NULL UNIQUE,
    password        VARCHAR(255) NOT NULL,
    role            ENUM('superadmin','biasa') NOT NULL DEFAULT 'biasa',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
" &> /dev/null
  [ $? -eq 0 ] && echo -e "      ${GREEN}[OK] Skema tabel berhasil dibuat.${NC}" || echo -e "      ${RED}[ERROR] Gagal membuat skema tabel.${NC}"
fi

# ==============================================================================
# 5/6 — BUAT .env & KONFIGURASI APACHE
# ==============================================================================
echo -e "\n${YELLOW}[5/6] Mengonfigurasi .env & Apache...${NC}"

APP_KEY=$(head /dev/urandom | tr -dc A-Za-z0-9 | head -c 32)
cat <<EOF > "${TARGET_DIR}/.env"
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASSWORD=${DB_PASS}
APP_DOMAIN=${APP_DOMAIN}
APP_KEY=${APP_KEY}
ENGINE_PORT=${ENGINE_PORT}
JS_API_PORT=${JS_API_PORT}
EOF
chown www-data:www-data "${TARGET_DIR}/.env"
chmod 600 "${TARGET_DIR}/.env"
echo -e "      ${GREEN}[OK] .env berhasil dibuat.${NC}"

a2enmod ssl &> /dev/null
a2enmod headers &> /dev/null
a2enmod proxy &> /dev/null
a2enmod proxy_http &> /dev/null

if ! grep -q "Listen ${EXT_PORT}" /etc/apache2/ports.conf; then
  echo "Listen ${EXT_PORT}" >> /etc/apache2/ports.conf
fi

APACHE_CONF="/etc/apache2/sites-available/${APP_DOMAIN}.conf"
if [ "$APP_PROTO" = "https" ]; then
  cat <<EOF > "$APACHE_CONF"
<VirtualHost *:${EXT_PORT}>
    ServerName ${APP_DOMAIN}
    DocumentRoot ${TARGET_DIR}/frontend
    <Directory ${TARGET_DIR}/frontend>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Proxy /api/* to Express JS API
    ProxyPass /api/ http://127.0.0.1:${JS_API_PORT}/api/
    ProxyPassReverse /api/ http://127.0.0.1:${JS_API_PORT}/api/

    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/ssl-cert-snakeoil.pem
    SSLCertificateKeyFile /etc/ssl/private/ssl-cert-snakeoil.key
</VirtualHost>
EOF
else
  cat <<EOF > "$APACHE_CONF"
<VirtualHost *:${EXT_PORT}>
    ServerName ${APP_DOMAIN}
    DocumentRoot ${TARGET_DIR}/frontend
    <Directory ${TARGET_DIR}/frontend>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Proxy /api/* to Express JS API
    ProxyPass /api/ http://127.0.0.1:${JS_API_PORT}/api/
    ProxyPassReverse /api/ http://127.0.0.1:${JS_API_PORT}/api/
</VirtualHost>
EOF
fi

a2ensite "${APP_DOMAIN}.conf" &> /dev/null
a2dissite 000-default.conf &> /dev/null
systemctl restart apache2
echo -e "      ${GREEN}[OK] Apache2 dikonfigurasi.${NC}"

if [ "$APP_PROTO" = "https" ] && [ "$EXT_PORT" = "443" ]; then
  apt-get install -y certbot python3-certbot-apache &> /dev/null
  CERTBOT_EMAIL_FLAG="--register-unsafely-without-email"
  if [ -n "$CERTBOT_EMAIL" ]; then
    CERTBOT_EMAIL_FLAG="-m ${CERTBOT_EMAIL}"
  fi
  certbot --apache -d "${APP_DOMAIN}" --non-interactive --agree-tos ${CERTBOT_EMAIL_FLAG} &> /dev/null
  [ $? -eq 0 ] && echo -e "      ${GREEN}[OK] HTTPS Let's Encrypt aktif.${NC}" || echo -e "      ${YELLOW}[WARNING] Certbot gagal. Pakai SSL fallback.${NC}"
fi

# ==============================================================================
# 6/6 — CRON JOB & FINALISASI
# ==============================================================================
echo -e "\n${YELLOW}[6/6] Mengonfigurasi Cron Job & Finalisasi...${NC}"
CRON_FILE="/etc/cron.d/smartolt-sync"
cat <<EOF > "$CRON_FILE"
* * * * * root ${TARGET_DIR}/backend/python_engine/venv/bin/python3 ${TARGET_DIR}/backend/python_engine/cron_sync.py > /dev/null 2>&1
EOF
chmod 644 "$CRON_FILE"
echo -e "      ${GREEN}[OK] Cron job didaftarkan.${NC}"

if [ -f "${TARGET_DIR}/deploy/systemd/smartolt-flush-write.service" ]; then
  sed "s#/var/www/smartolt.netbackup.web.id#${TARGET_DIR}#g" \
    "${TARGET_DIR}/deploy/systemd/smartolt-flush-write.service" > /etc/systemd/system/smartolt-flush-write.service
  cp "${TARGET_DIR}/deploy/systemd/smartolt-flush-write.timer" /etc/systemd/system/smartolt-flush-write.timer
  systemctl daemon-reload
  systemctl enable --now smartolt-flush-write.timer >/dev/null 2>&1
  echo -e "      ${GREEN}[OK] Timer flush-write systemd aktif (tiap 10 menit).${NC}"
fi

systemctl restart apache2

echo -e "\n${GREEN}======================================================================${NC}"
echo -e "${GREEN}        INSTALASI SMARTOLT BERHASIL SELESAI & AKTIF!                  ${NC}"
echo -e "${GREEN}======================================================================${NC}"
echo -e "\n   Akses panel: ${BLUE}${APP_PROTO}://${APP_DOMAIN}:${EXT_PORT}${NC}"
echo -e "   Web root   : ${YELLOW}${TARGET_DIR}/frontend${NC}"
echo -e "   Config     : ${YELLOW}${TARGET_DIR}/.env${NC}"
if [ -n "$IMPORT_SQL_PATH" ]; then
  echo -e "   Database   : ${GREEN}Direstore dari $(basename "$IMPORT_SQL_PATH")${NC}"
fi
echo ""
