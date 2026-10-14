# Audit Konsistensi Desain — SmartOLT Frontend

**Tanggal:** 14 Oktober 2026
**Scope:** 23 file PHP (`frontend/*.php`) + `public/style.css` (523 baris)
**Metode:** Dua audit paralel — visual/struktural (frontend) + kode/redundansi (reviewer). Hasil digabung, deduplikasi, dan diverifikasi silang di dokumen ini.
**Bukan ganti warna/tema.** Temuan bersifat refactor struktural — mematuhi skema yang sudah ada.

---

## Ringkasan Eksekutif

| Metrik | Jumlah |
|--------|--------|
| Jenis komponen UI diaudit | **15** |
| Total inkonsistensi ditemukan | **~100** (71 visual + 30 kode) |
| Inline `style="..."` di semua PHP | **1066** |
| Class tanpa definisi CSS (broken) | **9** |
| Dead CSS class (0 pemakaian) | **25** |
| Selector duplikat di style.css | **8** (3 konflik nyata) |
| Blok `<style>` lokal di PHP | **8 file** (total ~600 baris) |
| Sistem tombol paralel | 2 (`.btn` vs `.btn-solt`) |
| Sistem tabel paralel | 2+ (`.data-table` vs `.smartolt-data-table` vs inline) |
| Sistem badge/label paralel | 7 variasi |
| Nilai font-size berbeda | **40** |
| Nilai border-radius di luar skala | **4px** (56x), **6px** (38x) |
| Hex warna hardcoded | **75** |
| Sizing ikon inline | **131** |

### Root cause
Pondasi desain sudah baik (`:root` punya token radius, warna, shadow, durasi; ada komponen `.btn`, `.data-table`, `.content-card`, `.modal`, `.chip`, `.filter-panel`). Masalah: erosi seiring fitur baru — blok `<style>` lokal, inline style, sistem paralel, alih-alih extend yang ada.

### Yang sudah sehat (jangan diubah)
- **Icon library 100% Lucide** (178 pemakaian, 0 library lain)
- **Semua tabel punya wrapper scroll** (`<table>` tanpa wrapper = 0)
- **Token warna `:root`** dipakai cukup konsisten di komponen kanon

---

## Temuan Terparah (Top 8 — urut dampak visual)

| # | Temuan | Dampak | File |
|---|--------|--------|------|
| 1 | `.bg-green`/`.bg-red`/`.bg-orange` terdefinisi 2x di style.css dengan arti bertentangan — stat icon biru solid, hijau/merah/oranye pucat+border | **Dashboard: 4 ikon stat berdampingan, 1 beda sendiri** | style.css:206-209 vs 253-257, dashboard.php |
| 2 | `.btn-solt*` (61x) di onu-detail vs `.btn*` (128x) di semua halaman lain — padding, radius, border, font-weight beda | User bolak-balik configured ↔ onu-detail tiap hari | onu-detail-css.php:149, onu-detail.php |
| 3 | `content-card` dipakai 0x di olt-detail & onu-detail — dua halaman tersering diakses pakai panel lokal | Transisi daftar → detail terasa pindah aplikasi | olt-detail.php, onu-detail.php |
| 4 | `.close-btn` dipakai 9x tanpa definisi CSS — tombol tutup modal tampil default browser | Tombol browser-default di tengah UI bertema | auth-onu, olt-detail, onu-detail |
| 5 | 9 class dipakai tanpa definisi CSS (badge-online/offline/warning, badge-blue/green/red, btn-action, btn-block, btn-port-configure) | Badge status tanpa warna, tombol tanpa style | settings-users, vpn-tunnels, configured, onu-detail |
| 6 | 75 hex hardcoded — tidak adaptif light mode | Warna salah di light mode | onu-detail 23, header 18, olt-detail 13, dashboard 6 |
| 7 | Filter bar beda di 10 halaman — `.filter-panel` hanya dipakai 1x (configured) | Tinggi bar, jarak, perataan beda tiap menu | semua halaman daftar |
| 8 | 40 nilai font-size beda, unit rem/px campur, 0 token | Teks beda-beda antar halaman | seluruh frontend |

---

## 1. TOMBOL

### Variasi ditemukan: 7 sistem

| # | Sistem | Definisi | Dipakai di |
|---|--------|----------|-----------|
| 1 | `.btn` + `.btn-primary/secondary/danger/success/warning/outline/back` + `.btn-xs/.btn-sm` | style.css — **KANON** | 19 halaman |
| 2 | `.btn-solt` + `.btn-solt-blue/green/orange/yellow/red` (61x) | onu-detail-css.php:149-192 | onu-detail.php |
| 3 | `.btn-action` + inline hex hardcoded | **tidak terdefinisi** | settings-users.php |
| 4 | `.btn-icon-square`, `.btn-link-plain` | auth-onu.php:43-44 | auth-onu.php |
| 5 | `.btn-login`/`.btn-register` (gradient, radius 6px) | login.php:51, register.php:46 | login, register |
| 6 | `.btn-detail` (dalam `.data-table`) | style.css | splitters.php |
| 7 | `.header-btn`, `.close-btn`, `.btn-port-configure`, `.btn-block` | 2 ada di style.css, 2 tidak | header, onu-detail, configured |

