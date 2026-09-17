#!/bin/bash

# ==============================================================================
# Script Uninstalasi SmartOLT (Apache2 / PHP / MySQL Version)
# ==============================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "${RED}======================================================================${NC}"
echo -e "${RED}                      MEMULAI UNINSTALASI SMARTOLT                    ${NC}"
echo -e "${RED}======================================================================${NC}"

if [ "$EUID" -ne 0 ]; then
  echo -e "${RED}[ERROR] Harap jalankan script ini sebagai root (sudo ./uninstall.sh).${NC}"
  exit 1
fi

read -p "   Apakah Anda YAKIN ingin menghapus SELURUH aplikasi SmartOLT? (y/N): " confirm_uninstall
if [[ ! "$confirm_uninstall" =~ ^[Yy]$ ]]; then
  echo -e "${YELLOW}[INFO] Uninstalasi dibatalkan.${NC}"
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

[ -z "$DB_NAME" ] && [ -f "${TARGET_DIR}/.env" ] && load_env_file "${TARGET_DIR}/.env"

# Deteksi user login (bukan root) untuk folder backup
LOGIN_USER="${SUDO_USER:-$USER}"
[ "$LOGIN_USER" = "root" ] && LOGIN_USER="root"
BACKUP_BASE_DIR="/home/${LOGIN_USER}/smartolt_backup"

echo -e "\n${CYAN}   Domain    : ${APP_DOMAIN}${NC}"
echo -e "${CYAN}   Target    : ${TARGET_DIR}${NC}"
echo -e "${CYAN}   Database  : ${DB_NAME:-'(tidak terdeteksi)'}${NC}"
echo -e "${CYAN}   Backup SQL: ${BACKUP_BASE_DIR}${NC}"

# ==============================================================================
# 1/4 — BACKUP DATABASE (OPSIONAL)
# ==============================================================================
echo -e "\n${YELLOW}[1/4] Backup Database...${NC}"
if [ -n "$DB_NAME" ] && [ -n "$DB_USER" ]; then
  read -p "      Apakah Anda ingin mem-backup database '${DB_NAME}' sebelum dihapus? (Y/n): " confirm_backup
  confirm_backup=${confirm_backup:-"y"}
  if [[ "$confirm_backup" =~ ^[Yy]$ ]]; then
    read -s -p "      MySQL root password (kosong = auth socket): " MYSQL_ROOT_PASS
    echo ""

    mkdir -p "${BACKUP_BASE_DIR}"
    BACKUP_FILE="${BACKUP_BASE_DIR}/smartolt_${DB_NAME}_$(date +%Y%m%d_%H%M%S).sql"

    if [ -n "$MYSQL_ROOT_PASS" ]; then
      mysqldump -h "${DB_HOST}" -P "${DB_PORT}" -u root -p"${MYSQL_ROOT_PASS}" "${DB_NAME}" > "${BACKUP_FILE}" 2>/dev/null
    else
      mysqldump -h "${DB_HOST}" -P "${DB_PORT}" -u root "${DB_NAME}" > "${BACKUP_FILE}" 2>/dev/null
    fi

    if [ $? -ne 0 ] && [ -n "$DB_PASSWORD" ]; then
      mysqldump -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" > "${BACKUP_FILE}" 2>/dev/null
    fi

    if [ -s "${BACKUP_FILE}" ]; then
      echo -e "      ${GREEN}[OK] Backup tersimpan di: ${BACKUP_FILE}${NC}"
    else
      rm -f "${BACKUP_FILE}"
      echo -e "      ${RED}[WARNING] Gagal membuat backup database.${NC}"
    fi
  else
    echo -e "      ${BLUE}[INFO] Backup database dilewati.${NC}"
  fi
else
  echo -e "      ${BLUE}[INFO] Konfigurasi database tidak terdeteksi.${NC}"
fi

# ==============================================================================
# 2/4 — HAPUS DATABASE & USER MYSQL
# ==============================================================================
echo -e "\n${YELLOW}[2/4] Menghapus Database MySQL...${NC}"
if [ -n "$DB_NAME" ] && [ -n "$DB_USER" ]; then
  read -p "      HAPUS database '${DB_NAME}' & user '${DB_USER}'? (y/N): " drop_db
  if [[ "$drop_db" =~ ^[Yy]$ ]]; then
    if [ -z "$MYSQL_ROOT_PASS" ]; then
      read -s -p "      MySQL root password (kosong = auth socket): " MYSQL_ROOT_PASS
      echo ""
    fi

    if [ -n "$MYSQL_ROOT_PASS" ]; then
      mysql -h "${DB_HOST}" -P "${DB_PORT}" -u root -p"${MYSQL_ROOT_PASS}" -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; DROP USER IF EXISTS '${DB_USER}'@'localhost'; DROP USER IF EXISTS '${DB_USER}'@'%'; FLUSH PRIVILEGES;" &> /dev/null
    else
      mysql -h "${DB_HOST}" -P "${DB_PORT}" -u root -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; DROP USER IF EXISTS '${DB_USER}'@'localhost'; DROP USER IF EXISTS '${DB_USER}'@'%'; FLUSH PRIVILEGES;" &> /dev/null
    fi
    [ $? -eq 0 ] && echo -e "      ${GREEN}[OK] Database & user berhasil dihapus.${NC}" || echo -e "      ${RED}[ERROR] Gagal menghapus database.${NC}"
  else
    echo -e "      ${BLUE}[INFO] Database & user dipertahankan.${NC}"
  fi
