# Antislop Re-audit — Tahap 3 / 4 / 5 (Independent Double-Check)

**Branch:** `antislop-tahap2-5`
**Commit dasar:** `feaac73` (antislop tahap2: remove dead code)
**Tanggal:** 2026-10-14
**Metode:** baca kode langsung (grep + read + resolusi path diverifikasi dengan `php -r`). Tidak ada temuan berdasar tebakan.

Review sebelumnya melaporkan NIHIL untuk tahap 3/4/5. Re-audit ini menemukan
temuan terverifikasi di **tahap 3 dan tahap 5**, serta satu **bug fungsional**
(path require salah) yang ketemu saat menelusuri cakupan CSRF.

**File diperiksa:** 48 file
(`frontend/*.php` 24, `frontend/action/*.php` 43 → di-scan semua, 14 dibaca penuh,
`backend/*.php` 10, `frontend/public/style.css`, `frontend/style.css`)

---

## Tahap 3 — Aksesibilitas

| File | Baris | Masalah | Severity |
|---|---|---|---|
| `frontend/logs.php` | 91 | Tombol submit ikon-saja (`<i data-lucide="search">`) tanpa `aria-label` **dan** tanpa `title`. Screen reader membaca tombol tanpa nama. Semua tombol ikon-saja lain di repo punya `title=` (`auth-onu.php:64,201`, `onu-detail.php:1580`) — hanya file ini yang bolong. | Medium |
| `frontend/onu-detail.php` | 496, 508 | Tombol toggle visibilitas PPPoE user/password ikon-saja (`eye`) tanpa `aria-label`/`title`. Dua kontrol berdekatan dengan nama aksesibel identik (kosong) → tidak bisa dibedakan secara non-visual. | Medium |
| `frontend/logs.php` | 77, 78, 86 | Tiga kontrol filter (`input[name=search]`, `select[name=action_type]`, `select[name=limit]`) tanpa `<label>` sama sekali (`labels=0` di file ini) dan tanpa `aria-label`. `select` juga tak punya `placeholder` sebagai jaring pengaman. | Medium |
| `frontend/configured.php` | 172, 175, 185, 196, 205, 214, 223, 232 | 8 kontrol filter (1 `input` + 7 `select`) di `.filter-panel` tanpa `<label>` maupun `aria-label`. `select` mengandalkan `<option>` pertama sebagai pseudo-label ("Semua OLT", "Semua Status", dst) — bukan nama aksesibel. | Medium |
| `frontend/configured.php` | 644 | `select#header-limit-select` tanpa label/aria-label. | Low |
| seluruh app | — | Tidak ada `<h1>` di satu halaman pun (`grep '<h1'` = 0 match). Heading mulai dari `<h2>`/`<h3>`; `dashboard.php` dan `vpn-tunnels.php` malah membuka dengan `<h3>` lalu baru `<h2>` → urutan heading terbalik. `#page-title` masih didefinisikan di CSS (`public/style.css:146,176`) tapi tidak dipakai di markup mana pun. | Medium |
| `frontend/*.php` (180 elemen `.modal`) | — | Nol penggunaan `role="dialog"`/`aria-modal`/`aria-labelledby` di seluruh repo (`grep` = 0 match). Fokus tidak di-trap; Escape-handler ada hanya di 4 file (`header.php:221`, `olt-detail.php:596,1304`, `onu-detail.php:3913`, `settings-users.php:299`) — `tr069-management.php`, `vpn-tunnels.php`, `settings-speed-profiles.php`, `splitters.php`, `onu-types.php` tidak punya. | Medium |
| `frontend/onu-detail.php` | 277–500 (17 baris) | 17 `<div class="detail-plain-row" data-clickable onclick=...>` — div yang bisa diklik tanpa `role="button"`, tanpa `tabindex`, tanpa handler keyboard. CSS hanya memberi `cursor:pointer` (`public/style.css:356`). Tidak bisa dioperasikan lewat keyboard sama sekali. `tabindex` nol match di seluruh repo. | High |
| `frontend/configured.php` | 498–569 | Toast notification dibuat dinamis tanpa `role="status"`/`role="alert"`/`aria-live`. Pesan sukses/gagal aksi tidak diumumkan ke screen reader. `aria-live` nol match di seluruh repo. | Medium |
| `frontend/header.php` | 236–249 | Modal `#debug-log-modal` (sisa setelah fixer menghapus modal debug CLI) tetap tanpa `role="dialog"`; tombol close `&times;` tanpa `aria-label`. | Low |