### Inkonsistensi kunci
- **Urutan class tidak baku:** `btn btn-sm btn-primary` vs `btn btn-primary btn-sm` (bahkan dalam satu file: logs.php)
- **Ukuran aksi tabel 5 versi:** `btn-xs` (7 halaman), `btn-sm` (2 halaman), `btn-action` icon-only inline, `btn-detail`, `btn-solt`
- **Warna "Edit" beda:** `btn-warning` (settings-olt), `btn-secondary` (settings-speed-profiles), kuning inline (settings-users), `btn-outline` (vpn-tunnels, olt-detail)
- **Tombol ikon kecil 3 solusi:** `.btn-icon-square` (38x38), `.btn-action` (padding 6px), `.btn-solt`
- `.btn` radius `8px` literal (bukan `var(--radius-sm)`); skala ukuran campur px/rem

### Kanon
`.btn` + modifier semantik style.css. Aksi tabel: **`btn-xs`**. Urutan: `btn btn-<variant> btn-<size>`. Tambah varian `btn-icon` berbasis `.btn`.

---

## 2. TABEL DATA

### Variasi: 5 sistem

| # | Sistem | Definisi | Dipakai di |
|---|--------|----------|-----------|
| 1 | `.table-responsive` > `.data-table` — **KANON** | style.css | 12 halaman |
| 2 | `.smartolt-data-table` (didefinisikan 2x beda) | olt-detail.php:90-94, onu-detail-css.php:265-284 | olt-detail (3x), onu-detail (1x) |
| 3 | `.pr-table` + `.pr-table-wrap` | olt-detail.php:90-104 | olt-detail (1x) |
| 4 | `.smartolt-details-table` (key-value) | onu-detail-css.php:49 | onu-detail |
| 5 | `<table>` tanpa class, full inline | — | onu-detail (12 tabel) |

### Inkonsistensi
- `border-collapse`: `.data-table`→`collapse`, `.smartolt-data-table`/`.pr-table`→`separate`
- **Padding sel 3 versi:** `10px 14px` (kanon), `12px 16px` (smartolt), `4px 8px`–`6px 8px` (inline)
- **Font size tabel:** kanon th `11px`/td `13px`, smartolt th `0.72rem`/body `0.85rem`
- **Zebra striping & sticky header:** hanya `.data-table` punya; tabel lain kehilangan
- `top: 56px` sticky hardcoded (tidak ada `--header-height`)

### Kanon
`.table-responsive` > `.data-table`. Lebur `.smartolt-data-table`, `.pr-table`, 12 tabel inline. Tambah `.data-table--compact` bila butuh padat.

---

## 3. CARD / PANEL

### Variasi: 8 jenis wadah

| # | Class | Dipakai di |
|---|-------|-----------|
| 1 | `.content-card` + `.card-header` + `.card-body` — **KANON** | 11 halaman |
| 2 | `.card` (satu-satunya) | onu-types.php |
| 3 | `.tr069-panel` (14x) | tr069-management (+ onu-detail → dead class) |
| 4 | `.olt-tab-panel` + `.olt-section-head` + `.olt-section-actions` | olt-detail |
| 5 | `.health-card` (7x) | tr069-management |
| 6 | `.stat-card` / `.stats-grid` (19x) | dashboard, olt-detail, tr069-management |
| 7 | `.olt-stat-pill` (3x) | olt-detail |
| 8 | `.login-card`, `.register-card`, `.panel-render`, `.view-panel`, `.olt-header-card` | lokal per file |

### Inkonsistensi
- **Header accent:** `.card-header border-accent` (garis kiri aksen 4px) di 7 halaman, **hilang** di settings-speed-profiles, splitters, vpn-tunnels
- **Radius 4 nilai:** `--radius-lg` (content-card), `--radius-md` (card, health-card, stat-card), `0 0 lg lg` (tr069-panel)
- **Padding 5 nilai:** 20px (card-body), 24px (card), 28px (tr069-panel), 18px (health-card), `16px 20px` (stat-card)
- Box-shadow literal `rgba(0,0,0,.04/.06)` padahal `--shadow-sm/md` ada

### Kanon
`.content-card` > `.card-header border-accent` > `.card-body`. Lebur `.tr069-panel`, `.olt-tab-panel`, `.health-card`, `.card`, `.olt-header-card`. `.stat-card` tetap khusus KPI.

---

## 4. MODAL

### Variasi: 4 pola + 3 mekanisme buka/tutup

| # | Pola | Dipakai di |
|---|------|-----------|
| 1 | `.modal` > `.modal-content` > header/body/footer, toggle `.open` — **KANON** | 10 halaman |
| 2 | `.modal-overlay` > `.modal-box`, display='flex' | settings-speed-profiles (satu-satunya) |
| 3 | `.modal` kanon tapi di-override total inline (Monokai) | header.php (debug), olt-detail.php (CLI) |
| 4 | `<div class="modal open">` hardcoded | olt-detail.php |