else
  echo -e "      ${BLUE}[INFO] Konfigurasi database tidak terdeteksi.${NC}"
fi

# ==============================================================================
# 3/4 — HAPUS SEMUA LAYANAN & KONFIGURASI
# ==============================================================================
echo -e "\n${YELLOW}[3/4] Menghapus layanan & konfigurasi sistem...${NC}"

# Hentikan & hapus Python Engine service
if systemctl list-units --full -all | grep -Fq "smartolt-python.service"; then
  systemctl stop smartolt-python &> /dev/null
  systemctl disable smartolt-python &> /dev/null
  rm -f /etc/systemd/system/smartolt-python.service
  systemctl daemon-reload
  echo -e "      ${GREEN}[OK] Service smartolt-python dihapus.${NC}"
fi

# Hapus layanan Node.js lama (jika masih ada)
SERVICE_NAME="smartolt-${APP_DOMAIN//./-}"
if systemctl list-units --full -all | grep -Fq "${SERVICE_NAME}.service"; then
  systemctl stop "${SERVICE_NAME}.service" &> /dev/null
  systemctl disable "${SERVICE_NAME}.service" &> /dev/null
  rm -f "/etc/systemd/system/${SERVICE_NAME}.service"
  systemctl daemon-reload
  echo -e "      ${GREEN}[OK] Service Node.js lama dihapus.${NC}"
fi

# Hapus cron job
if [ -f /etc/cron.d/smartolt-sync ]; then
  rm -f /etc/cron.d/smartolt-sync
  echo -e "      ${GREEN}[OK] Cron job smartolt-sync dihapus.${NC}"
fi

# Hapus timer systemd flush-write
if [ -f /etc/systemd/system/smartolt-flush-write.timer ]; then
  systemctl disable --now smartolt-flush-write.timer &> /dev/null
  rm -f /etc/systemd/system/smartolt-flush-write.timer /etc/systemd/system/smartolt-flush-write.service
  systemctl daemon-reload
  echo -e "      ${GREEN}[OK] Timer flush-write systemd dihapus.${NC}"
fi

# Hapus Apache virtual host
APACHE_CONF="/etc/apache2/sites-available/${APP_DOMAIN}.conf"
if [ -f "$APACHE_CONF" ]; then
  a2dissite "${APP_DOMAIN}.conf" &> /dev/null
  rm -f "$APACHE_CONF"
  rm -f "/etc/apache2/sites-enabled/${APP_DOMAIN}.conf"
  systemctl restart apache2
  echo -e "      ${GREEN}[OK] Virtual host Apache '${APP_DOMAIN}.conf' dihapus.${NC}"
else
  echo -e "      ${BLUE}[INFO] Virtual host tidak ditemukan.${NC}"
fi

# ==============================================================================
# 4/4 — HAPUS SELURUH BERKAS APLIKASI
# ==============================================================================
echo -e "\n${YELLOW}[4/4] Menghapus berkas aplikasi...${NC}"
if [ -d "${TARGET_DIR}" ]; then
  read -p "      HAPUS seluruh direktori ${TARGET_DIR}? (y/N): " choice
  if [[ "$choice" =~ ^[Yy]$ ]]; then
    rm -rf "${TARGET_DIR}"
    echo -e "      ${GREEN}[OK] Direktori ${TARGET_DIR} berhasil dihapus.${NC}"
  else
    echo -e "      ${BLUE}[INFO] Direktori dipertahankan.${NC}"
  fi
else
  echo -e "      ${BLUE}[INFO] Direktori ${TARGET_DIR} tidak ditemukan.${NC}"
fi

# ==============================================================================
# SELESAI
# ==============================================================================
echo -e "\n${GREEN}======================================================================${NC}"
echo -e "${GREEN}           UNINSTALASI SMARTOLT BERHASIL DISELESAIKAN                 ${NC}"
echo -e "${GREEN}======================================================================${NC}"
if [ -d "${BACKUP_BASE_DIR}" ]; then
  echo -e "\n   Backup SQL tersimpan di: ${BLUE}${BACKUP_BASE_DIR}${NC}"
  echo -e "   (Dapat digunakan saat install ulang nanti)"
fi
echo ""
