#!/bin/bash

# ==============================================================================
# Script Pembaruan (Update) SmartOLT (Apache2 / PHP Version)
# ==============================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "${BLUE}======================================================================${NC}"
echo -e "${BLUE}                      MEMULAI PEMBARUAN SMARTOLT                      ${NC}"
echo -e "${BLUE}======================================================================${NC}"

if [ "$EUID" -ne 0 ]; then
  echo -e "${RED}[ERROR] Harap jalankan script ini sebagai root (sudo ./update.sh).${NC}"
  exit 1
fi

read -p "   Apakah Anda yakin ingin melanjutkan pembaruan aplikasi SmartOLT? (y/N): " confirm_update
if [[ ! "$confirm_update" =~ ^[Yy]$ ]]; then
  echo -e "${YELLOW}[INFO] Pembaruan dibatalkan oleh pengguna.${NC}"
  exit 0
fi

# ==============================================================================
# DETEKSI ENV & DOMAIN
# ==============================================================================
load_env_file() {
  local env_path="$1"
  if [ -f "$env_path" ]; then
    while IFS= read -r line || [ -n "$line" ]; do
      if [[ ! "$line" =~ ^# ]] && [[ -n "$line" ]]; then
        line=$(echo "$line" | tr -d '\r')
        export "$line"
      fi
    done < "$env_path"
  fi
}

load_env_file ".env"
[ -z "$APP_DOMAIN" ] && load_env_file "../.env"

if [ -z "$APP_DOMAIN" ] && [ -d "/etc/apache2/sites-available" ]; then
  for conf in /etc/apache2/sites-available/*.conf; do
    [[ "$(basename "$conf")" =~ ^000-|default|deny ]] && continue
    if [ -f "$conf" ] && grep -q "DocumentRoot" "$conf" && grep -q "frontend\|smartolt" "$conf"; then
      APP_DOMAIN=$(grep -i -oP 'ServerName\s+\K\S+' "$conf" | head -n 1)
      [ -z "$APP_DOMAIN" ] && APP_DOMAIN="$(basename "${conf%.conf}")"
      break
    fi
  done
fi

if [ -z "$APP_DOMAIN" ] && [ -d "/etc/apache2/sites-enabled" ]; then
  ACTIVE_SITE_CONF=$(ls /etc/apache2/sites-enabled/ | grep -vE "^000-|default|deny" | head -n 1 2>/dev/null)
  [ -n "$ACTIVE_SITE_CONF" ] && APP_DOMAIN="${ACTIVE_SITE_CONF%.conf}"
fi

if [ -z "$APP_DOMAIN" ]; then
  DETECTED_ENV=$(find /var/www -maxdepth 2 -type f -name ".env" | head -n 1 2>/dev/null)
  [ -n "$DETECTED_ENV" ] && APP_DOMAIN=$(grep -oP '^APP_DOMAIN=\K.*' "$DETECTED_ENV" | tr -d '\r')
fi

if [ -z "$APP_DOMAIN" ]; then
  DETECTED_DIR=$(find /var/www -maxdepth 2 -type d -name "frontend" | head -n 1 2>/dev/null)
  [ -n "$DETECTED_DIR" ] && APP_DOMAIN=$(basename "$(dirname "$DETECTED_DIR")")
fi

APP_DOMAIN=${APP_DOMAIN:-"localhost"}
TARGET_DIR="/var/www/${APP_DOMAIN}"
SOURCE_DIR="$(cd "$(dirname "$0")" && pwd)"

[ -z "$DB_NAME" ] && [ -f "${TARGET_DIR}/.env" ] && load_env_file "${TARGET_DIR}/.env"

if [ -f "${TARGET_DIR}/.env" ] && ! grep -q "^APP_KEY=" "${TARGET_DIR}/.env"; then
  echo "APP_KEY=$(head /dev/urandom | tr -dc A-Za-z0-9 | head -c 32)" >> "${TARGET_DIR}/.env"
  echo -e "      ${GREEN}[OK] Menambahkan APP_KEY baru ke berkas .env target.${NC}"
fi

echo -e "\n${CYAN}   Domain : ${APP_DOMAIN}${NC}"
echo -e "${CYAN}   Target : ${TARGET_DIR}${NC}"
echo -e "${CYAN}   Source : ${SOURCE_DIR}${NC}"

# ==============================================================================
# BACKUP DATABASE
# ==============================================================================
if [ -n "$DB_NAME" ] && [ -n "$DB_USER" ]; then
  read -p "   Backup database '${DB_NAME}' sebelum pembaruan? (Y/n): " confirm_backup
  confirm_backup=${confirm_backup:-"y"}
  if [[ "$confirm_backup" =~ ^[Yy]$ ]]; then
    read -s -p "      MySQL root password (kosong = auth socket): " MYSQL_ROOT_PASS
    echo ""
    if [ -n "$MYSQL_ROOT_PASS" ]; then
      MYSQL_DUMP_CMD="mysqldump -h ${DB_HOST} -P ${DB_PORT} -u root -p${MYSQL_ROOT_PASS}"
    else
      MYSQL_DUMP_CMD="mysqldump -h ${DB_HOST} -P ${DB_PORT} -u root"
    fi
    BACKUP_FILE="smartolt_db_backup_$(date +%Y%m%d_%H%M%S).sql"
    $MYSQL_DUMP_CMD "${DB_NAME}" > "${BACKUP_FILE}" < /dev/null 2>/dev/null
    if [ $? -eq 0 ]; then
      echo -e "      ${GREEN}[OK] Backup: $(pwd)/${BACKUP_FILE}${NC}"
    else
      [ -n "$DB_PASSWORD" ] && mysqldump -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" > "${BACKUP_FILE}" < /dev/null 2>/dev/null
      [ $? -eq 0 ] && echo -e "      ${GREEN}[OK] Backup: $(pwd)/${BACKUP_FILE}${NC}" || echo -e "      ${RED}[WARNING] Gagal backup. Melanjutkan...${NC}"
    fi
  fi
fi

# ==============================================================================
# 1/5 — HAPUS NODE.JS LAMA
# ==============================================================================
SERVICE_NAME="smartolt-${APP_DOMAIN//./-}"
echo -e "\n${YELLOW}[1/5] Membersihkan layanan Node.js lama...${NC}"
if systemctl list-units --full -all | grep -Fq "${SERVICE_NAME}.service"; then
  systemctl stop "${SERVICE_NAME}.service" &> /dev/null
  systemctl disable "${SERVICE_NAME}.service" &> /dev/null
  rm -f "/etc/systemd/system/${SERVICE_NAME}.service"
  systemctl daemon-reload
  echo -e "      ${GREEN}[OK] Layanan Node.js lama dibersihkan.${NC}"
else
  echo -e "      ${GREEN}[OK] Tidak ada layanan Node.js lama.${NC}"
fi

# ==============================================================================
# 2/5 — GIT PULL
# ==============================================================================
echo -e "\n${YELLOW}[2/5] Menarik pembaruan dari Git...${NC}"
if [ -d "${SOURCE_DIR}/.git" ]; then
  cd "${SOURCE_DIR}"
  git pull &> /dev/null
  [ $? -eq 0 ] && echo -e "      ${GREEN}[OK] Git pull berhasil.${NC}" || echo -e "      ${RED}[ERROR] Git pull gagal.${NC}"
else
  echo -e "      ${BLUE}[INFO] Bukan repositori git. Melompati.${NC}"
fi

# ==============================================================================
# 3/5 — GANTI SELURUH FILE DI TARGET
# ==============================================================================
echo -e "\n${YELLOW}[3/5] Mengganti seluruh berkas di ${TARGET_DIR}...${NC}"

# Cadangkan .env
ENV_BACKUP=""
if [ -f "${TARGET_DIR}/.env" ]; then
  ENV_BACKUP="/tmp/smartolt_env_backup_$$"
  cp "${TARGET_DIR}/.env" "$ENV_BACKUP"
  echo -e "      .env dicadangkan."
fi

# Cadangkan venv (berat, jangan download ulang)
VENV_BACKUP=""
if [ -d "${TARGET_DIR}/backend/python_engine/venv" ]; then
  VENV_BACKUP="/tmp/smartolt_venv_backup_$$"
  mv "${TARGET_DIR}/backend/python_engine/venv" "$VENV_BACKUP"
  echo -e "      venv dicadangkan."
fi

# Cadangkan backup database yang baru dibuat (jika ada)
DB_BACKUPS=()
for f in "${TARGET_DIR}"/smartolt_db_backup_*.sql; do
  [ -f "$f" ] && DB_BACKUPS+=("$f")
done
if [ ${#DB_BACKUPS[@]} -gt 0 ]; then
  mkdir -p /tmp/smartolt_db_backups_$$
  mv "${DB_BACKUPS[@]}" /tmp/smartolt_db_backups_$$/
  echo -e "      ${#DB_BACKUPS[@]} file backup database dicadangkan."
fi

# Hapus SELURUH isi target
echo -e "      Menghapus isi lama..."
find "${TARGET_DIR}" -mindepth 1 -delete 2>/dev/null

# Salin SELURUH isi source ke target (kecuali .git, .vscode, __pycache__)
echo -e "      Menyalin berkas baru..."
rsync -a --delete \
  --exclude='.git' \
  --exclude='.vscode' \
  --exclude='__pycache__' \
  --exclude='*.pyc' \
  --exclude='.env' \
  --exclude='smartolt_db_backup_*.sql' \
  "${SOURCE_DIR}/" "${TARGET_DIR}/"

# Hapus file catatan/dev yang tidak perlu di production
rm -f "${TARGET_DIR}/memory.txt"
rm -f "${TARGET_DIR}/plan.txt"
rm -f "${TARGET_DIR}/deskripsi.txt"
rm -f "${TARGET_DIR}/hasildebug.txt"
rm -f "${TARGET_DIR}/backup_olt.txt"
rm -f "${TARGET_DIR}/next_update.txt"
rm -f "${TARGET_DIR}/hybrid_architecture_plan.txt"
rm -f "${TARGET_DIR}/migration_plan_all_pages.txt"
rm -f "${TARGET_DIR}/update_security.txt"
echo -e "      ${GREEN}[OK] Seluruh berkas berhasil diganti.${NC}"

# Kembalikan .env
ENV_RESTORED=false
if [ -n "$ENV_BACKUP" ] && [ -f "$ENV_BACKUP" ]; then
  cp "$ENV_BACKUP" "${TARGET_DIR}/.env"
  rm -f "$ENV_BACKUP"
  chown www-data:www-data "${TARGET_DIR}/.env"
  chmod 600 "${TARGET_DIR}/.env"
  ENV_RESTORED=true
  echo -e "      ${GREEN}[OK] Berkas .env dikembalikan tanpa perubahan.${NC}"
fi

# Jika .env tidak ditemukan sama sekali — minta user isi
if [ "$ENV_RESTORED" = false ]; then
  echo ""
  if [ -z "$ENV_BACKUP" ]; then
    echo -e "      ${RED}[WARNING] Backup .env tidak ditemukan!${NC}"
  else
    echo -e "      ${RED}[WARNING] .env direstore tapi DB_PASSWORD kosong!${NC}"
  fi
  echo ""
  echo -e "      ${CYAN}Pilih tindakan:${NC}"
  echo -e "      ${GREEN}1)${NC} Pakai password database lama (ketik manual)"
  echo -e "      ${GREEN}2)${NC} Generate password baru (butuh MySQL root password)"
  echo ""
  read -p "      Pilihan [1/2]: " DB_PASS_CHOICE

  # Ambil info DB dari .env source atau dari .env yang terlanjur ter-restore
  DETECTED_DB_HOST="${DB_HOST:-localhost}"
  DETECTED_DB_PORT="${DB_PORT:-3306}"
  DETECTED_DB_NAME="${DB_NAME:-}"
  DETECTED_DB_USER="${DB_USER:-}"
  if [ -f "${TARGET_DIR}/.env" ]; then
    [ -z "$DETECTED_DB_NAME" ] && DETECTED_DB_NAME=$(grep "^DB_NAME=" "${TARGET_DIR}/.env" | cut -d'=' -f2-)
    [ -z "$DETECTED_DB_USER" ] && DETECTED_DB_USER=$(grep "^DB_USER=" "${TARGET_DIR}/.env" | cut -d'=' -f2-)
    [ -z "$DETECTED_DB_HOST" ] && DETECTED_DB_HOST=$(grep "^DB_HOST=" "${TARGET_DIR}/.env" | cut -d'=' -f2-)
    [ -z "$DETECTED_DB_HOST" ] && DETECTED_DB_HOST="localhost"
    [ -z "$DETECTED_DB_PORT" ] && DETECTED_DB_PORT=$(grep "^DB_PORT=" "${TARGET_DIR}/.env" | cut -d'=' -f2-)
    [ -z "$DETECTED_DB_PORT" ] && DETECTED_DB_PORT="3306"
  fi

  NEW_DB_PASS=""
  if [[ "$DB_PASS_CHOICE" == "2" ]]; then
    # Generate password baru
    NEW_DB_PASS=$(head /dev/urandom | tr -dc A-Za-z0-9!@#%^*_+ | head -c 20)
    echo ""
    echo -e "      ${CYAN}Password baru di-generate: ${GREEN}${NEW_DB_PASS}${NC}"
    echo ""
    read -s -p "      MySQL root password: " MYSQL_ROOT_PASS
    echo ""

    if [ -z "$DETECTED_DB_USER" ]; then
      read -p "      Nama DB user [smartoltuser]: " INPUT_DB_USER
      DETECTED_DB_USER="${INPUT_DB_USER:-smartoltuser}"
    fi
    if [ -z "$DETECTED_DB_NAME" ]; then
      read -p "      Nama database [smartolt]: " INPUT_DB_NAME
      DETECTED_DB_NAME="${INPUT_DB_NAME:-smartolt}"
    fi

    # Update password di MySQL
    if [ -n "$MYSQL_ROOT_PASS" ]; then
      mysql -h "${DETECTED_DB_HOST}" -P "${DETECTED_DB_PORT}" -u root -p"${MYSQL_ROOT_PASS}" -e \
        "ALTER USER '${DETECTED_DB_USER}'@'localhost' IDENTIFIED BY '${NEW_DB_PASS}'; FLUSH PRIVILEGES;" < /dev/null 2>/dev/null
    else
      mysql -h "${DETECTED_DB_HOST}" -P "${DETECTED_DB_PORT}" -u root -e \
        "ALTER USER '${DETECTED_DB_USER}'@'localhost' IDENTIFIED BY '${NEW_DB_PASS}'; FLUSH PRIVILEGES;" < /dev/null 2>/dev/null
    fi

    if [ $? -eq 0 ]; then
      echo -e "      ${GREEN}[OK] Password MySQL berhasil diubah.${NC}"
    else
      echo -e "      ${RED}[ERROR] Gagal mengubah password MySQL! Pastikan root password benar & user '${DETECTED_DB_USER}' ada.${NC}"
      read -p "      Ketik password DB manual sebagai fallback (kosong = skip): " NEW_DB_PASS
      [ -z "$NEW_DB_PASS" ] && echo -e "      ${RED}[SKIP] .env tanpa DB_PASSWORD. Isi manual: sudo nano ${TARGET_DIR}/.env${NC}"
    fi

    DB_PASS_TO_USE="$NEW_DB_PASS"

  elif [[ "$DB_PASS_CHOICE" == "1" ]]; then
    # Pakai password lama
    echo ""
    if [ -z "$DETECTED_DB_USER" ]; then
      read -p "      Nama DB user [smartoltuser]: " INPUT_DB_USER
      DETECTED_DB_USER="${INPUT_DB_USER:-smartoltuser}"
    fi
    read -s -p "      Password database untuk '${DETECTED_DB_USER}': " DB_PASS_TO_USE
    echo ""
  else
    echo -e "      ${RED}[SKIP] Pilihan tidak valid. Isi .env manual: sudo nano ${TARGET_DIR}/.env${NC}"
    DB_PASS_TO_USE=""
  fi

  # Tulis/update .env dengan password yang dipakai
  if [ -n "$DB_PASS_TO_USE" ]; then
    if [ -f "${TARGET_DIR}/.env" ]; then
      # Update hanya baris DB_PASSWORD
      sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS_TO_USE}|" "${TARGET_DIR}/.env"
    else
      # Buat .env baru minimal
      cat <<ENVEOF > "${TARGET_DIR}/.env"
DB_HOST=${DETECTED_DB_HOST}
DB_PORT=${DETECTED_DB_PORT}
DB_NAME=${DETECTED_DB_NAME}
DB_USER=${DETECTED_DB_USER}
DB_PASSWORD=${DB_PASS_TO_USE}
APP_DOMAIN=${APP_DOMAIN}
APP_KEY=$(head /dev/urandom | tr -dc A-Za-z0-9 | head -c 32)
ENGINE_PORT=${ENGINE_PORT:-8000}
ENVEOF
    fi
    chown www-data:www-data "${TARGET_DIR}/.env"
    chmod 600 "${TARGET_DIR}/.env"

    # Verifikasi koneksi DB
    if [ -n "$DETECTED_DB_NAME" ]; then
      mysql -h "${DETECTED_DB_HOST}" -P "${DETECTED_DB_PORT}" -u "${DETECTED_DB_USER}" -p"${DB_PASS_TO_USE}" "${DETECTED_DB_NAME}" -e "SELECT 1;" < /dev/null &>/dev/null
      if [ $? -eq 0 ]; then
        echo -e "      ${GREEN}[OK] .env ditulis & koneksi database terverifikasi.${NC}"
        ENV_RESTORED=true
      else
        echo -e "      ${RED}[WARNING] .env ditulis TAPI koneksi database GAGAL! Periksa manual.${NC}"
      fi
    else
      echo -e "      ${GREEN}[OK] .env ditulis (koneksi tidak bisa diverifikasi, DB_NAME belum diketahui).${NC}"
    fi
  fi
fi

# Kembalikan venv
if [ -n "$VENV_BACKUP" ] && [ -d "$VENV_BACKUP" ]; then
  mkdir -p "${TARGET_DIR}/backend/python_engine"
  mv "$VENV_BACKUP" "${TARGET_DIR}/backend/python_engine/venv"
  echo -e "      venv dikembalikan."
fi

# Kembalikan backup database
if [ -d /tmp/smartolt_db_backups_$$ ]; then
  mv /tmp/smartolt_db_backups_$$/*.sql "${TARGET_DIR}/" 2>/dev/null
  rm -rf /tmp/smartolt_db_backups_$$
  echo -e "      Backup database dikembalikan."
fi

# Paksa sync config penuh setelah update
rm -f "${TARGET_DIR}/backend/last_config_sync.txt"

# ==============================================================================
# 4/5 — SETUP PYTHON ENGINE
# ==============================================================================
echo -e "\n${YELLOW}[4/5] Mengonfigurasi Python Engine...${NC}"
if ! command -v python3 &> /dev/null; then
  apt-get update &> /dev/null && apt-get install -y python3 python3-pip python3-venv &> /dev/null
fi
! dpkg -l | grep -q "python3-venv" && apt-get install -y python3-venv &> /dev/null

PYTHON_DIR="${TARGET_DIR}/backend/python_engine"

# Auto-detect port (mulai dari base, +1 jika dipakai)
find_free_port() {
  local port=$1
  while ss -tlnp | grep -q ":${port} " 2>/dev/null; do
    # Kecuali jika yang memakai adalah service kita sendiri
    if ss -tlnp | grep ":${port} " | grep -q "$2"; then
      break
    fi
    port=$((port+1))
  done
  echo $port
}

ENGINE_PORT=$(grep -oP '^ENGINE_PORT=\K\d+' "${TARGET_DIR}/.env" 2>/dev/null)
if [ -z "$ENGINE_PORT" ]; then
  ENGINE_PORT=$(find_free_port 8000 "")
fi
# Pastikan port yang tersimpan masih free; jika sudah dipakai oleh proses lain, cari yang baru
while ss -tlnp | grep -q ":${ENGINE_PORT} " 2>/dev/null; do
  if ss -tlnp | grep ":${ENGINE_PORT} " | grep -q "uvicorn"; then
    break
  fi
  echo -e "      Port ${ENGINE_PORT} sudah dipakai proses lain, mencoba $((ENGINE_PORT+1))..."
  ENGINE_PORT=$((ENGINE_PORT+1))
done

# Simpan ENGINE_PORT ke .env jika belum ada
if [ -f "${TARGET_DIR}/.env" ] && ! grep -q "^ENGINE_PORT=" "${TARGET_DIR}/.env"; then
  echo "ENGINE_PORT=${ENGINE_PORT}" >> "${TARGET_DIR}/.env"
fi
# Update jika sudah ada tapi berbeda
if [ -f "${TARGET_DIR}/.env" ] && grep -q "^ENGINE_PORT=" "${TARGET_DIR}/.env"; then
  sed -i "s/^ENGINE_PORT=.*/ENGINE_PORT=${ENGINE_PORT}/" "${TARGET_DIR}/.env"
fi
echo -e "      Python Engine port: ${ENGINE_PORT}"

if [ -d "$PYTHON_DIR" ]; then
  if [ ! -d "${PYTHON_DIR}/venv" ]; then
    echo -e "      Membuat Python Virtual Environment..."
    python3 -m venv "${PYTHON_DIR}/venv" &> /dev/null
  fi

  if [ ! -f "${PYTHON_DIR}/venv/bin/uvicorn" ] || ! "${PYTHON_DIR}/venv/bin/python3" -c "import pymysql" &> /dev/null; then
    echo -e "      Menginstal pustaka Python..."
    "${PYTHON_DIR}/venv/bin/pip" install --upgrade pip &> /dev/null
    "${PYTHON_DIR}/venv/bin/pip" install netmiko fastapi uvicorn pydantic cryptography requests pymysql &> /dev/null
  fi

  chown -R www-data:www-data "$PYTHON_DIR"
  chmod -R 700 "$PYTHON_DIR"
  echo -e "      ${GREEN}[OK] Virtual Environment siap.${NC}"

  SERVICE_FILE="/etc/systemd/system/smartolt-python.service"
  cat <<EOF > "$SERVICE_FILE"
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
  chmod 644 "$SERVICE_FILE"
  systemctl daemon-reload
  systemctl enable smartolt-python &> /dev/null
  systemctl restart smartolt-python &> /dev/null
  echo -e "      ${GREEN}[OK] Service smartolt-python dijalankan di port ${ENGINE_PORT}.${NC}"
else
  echo -e "      ${YELLOW}[WARNING] Direktori Python engine tidak ditemukan.${NC}"
fi

# Set hak akses
chown -R www-data:www-data "${TARGET_DIR}/backend"
chmod -R 700 "${TARGET_DIR}/backend"
chown -R www-data:www-data "${TARGET_DIR}/frontend"
chmod -R 755 "${TARGET_DIR}/frontend"
[ -f "${TARGET_DIR}/update.sh" ] && chmod +x "${TARGET_DIR}/update.sh"
[ -f "${TARGET_DIR}/install.sh" ] && chmod +x "${TARGET_DIR}/install.sh"
[ -f "${TARGET_DIR}/uninstall.sh" ] && chmod +x "${TARGET_DIR}/uninstall.sh"

# =============================================================================
# 4b/5 — SETUP JS API (Express)
# =============================================================================
echo -e "\n${YELLOW}[4b/5] Mengonfigurasi JS API (Express)...${NC}"
JS_API_DIR="${TARGET_DIR}/backend/js-api"
JS_API_PORT=$(grep -oP '^JS_API_PORT=\K\d+' "${TARGET_DIR}/.env" 2>/dev/null)
if [ -z "$JS_API_PORT" ]; then
  JS_API_PORT=$(find_free_port 3002 "")
fi
# Pastikan port masih free
while ss -tlnp | grep -q ":${JS_API_PORT} " 2>/dev/null; do
  if ss -tlnp | grep ":${JS_API_PORT} " | grep -q "node"; then
    # Cek apakah ini smartolt-js-api kita
    if systemctl is-active --quiet smartolt-js-api 2>/dev/null; then
      break
    fi
  fi
  echo -e "      Port ${JS_API_PORT} sudah dipakai, mencoba $((JS_API_PORT+1))..."
  JS_API_PORT=$((JS_API_PORT+1))
done
# Simpan JS_API_PORT ke .env
if [ -f "${TARGET_DIR}/.env" ] && ! grep -q "^JS_API_PORT=" "${TARGET_DIR}/.env"; then
  echo "JS_API_PORT=${JS_API_PORT}" >> "${TARGET_DIR}/.env"
fi
if [ -f "${TARGET_DIR}/.env" ] && grep -q "^JS_API_PORT=" "${TARGET_DIR}/.env"; then
  sed -i "s/^JS_API_PORT=.*/JS_API_PORT=${JS_API_PORT}/" "${TARGET_DIR}/.env"
fi
echo -e "      JS API port: ${JS_API_PORT}"

if [ -d "$JS_API_DIR" ] && [ -f "$JS_API_DIR/package.json" ]; then
  cd "$JS_API_DIR"
  if [ ! -d "node_modules" ]; then
    echo -e "      Menginstal dependensi Node.js..."
    npm install --production &> /dev/null
  fi
  chown -R www-data:www-data "$JS_API_DIR"

  JS_SERVICE_FILE="/etc/systemd/system/smartolt-js-api.service"
  cat <<EOF > "$JS_SERVICE_FILE"
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
  chmod 644 "$JS_SERVICE_FILE"
  systemctl daemon-reload
  systemctl enable smartolt-js-api &> /dev/null
  systemctl restart smartolt-js-api &> /dev/null
  echo -e "      ${GREEN}[OK] Service smartolt-js-api dijalankan di port ${JS_API_PORT}.${NC}"
else
  echo -e "      ${YELLOW}[WARNING] Direktori JS API tidak ditemukan.${NC}"
fi

# Update Apache proxy config
APACHE_CONF="/etc/apache2/sites-available/${APP_DOMAIN}.conf"
if [ -f "$APACHE_CONF" ] && ! grep -q "ProxyPass /api/" "$APACHE_CONF"; then
  sed -i '/SSLEngine on/i\    # Proxy /api/* to Express JS API\n    ProxyPass /api/ http://127.0.0.1:'"${JS_API_PORT}"'/api/\n    ProxyPassReverse /api/ http://127.0.0.1:'"${JS_API_PORT}"'/api/\n' "$APACHE_CONF"
  echo -e "      ${GREEN}[OK] Apache proxy /api/* dikonfigurasi.${NC}"
fi

# ==============================================================================
# 5/5 — CLEANUP & RESTART
# ==============================================================================
echo -e "\n${YELLOW}[5/5] Membersihkan & merestart layanan...${NC}"

# Bersihkan OLT Demo
if [ -n "$DB_NAME" ] && [ -n "$DB_USER" ]; then
  export MYSQL_PWD="${DB_PASSWORD}"
  mysql -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" "${DB_NAME}" -e "DELETE FROM olts WHERE ip = '127.0.0.1';" < /dev/null &> /dev/null
  unset MYSQL_PWD
  echo -e "      ${GREEN}[OK] Data demo dibersihkan.${NC}"
fi

# Cron Job
CRON_FILE="/etc/cron.d/smartolt-sync"
cat <<EOF > "$CRON_FILE"
* * * * * root ${TARGET_DIR}/backend/python_engine/venv/bin/python3 ${TARGET_DIR}/backend/python_engine/cron_sync.py > /dev/null 2>&1
EOF
chmod 644 "$CRON_FILE"
echo -e "      ${GREEN}[OK] Cron job diperbarui.${NC}"

# Restart Apache
systemctl restart apache2
echo -e "      ${GREEN}[OK] Apache2 direstart.${NC}"

# Restart JS API
systemctl restart smartolt-js-api &> /dev/null
echo -e "      ${GREEN}[OK] JS API direstart.${NC}"

echo -e "\n${GREEN}======================================================================${NC}"
echo -e "${GREEN}        APLIKASI SMARTOLT BERHASIL DIPERBARUI & AKTIF!                ${NC}"
echo -e "${GREEN}======================================================================${NC}"