### Inkonsistensi
- **Tombol tutup:** `.modal-close` (terdefinisi, 10x) vs `.close-btn` (TANPA definisi, 9x)
- **Mekanisme toggle:** `classList.add/remove('open')` (69x), `classList.toggle('open')` (4x), `style.display` (41x) → yang pakai display **kehilangan animasi**
- **Judul modal:** `<h2>` vs `<h3>` dengan **6 ukuran berbeda** (`1.1rem`–`1.4rem`)
- **Max-width 5 nilai:** default 600px, lalu 520/800/450px + `width:90%`
- Modal di header/olt-detail override radius ke `6px` (kanon `--radius-lg` = 16px)

### Kanon
Pola #1 + `.modal-close` + `<h2>` + `classList.toggle('open')`. Tambah `--modal-sm/md/lg` untuk max-width.

---

## 5. BADGE / STATUS

### Variasi: 7 sistem penanda status

| # | Sistem | Definisi | Dipakai di |
|---|--------|----------|-----------|
| 1 | `.badge` + `.bg-green/red/orange/yellow/gray/blue` — **KANON** | style.css | configured, dashboard, logs, settings-olt, settings-speed-profiles, unconfigured |
| 2 | `.badge` + `.badge-online/offline/warning` | **TIDAK TERDEFINISI** | settings-users |
| 3 | `.badge` + `.badge-blue/green/red` | **TIDAK TERDEFINISI** | vpn-tunnels |
| 4 | `.chip` + `.chip-green/red/indigo/purple/teal/slate/amber/blue` (pill) | style.css | olt-detail, onu-detail, configured |
| 5 | `.status-icon-badge` + `.icon-green/red/yellow/gray` | style.css | configured, onu-detail |
| 6 | `.status-dot` + `.dot-green/red` | `<style>` tr069 | tr069 (7x), onu-detail (1x → dead) |
| 7 | `.pd-badge`, `.pd-status`, `.detail-status-dot`, `.olt-stat-pill`, `.smartolt-label` | lokal | tr069, onu-detail |

### Inkonsistensi
- **Dua bentuk bersaing:** `.badge` kotak (radius 6px, uppercase, 11px) vs `.chip` pil (radius 20px, 0.78rem) untuk status sama
- **4 cara tampil status online:** `.chip chip-green`, `.badge bg-green`, `.status-dot dot-green`, `.status-icon-badge icon-green`
- 6 badge di settings-users/vpn-tunnels pakai modifier tanpa definisi → tanpa warna
- Badge radius `6px` literal; chip radius `20px` literal

### Kanon
**`.chip`** + `.chip-<warna>` untuk status (paling modern, pakai token `--chip-*`, 8 warna lengkap). `.badge` disisakan untuk label non-status.

---

## 6. FORM INPUT

### Variasi: 4 sistem field

| # | Sistem | Dipakai di |
|---|--------|-----------|
| 1 | `.form-group` > `label` + `.form-control` — **KANON** | settings-olt, splitters, auth-onu, vpn-tunnels, settings-users |
| 2 | `.modal-label` + `.modal-input` | olt-detail, onu-detail |
| 3 | `.form-group` tanpa `.form-control` (selector turunan) | onu-types, tr069, login, register |
| 4 | input tanpa class, full inline | onu-detail (WAN, PPPoE, IP, WLAN) |

### Inkonsistensi kritis
- **DUA definisi input berbeda di style.css SENDIRI:**
  - `.form-control`: `padding:10px 14px`, `font-size:13px`, `height:40px`
  - `.form-group input/select/textarea`: `padding:8px 12px`, `font-size:0.9rem`, tanpa height
  - → tinggi field beda antar halaman!
- `.modal-input` background `--bg-secondary` vs `.form-control` background `--bg-primary` → field modal beda warna
- **Label 3 versi:** `.form-group label` (13px, 500, normal) vs `.modal-label` (0.78rem, 700, UPPERCASE) vs inline
- Lebar field ditentukan inline: `width:200px`, `220px`, `150px`, `105px;height:32px`, `90px`, `flex:1`

### Kanon
`.form-group` > `label` + `.form-control`. Hapus selector `.form-group input/select/textarea`. Lebur `.modal-input`/`.modal-label`. Tambah `.form-group--compact` untuk modal.

---

## 7. FILTER BAR

### Variasi: 3 pola

| # | Pola | Dipakai di |
|---|------|-----------|
| 1 | `.filter-panel` > `.filter-row` > `.filter-item` — **KANON** | **hanya configured.php** |
| 2 | `.section-actions` + inline flex | logs, settings-speed-profiles |
| 3 | Input/select ditempel di `.card-header` | splitters, logs |

### Inkonsistensi
`.filter-panel` ada di style.css lengkap tapi **1 dari 23 halaman** memakainya. 10 halaman daftar lain susun sendiri. Hanya 3 halaman punya search (configured, logs, splitters).

### Kanon
Pola `.filter-panel` — terapkan ke semua halaman daftar.

---

## 8. PAGINATION

### Variasi: 4 pola, hanya 4 halaman punya pagination

| # | Pola | Dipakai di |
|---|------|-----------|
| 1 | `.pagination-bar` + `.pagination-buttons` | configured |
| 2 | Sama #1, JS-rendered | unconfigured |
| 3 | `<nav style="display:flex;...">` | logs |
| 4 | `<div style="...padding:12px 16px;border-top:1px solid">` | splitters |

