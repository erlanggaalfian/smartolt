<div align="center">

# 📡 SmartOLT

**Aplikasi manajemen OLT/ONU GPON berbasis web** — monitoring real-time, auto-discovery, dan konfigurasi ONU multi-vendor dalam satu dashboard.

![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)
![Python](https://img.shields.io/badge/Python-3-3776AB?logo=python&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-MariaDB-4479A1?logo=mysql&logoColor=white)
![Apache](https://img.shields.io/badge/Apache-2-D22128?logo=apache&logoColor=white)
![SNMP](https://img.shields.io/badge/SNMP-Integrated-informational)
![License](https://img.shields.io/badge/license-Internal-lightgrey)

</div>

---

## 📋 Daftar Isi

- [Tampilan Aplikasi](#-tampilan-aplikasi)
- [OLT yang Didukung](#-olt-yang-didukung)
- [Fitur](#-fitur)
- [Kebutuhan Sistem](#-kebutuhan-sistem)
- [Instalasi](#-instalasi)
- [Update](#-update)
- [Uninstall](#-uninstall)
- [Struktur Direktori](#-struktur-direktori)
- [Arsitektur Singkat](#-arsitektur-singkat)

---

## 🖥 Tampilan Aplikasi

<table>
<tr>
<td width="50%">

**Dashboard**
Ringkasan total OLT, status ONU online/offline, kualitas sinyal, dan aktivitas login terakhir.

<img src="docs/screenshots/dashboard.png" width="100%">

</td>
<td width="50%">

**Daftar Pelanggan Terdaftar (Configured)**
Tabel ONU yang sudah dikonfigurasi lengkap dengan status, VLAN, sinyal Rx ONU/OLT.

<img src="docs/screenshots/configured.png" width="100%">

</td>
</tr>
<tr>
<td width="50%">

**ONU Belum Terdaftar (Unconfigured)**
Deteksi ONU baru per shelf/slot/port PON yang siap diauth.

<img src="docs/screenshots/unconfigured.png" width="100%">

</td>
<td width="50%">

**Pengaturan OLT**
Manajemen multi-OLT: tambah, edit, hapus, lihat detail koneksi.

<img src="docs/screenshots/settings-olt.png" width="100%">

</td>
</tr>
</table>

**Manajemen VLAN**

<img src="docs/screenshots/vlan.png" width="50%">

> 🔒 Data pelanggan/IP asli pada semua screenshot di atas sudah disamarkan sebelum publikasi.

---

## 📡 OLT yang Didukung

| Merk / Model | Akses | Status |
|---|:---:|:---:|
| ZTE C300 | SSH (CLI) + SNMP | ✅ Penuh |
| ZTE C320 | SSH (CLI) + SNMP | ✅ Penuh |
| Cdata FD1602SB1 | SSH (CLI) + SNMP | ✅ Didukung |

Arsitektur driver berbasis plug-in (`backend/python_engine/drivers/base_driver.py`) — dukungan vendor baru tinggal implementasikan interface yang sama.

---

## ⚙️ Fitur

### Monitoring & Discovery
- Auto-discovery ONU baru per shelf/slot/port PON
- Sinkronisasi background (cron tiap menit): status online/offline, nama, sinyal Rx ONU/OLT — **tanpa membebani UI**
- Sumber data hybrid: tampilan baca cepat dari database, diperbarui dari polling SNMP; kalau sinkron CLI gagal, tampilan tetap pakai data DB terakhir (bukan kosong/error)

### Manajemen ONU
- Authorize / hapus / reboot ONU langsung dari web
- Enable / disable ONU
- Konfigurasi WAN (VLAN, mode DHCP / PPPoE / Static) per ONU
- Statistik traffic & histori sinyal

### Manajemen OLT
- Multi-OLT, mendukung IP sama dengan port SSH berbeda
- Setup SNMP (community RO/RW) langsung dari UI
- Health check OLT: CPU load, RAM usage, suhu board

### VLAN
- Tambah dan lihat VLAN per OLT

### Lainnya
- Log aktivitas & role admin
- Worker async untuk operasi berat (tarik konfigurasi OLT besar) — form tetap responsif, hasil menyusul di background
- Batch flush "pending write" ke OLT via systemd timer (tiap 10 menit) — aksi tertunda dieksekusi ulang otomatis

---

## 💻 Kebutuhan Sistem

| Komponen | Versi Minimal |
|---|---|
| OS | Ubuntu / Debian (akses root) |
| PHP | 8.2+ dengan `libapache2-mod-php` |
| Web server | Apache 2 |
| Database | MySQL / MariaDB |
| Python | 3.x (venv otomatis dibuat installer) |
| Node.js | untuk `backend/js-api` (Express) |
| Jaringan | SSH + SNMP terbuka ke OLT target |

---

## 🚀 Instalasi

```bash
git clone https://github.com/erlanggaalfian/smartolt.git
cd smartolt
sudo ./install.sh
```

`install.sh` interaktif — akan menanyakan detail koneksi database, domain, dan port sebelum lanjut, serta otomatis mendeteksi backup SQL lama di `~/smartolt_backup/*.sql` untuk direstore.

<details>
<summary><b>📟 Lihat alur instalasi (klik untuk expand)</b></summary>

```
======================================================================
                      MEMULAI INSTALASI SMARTOLT
======================================================================

[1/6] Menginstal dependensi sistem...
      [OK] PHP, Apache2, SNMP, SSHPass terpasang.

[2/6] Menyalin berkas ke /var/www/<domain>...
      [OK] Berkas berhasil disalin.

[3/6] Mengonfigurasi Python Engine...
      (membuat virtualenv + install dependency)

[4/6] Menyiapkan database...
      (import skema baru, atau restore dari backup terdeteksi)

[5/6] Mengonfigurasi .env & Apache...
      [OK] .env berhasil dibuat.
      [OK] Apache2 dikonfigurasi.       (vhost, SSL opsional via certbot)

[6/6] Mengonfigurasi Cron Job & Finalisasi...
      [OK] Cron job didaftarkan.        (sinkronisasi tiap menit)
      [OK] Timer flush-write systemd aktif (tiap 10 menit).

======================================================================
        INSTALASI SMARTOLT BERHASIL SELESAI & AKTIF!
======================================================================

   Akses panel: https://<domain>
   Web root   : /var/www/<domain>/frontend
   Config     : /var/www/<domain>/.env
```

</details>

---

## 🔄 Update

```bash
cd smartolt
sudo ./update.sh
```

Update menarik kode terbaru dari Git dan me-refresh berkas aplikasi — **`.env` dan database yang sudah ada tetap dipertahankan**.

<details>
<summary><b>📟 Lihat alur update (klik untuk expand)</b></summary>

```
======================================================================
                      MEMULAI PEMBARUAN SMARTOLT
======================================================================
   Apakah Anda yakin ingin melanjutkan pembaruan aplikasi SmartOLT? (y/N): y

[1/5] Membersihkan layanan Node.js lama...
[2/5] Menarik pembaruan dari Git...
[3/5] Mengganti seluruh berkas di /var/www/<domain>...
[4/5] Mengonfigurasi Python Engine...
[5/5] Membersihkan & merestart layanan...
```

</details>

---

## 🗑 Uninstall

```bash
cd smartolt
sudo ./uninstall.sh
```

Sebelum menghapus, script menawarkan backup database ke `~/smartolt_backup/` (bisa dipakai lagi saat `install.sh` berikutnya).

<details>
<summary><b>📟 Lihat alur uninstall (klik untuk expand)</b></summary>

```
======================================================================
                      MEMULAI UNINSTALASI SMARTOLT
======================================================================
   Apakah Anda YAKIN ingin menghapus SELURUH aplikasi SmartOLT? (y/N): y

[1/4] Backup Database...
      Apakah Anda ingin mem-backup database '<db_name>' sebelum dihapus? (Y/n): y
      [OK] Backup: ~/smartolt_backup/smartolt_<db_name>_<timestamp>.sql

[2/4] Menghapus Database MySQL...
[3/4] Menghapus layanan & konfigurasi sistem...   (vhost Apache, cron job, timer flush-write)
[4/4] Menghapus berkas aplikasi...                (/var/www/<domain>)
```

</details>

---

## 📁 Struktur Direktori

```
smartolt/
├── frontend/                       # Halaman PHP (dashboard, configured, unconfigured, dll)
├── backend/
│   ├── python_engine/
│   │   ├── drivers/                # Driver per vendor OLT (ZTE C300/C320, Cdata FD1602SB1)
│   │   └── cron_sync.py            # Job sinkronisasi background tiap menit
│   ├── js-api/                     # API Express.js pendukung
│   └── background_pull_onus.php    # Worker async sinkronisasi ONU
├── install.sh
├── update.sh
└── uninstall.sh
```

---

## 🏗 Arsitektur Singkat

```
┌─────────────┐     HTTP      ┌──────────────┐     SSH / SNMP     ┌─────────┐
│   Browser   │◄─────────────►│  Apache+PHP  │◄──────────────────►│   OLT   │
└─────────────┘               │  (frontend)  │                     └─────────┘
                               └──────┬───────┘
                                      │ exec (async)
                                      ▼
                               ┌──────────────┐
                               │ Python Engine│──┐
                               │  (drivers)   │  │ cron tiap menit
                               └──────┬───────┘  │
                                      │           ▼
                                      ▼    ┌──────────────┐
                               ┌──────────►│    MySQL     │
                               │           └──────────────┘
                        SNMP polling
                        (update DB berkala)
```

Form add/edit OLT tidak menunggu proses SSH yang lambat — operasi berat dilempar ke worker background, UI tetap responsif, hasil disinkronkan lewat cron.

---

<div align="center">

**Internal use** — hubungi pemilik repo untuk detail lisensi.

</div>