**Catatan positif terverifikasi:** `<img>` tanpa `alt` = 0 (tidak ada `<img>` sama sekali, semua ikon via Lucide `<i>`); `<html lang="id">` ada di ketiga entry point (`header.php:53`, `login.php:30`, `register.php:26`); `login.php`/`register.php`/`settings-users.php` pakai `<label for=...>` yang benar; `:focus-visible` ring ada untuk `.btn` (`public/style.css:518`) dan `prefers-reduced-motion` dihormati (`:512`).

---

## Tahap 4 — Token CSRF + Responsive

### CSRF

**Hasil: NIHIL untuk celah CSRF yang bisa dieksploitasi.** Diverifikasi berlapis:

1. Middleware pusat `backend/config.php:78` memvalidasi **semua** POST ke path yang
   mengandung `/action/` via `$_POST['csrf_token']` atau header `X-CSRF-Token`
   dengan `hash_equals()`. Jadi 24 file di `frontend/action/` yang tidak memanggil
   `verify_csrf_token()` sendiri **tetap terlindungi** — bukan temuan.
2. `header.php:104–110` meng-inject `X-CSRF-Token` ke semua `fetch()` POST, dan
   meng-inject hidden input ke semua form submit. Konsisten dengan middleware.
3. 4 halaman di luar `/action/` yang memproses POST sudah memvalidasi token sendiri
   secara eksplisit: `onu-types.php:16`, `splitters.php:12`, `vpn-tunnels.php:95`,
   dan semuanya mengirim token di form (`vpn-tunnels.php:300,322,359`).
4. Endpoint yang menulis DB tapi tanpa guard `REQUEST_METHOD` (`onu-presets.php`,
   `update-onu-splitter.php`, `update-onu-type.php`, `onu-set-mgmt-ip.php`,
   `onu-set-tr609.php`, `tr069-profile-save.php`, `tr069-profile-delete.php`)
   semuanya membaca parameter mutasi **hanya** dari `$_POST` atau
   `php://input`, bukan `$_GET` — jadi tidak ada state-change lewat GET.
   `$_GET` di `onu-presets.php:18` hanya untuk `action=list` (read-only).

| File | Baris | Masalah | Severity |
|---|---|---|---|
| `frontend/api-vpn-cert-toggle.php` | 12–23 | POST handler di luar `/action/` **tanpa validasi CSRF** (satu-satunya dari 4 halaman non-action yang bolong). Menulis `/opt/genieacs/certs/.cert-access` → bisa dipakai pihak ketiga membuka jendela unduh sertifikat VPN 5 menit lewat CSRF. Gate hanya `can_access_settings()` (berbasis cookie sesi, tidak menahan CSRF). Pola perbaikan sudah ada di `onu-types.php:15–20`. | **High** |
| `frontend/vpn-tunnels.php` | 95 | Perbandingan token pakai `!==` biasa, bukan `hash_equals()`. Secara teori timing-leak; file lain (`onu-types.php:16`, `splitters.php:12`, `config.php:80`) konsisten pakai `hash_equals()`. Inkonsistensi, bukan celah praktis. | Low |

### Responsive