### Inkonsistensi
- Penempatan: di luar card vs di dalam card sebagai footer
- Ukuran tombol: `btn-xs` (3 halaman) vs `btn-sm` (logs)
- Teks ringkasan 3 format + 0 format (logs)
- `.pagination-bar` & `.pagination-buttons` **0 definisi CSS**
- 7 tabel panjang tanpa pagination sama sekali

### Kanon
Pola splitters (footer dalam card, `space-between`). Angkat ke style.css.

---

## 9. EMPTY STATE

### Variasi: 8 pola inline

| Pola | Padding | File |
|------|---------|------|
| `text-align:center;color:var(--text-muted);padding:16px` | 16px | configured, logs, unconfigured |
| Tanpa padding | 0 | dashboard, settings-olt, settings-speed-profiles |
| `padding:30px` | 30px | tr069, unconfigured |
| `padding:24px` | 24px | splitters |
| `padding:6px;border:1px solid...` | 6px | onu-detail |
| `.vlan-row-empty` | 36px | olt-detail |
| `.pr-empty` | — | olt-detail |
| `<p>` biasa | 0 | vpn-tunnels |

### Inkonsistensi
**6 nilai padding** untuk peran identik. Hanya olt-detail punya class. Nada pesan campur "Tidak ada" vs "Belum ada". Tidak ada ikon/CTA. vpn-tunnels: teks kiri, warna normal.

### Kanon
Buat `.empty-state` (center, padding 24px, color `--text-muted`). Standarkan: "Belum ada <X>." + CTA bila relevan.

---

## 10. LOADING STATE

### Variasi: 5 pola

| # | Pola | Dipakai di |
|---|------|-----------|
| 1 | `.loading-spinner` > `.spinner` — **KANON** | settings-olt, unconfigured |
| 2 | `.loading-spinner.hidden` + `.skeleton` | auth-onu |
| 3 | `.spinner-small` (didefinisikan **3x di 3 file**) | configured, olt-detail, onu-detail |
| 4 | `.pr-loading` | olt-detail |
| 5 | Teks loading di tabel, tanpa spinner | olt-detail |

### Inkonsistensi
`.spinner-small` **3 salinan** di 3 file (tidak ada di style.css). Hanya auth-onu pakai skeleton. 17 halaman **tanpa loading state**. Spinner track token beda: `.spinner` pakai `--border-color`, `.spinner-small` pakai `--spinner-track`.

### Kanon
Pindahkan `.spinner-small` ke style.css, hapus 3 salinan. Pakai `.skeleton` untuk tabel async.

---

## 11. SPACING / PADDING

**`:root` TIDAK punya variable spacing.** Semua literal.

| Metrik | Variasi |
|--------|---------|
| `margin-bottom` | **12 nilai**: 4/5/6/8/10/12/15/16/18/20/24/25px |
| `padding` | **18+ kombinasi** |
| `gap` | **11 nilai**: 4/5/6/8/10/12/15/16/18/20/40px |

### Inkonsistensi
- Jarak antar section: `16px`, `20px`, `24px`, **`26px`** (nilai unik, olt-detail)
- Padding card: 18/20/24/28px — **4 ritme** untuk isi card
- Padding modal: `16px 24px` (kanon) vs `15px 24px` inline (12 tempat) — selisih 1px dari copy-paste
- Grid gap: 16/20/40px

### Kanon
Tambah skala 4px: `--space-1:4px` … `--space-6:24px`, `--space-7:32px`. Card padding = `--space-5` (20px).

---

## 12. BORDER-RADIUS

### Token `:root` ada: `--radius-sm:8px`, `--radius-md:12px`, `--radius-lg:16px`

### Pemakaian nyata vs token

| Nilai | Frekuensi | Status |
|-------|-----------|--------|
| **`4px`** | **56x** | TIDAK di skala (paling sering!) |
| **`6px`** | **38x** | TIDAK di skala |
| `50%` | 10x | OK (lingkaran) |
| `var(--radius-sm)` | 11x | OK |
| `var(--radius-md)` | 9x | OK |
| `var(--radius-lg)` | 3x | OK |
| `8px` literal | 5x | ≈ --radius-sm, hardcoded |
| `3px`, `10px`, `99px` | 5x | Di luar skala |

### Masalah
- Fallback salah: `var(--radius-sm, 6px)` dan `var(--radius-sm, 4px)` — nilai asli **8px**
- style.css sendiri masih literal: `.badge` 6px, `.chip` 20px, `.stat-icon` 10px, `.btn-detail` 6px

### Rekomendasi
Tambah `--radius-xs:4px` (karena 56 pemakaian). `6px` → `var(--radius-sm)`. Tambah `--radius-pill:999px`. Perbaiki fallback.

---

## 13. FONT-SIZE

**`:root` TIDAK punya variable font-size.** 40 nilai berbeda, unit rem/px campur.

