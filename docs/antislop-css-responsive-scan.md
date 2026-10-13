# Antislop Scan — CSS, Responsive, Kontras Warna

Branch: `antislop-tahap2-5` · Scope: `frontend/` · Files scanned: **22**

Kontras dihitung programatis (WCAG 2.1 relative luminance, alpha dikomposit ke
surface induk). `color-mix()` token diresolusi manual ke sRGB sebelum dihitung.

**Catatan path:** task menyebut `public/style.css`. File sebenarnya
`frontend/public/style.css` (559 baris). Ada juga `frontend/style.css` (562
baris) yang **tidak dirujuk file mana pun** — lihat R-09.

## Ringkasan

| Area | Jumlah |
|---|---|
| Kontras (CONTRAST) | 18 |
| Responsive (R) | 11 |
| Form (F) | 8 |
| Touch target (T) | 5 |
| **Total** | **42** |

Severity: **HIGH** = melanggar WCAG AA pada teks fungsional / layout pecah ·
**MED** = degradasi nyata · **LOW** = higiene.

---

## 1. Kontras Warna

67 pasangan diuji, 32 gagal ambang numerik. Setelah dibuang
yang **bukan teks** (dot dekoratif, fill chart — tidak kena 1.4.3; lihat
§1.4), **18 isu nyata** tersisa.

### HIGH

**CONTRAST-01 — `.btn-success` teks putih di `#10b981` → 2.54:1** (butuh 4.5)
`public/style.css:240`
```css
.btn-success { background: var(--color-success); color: var(--color-white); }
```
Paling parah dari semua tombol. `--color-green: #10b981` terlalu terang untuk
teks putih. Fix: pakai teks gelap, atau gelapkan background.
```css
.btn-success { background: color-mix(in srgb, var(--color-green) 75%, var(--color-black)); color: var(--color-white); }
/* 75% → 4.9:1 */
```

**CONTRAST-02 — `.btn-danger` putih di `#ef4444` → 3.76:1** `public/style.css:241`
Fix sama: `color-mix(in srgb, var(--color-red) 88%, var(--color-black))` → 4.6:1.

**CONTRAST-03 — `.stat-icon` putih di green/orange/red/blue → 2.15–4.05:1**
`public/style.css` (`.bg-green`, `.bg-orange`, `.bg-red`, `.bg-blue`)
Terburuk `.bg-orange` (`#f59e0b`) **2.15:1**. Jika ikon hanya dekoratif dan
angka stat ada di luar box, ini masuk 1.4.11 non-text (butuh 3:1) — `.bg-orange`
dan `.bg-green` **masih gagal**. Fix: turunkan luminance background, atau ikon
jadi `var(--color-ink)` di varian terang.

**CONTRAST-04 — `.btn-primary` di light mode → 3.87:1** `public/style.css`
```css
.btn-primary { background: var(--text-accent); color: var(--bg-primary); }
```
`--bg-primary` light = `#f8fafc` di atas `#3081d1` → 3.87:1. Dark mode lolos
(4.73:1) karena `--bg-primary` `#0b0f19`. Satu aturan, dua hasil — dark aman,
light gagal.

**CONTRAST-05 — link `--text-accent` di light mode → 3.87:1**
`#3081d1` di `#f8fafc`. Semua anchor body text. Fix: light mode override
`--text-accent: #1d4ed8` (≈6.3:1 di `#f8fafc`).

**CONTRAST-06 — `--text-accent` di `--bg-tertiary` → 3.63:1** (dark)
Kena `.card-header`, `.data-table th`, `.detail-card-header`. Accent dipakai
sebagai teks di permukaan yang lebih terang dari `--bg-primary`, dan rasio
jatuh dari 4.73 ke 3.63.

**CONTRAST-07 — topnav light mode, teks putih transparan di bar `#3081d1`**
`public/style.css:433-441`
| Elemen | Alpha | Rasio |
|---|---|---|
| `.header-btn` | .75 | **2.99:1** |
| `.topnav-item` | .80 | **3.18:1** |
| `.status-badge-container` | .90 | **3.58:1** |

Nav utama. Bahkan putih solid di `#3081d1` cuma 4.05:1 — bar-nya sendiri
terlalu terang. Fix: gelapkan bar light-mode ke `#1d4ed8`, lalu putih = 6.3:1
dan ketiga alpha di atas lolos.