| File | Baris | Masalah | Severity |
|---|---|---|---|
| `frontend/logs.php` | 76–78 | Form filter `display:flex` tanpa `flex-wrap`, berisi kontrol lebar tetap (`width:200px` + `width:160px` + `width:80px` + tombol ≈ 490px). Di viewport <500px form meluber keluar layar. Pembanding: `configured.php` pakai `.filter-row { flex-wrap: wrap }` (`public/style.css:273`) untuk masalah yang sama. | Medium |
| `frontend/logs.php` | 77, 78, 86 | Ketiga kontrol pakai `class="form-input"` — **kelas ini tidak terdefinisi di CSS mana pun** (`grep form-input public/style.css style.css` = 0 match). Jadi tidak dapat `width:100%`/`box-sizing:border-box` dari `.form-control` (`public/style.css:462`). Kelas yang benar: `form-control`. | Medium |
| `frontend/public/style.css` | 221–227 | Breakpoint `@media (max-width: 992px)` menata ulang `.sidebar`, `.sidebar.open`, dan `#mobile-menu-toggle` — ketiganya **tidak ada lagi di markup** (sidebar dihapus di `feaac73`; `#mobile-menu-toggle` nol referensi di `*.php`). Aturan mati di dalam media query, tersisa di 2 salinan CSS. | Low |
| `frontend/public/style.css` | 443–450 | Satu-satunya breakpoint mobile sungguhan (`max-width: 768px`) hanya menyentuh `.top-header`/`.topnav-*`/`.header-right`. `.main-content { padding: 24px 48px }` (`:169`) baru dikecilkan di breakpoint 992px yang jauh lebih longgar; tidak ada penyesuaian padding/typography untuk <600px. Tabel aman karena `.table-responsive` dipakai konsisten di 8/8 halaman bertabel (terverifikasi). | Low |
| `frontend/public/style.css` vs `frontend/style.css` | — | Dua salinan stylesheet, **isi berbeda** (562 vs 559 baris, 4 blok divergen termasuk aturan `@media (max-width:453)` `.topnav-dropdown-menu` yang hanya ada di `style.css`, dan `:hover` dropdown yang hanya ada di `public/style.css`). Hanya `public/style.css` yang dimuat (`header.php:64`, `login.php:38`, `register.php:33`). `frontend/style.css` = file mati 562 baris, tapi berisi aturan responsive yang TIDAK ada di versi aktif → sumber kebingungan saat edit. | Medium |

---

## Tahap 5 — Komentar Basi / Kode Mati

| File | Baris | Masalah | Severity |
|---|---|---|---|
| `frontend/public/style.css` + `frontend/style.css` | 365–380 / 367–382 | Komentar mengaku sendiri basi: `ONU DETAIL: 2-Column Card Layout (unused after plain-text redesign — kept until confirmed safe to delete)`. Diverifikasi: `detail-grid-2col`, `detail-card`, `detail-field-list`, `detail-field-label`, `detail-field-value` **nol referensi** di seluruh `*.php`. Aman dihapus; "confirmed" sudah terjadi tapi komentar tidak diupdate. | Medium |
| `frontend/public/style.css` + `frontend/style.css` | 166–167 | `/* SIDEBAR — deprecated, nav moved to top header */` + `.sidebar { display: none; }`. Setelah `feaac73` menghapus `sidebar.php`, tidak ada elemen `.sidebar` sama sekali (`class="sidebar` nol match). Komentar + aturan sudah murni historis. Ikut: `.sidebar-overlay` (`:203–204`), `.sidebar-dropdown` (`:323–328`, `:539–545`), `.sidebar-nav` (`:549–550`) — semua nol referensi. | Medium |
| `frontend/action/get-debug-cli.php` | seluruh file (29 baris) | **Endpoint menjadi orphan akibat `feaac73`.** Fixer menghapus modal debug CLI dari `header.php` beserta `#btn-show-debug-cli` dan `fetch('action/get-debug-cli.php')`. Diverifikasi: nol pemanggil di `*.php`, `deploy/`, `backend/python_engine/`, `backend/js-api/`, `install.sh`, `update.sh`. Komentar header file masih menggambarkan fitur yang sudah tidak ada jalurnya. | Medium |
| `frontend/action/test-olt.php` | seluruh file (35 baris) | **Endpoint menjadi orphan akibat `feaac73`.** Blok `verifyConnection()` + `.btn-test-conn` + `.btn-status-test` + `#status-olt-<id>` dihapus dari `settings-olt.php`. Diverifikasi: `btn-test-conn`/`btn-status-test`/`status-olt-` nol match di repo, dan `action/test-olt.php` nol referensi. Kolom "Status" juga hilang dari tabel OLT (`settings-olt.php:43–46` kini hanya Nama/IP/Tipe/Aksi, `colspan="4"` sudah konsisten). | Medium |
| `backend/db.php.bak` | seluruh file | Backup file 11.7 KB ter-commit, berbeda dari `db.php` (contoh data pelanggan lama "TMY01A0201-CaffeFoodMie"). Berisi kredensial-adjacent logic + data pelanggan nyata. | Medium |
| `frontend/vpn-tunnels.php` | 329, 367 | Komentar HTML `<!-- Tunnel IP auto-assigned by OpenVPN server -->` dua kali, menandai tempat field yang sudah dihapus. Komentar penanda kode yang tidak ada lagi. | Low |
| `frontend/index.php` | 3 | Komentar pakai `#` di tengah blok `//`-style — inkonsistensi sepele yang sama juga ada di `settings-olt.php:3`. | Low |