### Masalah utama
- **Unit campur dalam satu komponen:** `.btn` 13px, `.btn-sm` 0.8rem
- **9 ukuran nyaris sama:** `0.8/0.8125/0.82/0.85/0.875/0.88/0.9/0.92/0.95rem` (12.8–15.2px range) — noise
- **Heading tanpa skala:** `<h1>` 20px (olt-detail) vs default (lain), `<h3>` modal 6 ukuran berbeda
- **Label kecil 7 ukuran:** 0.68/0.72/0.75/0.78/0.8/0.8125/11px

### Kanon
6 token: `--fs-xs:0.75rem` (label/th), `--fs-sm:0.8125rem` (13px — body, btn, input), `--fs-base:0.875rem` (14px), `--fs-lg:1rem`, `--fs-xl:1.125rem` (judul modal), `--fs-2xl:1.5rem` (KPI). Satu unit: `rem`.

---

## 14. ICON USAGE

### Titik paling sehat: 100% Lucide, 178 pemakaian

### Inkonsistensi
- **12 ukuran berbeda**, semua inline: 10/11/12/13/14/15/16/17/18/20/22/48px
- **Judul section 3 ukuran ikon:** 17px (olt-detail, 8x), 18px (tr069, vpn-tunnels), 16px (settings-users) — peran identik
- **Hanya 1 dari 178** ukurannya dari CSS (`.status-icon-badge svg`)
- **Ikon di header.php** (21 sizing inline, tampil setiap halaman) — ukuran campur 14/16/18px

### Kanon
3 class dari CSS: `.icon-sm` 14px (tombol/tabel), `.icon-md` 18px (judul section), `.icon-lg` 22px (status). `.btn svg` otomatis 14px.

---

## 15. RESPONSIVE / TABLE WRAPPER

### Variasi: 3 pendekatan

| # | Pola | Dipakai di |
|---|------|-----------|
| 1 | `.table-responsive` — **KANON** | 32 pemakaian, 13 halaman |
| 2 | `.pr-table-wrap` (+ background + shadow) | olt-detail (5x) |
| 3 | `overflow-x:auto` inline | onu-detail (beberapa) |

Positif: semua tabel punya wrapper. Masalah: `.pr-table-wrap` = duplikat `.table-responsive` + estetika beda. Breakpoint tersebar, tidak ada daftar resmi.

---

## Temuan Kode (dari audit reviewer)

### CSS Conflict: `.bg-green/red/orange` didefinisikan 2x
Definisi pertama (baris 206-209): solid fill untuk stat-icon.
Definisi kedua (baris 253-257): tint + teks + border untuk badge.
Specificity sama → definisi kedua menang. **Dashboard: ikon biru solid, hijau/merah/oranye pucat — sejajar.**

### Inline style vs class yang sudah ada
- **41 form control** ditulis ulang inline di onu-detail (`.form-control`/`.modal-input` sudah ada)
- **13 tabel** ditulis ulang inline (`.data-table` sudah ada)
- **31 tombol** pakai class + inline sekaligus → override padding `.btn-xs`
- **131 sizing ikon** inline (CSS hanya atur 1)
- **30 warna muted** inline (`color:var(--text-muted)`) — layak jadi `.text-muted`

### Dead CSS — 25 class, terverifikasi 0 pemakaian
```
.action-buttons-group    .detail-container       .ont-image-gpon
.badge-pulse             .detail-table           .physical-ont-image-container
.bg-gray                 .flex-column-center     .signal-gauge-container
.card-title              .form-control-static    .signal-reading
.chevron-icon            .motion-slide-left      .stat-label
.chip-edit               .notification-badge     .stat-value
.col-12                  .ont-image-epon         .tab-active-indicator
.col-6                   .collapse-content
.content-area            .detail-card-header
```
Termasuk `.detail-table` (4 rule lengkap, 0 pemakaian) dan 2 data-URI SVG (~1.5KB) tak terpakai.

**Hati-hati:** `.bg-yellow`, `.icon-red`, `.icon-gray` **BUKAN dead** — dibangun dinamis di configured.php.

### Duplikasi lintas file
| Komponen | Lokasi | Masalah |
|----------|--------|---------|
| `.spinner-small` | configured, olt-detail, onu-detail | 3 salinan |
| `@keyframes spin` | style.css + onu-detail | redefinisi |
| `.health-card` | olt-detail + tr069 | 2 definisi |
| Modal padding | style.css vs settings-speed-profiles | `16px 24px` vs `14px 18px` |
| Debug modal Monokai | header + olt-detail | duplikat penuh (~150 baris) |
| `.form-group/.form-control` | style.css vs onu-detail-css | override beda |

### Selector duplikat di style.css
**Konflik nyata:** `.bg-green/red/orange` (2x, arti bertentangan)
**Rawan:** `.stat-card:hover` (translateY -1px vs -2px), `.data-table tbody tr:hover` (background vs transform), `.form-control:focus` (shadow-glow vs rgba indigo hardcoded)
**Wajar:** duplikat dalam media query (`.top-header`, `.topnav-*`)