**CONTRAST-08 — theme leak: `#f8fafc` hardcoded di modal dark**
`settings-users.php:121` dan `:152`
```html
<div style="... border: 1px solid #cbd5e1; background: #f8fafc; ...">
```
Background light hardcoded tanpa override warna teks. Anak elemennya pakai
`var(--text-color)` (undefined, lihat R-06) → mewarisi `--text-main` `#f3f4f6`.
Putih-di-putih = **1.05:1**, teks praktis hilang di dark mode. Ini bug paling
parah di laporan. Fix: `background: var(--bg-primary); border-color: var(--border-color);`

### MED

**CONTRAST-09 — chip/badge: fg saturated di tint 15% sendiri**
`public/style.css:385-398` (`--chip-alpha: 15%`)
| Chip | Rasio |
|---|---|
| indigo `#6366f1` | **3.09:1** |
| purple `#8b5cf6` | **3.23:1** |
| blue `#3081d1` | **3.36:1** |
| danger `#ef4444` | **3.73:1** |
| `.bg-orange` | **4.47:1** |

Green (4.97) / amber (5.74) / teal (5.17) lolos. Pola tint-nya benar, alpha-nya
saja terlalu rendah untuk hue gelap. Fix satu baris: `--chip-alpha: 22%` menaikkan
yang gagal tanpa merusak yang lolos.

**CONTRAST-10 — chip light mode, tint 10%** `public/style.css`
blue `#3081d1` **3.58:1**, green `#15803d` **4.34:1**, amber `#b45309` **4.35:1**.
Dua terakhir nyaris; blue jelas gagal.

**CONTRAST-11 — `[light] --text-muted` di `--bg-tertiary` → 4.34:1**
`#64748b` di `#f1f5f9`. Di `--bg-primary` lolos tipis (4.55:1), di tertiary
gagal tipis. Fix: `--text-muted: #55637a` di light mode → 5.3:1.

**CONTRAST-12 — `[light] .btn-warning` hitam di `#b45309` → 4.18:1**
Dark mode lolos besar (9.78:1); light gagal. Fix: teks putih di `#b45309` = 5.0:1.

**CONTRAST-13 — `onu-detail-css.php:168-190` fallback putih di brand terang**
```css
.btn-solt-orange { background: var(--color-warning, #fd7e14); color: #ffffff !important; }
```
Resolved `--color-warning` = `#f59e0b` → putih = **2.15:1**. `.btn-solt-green`
(`#10b981`) **2.54:1**, `.btn-solt-blue` (`#3081d1`) **4.05:1**. `!important`
mengunci warna gagal agar tak bisa di-override tema. `.btn-solt-yellow`
(`#212529` di amber, 7.18:1) dan `.btn-solt-red` (`#dc3545`, 4.53:1) aman.

**CONTRAST-14 — `onu-detail.php:2412` badge `#28a745` + teks putih → 3.13:1**

**CONTRAST-15 — `onu-detail.php:2479` `#fff` di `#6c757d` → 4.69:1 — LOLOS tapi**
font-size `0.8rem` (12.8px) bukan large text, jadi ambangnya tetap 4.5 — aman.
Dicatat karena marginnya tipis; jangan diterangkan lagi.

**CONTRAST-16 — `onu-types.php:79` `#64748b` di bg-card dark → 3.4:1**
Warna muted light-mode dipakai di permukaan dark. Fix: `var(--text-muted)`.

**CONTRAST-17 — `vpn-tunnels.php:402` `#ef4444` di bg-card dark → 4.3:1**
Juga `:335`, `:374`, `:429` pakai `var(--red)` yang **tidak terdefinisi**
(lihat R-06) → fallback ke warna terwarisi, tidak merah sama sekali.

### LOW

**CONTRAST-18 — `header.php:244` `#ef4444` di tint-nya sendiri → 4.08:1**
Status badge offline. Counterpart online (`#10b981`, 5.53:1) lolos.

### 1.4 Diperiksa, dinyatakan aman

Bukan teks → 1.4.3 tidak berlaku: `.download-dot` / `.upload-dot` /
`.signal-dot` (`onu-detail-css.php:260-262`), `.legend-dot` `#22d3ee` / `#a855f7`
(`onu-detail.php:567,572`), `.detail-status-dot`, `.dot-indicator`. Semuanya
`border-radius:50%` 10px tanpa teks anak, dan tiap satu **didampingi label teks**
(`Rx ONU`, `Rx OLT`) sehingga warna bukan satu-satunya pembawa makna → 1.4.1 juga lolos.