**Diverifikasi BERSIH (bukan temuan):** nol `TODO`/`FIXME`/`XXX`/`HACK` di kode first-party (semua hit `grep` berasal dari `backend/js-api/node_modules/`). Nol blok kode yang dikomentari-out di `frontend/*.php`, `frontend/action/*.php`, `backend/*.php` (regex untuk `// if/for/function/$/echo/const/return/document./console.` → 0 match first-party). Komentar panjang di `backend/genieacs.php` dan `backend/driver.php` yang menyebut "lama"/"belum" semuanya deskriptif-akurat (mis. `genieacs.php:729–731` menandai ketidakpastian live-test dengan penanda `ponytail:` yang benar, bukan basi). `backend/test_registry_meta.php` dan `test_snmp_status.php` adalah self-check runnable yang valid, bukan sampah.

---

## Temuan di Luar Cakupan 3/4/5 — Bug Fungsional

Ketemu saat memverifikasi cakupan middleware CSRF. Bukan tahap 3/4/5, tapi terlalu
konkret untuk dibuang.

| File | Baris | Masalah | Severity |
|---|---|---|---|
| `frontend/action/snmp-sync.php` | 6, 30, 31 | `require_once __DIR__ . '/../backend/db.php'` — kurang satu level. `realpath()` = `false`; diuji dengan `php -r include` → **`Error: Failed opening required`** (fatal). Harusnya `/../../backend/db.php`. Baris 30–31 salah dengan cara yang sama: `dirname(__DIR__) . '/backend/python_engine/...'` → `frontend/backend/python_engine/` (tidak ada). Fitur bulk SNMP sync **mati total**. | **High** |
| `frontend/action/backup-config.php` | 6, 30, 31 | Bug identik dan konsisten: `require` kurang satu level (fatal, diuji) + `dirname(__DIR__)` salah untuk `backup_configs.py`. Fitur backup running-config **mati total**. | **High** |

Konteks: `CHANGELOG.md:1357–1369` mengklaim kedua file ini sudah diperbaiki di
`3710c77` — tapi perbaikan saat itu hanya mengganti `require_login()` yang tidak
pernah ada. Path `require`-nya tidak pernah dibetulkan, jadi kedua fitur tetap
fatal error sejak saat itu. Keduanya juga nol pemanggil dari UI (diverifikasi:
`grep` di `*.php`, `deploy/`, `python_engine/`, `js-api/`, `install.sh`,
`update.sh` → hanya komentar di file itu sendiri + CHANGELOG) — jadi kerusakannya
belum pernah terlihat. Target Python-nya ada: `backend/python_engine/sync_snmp_onus.py`
dan `backup_configs.py` keduanya nyata.

---

## Verifikasi Regresi dari Commit Fixer (`feaac73`)

Dicek: apakah penghapusan 159 baris dead code ikut membuang aksesibilitas /
responsive / CSRF yang masih aktif.