### Hex hardcoded — 75 instance
| File | Jumlah | Contoh |
|------|--------|--------|
| onu-detail.php | 23 | `#28a745`, `#dc3545`, `#6c757d` (palet Bootstrap) |
| header.php | 18 | Monokai `#1e1e1e`, `#a6e22e`; status `#10b981` |
| olt-detail.php | 13 | duplikat Monokai |
| dashboard.php | 6 | `rgba(239,68,68,0.15)`, `#f59e0b`, `#3081d1` |
| lainnya | 15 | tersebar |

Semua punya padanan token. Ganti = light mode berfungsi benar.

### Pattern duplication
1. **Debug modal** — duplikat penuh di header + olt-detail (~150 baris)
2. **Accordion rows** — 13x inline identik di onu-detail
3. **Label/value cells** — 20x inline, padahal `.detail-table` sudah ada (mati)
4. **Tab system** — 3 pola identik terpisah: `.olt-tab*`, `.tr069-tab*`, `.sp-subnav-tab*`

### Naming inconsistency — 7 grup

| Konsep | Varian | Kanon |
|--------|--------|-------|
| Tombol | `.btn-*` (128x) vs `.btn-solt-*` (61x) | `.btn-*` |
| Panel/kartu | `.content-card`, `.card`, `.health-card`, `.tr069-panel`, `.olt-tab-panel` | `.content-card` + modifier |
| Label/value | `.lbl/.val`, `.label/.value`, `.detail-plain-*`, `.smartolt-label` | `.detail-plain-*` |
| Status dot | `.dot-indicator`, `.detail-status-dot`, `.status-dot`, `.legend-dot` | `.status-dot` |
| Spinner | `.spinner`, `.spinner-small`, `.loading-spinner` | `.spinner` + `.spinner-sm` |
| Tabel | `.data-table`, `.detail-table`, `.smartolt-data-table`, `.pr-table-wrap` | `.data-table` + `.table-responsive` |
| Tombol tutup | `.close-btn` (9x, tanpa CSS) vs `.modal-close` (10x) | `.modal-close` |

**Prefix tidak seragam:** `smartolt-*`, `olt-*`, `tr069-*`, `sp-*`, `detail-plain-*`, `solt-*`

---

## Cross-Check Dampak Visual

### SANGAT KELIHATAN (halaman harian)
1. **Dashboard: ikon stat hijau/merah/oranye pucat, biru solid** — konflik `.bg-*`
2. **onu-detail: tombol `.btn-solt` beda dari `.btn`** — configured ↔ onu-detail tiap hari
3. **Dashboard ↔ configured: stat card vs stat bar inline** — pola sama, markup beda total
4. **Filter bar beda di 10 halaman daftar** — terasa setiap pindah menu
5. **olt-detail & onu-detail tanpa shell `content-card`** — transisi pindah aplikasi
6. **Tombol tutup modal `.close-btn` tanpa style** — tombol browser-default
7. **Ikon header.php ukuran campur** — hadir di setiap halaman
8. **Hex hardcoded dashboard & header** — tidak adaptif light mode

### SEDANG (sering diakses, beda halus)
9. Input onu-detail radius 4px vs 8px halaman lain
10. Tabel onu-detail tanpa zebra striping & hover
11. Dua layout label-value di onu-detail
12. Ring fokus input indigo, bukan warna aksen
13. Tinggi baris tabel beragam (padding 4/5/6px tercampur)
14. Modal settings-speed-profiles padding beda

### KECIL (jarang / developer-facing)
15. Debug modal Monokai hardcoded (light mode tabrakan)
16. 25 dead CSS class
17. Tab system terpisah 3x
18. `.spinner-small` & `@keyframes spin` duplikat

---

## Skor Per File

| File | Inline style= | Hex literal | `<style>` blok | Sistem sendiri | Skor |
|------|:---:|:---:|:---:|:---:|:---:|
| **onu-detail.php** | 629 | 61 | ya | btn-solt, tabel inline, smartolt-* | **sangat tinggi** |
| **olt-detail.php** | 114 | 14 | ya | smartolt-data-table, pr-table, olt-tab-panel, olt-stat-pill, modal-input | **sangat tinggi** |
| **onu-detail-css.php** | — | 45 | ya | pendukung onu-detail | tinggi |
| **tr069-management.php** | 44 | 0 | ya | tr069-panel, health-card, status-dot | tinggi |
| **header.php** | 34 | 18 | — | modal Monokai override | sedang |
| **settings-users.php** | 27 | 6 | — | btn-action, badge-online/offline/warning | sedang |
| **configured.php** | 43 | 12 | — | pagination-bar, spinner-small, btn-block | sedang |
| **vpn-tunnels.php** | 36 | 1 | — | badge-blue/green/red, tanpa border-accent | sedang |
| **settings-speed-profiles.php** | 10 | 1 | ya | modal-overlay/modal-box | sedang |
| **unconfigured.php** | 22 | 1 | — | pagination JS, btn disabled | rendah |
| **splitters.php** | 22 | 0 | — | pagination inline, tanpa border-accent | rendah |
| **auth-onu.php** | 15 | 2 | ya | btn-icon-square, skeleton | rendah |
| **onu-types.php** | 10 | 0 | — | `.card` sendiri | rendah |
| **settings-olt.php** | 27 | 5 | — | — | rendah |
| **dashboard.php** | 20 | 6 | — | — | rendah |
| **logs.php** | 11 | 0 | — | pagination btn-sm | rendah |
| **login/register** | 1/1 | 1/1 | ya | halaman auth terpisah | rendah |
| **footer/index/api-vpn-*** | 0 | 0 | — | — | bersih |