Blok CLI `olt-detail.php:577-589` seluruhnya lolos besar (6.5–15.6:1): palet
Monokai di `#1e1e1e` konsisten, termasuk `#94a3b8` 6.5:1 dan `#000` di `#a6e22e`
13.54:1. Body text dark (`#f3f4f6`/`#0b0f19`) **17.4:1**, light
(`#0f172a`/`#f8fafc`) **17.06:1** — fondasinya memang kuat; kegagalan
terkonsentrasi di warna brand/aksen, bukan teks utama.

---

## 2. Responsive

### HIGH

**R-01 — `onu-detail.php`: 14 `<table>`, 0 `.table-responsive`**
File terbesar (4194 baris), halaman terpenting, dan satu-satunya yang tabelnya
sama sekali tanpa wrapper scroll. 11 file lain konsisten memakainya
(`configured`, `dashboard`, `logs`, `settings-*`, `splitters`, `tr069`,
`unconfigured`, `vpn-tunnels` — semua 1:1). Jadi ini penyimpangan dari pola
yang sudah ada, bukan pola yang belum ada.
Diperburuk `onu-detail.php:2883`:
```js
html += '<table style="width:100%;border-collapse:collapse;white-space:nowrap;">';
```
`nowrap` + tanpa wrapper = tabel memaksa lebar viewport. `body { overflow-x: hidden }`
(`public/style.css:144`) lalu **memotong** kolom kanan, bukan membuatnya
bisa di-scroll — datanya tidak bisa diakses sama sekali di mobile.
Fix: bungkus `<div class="table-responsive">`, dan buang `white-space:nowrap`.

**R-02 — `olt-detail.php`: 5 `<table>`, 0 wrapper** — sama dengan R-01.

**R-03 — `onu-types.php`: 1 `<table>`, 0 wrapper** — sama.

**R-04 — `.data-table th` sticky bentrok dengan `.top-header` sticky**
`public/style.css:252` (`th`, `top:0`, `z-index:2`) vs `:171` (`.top-header`,
`position:sticky; top:0; z-index:50`).
Keduanya `top:0`. Header tabel akan menempel di y=0 **di belakang** top bar
56px (z-index 50 > 2), jadi tersembunyi persis saat sticky-nya dibutuhkan.
Fix: `th { top: 56px; }` atau beri wrapper `position:relative` agar
sticky-nya terkurung.

### MED

**R-05 — breakpoint sprawl: 6 nilai berbeda, tidak ada yang sama antar file**
| Breakpoint | Lokasi |
|---|---|
| 992px | `style.css:221`, `onu-detail-css.php:21,200` |
| 900px | `style.css:339,369` |
| 768px | `style.css:443`, `onu-detail-css.php:119` |
| 640px | `olt-detail.php:54,109` |
| 600px | `style.css:342`, `auth-onu.php:46` |
| 500px | `onu-detail-css.php:395` |

Enam breakpoint untuk satu aplikasi. `olt-detail.php` sendirian pakai 640px;
`auth-onu.php` 600px; `onu-detail-css.php` 500px. Antara 600–640px dan
900–992px ada zona di mana sebagian layout sudah collapse dan sebagian belum.
Fix: kurangi ke 3 (`768`, `992`) + 1 khusus small (`480`), dan karena semuanya
`max-width` (desktop-first), pertimbangkan `min-width` kalau mau mobile-first.

**R-06 — 6 CSS variable dipakai tapi tidak pernah didefinisikan**
| Var | Dipakai | Didefinisikan |
|---|---|---|
| `--bg-main` | 14× (`onu-detail.php:2411`–`3190`) | **0** |
| `--text-primary` | 4× (`style.css:457,460`; `onu-detail.php:4174,4180`) | **0** |
| `--text-color` | 2× (`settings-users.php:43,160`) | **0** |
| `--red` | 3× (`vpn-tunnels.php:335,374,429`) | **0** |
| `--text-secondary` | 1× (`style.css:466`) | **0** |
| `--primary-color` | 1× (`settings-users.php:151`) | **0** |