| Berkas | Putusan |
|---|---|
| `frontend/header.php` (−74) | Aman untuk a11y/CSRF. Yang dibuang: modal debug CLI + skrip-nya. Injeksi CSRF (`:104–110`) utuh, `<meta viewport>` (`:56`) utuh, `<html lang="id">` (`:53`) utuh, Escape-handler modal `#debug-log-modal` yang tersisa masih ada (`:221`). **Efek samping:** `action/get-debug-cli.php` jadi orphan (lihat Tahap 5). |
| `frontend/onu-detail.php` (−42) | Aman. Yang dibuang: CSS `.sync-banner` + JS banner sinkronisasi. Diverifikasi `sync-banner`/`sync-loading-banner` nol referensi sisa. Tidak ada `aria-*`/`@media`/token yang hilang (`grep` pada diff untuk `aria|role=|alt=|csrf|@media|tabindex|label|focus` → 0 baris terhapus). 4 `@media` di `onu-detail-css.php` utuh. |
| `frontend/settings-olt.php` (−39) | Aman untuk a11y/CSRF; form delete OLT tetap mengirim POST ke `/action/` (terlindungi middleware), `verify-ssh-only.php` masih dipanggil di `:340` dan `:495`. **Efek samping:** `action/test-olt.php` jadi orphan, dan kolom "Status" hilang dari tabel OLT — `colspan="4"` sudah konsisten dengan 4 header, jadi bukan markup rusak, tapi fitur uji koneksi hilang dari UI. |
| `frontend/sidebar.php` (dihapus) | Aman. Nol `require`/`include` sisa. Namun CSS sidebar tertinggal di kedua stylesheet (lihat Tahap 4 & 5). |

**Tidak ada regresi aksesibilitas, responsive, atau CSRF yang diintroduksi oleh `feaac73`.**
Dua efek samping yang nyata adalah kode mati baru (2 endpoint orphan), bukan kerusakan.

---

## Ringkasan

| Tahap | Temuan | Severity tertinggi |
|---|---|---|
| 3 — Aksesibilitas | 10 | High (div `onclick` tanpa keyboard, 17 baris) |
| 4 — CSRF + Responsive | 7 | High (`api-vpn-cert-toggle.php` tanpa CSRF) |
| 5 — Komentar basi / kode mati | 7 | Medium |
| Luar cakupan — bug fungsional | 2 | High (2 fitur fatal error) |
| **Total** | **26** | |

Putusan NIHIL dari review sebelumnya **tidak akurat** untuk tahap 3 dan 5.
Tahap 4 benar NIHIL untuk CSRF di `frontend/action/` (middleware pusat memang
menutup semuanya — kemungkinan inilah yang dicek), tapi halaman POST di **luar**
`/action/` tidak ikut terperiksa, dan di situlah `api-vpn-cert-toggle.php` lolos.

### Urutan perbaikan yang disarankan

1. `api-vpn-cert-toggle.php` — tambah validasi CSRF (pola `onu-types.php:15–20`). Keamanan.
2. `snmp-sync.php` + `backup-config.php` — betulkan 3 path per file (`../` → `../../`, `dirname(__DIR__)` → `dirname(dirname(__DIR__))`). Dua fitur dari mati jadi hidup, diff ~6 baris.
3. `onu-detail.php:277–500` — tambah `role="button"` + `tabindex="0"` + handler `Enter`/`Space` pada 17 `.detail-plain-row[data-clickable]`. Paling baik lewat satu delegated listener di footer skrip, bukan 17 tambalan.
4. `logs.php` — `form-input` → `form-control`, bungkus form dengan `.filter-row`, tambah `aria-label` ke 3 kontrol + tombol search. Satu file, memperbaiki 1 temuan tahap 3 dan 2 temuan tahap 4 sekaligus.
5. `configured.php` — `aria-label` untuk 9 kontrol filter; `role="status"` + `aria-live="polite"` pada `#toast-container`.
6. Hapus `frontend/style.css` (salinan mati), `backend/db.php.bak`, blok CSS `.sidebar*` + `.detail-grid-2col*`, dan dua endpoint orphan `get-debug-cli.php` / `test-olt.php` — atau sambungkan kembali `test-olt.php` kalau tombol uji koneksi OLT memang masih diinginkan (keputusan produk, bukan teknis).
7. `vpn-tunnels.php:95` — `!==` → `hash_equals()`. Satu baris, konsistensi.
8. Tambahkan satu `<h1>` per halaman (mis. hidupkan kembali `#page-title` yang CSS-nya sudah ada) dan rapikan urutan heading di `dashboard.php` + `vpn-tunnels.php`.