### Referensi paling konsisten (jadikan contoh saat refaktor)
1. **settings-olt.php** — pola terlengkap: `.content-card` + `.card-header border-accent` + `.table-responsive` + `.data-table` + `.form-group`/`.form-control` + `.modal`/`.modal-close`
2. **logs.php** — card + tabel + filter bersih (kurang: pagination pakai `btn-sm` seharusnya `btn-xs`)
3. **dashboard.php** — `.stats-grid` + `.stat-card` + `.data-table`, minim penyimpangan (kurang: `.bg-*` conflict)
4. **splitters.php** — pola pagination terbaik (kurang: tanpa `border-accent`)

---

## Urutan Prioritas Perbaikan

### P0 — Higienis (tanpa ini, perbaikan lain ikut rusak) + dampak langsung

| # | Aksi | Dampak | Effort | File tersentuh |
|---|------|--------|--------|----------------|
| 1 | Namespace konflik `.bg-*`: `.stat-icon.bg-green` (solid) vs `.badge.bg-green` (tint) | Dashboard langsung benar | ~4 baris CSS | style.css |
| 2 | Hapus duplikasi blok di style.css (`.btn`, `.data-table`, `.modal*`, `.form-control`, `.badge` tertulis 2-3x) | Perbaikan lain tidak rusak karena kena 1 salinan saja | Kecil | style.css |
| 3 | Tambah token hilang ke `:root`: `--space-1..7`, `--fs-xs..2xl`, `--radius-xs:4px`, `--radius-pill`, `--header-height:56px`, `--control-height:40px`, `--icon-sm/md/lg` | Dasar untuk semua perbaikan berikutnya | Kecil | style.css |
| 4 | Ganti `.close-btn` → `.modal-close` (9x) | Tombol tutup modal berstyle | Trivial | auth-onu, olt-detail, onu-detail, onu-types, splitters |
| 5 | Fix `.bg-yellow`, `.icon-red`, `.icon-gray` — **JANGAN dihapus**, terpakai dinamis | Mencegah regression | — | style.css |

### P1 — Broken (elemen tampil tanpa style)

| # | Aksi | Dampak | Effort |
|---|------|--------|--------|
| 6 | Buat definisi untuk `.badge-online/offline/warning` (settings-users), `.badge-blue/green/red` (vpn-tunnels) | Badge berwarna | Kecil |
| 7 | Buat definisi `.btn-action`, `.btn-edit-user/-manage-access/-delete` (settings-users) | Tombol berstyle | Kecil |
| 8 | Buat definisi `.btn-block` (configured), `.btn-port-configure` (onu-detail) | Tombol berfungsi | Kecil |
| 9 | Pindahkan `.tr069-panel` & `.status-dot` ke style.css (saat ini hanya di `<style>` tr069) | Dead class hidup | Kecil |
| 10 | Tambah style `.btn.disabled` | Tombol disabled terlihat mati | Trivial |
| 11 | Perbaiki fallback: `var(--radius-sm, 6px)` → `var(--radius-sm, 8px)` | Fallback benar | Trivial |

### P2 — Inkonsistensi besar (terlihat saat navigasi antar halaman)

| # | Aksi | Dampak | Effort | File tersentuh |
|---|------|--------|--------|----------------|
| 12 | Peta `.btn-solt-*` → `.btn-*` di onu-detail (61x, padanan 1:1) | Tombol seragam | Sedang | onu-detail.php, onu-detail-css.php |
| 13 | Pakai `.filter-panel` di 10 halaman daftar (ikuti pola configured) | Filter bar seragam | Sedang | 10 file PHP |
| 14 | Pakai `.form-control` untuk 41 form inline; hapus selector `.form-group input/select/textarea` di style.css | Input seragam | Sedang | onu-detail, tr069 + style.css |
| 15 | Pakai `.data-table` untuk 13 tabel inline + lebur `.smartolt-data-table`/`.pr-table` | Tabel seragam | Sedang | onu-detail, olt-detail + style.css |
| 16 | Bungkus olt-detail & onu-detail dengan `content-card` + `card-header` | Transisi halaman menyatu | Besar | olt-detail.php, onu-detail.php |
| 17 | Ganti 75 hex hardcoded → token yang sudah ada | Light mode berfungsi | Sedang | 8+ file |
| 18 | Lebur `.modal-overlay`/`.modal-box` → `.modal`/`.modal-content` + `.open` di settings-speed-profiles | Modal konsisten + animasi | Kecil | settings-speed-profiles.php |
| 19 | Unifikasi badge: `.chip` untuk status, `.badge` untuk label non-status | Satu sistem status | Sedang | 10+ file |

### P3 — Standarisasi