Tidak satu pun punya fallback (`var(--x, default)`) — sudah diverifikasi per
baris. Semua jadi `unset` → mewarisi, atau `initial` → hitam. Yang terparah
`--text-primary` di `.form-group input` (`style.css:460`): **warna teks setiap
input di aplikasi** tidak terdefinisi. Fix: `--text-primary`→`--text-main`,
`--text-secondary`/`--text-color`→`--text-muted`, `--bg-main`→`--bg-primary`,
`--red`→`--color-danger`, `--primary-color`→`--text-accent`.

**R-07 — `.form-group` didefinisikan dua kali, saling bertabrakan**
`public/style.css:278-279` lalu `:456-457`
```css
/* :279 */ .form-group label { ... font-weight: 500; color: var(--text-muted); font-size: 13px; }
/* :457 */ .form-group label { ... font-weight: 600; color: var(--text-primary); font-size: 0.9rem; }
```
Spesifisitas identik → yang kedua menang, blok pertama mati total. Pemenangnya
justru yang memakai var undefined (R-06), jadi label form kehilangan warnanya.
Fix: hapus `:456-457`, pindahkan properti yang diinginkan ke `:278-279`.

**R-08 — `.main-content` padding tidak pernah di-reset di ≤768px**
`:169` `padding: 24px 48px` → di-override jadi `16px` hanya di **992px**
(`:226`), dalam blok yang isinya aturan `.sidebar` mati (R-09). Blok 768px
(`:443-451`) hanya menyentuh elemen topnav. Jadi padding bergantung pada satu
baris yang bersebelahan dengan dead code. Diperparah `.top-header` yang pakai
`margin-left:-48px; margin-right:-48px` untuk membatalkan padding 48px itu —
begitu padding jadi 16px di 992px, margin −48px tidak lagi cocok dan header
meluber 32px per sisi.

### LOW

**R-09 — dead CSS: `.sidebar` + `#mobile-menu-toggle`**
`public/style.css:223-227`. `grep` ke seluruh `frontend/*.php`: **0 kemunculan**
`sidebar` maupun `mobile-menu-toggle` di markup. Layout sudah pindah ke topnav
(`.topnav-*`). Aturan `#mobile-menu-toggle { display: flex !important; }`
menyalakan elemen yang tidak ada. Fix: hapus; sisakan `.content-row` / `.col-*`
/ `.main-content` di blok itu.

**R-10 — `frontend/style.css` orphan, 562 baris, drift dari yang live**
Hanya `frontend/public/style.css` yang di-`<link>`. Keduanya berbeda:
orphan punya `.detail-status-dot.checking` + `@keyframes pulse-checking` dan
aturan `.topnav-dropdown-menu` mobile (fixed, left/right 8px) yang **tidak ada**
di file live; file live punya `:hover` dropdown yang tidak ada di orphan.
Risiko: edit mendarat di file yang salah dan tampak tidak berefek.
Fix: hapus orphan, atau port dulu aturan mobile dropdown yang hilang itu.

**R-11 — `onu-detail-css.php:395-409` hanya menargetkan satu modal**
Pola table-to-block (`display:block` untuk `table/tbody/tr/td`) di 500px
di-scope ke `#update-onu-mode-modal`. Teknik yang benar, dipakai sekali, tidak
digeneralisasi ke 13 tabel lain di file yang sama (R-01).

---

## 3. Form Consistency

### HIGH

**F-01 — `splitters.php`: 15 `<label>`, 0 `for=`, 0 input `id=`**
```html
<div class="form-group"><label>Nama</label><input type="text" name="name" class="form-control" required></div>
```
Label tidak terasosiasi **dan** input tidak punya `id`, jadi tidak ada
pengaitan implisit (label tidak membungkus input). Screen reader membacakan
input tanpa nama; klik label tidak memfokuskan field. Melanggar WCAG 1.3.1 +
3.3.2. 23 input terdampak. Fix: `id="splitter-name"` + `for="splitter-name"`.

**F-02 — `onu-types.php`: 22 `<label>`, 0 `for=`, 0 `id=`** — identik dengan F-01
(23 input, hanya 2 yang membungkus input sehingga tak sengaja lolos).

**F-03 — `logs.php`: 3 kontrol filter, 0 `<label>`, 0 `id`, 0 `aria-label`**
`logs.php:76-90` — `search`, `action_type`, `limit`. Hanya `placeholder="Cari log..."`
sebagai petunjuk; dua `<select>` tidak punya nama apa pun yang bisa diakses.
`select[name=limit]` juga `onchange="this.form.submit()"` — berubah konteks tanpa
peringatan (WCAG 3.2.2).