| # | Aksi | Dampak | Effort |
|---|------|--------|--------|
| 20 | Buat `.empty-state` + standarkan kalimat | Empty state seragam | Kecil |
| 21 | Angkat pagination ke style.css (ikuti pola splitters) + tambah ke tabel panjang | Navigasi data | Sedang |
| 22 | Petakan 40 font-size → 6 token, satu unit `rem` | Tipografi seragam | Besar |
| 23 | 12 ukuran ikon inline → 3 class `.icon-sm/md/lg` | Ikon seragam | Sedang |
| 24 | Ukuran aksi tabel 5 versi → `btn-xs` | Konsisten | Kecil |
| 25 | `.card-header border-accent` hilang di 3 halaman → tambah | Card header seragam | Trivial |
| 26 | Pindahkan 8 blok `<style>` ke style.css | Single source of truth | Besar |
| 27 | `.spinner-small` 3 salinan → 1 di style.css | Konsisten | Kecil |
| 28 | Ekstrak debug modal ke partial (~150 baris hilang) | Kurangi duplikat | Kecil |
| 29 | Unifikasi tab system (olt-/tr069-/sp-) jadi satu class | Kurangi duplikat | Sedang |
| 30 | Hapus 25 dead CSS (setelah verifikasi target JS) | File lebih kecil | Kecil |
| 31 | Modal max-width 5 nilai → `--modal-sm/md/lg` | Konsisten | Kecil |
| 32 | Pusatkan breakpoint ke style.css | Responsif konsisten | Kecil |
| 33 | Ganti literal rgba di style.css → token shadow | Light mode patuh | Kecil |
| 34 | Ring fokus indigo → `--text-accent` | Aksen konsisten | Trivial |
| 35 | Tambah pagination ke 7 tabel panjang tanpa pagination | UX data | Sedang |

---

## File yang Perlu Disentuh

### style.css (wajib — hampir semua P0-P1 di sini)
- Hapus blok duplikat (`.btn`, `.data-table`, `.modal*`, `.form-control`, `.badge`, `.filter-*`, `.bg-*`)
- Namespace `.bg-*`: `.stat-icon.bg-*` (solid) vs `.badge.bg-*` (tint)
- Tambah token: spacing, font-size, radius-xs, radius-pill, header-height, control-height, icon-size
- Tambah class: `.empty-state`, `.text-muted`, `.icon-sm/md/lg`, `.btn-icon`, `.btn.disabled`
- Pindahkan: `.spinner-small`, `.tr069-panel`, `.status-dot` dari file lokal
- Fix: `.badge-online/offline/warning`, `.badge-blue/green/red`, `.btn-action`, `.btn-block`, `.btn-port-configure`
- Hapus selector `.form-group input/select/textarea`
- Fix fallback `var(--radius-sm, 6px|4px)`

### onu-detail.php + onu-detail-css.php (paling banyak menyimpang)
- 629 inline style → class
- `.btn-solt*` → `.btn*`
- 12 tabel tanpa class → `.data-table`
- Input tanpa `.form-control` → `.form-control`
- 5 `<h3>` modal → `<h2>`
- Hapus `.spinner-small` lokal
- Namespace `.bg-*` di markup

### olt-detail.php
- `.smartolt-data-table`/`.pr-table` → `.data-table`
- `.modal-input`/`.modal-label` → `.form-control`/`label`
- `.olt-tab-panel`/`.olt-section-head`/`.olt-stat-pill` → `.content-card` + modifier
- Hapus `.spinner-small` lokal
- Hapus duplikat debug modal → include partial
- 114 inline style → class

### configured.php
- 43 inline stat bar → `.stat-card` atau buat `.stat-pill`
- `.pagination-bar`/`.pagination-buttons` → style.css
- Hapus `.spinner-small` lokal (baris 541)
- `.btn-block` → definisi di style.css

### header.php
- 34 inline style (termasuk modal Monokai) → class
- 18 hex hardcoded → token
- `.close-btn` → `.modal-close`
- Ekstrak debug modal ke partial

### settings-users.php
- `.badge-online/offline/warning` → `.chip chip-green/red/amber`
- `.btn-action` → `.btn btn-xs btn-*`
- 27 inline → class

### vpn-tunnels.php
- `.badge-blue/green/red` → `.chip chip-blue/green/red`
- `.pagination-bar` → style.css
- Tambah `border-accent` ke card-header

### tr069-management.php
- `.tr069-panel`/`.health-card`/`.status-dot` → style.css
- `.tr069-tab*` → unified tab class
- 44 inline → class

### settings-speed-profiles.php
- `.modal-overlay`/`.modal-box` → `.modal`/`.modal-content` + `.open`
- Tambah `border-accent` ke card-header

### 10 halaman daftar (configured sudah OK)
- Terapkan `.filter-panel` pattern

### 3 halaman (settings-speed-profiles, splitters, vpn-tunnels)
- Tambah `.card-header border-accent`

---

## Estimasi

| Fase | Effort | Item |
|------|--------|------|
| P0 (higienis + broken) | ~1 hari | 11 item, sebagian besar style.css |
| P2 (inkonsistensi besar) | ~2-3 hari | 8 item, butuh sentuh 15+ file |
| P3 (standarisasi) | ~1-2 hari | 16 item, sebagian besar mekanis |

**Total: ~4-6 hari kerja.** Tidak ada langkah yang mengubah palet warna.