### MED

**F-04 — `for=` terpakai hanya di 4 dari 16 file**
| Pola | File |
|---|---|
| `for=` lengkap | `login` (2/2), `register` (3/3), `settings-olt` (18/18), `auth-onu` (14/21) |
| **0 `for=`** | `olt-detail` (16 label), `onu-detail` (57), `splitters` (15), `onu-types` (22), `tr069-management` (5), `vpn-tunnels` (6), `settings-speed-profiles` (3) |

`settings-olt.php` membuktikan konvensi yang benar sudah ada di repo —
mayoritas file hanya tidak mengikutinya. `onu-detail.php` paling besar:
57 label, 0 `for=`, 22 di antaranya tertolong karena membungkus input.

**F-05 — 45 input hanya mengandalkan `placeholder`, `aria-label` 0 di seluruh repo**
`grep -c aria-label` seluruh `frontend/*.php` → tidak ada hasil. Placeholder
hilang begitu user mengetik, dan tidak dibacakan konsisten → bukan pengganti
label (WCAG 3.3.2).

**F-06 — field numerik/terstruktur dipakai `type="text"`**
Distribusi: `text` 77×, `number` hanya 10×, `email`/`tel`/`url` **0×**.
| Field | Lokasi |
|---|---|
| IP address | `olt-detail.php:281`, `settings-olt.php:120,193`, `onu-detail.php:1366,4016` |
| Latitude/Longitude | `auth-onu.php:199,200`, `splitters.php:118,119,229,230`, `onu-detail.php:1578,1585` |
| Port ODB | `onu-detail.php:1562` |

Mobile tidak memunculkan keypad numerik. Catatan: `onu-detail.php:1578,1585`
**sudah** memakai `pattern=` untuk lat/long — jadi validasinya ada, tapi
`splitters.php` yang punya field sama tidak. Fix: `inputmode="decimal"` +
`pattern` untuk koordinat (jangan `type=number` — step/spinner merusak desimal);
IP cukup `pattern` + `inputmode="numeric"`.

### LOW

**F-07 — `required` tidak merata di field yang setara**
`settings-olt.php` 13 `required` vs `splitters.php` 2 dan `onu-detail.php` 2
(dari 116 input). `dashboard.php` 0 input (wajar — read-only, tidak dihitung isu).
Validasi client-side ada di sebagian form saja; server-side harus tetap
dianggap satu-satunya penjaga.

**F-08 — `.form-control` dipakai 84×, hanya 4 aturan; `.modal-input` 25× / 2 aturan**
Sementara `.form-group input` (yang di-styling paling lengkap) justru menarget
by-element, jadi `.form-control` di luar `.form-group` kehilangan styling.
Dua sistem penamaan untuk satu maksud, saling tumpang-tindih tak lengkap.

---

## 4. Overflow / Truncation

Sudah tercakup di R-01…R-03 (tabel tanpa wrapper) dan R-04 (sticky bentrok).
Tambahan:

- `body { overflow-x: hidden }` (`style.css:144`) — menyembunyikan **gejala**
  overflow, bukan menanganinya. Kombinasi dengan R-01 membuat kolom tabel
  terpotong permanen, bukan bisa di-scroll. Ini penguat tiap isu tabel di atas.
- `onu-detail.php:299` — satu-satunya tempat yang menangani teks panjang dengan
  benar: `overflow:hidden; white-space:nowrap; text-overflow:ellipsis` **plus
  `title=`** untuk teks penuh. Pola ini layak jadi acuan, belum diterapkan di tempat lain.
- `word-break` total 5 kemunculan (`break-word` 3, `break-all` 2) untuk seluruh
  aplikasi yang menampilkan serial number, MAC, dan path — kurang untuk data
  string panjang tanpa spasi.
- `.topnav-links { overflow-x: auto }` di 768px (`:446`) — penanganan benar,
  dan bukti pola scroll-container memang sudah dipahami di repo ini.

---

## 5. Touch Target (WCAG 2.5.5 / 2.5.8)

AA level untuk 2.5.8 adalah **24×24px**; 44×44px adalah 2.5.5 (AAA).
Keduanya dicatat, dengan 24px sebagai garis kegagalan tegas.

**T-01 — `.btn-xs` → tinggi ±19px — HIGH**
`public/style.css:245` `padding: 4px 8px; font-size: 11px`
11px font (≈13px line-box) + 8px padding ≈ **19px**. Di bawah 24px → gagal AA.

**T-02 — tombol ikon `settings-users.php:63,66,70` → 28×28px — MED**
`padding:6px` + ikon 16px = 28px. Lolos 24px, gagal 44px. Tiga tombol aksi
per baris tabel (edit, akses, hapus) dengan `margin-right:4px` — target kecil
dan berdekatan, kombinasi terburuk untuk jempol.

**T-03 — `.header-btn` 32×32px, `.modal-close` ±24px — MED**
`public/style.css:191`, `:307`. `.modal-close` hanya `font-size:24px` tanpa
width/height/padding eksplisit → sekitar ambang, tergantung font. Tombol tutup
modal adalah kontrol yang paling sering ditekan di mobile.

**T-04 — `.chip` tinggi ±26px — LOW (kondisional)**
`:385` `padding: 3px 10px; font-size: 0.78rem`. `cursor: default` → non-interaktif,
jadi 2.5.5 tidak berlaku. **Tapi** aturan yang sama menyetel `text-decoration: none`,
yang hanya bermakna untuk `<a>` — indikasi chip dipakai sebagai link di
sebagian tempat. Di kasus itu, gagal.

**T-05 — `.detail-plain-edit` ikon 16px, `padding: 0` — MED**
`public/style.css:354,358` (`width:16px !important`). Target efektif **16×16px**,
jelas di bawah 24px. Ini tombol edit inline di halaman detail ONU — fungsi
utama, bukan aksi pinggiran. Lebih parah lagi `onu-detail.php:299` memakai ikon
pencil `width:11px;height:11px` → **11px**.
Fix: `padding: 6px; margin: -6px` — memperbesar hit area tanpa menggeser layout.

Lolos: `.btn` (`padding:8px 16px` ≈ 36px), `.olt-tab` (`12px 20px` ≈ 42px),
`.dropdown-item` (`8px 12px` ≈ 36px), `.topnav-item` (36px desktop / 32px mobile),
`.toggle-label input` 16px — tapi dibungkus label flex yang bisa diklik, jadi
target sebenarnya sebesar teksnya.

---

## Prioritas perbaikan

Tiga yang pertama murah dan berdampak besar:

1. **CONTRAST-08** — `settings-users.php:121,152`: ganti `#f8fafc` → `var(--bg-primary)`.
   Teks tak terbaca (1.05:1) jadi terbaca. Dua baris.
2. **R-06 + R-07** — map 6 var undefined ke token nyata, hapus `.form-group`
   duplikat di `style.css:456-457`. Memulihkan warna teks **seluruh input** aplikasi.
3. **R-01/R-02/R-03** — bungkus 20 tabel dengan `.table-responsive` yang
   **sudah ada**, buang `white-space:nowrap` di `onu-detail.php:2883`.
   Mengembalikan akses data di mobile. Nol CSS baru.

Lalu: `--chip-alpha: 15% → 22%` (CONTRAST-09, satu baris, memperbaiki 5 chip),
light-mode nav bar `#3081d1 → #1d4ed8` (CONTRAST-07, memperbaiki 3 sekaligus +
CONTRAST-04/05), `.detail-plain-edit` padding (T-05), lalu `for=`/`id=` di
`splitters.php` + `onu-types.php` + `logs.php` (F-01…F-03).

## Catatan metodologi

- Kontras dihitung dengan luminance WCAG 2.1 dan alpha-compositing ke surface
  induk sebenarnya; `color-mix()` diresolusi manual. Rasio di laporan ini
  berasal dari perhitungan, bukan perkiraan.
- Dark **dan** light mode diuji terpisah. Beberapa aturan lolos di satu mode
  dan gagal di mode lain (CONTRAST-04, -05, -12) — menguji satu mode saja akan
  melewatkannya.
- Elemen non-teks sengaja dikeluarkan dari hitungan 1.4.3 (§1.4) agar angka
  total tidak menggelembung.
- Belum diverifikasi di browser sungguhan: tinggi efektif touch target (T-01,
  T-03) bergantung pada line-height font yang dirender. Angka di atas adalah
  hitungan dari box model. Untuk konfirmasi: ukur `getBoundingClientRect()`
  pada elemen live.
