## 2026-09-18 — Fix onu_type sanitizer + stats number format consistency

**File:** `frontend/action/auth-onu.php`, `frontend/action/update-onu-mode.php`, `frontend/configured.php`
**Masalah 1:** `onu_type` memakai `cli_safe_strict()` yang strip spasi dari nilai — berisiko corrupt nama ONU type yang mengandung spasi (saat ini tidak ada type dengan spasi, tapi ini bug latensi yang bisa muncul kapan saja user menambah type baru).
**Fix 1:** Ganti `cli_safe_strict` → `cli_safe` untuk `onu_type` di kedua action handler. `cli_safe` tetap filter karakter berbahaya tapi izinkan spasi.
**Masalah 2:** Stats cards di configured.php dirender PHP dengan `number_format()` (format English: `1,500`) tapi live-poll JS pakai `toLocaleString('id-ID')` (format Indonesian: `1.500`). Angka berubah format separator ribuan setelah 60 detik.
**Fix 2:** JS stats pakai `toLocaleString('en-US')` supaya konsisten dengan server-rendered value.
**Scope:** App-side only, zero OLT impact. Desktop + mobile (tidak ada perubahan layout).

---

## 2026-09-18 — Fix mobile responsiveness for Update ONU Mode modal

**File:** `frontend/onu-detail-css.php`
**Masalah:** Table-based form layout di update-onu-mode-modal (onu-detail.php)
cramp/overflow horizontal pada layar <500px — kolom label 160px + input max 320px
tidak muat di modal width 90% (≈324px di viewport 360px), menyebabkan layout
terpotong atau perlu scroll horizontal.
**Fix:** Tambah media query `@media (max-width:500px)` yang convert table/tr/td
jadi `display:block` (stacked layout: label di atas, input di bawah). Label `height`
inline di-override ke `auto`. Desktop/tablet layout tidak terpengaruh.
**Commit:** 9d5f75d

---

## 2026-09-17 — Fix zone→splitter cascade in edit-identity modal (desktop+mobile)

**File:** `frontend/onu-detail.php`
**Masalah:** Saat user mengganti zone di modal Edit Identitas, dropdown splitter
tetap menampilkan splitter dari zone LAMA sebagai pilihan aktif (param `keepCurrent=true`
dipakai untuk initial load DAN zone change). Jika user tidak sadar dan langsung simpan,
splitter dari zone salah ikut tersimpan.
**Fix:** Zone change handler sekarang pakai `keepCurrent=false` + tambah placeholder
"- Pilih ODB -" supaya user wajib pilih splitter dari zone baru secara eksplisit.
Initial load (modal pertama kali dibuka) tetap `keepCurrent=true` untuk preserve
nilai existing.
**Scope:** onu-detail.php edit-identity modal only, no OLT interaction.


**File:** `frontend/onu-detail.php`
**Masalah 1:** `needFullSync` hanya di-reset saat network error (`.catch()`), tapi
tidak saat OLT mengembalikan error HTTP 502 (`data.success === false`). Akibat: jika
full sync pertama gagal karena OLT timeout/unreachable, polling berikutnya selalu
menggunakan snmp mode dan VLAN/PPPoE/distance tidak pernah ter-sync ulang sampai
manual page reload.
**Masalah 2:** Field IP Manajemen (`#detail-mgmt-ip`) tidak di-update oleh auto-refresh
polling 15 detik, padahal `data.mgmt_ip` tersedia di response JSON onu-data.php.
**Fix 1:** Tambah `needFullSync = true` di else block (OLT error handler) supaya
retry full sync pada poll cycle berikutnya.
**Fix 2:** Tambah update `mgmt_ip` di auto-refresh JS, termasuk link eksternal.
**Scope:** ONU Detail page only, no OLT interaction.

---

## 2026-09-17 — Fix splitter dropdown reset on zone change in onu-detail.php

**File:** `frontend/onu-detail.php`
**Masalah:** Modal Edit Identitas di halaman ONU Detail: saat user mengganti zone,
dropdown splitter di-reset ke opsi pertama tanpa mempertahankan pilihan valid yang
sama. User bisa tanpa sadar menyimpan kombinasi zone+splitter yang salah.
**Fix:** `loadSplitters(zone, true)` saat zone berubah — splitter yang valid di zone
baru tetap terpilih otomatis.
**Scope:** UI only, desktop & mobile modal.

---

## 2026-09-17 — Fix preset speed profile deferred handling in auth-onu.php

**File:** `frontend/auth-onu.php`
**Masalah:** Saat user memilih preset sebelum daftar speed profile selesai dimuat (koneksi lambat), download/upload profile select tidak ter-apply — silent no-op, tidak ada deferred mechanism seperti yang sudah ada untuk VLAN (`pendingPresetVlan`) dan Splitter (`pendingPresetSplitter`).
**Fix:** Tambah `pendingPresetDl`/`pendingPresetUl` deferred variables. Saat preset dipilih dan profile belum loaded, nilainya disimpan. Saat `fetchSpeed_profiles()` selesai, deferred values langsung di-apply.

---

## 2026-09-17 — Live-update stats cards (Online/Offline/Disabled/Total) on configured.php

**File:** `frontend/configured.php`
**Masalah:** Stats cards (Total/Online/Offline/Disabled) di atas tabel configured.php hanya di-render server-side saat page load. Live-polling (tiap 60 detik) sudah update baris tabel per-ONU tapi tidak pernah refresh angka stats — user bisa lihat "Online: 1500" padahal tabel sudah menunjukkan perubahan status.
**Fix:** Tambah `id` atribut ke stats card value spans. Di `updateAllOnuSignals()`, hitung status counts dari data ONU yang sudah dikembalikan endpoint (full dataset, bukan hanya current page) dan update stats cards setiap poll cycle. Zero extra DB query — data sudah ada di response.

---

## 2026-09-17 — Fix pending-write tracking in 6 action handlers

**File:** `frontend/action/{update-onu-mode,delete-onu,onu-state,restore-factory,reboot-onu,replace-onu}.php`
**Masalah:** Array `$olt` di 6 action handler tidak punya key `'id'`. Saat handler mengirim command config ke OLT (configureOnuFull, deleteOnu, enable/disable, restoreFactory, replaceOnu), `helper.py`'s `_mark_pending_write(olt)` gagal menyimpan marker karena `olt.get('id')` mengembalikan `None`. Akibat: config yang dikirim lewat jalur ini tidak pernah auto-flush ke flash oleh `smartolt-flush-write` timer (tiap 10 menit). Jika OLT restart sebelum flush manual, config hilang.
**Fix:** Tambah `'id' => (int)$olt_id` ke setiap array `$olt`. `auth-onu.php` sudah benar (pakai `SELECT *` dari olts).

---

## 2026-09-17 — Chart colors theme-aware (light mode fix)

**File:** `frontend/onu-detail.php`
**Masalah:** Chart signal dan traffic di `onu-detail.php` pakai warna hardcoded dark-mode (`#9ca3af`, `rgba(255,255,255,0.05)`) — di light mode, grid invisible (putih di atas putih), tick text nyaris tak terbaca.
**Fix:** Baca `--text-muted` CSS variable dan deteksi `.light-mode` class untuk grid color. Grid: `rgba(0,0,0,0.08)` di light mode, `rgba(255,255,255,0.05)` di dark mode. Text: langsung dari CSS variable. Desktop + mobile.

---

## 2026-09-17 — auth-onu.php mobile responsive fix

**File:** `frontend/auth-onu.php`
**Masalah:** Form otorisasi punya label `flex:0 0 200px` tanpa media query — di mobile (<600px), input field terkompresi jadi ~100px, sulit diisi.
**Fix:** Tambah `@media (max-width: 600px)` — form row jadi vertikal (label di atas, input full-width), preset row juga stacked, GPS fields stacked. Desktop unchanged.
**Cakupan:** Mobile only (≤600px). Desktop layout tidak berubah.

## 2026-09-17 — ONU Type filter dropdown + inline font-family cleanup

**Commit:** e49377a
**File:** `frontend/configured.php`, `frontend/onu-detail.php`
**Masalah:** configured.php punya filter untuk OLT/status/zone/ODB/PON/sinyal tapi tidak ada filter per Tipe ONU (e.g. ZTE F670L, CDATA). Juga ada 4 modal h3 dengan inline `font-family: 'Poppins'` yang redundan dengan CSS `--font-heading`.
**Fix:** (1) Tambah dropdown filter "Tipe ONU" di configured.php — query DISTINCT onu_type dari DB, scoped per-OLT, dengan SQL prepared statement. Filter ikut terbawa di pagination, CSV export, dan tombol Reset. (2) Hapus inline `font-family` dari 4 modal h3 di onu-detail.php supaya pakai CSS var(--font-heading) — mencegah font hardcoded kalau tema berubah.
**Tier:** 1 (UI only, zero OLT impact)
**Scope:** Desktop + mobile (filter dropdown responsif via filter-row flex-wrap)
**Dampak OLT/ONU:** Tidak ada. Filter murni query DB lokal.
## 2026-09-17 — Add distance_m to SNMP polling mode

**Commit:** 71c1853
**File:** `backend/python_engine/snmp_module.py`, `frontend/action/onu-data.php`
**Masalah:** Distance optik ONU (jarak meter) hanya terisi saat full sync (page load).
Selama polling 15 detik (SNMP-lite mode), `distance_m` selalu null → UI
menampilkan "(N/A)" terus-menerus meski data tersedia via SNMP.
**Fix:** Tambah `get_onu_distance_zte()`/`get_onu_distance_cdata()` (1 SNMP OID get,
~50ms overhead) ke `get_onu_signal_snmp()`. `onu-data.php` pass result ke response.
JS onu-detail.php sudah handle `data.distance_m` — tidak perlu ubah frontend.
**Scope:** App-side only, tidak sentuh OLT config. Desktop + mobile (sama element).

---

## 2026-09-17 — Fix stats ignoring zone/odb/pon/signal filters

**Commit:** e92ed25
**File:** `frontend/configured.php`
**Masalah:** Stats summary (Online/Offline/Disabled) hanya pakai search+OLT filter.
Zone/ODB/splitter/PON/signal filter diabaikan — stats menampilkan total semua
zone meski user filter by zone tertentu. Introduced oleh commit 3fabd09 yang
memindah `$stats_filter_sql` save ke posisi terlalu awal (sebelum zone/odb/
pon/signal filter ditambahkan).
**Fix:** Pindah `$stats_filter_sql` save ke SETELAH zone/odb/pon/signal filter
tapi SEBELUM status filter. Stats sekarang akurat untuk semua filter kombinasi.
**Scope:** Desktop + mobile (stats bar, perubahan murni PHP backend logic).

---

## 2026-09-17 — Fix stats cards showing only filtered status when status filter active

**Commit:** (this commit)
**File:** `frontend/configured.php`
**Masalah:** Stats summary (Online/Offline/Disabled count) menggunakan `$filter_sql` yang
SUDAH termasuk kondisi status. Ketika user filter by "Online", stats menampilkan
"Online: 200, Offline: 0, Disabled: 0" — seharusnya menampilkan semua status untuk
filter non-status yang aktif (OLT, zone, search, dll).
**Root cause:** Commit 1c604c0 (consolidate duplicate filter logic) menyalin `$filter_sql`
ke `$stats_filter_sql` SETELAH status filter ditambahkan, padahal komentar asli bilang
"belum termasuk kondisi status" — komentar salah.
**Fix:** Simpan `$stats_filter_sql` dan `$stats_params` SEBELUM blok status filter, sehingga
stats query tidak terpengaruh oleh filter status. Komentar lama dihapus, diganti yang akurat.
**Scope:** Desktop + mobile (filter panel + stats bar, perubahan murni PHP backend logic).

---

## 2026-09-17 — Consolidate duplicate filter logic in configured.php stats query

**Commit:** 1c604c0
**Files:** `frontend/configured.php`
**Masalah:** Stats query (Online/Offline/Disabled count) menyalin ulang seluruh filter logic
(23-kolom LIKE search + zone/odb/pon/signal/OLT filters) dari main query — 37 baris duplikat.
Kalau filter baru ditambah ke satu tapi lupa yang lain, stats bisa diverge dari data aktual.
**Fix:** Reuse `$filter_sql` + `$params` yang sudah dibangun untuk main query, hapus duplikasi.
Placeholder search juga disingkat dari daftar 23 kolom menjadi ringkas (simpel, padat).
**Scope:** Desktop + mobile (table layout unchanged, only PHP backend logic + placeholder text).

---

## 2026-09-17 — Fix fragile cell[N] indices in configured.php live-polling

**Commit:** ea280ff
**Files:** `frontend/configured.php`
**Masalah:** JS live-polling di configured.php pakai hardcoded `cells[7]`, `cells[8]`,
`cells[9]`, `cells[13]` untuk update WAN, DL/UL profile, dan ONU type. Kalau kolom
di-reorder, update salah cell secara silent.
**Fix:** Tambah CSS class (`wan-cell`, `dl-profile-cell`, `ul-profile-cell`, `onu-type-cell`)
ke `<td>` terkait. JS ganti ke `row.querySelector('.class')` — tidak tergantung urutan kolom.
**Coverage:** Desktop + mobile (table-responsive, perubahan murni atribut class).

---

## 2026-09-17 — (REVIEW) CSRF token verification gap on POST action handlers

**Commit:** 97a2639
**Proposal:** `REVIEW.md` #5
**Status:** MENUNGGU KEPUTUSAN
**Masalah:** `header.php` inject CSRF token ke semua form/fetch POST, tapi hanya 2 dari ~12
action handler yang verify server-side (`onu-types.php`, `splitters.php`). Sisanya
(auth-onu, update-onu-mode, onu-state, delete-onu, dll) tidak cek — token CSRF yang
di-inject client-side tidak berguna tanpa verifikasi server-side.

---

## 2026-09-17 — Fix placeholder violations & theme-safe toast notifications

**Files:** `frontend/onu-detail.php`, `frontend/configured.php`
**Masalah:**
1. Static IP form fields di `onu-detail.php` modal "Update Mode ONU" pakai placeholder berisi contoh IP konkret (`192.168.1.10`, `255.255.255.0`, `192.168.1.1`, `8.8.8.8`, `8.8.4.4`) — melanggar aturan placeholder UI (jangan contoh isian nyata).
2. Toast notification di `configured.php` (Sync Pelanggan feedback) pakai warna hardcoded dark-theme (`#1e3a8a`, `#064e3b`, `#7f1d1d`) + emoji — tidak pakai CSS variables, berantakan di light theme.
**Fix:**
1. Ganti placeholder dengan label netral (`IP Address`, `Netmask`, `Gateway`, `DNS Primer`, `DNS Sekunder`).
2. Toast gunakan `var(--bg-tertiary)`, `var(--text-accent)`, `var(--color-success)`, `var(--color-danger)`, `var(--text-main)` + fallback hex. Hapus emoji dekoratif.

---

## 2026-09-17 — Sanitize profile names in authorize_onu() (command injection prevention)

**Commit:** 6ff4799
**Files:** `backend/python_engine/drivers/zte_c300.py`, `backend/python_engine/drivers/zte_c320.py`
**Masalah:** `authorize_onu()` interpolasi `upload_profile`/`download_profile` langsung ke CLI
command string tanpa sanitasi. `assign_speed_profile()` sudah panggil `_sanitize_profile_name()`
tapi `authorize_onu()` belum — celah command injection via POST forged (profile name containing
newlines/command chars).
**Fix:** Tambah `_sanitize_profile_name()` untuk kedua parameter di awal `authorize_onu()`.
Konsisten dengan `assign_speed_profile()`.
**Tier:** 1 (driver Python, hardening, no OLT config change)

---

## 2026-09-17 — Fix signal display false-negative for 0.00 dBm in configured.php

**Commit:** 81db9cb
**File:** `frontend/configured.php`
**Masalah:** PHP truthiness check `$onu['last_rx_power']` memperlakukan string `"0"` sebagai falsy.
Sama dengan bug class yang sudah diperbaiki di onu-detail.php (commit fcdb17c). ONU dengan
last_rx_power = 0.00 dBm salah tampil sebagai "N/A" bukan nilai aktual.
**Fix:** Ubah `if ($onu['last_rx_power'])` menjadi `if ($onu['last_rx_power'] !== null)` untuk Rx ONU
dan Rx OLT (2 baris di lines 690 dan 706).
**Tier:** 1 (frontend PHP, no OLT impact)

---

## 2026-09-17 — Restore Zone/Splitter/Profil DL/Profil UL columns in configured.php table

**Commit:** a716673
**File:** `frontend/configured.php`
**Masalah:** SELECT query sudah fetch `onus.zone`, `onus.splitter`, `onus.download_profile`,
`onus.upload_profile` tapi HTML table hanya render 11 kolom — data ke-4 field tsb
di-fetch tapi tidak pernah ditampilkan di tabel. Kolom pernah ditambah (commit 6a502a2,
fe1a1d2, 8185ca3) tapi hilang dari file — kemungkinan ter-overwrite saat sync.
**Fix:** Tambah4 `<th>` header + 4 `<td>` cell per row (Zone, Splitter after Nama;
Profil DL/Profil UL after WAN). Update colspan empty-state 11→15. Kolom Zone/Splitter
pakai font kecil muted; Profil DL/UL pakai tooltip untuk nama panjang.
**Scope:** UI display only, zero OLT impact. Desktop + mobile (horizontal scroll).

## 2026-09-17 — Fix: wan_mode 'Static IP' vs 'Static' mismatch (drivers + DB)

- **Bug**: Python drivers (zte_c300, zte_c320, cdata_fd1602sb1) mendeteksi
  Static IP dari OLT config dan menyimpan `wan_mode='Static IP'` ke DB.
  PHP UI (auth-onu.php, onu-detail.php, update-onu-mode.php) mengharapkan
  nilai `'Static'`. Akibat:19 ONU dengan Static IP menampilkan radio button
  yang TIDAK ter-select di modal Update ONU Mode, dan submit form tanpa
  mengubah radio bisa meng-overwrite ke default 'Setup via ONU webpage'.
- **Fix**: Normalisasi ketiga driver ke `'Static'` (konsisten dengan UI).
  Backfill DB: `UPDATE onus SET wan_mode='Static' WHERE wan_mode='Static IP'`
  (19 row). Restart `smartolt-python`.
- **Commit**: 8a1ca15

## 2026-09-17 — Fix: needFullSync stuck false on network error (onu-detail.php)

- **Bug**: Jika fetch pertama `onu-data.php?full=1` gagal (network error/timeout),
  `needFullSync` langsung `false` dan tidak pernah di-reset. Polling berikutnya
  selalu pakai mode ringan (SNMP only) — field VLAN, PPPoE, distance, speed profile
  stuck di placeholder sampai user manual refresh halaman.
- **Fix**: Tambah `needFullSync = true` di `.catch()` handler JS supaya poll
  berikutnya retry full sync otomatis.
- **File**: `frontend/onu-detail.php` (1 baris ditambah)
- **Tier**: 1 (murni frontend JS, tidak pengaruh OLT/pelanggan)

# CHANGELOG.md — Riwayat Perubahan Otomatis

## 2026-09-17 — Fix: splitter ellipsis truncation onu-detail.php

- **Bug**: `setChipVal()` JS mengganti `innerHTML` `#detail-splitter`, menghancurkan
  inner `<span>` yang punya `text-overflow:ellipsis`. Setelah auto-refresh pertama,
  nama splitter panjang tidak ter-truncate dengan `...`.
- **Bug**: `title` tooltip `#detail-splitter` tetap menampilkan nilai PHP lama,
  tidak pernah di-update oleh JS sync.
- **Fix**: CSS ellipsis (`white-space:nowrap; text-overflow:ellipsis; min-width:0`)
  dipindah ke outer span `#detail-splitter`. Inner wrapper dihapus (redundan).
  Tambah `title` update setelah `setChipVal()`.
- Scope: desktop + mobile (elemen yang sama).
- Commit: 602c8aa

## 2026-09-17 — REVIEW.md roadmap cleanup (docs only)

- **REVIEW.md**: Roadmap "BELUM ADA" items 5 (Restore factory), 6 (Update location +
  Maps), 8 (Change ONU type) verified as ALREADY IMPLEMENTED in codebase. Removed from
  backlog, added "Sudah selesai" section. Narrowed remaining gaps to: Move ONU, Change
  ONU ID/GPON channel, Ethernet/WiFi per-port control, Firmware upgrade, VoIP toggle.
- **Scope**: docs only (REVIEW.md), no code changes, no OLT touch.

## 2026-09-17 — Fix replace-onu wan_mode fallback + identity sync warning

- **replace-onu.php**: `wan_mode` fallback in step C (configure_onu_full) defaulted
  to `'PPPoE'` while step B (authorize_onu) defaulted to `'Setup via ONU webpage'`
  when onu's wan_mode was null/empty. Inconsistent WAN config sent to OLT during
  Replace ONU operation. Fixed: both steps now use `'Setup via ONU webpage'`.
- **update-onu-mode.php**: Identity edits (name/zone/splitter/address) always showed
  success message "dan disinkronkan ke OLT" even when the OLT description sync failed.
  User had no indication of failure. Fixed: warning message now shown when sync fails,
  noting cron will auto-fix.
  Commit: 91718d9

## 2026-09-17 — Fix signal legend initial render

- **onu-detail.php**: chart legend (Rx ONU/Rx OLT) initial values used PHP elvis
  operator `?:` which treats `0` as falsy — showed "N/A" instead of actual0 dBm
  value (edge case). Replaced with `!== null` check, consistent with rest of page.
  Commit: fcdb17c




## 2026-09-17 — Config preset dropdown di onu-detail.php

**Commit:** dfe19cb
**File:** frontend/onu-detail.php
**Masalah:** Modal Edit Preset Konfigurasi di onu-detail.php pakai free-text `<input>`, tidak konsisten
dengan auth-onu.php yang pakai `<select>` dropdown dari tabel `onu_presets`.
**Fix:** Ganti dengan `<select>` dropdown dari DB + fallback jika value lama tidak ada di list.

## 2026-09-17 — Fix Replace ONU broken due to missing speed profiles

**Commit:** f245905
**File:** `frontend/action/replace-onu.php`
**Masalah:** Replace ONU memanggil `authorize_onu()` tanpa parameter `upload_profile`/`download_profile`/`wan_mode`/`pppoe_credentials`. Tanpa speed profiles, `authorize_onu()` skip `tcont 1 profile X` + `gemport 1 tcont 1` creation → `service-port 1 vport 1` ditolak OLT `%Code 66657: GEM port does not exist`. Fitur Replace ONU sepenuhnya tidak berfungsi untuk ONU yang punya speed profile.
**Fix:** Kirim semua parameter existing dari DB row (`wan_mode`, `pppoe_username`, `pppoe_password`, `upload_profile`, `download_profile`) ke `authorize_onu()` — baik untuk ONU baru (step B) maupun rollback ke ONU lama (step B fail-safe).
**Tier:** 1 (app-side handler only, zero OLT config change)


## 2026-09-17 — Fix CSV export search missing mgmt_ip, odb_port, pppoe_ip

**Commit:** 2d6e3f1
**File:** `frontend/action/export-csv.php`
**Masalah:** export-csv.php hanya punya20 kolom LIKE di search filter, sementara configured.php sudah 23 (tambahan mgmt_ip, odb_port, pppoe_ip di commit ab7b628 belum disalin ke CSV export). Filter CSV tidak konsisten dengan halaman utama.
**Fix:** Tambah 3 kolom yang hilang + sesuaikan counter parameter (20 → 23).
**Tier:** 1 (app-side only, no OLT impact)


## 2026-09-17 — Add mgmt_ip, odb_port, pppoe_ip to configured.php search

**Commit:** ab7b628
**File:** `frontend/configured.php`
**Perubahan:** Search filter configured.php tidak mencakup mgmt_ip, odb_port, pppoe_ip — padahal ketiganya sudah ada di CSV export. Tambah 3 kolom ke LIKE clause (query utama + stats query), update placeholder text.
**Tier:** 1 (app-side only, no OLT impact)


## 2026-09-17 — Add missing columns to CSV export

**Klasifikasi:** EKSEKUSI OTOMATIS (app non-driver, no OLT impact)

**Masalah:** CSV export kehilangan 6 kolom yang sudah ada di DB dan bisa di-edit
via ONU detail page: `latitude`, `longitude`, `odb_port`, `wan_remote_access`,
`mgmt_ip`, `pppoe_ip`. Export tidak lengkap untuk keperluan audit/backup data.

**Fix:** Tambah 6 kolom ke SELECT query, CSV header row, dan data row output.
Total kolom CSV: 25 → 31.

**Scope:** Backend-only, tidak ada perubahan UI.


## 2026-09-17 — Fix unconfigured.php missing footer.php

**Commit:** cf4400b
**File:** `frontend/unconfigured.php`
**Klasifikasi:** EKSEKUSI OTOMATIS (app non-driver, no OLT impact)

**Masalah:** Halaman `unconfigured.php` tidak meng-include `footer.php` di akhir file.
Ini menyebabkan:
- HTML container tags (`<div class="app-container">`, `<main>`, `<div class="view-panel">`)
  yang dibuka oleh `header.php` tidak pernah ditutup — halaman secara teknis malformed.
- Topnav dropdown menus tidak berfungsi di halaman ini (JS toggle ada di `footer.php`).
- Theme toggle (dark/light mode) tidak berfungsi di halaman ini.
- `</body>` dan `</html>` closing tags hilang.

**Fix:** Tambah `require_once __DIR__ . '/footer.php';` setelah closing `</script>` tag.

**Scope:** Desktop + mobile (sama-sama terdampak karena footer.php berisi JS global).


## 2026-09-17 — Add config_preset to search + CSV export

**Commit:** `4983118`
**File:** `frontend/configured.php`, `frontend/action/export-csv.php`
**Perubahan:**
1. `configured.php`: tambah `onus.config_preset LIKE ?` ke search filter (main + stats query, 19→20 kolom).
2. `export-csv.php`: tambah `config_preset` ke SELECT, CSV header ("Config Preset"), dan data rows. Search filter di-sync ke 20 kolom.
3. Update search placeholder: tambah "Preset".
**Alasan:** config_preset disimpan saat otorisasi tapi tidak bisa dicari atau diekspor. Operator tidak bisa filter ONUs berdasarkan template konfigurasi.
**Risiko:** N/A — search + export only, tidak sentuh OLT.


## 2026-09-17 — Fix config_preset not saved during authorization

**Commit:** `07f65b5`

**Masalah:** Form auth-onu.php tidak punya field `config_preset`. Action handler
`auth-onu.php` baca `$_POST['config_preset']` tapi form tidak pernah mengirimnya →
nilai selalu `'None'` → `NULL` di DB. Akibatnya "Preset Konfigurasi" di onu-detail.php
selalu kosong untuk ONU baru walau user pilih preset saat otorisasi.

**Fix:** Tambah hidden input `<input type="hidden" name="config_preset" id="auth-config-preset">`
di dalam form. Update preset change handler untuk set value hidden field saat preset dipilih,
clear saat preset di-reset ke "Tidak ada".

**Scope:** Tier 1 (app-side, zero OLT risk). Tidak menyentuh OLT/driver.


## 2026-09-17 — Fix PPPoE password sanitizer stripping special characters

**Masalah:** `pppoe_password` di `auth-onu.php` dan `update-onu-mode.php` menggunakan
`cli_safe_strict()` yang hanya mengizinkan `[A-Za-z0-9._-]`. Karakter umum dalam
password PPPoE seperti `@`, `!`, `#`, `$`, `%`, `&`, `+`, `=`, `?`, `^`, `~` di-strip
secara diam-diam → password yang tersimpan di DB dan dikirim ke OLT berbeda dari input
asli user → autentikasi PPPoE gagal tanpa error di UI.

**Fix:** Tambah fungsi `cli_safe_password()` di `backend/config.php` — hanya strip
control characters (newline, null byte, dll) yang benar-benar berbahaya untuk CLI
interpolation, pertahankan semua karakter password umum. Update kedua file handler
(`auth-onu.php`, `update-onu-mode.php`) untuk pakai fungsi baru.

**File:** `backend/config.php`, `frontend/action/auth-onu.php`,
`frontend/action/update-onu-mode.php`


## 2026-09-17 — Improve CSV export columns + search placeholder accuracy

**Commit:** `777b00a`

**Masalah:** CSV export hanya punya 21 kolom — missing ONU Mode, Contact, External ID. Search placeholder di configured.php hanya sebut 14 field padahal search mencakup 19 kolom.

**Fix:**
1. CSV export: tambah kolom `ONU Mode`, `Contact`, `External ID` (total 24 kolom)
2. Search placeholder: update dari "Cari SN, ID, Nama, PPPoE, ..." ke "Cari SN, ID, Nama, Alamat, Kontak, PPPoE, OLT, IP, VLAN, PON, WAN, Tipe ONU, Splitter, Zone, Last Down, Profil, External ID..."

**Scope:** Tier 1 (app-side, zero OLT risk). Desktop + mobile: search bar placeholder.

---

## 2026-09-17 — Fix ETag: include vlan in signal refresh CRC

**Commit:** `9b86b17`

**Masalah:** `get-signals-db.php` ETag CRC hanya hash `status`, `last_rx_power`, `last_rx_olt_power`, `last_down_cause` — tidak termasuk `vlan`. Kalau cron sync update VLAN di DB, ETag tetap sama, browser dapat 304, dan kolom VLAN di configured.php table tidak ter-refresh secara live.

**Fix:** Tambah `COALESCE(vlan,'')` ke `CONCAT_WS` di ETag calculation.

**Scope:** Tier 1 (app-side). Zero OLT risk.

---

## 2026-09-17 — Add Restore Factory Defaults button + driver methods

**Commit:** `bbd1f7d`

**Masalah:** ONU detail page hanya punya Reboot, Resync, Disable/Enable, Delete — tidak ada opsi "Restore Factory" untuk reset ONU ke pengaturan pabrik. Feature ini ada di SmartOLT SaaS asli (mitrafiber.smartolt.com) dan masuk roadmap REVIEW.md.

**Fix:**
1. Tambah `restore_factory()` method di `zte_c300.py`, `zte_c320.py`, dan `cdata_fd1602sb1.py`.
2. ZTE: `pon-onu-mng` → `restore factory` → `exit`.
3. CDATA: `ont restore-factory <port> <onu_id>`.
4. PHP wrapper `restore_factory_onu()` di `driver.php`.
5. Action handler `frontend/action/restore-factory.php` (modeled after `reboot-onu.php`).
6. Tombol "Restore Factory" di Section 8 `onu-detail.php` dengan konfirmasi dialog eksplisit.

**Scope:** App-side (UI + action handler + driver methods). Per-ONU action, same risk class as reboot/delete. Tidak menyentuh config OLT pelanggan secara bulk.

---

## 2026-09-17 — Fix wan_remote_access Validation + Normalize Line Endings (547025a)

**File:** `frontend/action/update-onu-mode.php`, `frontend/action/onu-data.php`
**Perubahan:**
1. `update-onu-mode.php`: `wan_remote_access` sebelumnya pakai `cli_safe_strict()` — tidak konsisten dengan validasi enum lain (`onu_mode`, `wan_mode`, `config_method`) yang pakai `in_array()`. Sekarang divalidasi dengan `in_array(['yes', 'no'])` + fallback ke nilai DB lama / `'no'`.
2. `onu-data.php`: normalisasi CRLF → LF line endings (cosmetic, no functional change).

## 2026-09-17 — Extract Speed Profiles from Running-Config During Sync (dbf7c52)

**File:** `backend/python_engine/drivers/zte_c300.py`, `zte_c320.py`, `backend/python_engine/sync_one_onu.py`, `frontend/action/onu-data.php`, `frontend/onu-detail.php`
**Perubahan:**
1. `sync_onu_config()` di C300+C320: tambah regex tcont/gemport extraction — parse upload profile (`tcont N profile X`) dan download profile (`gemport N traffic-limit downstream X`) dari `show running-config interface gpon-onu`. Juga handle CDATA `dba-profile-id` format.
2. `sync_one_onu.py`: tambah `download_profile`/`upload_profile` ke UPDATE query supaya data tersimpan ke DB saat ONU detail page di-load.
3. `onu-data.php`: tambah ke UPDATE + JSON response supaya auto-refresh 15 detik update speed profile table secara live di onu-detail.php.
4. `onu-detail.php`: JS update speed profile cells dari polling data.
**Alasan:** Kolom Profil DL/UL di configured table 100% kosong (0/282 ONU) karena speed profile hanya disimpan saat authorize/update mode, tidak pernah di-sync dari OLT. ONU existing yang diotorisasi sebelum kolom ditambahkan tidak punya data. Sekarang data otomatis terisi saat ONU detail page di-load.
**Risiko:** N/A — murni app-side, tidak ubah command ke OLT.
## 2026-09-17 — Fix Speed Profiles: DB Columns as Primary Source (e18b349)

**File:** `frontend/onu-detail.php`
**Perubahan:** Tabel Profil Kecepatan di ONU Detail sekarang membaca `download_profile`/`upload_profile` dari kolom DB (diisi saat otorisasi/update mode) sebagai sumber utama. Fallback ke regex parse `last_running_config` hanya jika DB kosong.
**Alasan:** Sebelumnya tabel hanya baca dari `last_running_config` yang butuh klik "Show running-config" dulu → sebagian besar ONU tampil "N/A" walau data speed profile sudah tersimpan di DB sejak otorisasi.
**Risiko:** N/A — display only, tidak sentuh OLT.

## 2026-09-17 — Fix wan_remote_access Default 'no' → 'yes' (74e33d3)

**File:** `backend/db.php`
**Perubahan:** 1) Data fix: UPDATE semua 282 ONU dari wan_remote_access='no' ke 'yes'. 2) Ubah DEFAULT schema migration dari 'no' ke 'yes'.
**Alasan:** authorize_onu() selalu mengirim security-mgmt 998/999 (remote access aktif). Kolom ditambahkan ke DB setelah ONU pertama diotorisasi → DEFAULT 'no' salah untuk semua ONU existing. onu-detail.php menampilkan status palsu "Nonaktif" padahal akses remote benar-benar aktif di OLT.
**Risiko:** N/A — data fix + schema default, tidak sentuh OLT.

## 2026-09-17 — WAN Remote Access Display + ETag 304 Fix (5563e43)

**File:** `frontend/onu-detail.php`, `frontend/action/get-signals-db.php`
**Perubahan:**
1. Tambah baris "Akses Remote WAN" di detail panel kanan onu-detail.php — tampilkan "Aktif" (hijau) atau "Nonaktif" (muted). Update live via polling JS dari `onu-data.php` response.
2. Ganti ETag computation di get-signals-db.php dari `MAX(updated_at)` ke `BIT_XOR(CRC32(...))` per baris — ETag sekarang stabil saat data sinyal tidak berubah,304 Not Modified aktif, hemat ~20KB/poll.
**Alasan:** 1) Operator tidak bisa lihat status remote WAN tanpa buka modal. 2) ETag lama selalu berubah karena cron bump updated_at tiap menit, 304 optimization tidak pernah aktif.
**Risiko:** N/A — UI display + caching optimization, tidak sentuh OLT.

## 2026-09-17 — Download/Upload Profile Columns di Configured Table (8185ca3)

**File:** `frontend/configured.php`
**Perubahan:** Tambah kolom "Profil DL" dan "Profil UL" di tabel Configured ONUs. SELECT query, header, cell, colspan updated. Profile name muncul dengan tooltip untuk nama panjang.
**Alasan:** Operator tidak bisa lihat speed tier pelanggan dari tabel — harus klik Detail tiap ONU. Search+CSV sudah punya field ini, tabel belum.
**Risiko:** N/A — UI only, tidak sentuh OLT.

## 2026-09-17 — Add wan_mode/profiles/onu_type to configured.php live polling

**Commit:** f0a98ab
**File:** `frontend/action/get-signals-db.php`, `frontend/configured.php`
**Masalah:** Live polling configured.php (get-signals-db.php, tiap 60 detik) hanya refresh
status/VLAN/signal/last_down. Kolom WAN, Profil DL, Profil UL, dan Tipe ONU tetap stale
sampai full page reload — kalau user ganti WAN mode/speed profile di ONU Detail, configured
table tidak ikut update.
**Fix:**
1. `get-signals-db.php`: tambah `wan_mode`, `download_profile`, `upload_profile`, `onu_type`
   ke ETag hash, SELECT query, dan JSON response.
2. `configured.php`: tambah `data-wan`/`data-dl-prof`/`data-ul-prof`/`data-onu-type`
   attributes ke row. Update skip-check + DOM update untuk 4 kolom baru.
**Scope:** App-side polling endpoint + frontend JS. Zero OLT impact.

Setiap perubahan yang diterapkan otomatis oleh agen analisa (lihat REVIEW.md untuk
kriteria eksekusi-otomatis vs wajib-review). Tiap entri = 1 commit git di repo ini.

## 2026-09-17 — Download/Upload Profile di Search + CSV Export (commit 05edc6c)

**Masalah:** `download_profile` dan `upload_profile` tidak bisa dicari di halaman Configured
dan tidak termasuk dalam ekspor CSV — operator tidak bisa filter ONUs berdasarkan nama profil
kecepatan atau mengekspor data profil ke CSV.

**Fix:**
- Tambah `onus.download_profile` dan `onus.upload_profile` ke search LIKE di `configured.php`
  (filter utama + stats query, 17→19 kolom) dan `export-csv.php` (17→19 kolom).
- Tambah kolom "Download Profile" dan "Upload Profile" ke CSV export header + data rows.
- Update placeholder search input: "..., Profil..."

**Scope:** App-side only, tidak ada perubahan ke OLT. Eksekusi otomatis.

## 2026-09-17 — Add Splitter column + extend search to 17 columns

**Commit:** `fe1a1d2`
**File:** `frontend/configured.php`, `frontend/action/export-csv.php`

**Masalah 1:** Splitter/ODB bisa difilter via dropdown tapi tidak terlihat di tabel — operator harus klik Detail satu per satu untuk tahu ONU lewat splitter mana.
**Masalah 2:** Search box tidak bisa cari "TGR-01D0804" (splitter name) atau nama zone — kolom splitter/zone missing dari LIKE filter.
**Fix 1:** Tambah kolom Splitter (font kecil, muted) setelah Zone. SELECT query ditambah `onus.splitter`. colspan empty-state 12→13.
**Fix 2:** Tambah `onus.splitter LIKE ?` + `onus.zone LIKE ?` ke search filter (15→17 kolom). Sync di configured.php dan export-csv.php. Placeholder search diperbarui.
**Scope:** UI only, zero OLT impact. Desktop + mobile (horizontal scroll existing).

## 2026-09-17 — Add Zone column to configured table

**Commit:** `6a502a2`
**File:** `frontend/configured.php`

**Masalah:** Zone bisa difilter lewat dropdown tapi tidak terlihat di tabel — operator harus klik Detail satu per satu untuk tahu ONU di zone mana.
**Fix:** Tambah kolom Zone (font kecil, muted) setelah Nama Pelanggan. SELECT query ditambah `onus.zone`. colspan empty-state diperbarui dari 11→12. Cakupan: desktop + mobile (horizontal scroll existing).

---

## 2026-09-17 — Add last_down_cause to search filter (15 columns)

**Commit:** `603da21`
**File:** `frontend/configured.php`, `frontend/action/export-csv.php`

**Masalah:** Search box configured.php dan CSV export hanya bisa cari 14
kolom — operator tidak bisa mengetik "LOS" atau "DyingGasp" untuk temukan
semua ONU dengan down cause tertentu. Kolom Last Down terlihat di tabel
tapi tidak bisa dicari.

**Fix:** Tambah `onus.last_down_cause LIKE ?` ke search filter (14→15 kolom).
Di-update di configured.php (main query + stats query) dan export-csv.php.
Placeholder search diperbarui: "..., Last Down...".

**Scope:** Search only, zero OLT impact. Desktop + mobile.

## 2026-09-17 — Add WAN mode + ONU type columns to configured table

**Commit:** `4ea2575`
**File:** `frontend/configured.php`

**Masalah:** Search filter configured.php sudah bisa cari `wan_mode`/`onu_type`
(tambahan siklus lalu) tapi tabel tidak menampilkan kolom tsb — operator tidak
bisa lihat kenapa search cocok tanpa klik Detail tiap ONU.

**Fix:** Tambah kolom WAN (badge biru) dan Tipe ONU (text muted) ke data table.
SELECT query diperbarui (tambah `onus.wan_mode`, `onus.onu_type`). Colspan
empty-state diperbarui dari 9 ke 11.

**Scope:** UI only, zero OLT impact. Desktop + mobile.

## 2026-09-17 — Add onu_type to CSV export + sync search filter

**Commit:** `fc3b39a`
**File:** `frontend/action/export-csv.php`

**Masalah 1:** CSV export tidak punya kolom ONU Type — operator tidak bisa
identifikasi model ONU saat audit inventory.

**Masalah 2:** Search filter di export-csv.php cuma 10 kolom, configured.php 14.
Search "PPPoE" di halaman configured menghasil N hasil, CSV export dengan search
sama menghasil lebih sedikit — inkonsisten.

**Fix 1:** Tambah onu_type ke SELECT, CSV header, dan CSV data rows.

**Fix 2:** Sync search filter ke 14 kolom (sama dengan configured.php).

**Scope:** Backend only, zero OLT impact.

## 2026-09-17 — Add wan_mode + onu_type to configured.php search

**Commit:** `0fe9db4`
**File:** `frontend/configured.php`

**Masalah:** Search box configured.php hanya mencari 12 kolom — operator tidak
bisa cari "PPPoE" untuk temukan semua pelanggan PPPoE, atau model ONU tertentu
("ZTE-F670L", "Bridging", dll).

**Fix:** Tambah `onus.wan_mode LIKE ?` dan `onus.onu_type LIKE ?` ke search
filter (14 kolom, sebelumnya 12). Update di COUNT, data pagination, stats
summary, dan placeholder text.

**Scope:** UI only, zero OLT impact. Desktop + mobile.

## 2026-09-17 — Remove hardcoded VLAN 25 fallback in onu-detail.php

**Commit:** `ad024af`
**File:** `frontend/onu-detail.php`

**Masalah:** Modal "Update ONU Mode" di onu-detail.php masih punya fallback
hardcoded `$cached_vlans = [25]` saat tidak ada VLAN cached di DB untuk OLT
tersebut. Sama pola dengan bug yang sudah di-fix di configured.php (commit
faebebd) — angka 25 arbitrary, bukan data aktual.

**Fix:** Hapus fallback. Dropdown mulai kosong; `loadLiveVlans()` JS mengisi
dari live OLT data saat modal dibuka. Zero OLT impact.

**Scope:** UI only, desktop + mobile (modal hanya tampil di desktop/tablet).

## 2026-09-17 — Fix hardcoded VLAN 25 fallback + add PON port to search

**Commit:** `faebebd`
**File:** `frontend/onu-detail.php`, `frontend/configured.php`

**Masalah 1:** "Mode ONU" di onu-detail.php tampilkan "Routing — WAN 25" saat
VLAN kosong/null. Angka 25 hardcoded arbitrary, bukan data aktual — misleading.

**Fix 1:** Fallback ubah dari `'25'` ke `'N/A'` (PHP render + JS live-update).

**Masalah 2:** Search box configured.php tidak mencari kolom `pon_port`.
Operator ketik "1/2/6" tidak menemukan ONU di PON port tsb.

**Fix 2:** Tambah `onus.pon_port LIKE ?` ke search filter (12 kolom, sebelumnya 11).
Update di COUNT, data pagination, stats summary. Placeholder text diperbarui.

**Scope:** UI only, zero OLT impact. Desktop + mobile.

## 2026-09-17 — Add zone/splitter to presets + fix Shelf/Port label

**Commit:** `6bc804d`
**File:** `frontend/auth-onu.php`, `frontend/action/onu-presets.php`

**Masalah 1:** Label "Board X / Port Y" di halaman Otorisasi salah istilah
(mestinya Slot) dan hilangkan shelf. Tidak konsisten dengan onu-detail.php.

**Masalah 2:** Preset tidak menyimpan zone/splitter. Operator harus pilih
ulang tiap kali padahal satu area biasanya = satu zone/splitter.

**Masalah 3:** Race condition — preset load bisa selesai sebelum VLAN list
fetch, VLAN dari preset tidak ter-select.

**Fix 1:** Label jadi "Shelf X / Slot Y / Port Z".

**Fix 2:** `ALTER TABLE onu_presets ADD COLUMN zone/splitter`. Save/load
presets sekarang mencakup zone + splitter. Cascade: set zone → load splitter
list → select splitter dari preset.

**Fix 3:** Deferred apply — preset VLAN/splitter disimpan sementara,
di-apply setelah list selesai dimuat.

**Scope:** UI + DB only, zero OLT impact. Desktop + mobile.
## 2026-09-17 — Fix PPPoE password field type + add VLAN search

**Commit:** `499316f`
**File:** `frontend/auth-onu.php`, `frontend/configured.php`

**Masalah 1 (Security):** Field password PPPoE di halaman Otorisasi (`auth-onu.php`)
menggunakan `type="text"` sehingga password terlihat jelas saat diketik operator.

**Fix 1:** Ubah `type="text"` → `type="password"` pada field `pppoe_password`.

**Masalah 2 (Usability):** Search box configured.php tidak mencari kolom VLAN.

**Fix 2:** Tambah `onus.vlan LIKE ?` ke search filter (11 kolom, sebelumnya 10).
Diperbarui di ketiga query: COUNT, data pagination, dan stats summary.

## 2026-09-17 — Filter dropdown auto-submit + ONU scan timeout

**Commit:** `45e74f8`

**Masalah 1:** Filter dropdown (status, zone, ODB, PON, signal) di configured.php
harus klik tombol "Filter" setelah pilih — beda dengan dropdown OLT yang auto-submit.
Kurang konsisten, perlu extra klik especially di mobile.

**Fix 1:** Tambah `onchange="this.form.submit()"` ke semua filter `<select>` di
configured.php (status, zone, odb, pon_port, signal). Filter button tetap ada
sebagai fallback.

**Masalah 2:** Scan ONU baru di unconfigured.php tidak punya timeout — spinner
berputar terus kalau OLT tidak merespons (misal network down / OLT rebooting).

**Fix 2:** Tambah AbortController timeout 60 detik ke fetch scan. Pesan error
spesifik "Waktu pemindai habis (>60 detik)" muncul kalau timeout tercapai.

**Scope:** Frontend only (configured.php, unconfigured.php), no OLT/DB changes.

## 2026-09-17 — Fix wan_remote_access default on authorize + PON port label swap

**Commit:** `768a1c1`

**Masalah 1:** INSERT di auth-onu.php tidak menyertakan kolom `wan_remote_access`,
jadi ONU baru selalu default 'no' walau authorize aktifkan security-mgmt 998/999.
Dropdown di onu-detail salah tampil "Nonaktif".

**Masalah 2:** Header kolom unconfigured.php "Slot PON"/"Shelf PON" terbalik dari
konvensi ZTE shelf/slot/port. onu-detail.php juga label "Slot" padahal isinya shelf.

**Fix 1:** Tambah `wan_remote_access='yes'` ke INSERT auth-onu.php.
**Fix 2:** Koreksi header + tambah baris Shelf/Slot terpisah di onu-detail.php.

**Impact:** UI/DB only, zero OLT impact. Desktop + mobile.

## 2026-09-17 — Fix LIVE button ReferenceError (scope bug)

**Commit:** `46d926b`

**Masalah:** LIVE toggle handler di second DOMContentLoaded callback referensi
`countdownSec` dan `fetchRealtimeData` yang block-scoped di first callback →
ReferenceError setiap klik LIVE. Immediate fast poll tidak jalan.

**Fix:** Pindah handler ke first callback (sama scope dengan variabel).
Klik LIVE sekarang langsung trigger fetch + set 3 detik interval.

**Impact:** UI only, zero OLT impact.

## 2026-09-17 — Add VLAN to ONU preset system

**Commit:** `cc7e445`

**Masalah:** Preset otorisasi ONU tidak menyimpan VLAN. User harus pilih VLAN
manual tiap kali padahal VLAN biasanya konsisten per preset/area (misal satu
zone = satu VLAN). Membuat preset kurang lengkap.

**Fix:**
1. `ALTER TABLE onu_presets ADD COLUMN vlan INT DEFAULT NULL` — kolom baru.
2. `action/onu-presets.php`: save/load menyertakan `vlan`.
3. `auth-onu.php` JS: saat preset dipilih, VLAN dropdown otomatis ter-select
   sesuai preset. Saat save preset, VLAN saat ini ikut tersimpan.

**Scope:** App-only, TIDAK menyentuh OLT/pelanggan. Eksekusi otomatis.

---

## [eeb2d06] — Fix cron_sync stale cleanup mass-delete + restore missing cron job

**Klasifikasi:** EKSEKUSI OTOMATIS (app-side + infra, zero OLT impact)

**Masalah (kritis):** Dua masalah sekaligus:
1. `/etc/cron.d/smartolt-sync` hilang dari server — cron_sync.py tidak berjalan
   selama beberapa jam, semua data ONU stale >1 jam.
2. Saat cron di-restore dan cron_sync jalan: `pull_configured_onus()` OLT TGR
   (id=8) mengembalikan `success=True` + 0 ONU (telnet port 10023 refused — OLT
   down). Stale cleanup melihat 0 ONU dari OLT dan menghapus SELURUH ~2056 baris
   ONU TGR dari DB.

**Fix:**
1. Restore `/etc/cron.d/smartolt-sync` (cron_sync berjalan lagi setiap menit).
2. Tambah safety check di cron_sync.py: jika pull mengembalikan 0 ONU tapi DB
   masih punya baris untuk OLT tersebut → skip stale cleanup. Mencegah
   mass-delete saat OLT transient unreachable. ONU akan di-sync ulang normal
   saat pull berikutnya berhasil.

**Commit:** eeb2d06

---

## [f428621] — Fix CSV export search filter parity with configured.php

**Klasifikasi:** EKSEKUSI OTOMATIS (app-side, zero OLT impact)

**Masalah:** CSV export search filter hanya punya 8 kolom (name/SN/onu_id/pppoe/
address/desc/extid/contact), tapi configured.php search filter 10 kolom (tambah
`olts.name` LIKE dan `olts.ip` LIKE). Akibat: kalau user search "TGR" (nama OLT)
lalu klik CSV, export berisi lebih banyak baris dari yang tampil di halaman karena
filter OLT name/IP tidak diterapkan di export.

**Fix:** Tambah `OR olts.name LIKE ? OR olts.ip LIKE ?` ke search filter export
(match persis 10 params yang sama dengan configured.php). Juga refactor dari 8x
`$params[]` manual ke `for` loop (lebih singkat, konsisten dengan pola di
configured.php).

**File:** `frontend/action/export-csv.php` (search filter query)
**Scope:** Tidak menyentuh OLT/pelanggan, murni perbaikan query export.

## 6ee448c — Fix LIVE mode polling reverting to 15s after first poll
- **File:** `frontend/onu-detail.php`
- **Masalah:** Tombol LIVE! di halaman ONU Detail hanya melakukan 1 poll cepat (3 detik) lalu kembali ke interval 15 detik. `fetchRealtimeData()` hardcode `countdownSec = 15` tanpa cek apakah LIVE mode aktif. Kedua kode (LIVE toggle dan fetchRealtimeData) berada di `<script>` block terpisah dengan scope berbeda, jadi variabel `liveMode` tidak bisa diakses lintas scope.
- **Fix:** Tambah `isLiveMode()` helper yang cek `data-live` attribute pada tombol LIVE. Tombol LIVE toggle `dataset.live = 'on'/'off'` saat diklik. `fetchRealtimeData()` panggil `isLiveMode()` untuk set countdown ke 3 atau 15 detik sesuai mode.
- **Cakupan:** Desktop + mobile (perubahan murni di JS polling logic, tidak ada layout change)
- **Tier:** 1 (app-side JS only, no OLT interaction)

## [792921a] Fix splitter_port vs odb_port column mismatch in auth-onu.php

**Tanggal:** 2026-09-17 01:xx WIB
**Tier:** 1 (app-side, zero OLT risk)
**File:** `frontend/action/auth-onu.php`
**Masalah:** `auth-onu.php` INSERT ke kolom `splitter_port` tapi `onu-detail.php`
identity modal baca/tulis kolom `odb_port`. Kedua kolom ada di tabel `onus` dan
mewakili konsep sama (port ODB/splitter). Data port yang diisi saat otorisasi
ONU baru tersimpan di kolom yang salah, sehingga tidak terlihat di halaman edit
identitas ONU.
**Fix:** Ganti `splitter_port` → `odb_port` di INSERT statement.
**Cakupan:** Desktop + mobile (berlaku untuk semua viewport).
**Commit:** 792921a

---

## [e761d4c] Fix ONU detail mobile layout — stack label/value on small screens

**Tanggal:** 2026-09-17 00:xx WIB
**Tier:** 1 (UI-only, zero OLT risk)
**Masalah:** `.detail-plain-row` di onu-detail.php pakai grid `190px 1fr` di SEMUA ukuran layar. Pada layar <600px, label makan ~190px + gap 20px, sisa ~50px untuk value — teks terpotong/tidak terbaca. Edit identity modal (`.modal-body-grid`) juga 2-kolom inline yang tidak collapse di mobile.
**Fix:** Tambah `@media (max-width: 600px)` di `style.css`:
- `.detail-plain-row` → `grid-template-columns: 1fr` (label di atas, value di bawah)
- `.detail-plain-label` → `text-align: left` + smaller font
- `.modal-body-grid` → `grid-template-columns: 1fr !important` (override inline)
**Cakupan:** Mobile (<600px) pada onu-detail.php. Desktop tidak berubah.
**Commit:** `e761d4c`
**Rollback:** `git revert e761d4c` di `/home/kamal/smartolt`, lalu `bash update.sh`.

---

## 2026-09-16 — Fix VLAN display showing literal 'None' in onu-detail + configured

**Commit:** `f754bc9`

## Fix contact 'None' display in onu-detail (commit aab096a)

**File:** frontend/onu-detail.php
**Masalah:** Kolom kontak di ONU detail page langsung baca `$onu['contact']` tanpa filter
`strtolower() !== 'none'` — beda dengan zone/splitter/address/name yang sudah difix.
DB row lama dengan literal string 'None' tampil apa adanya ke user.
**Fix:** Gunakan `$parsed_desc['contact']` dengan guard `strtolower() !== 'none'`,
konsisten dengan pola field identitas lain di halaman yang sama.
**Dampak OLT:** Tidak ada. Murni fix tampilan di sisi frontend.

**Masalah:** VLAN field di onu-detail.php menampilkan teks literal "None" saat VLAN kosong/null. Badge VLAN di configured.php juga menampilkan "None". Bug ini sekelas dengan 1ccbd0a (zone/splitter/address/contact) tapi kolom VLAN terlewat.

**Fix:**
- `onu-detail.php`: PHP render `': 'None'` → `': 'Belum diisi'`, JS live-update: tambah fallback `'Belum diisi'` saat `data.vlan` falsy.
- `configured.php`: PHP badge `': 'None'` → `': '-'`, JS live-update badge `|| 'None'` → `|| '-'`.
- `data-vlan` attribute tetap pakai `'None'` sebagai sentinel internal filter (bukan display).

## 2026-09-16 — Fix 'None' sentinel leaking to UI display and identity modal inputs

**Commit:** `1ccbd0a`

**Masalah:** Zone/Splitter/Address di halaman ONU Detail menampilkan teks literal "None" saat data kosong (bukan "Belum diisi"). Identity modal (Edit Identitas & Lokasi ONU) juga menampilkan "None" sebagai nilai editable di input Nama/Kontak/Zone/Splitter/Alamat saat data belum diisi — user bisa submit "None" kembali ke OLT tanpa menyadari itu bukan data nyata.

**Fix:** Tambahkan pengecekan `strtolower() !== 'none'` pada semua display dan modal value di `onu-detail.php`, konsisten dengan pola yang sudah benar di field Nama. 8 titik perbaikan: 3 display (zone/splitter/address), 5 modal value (name/contact/zone/splitter/address + JS currentSplitter).

## 2026-09-16 — Align signal badge thresholds with filter dropdown (4-tier)

**Commit:** `9209875`

**Masalah:** Signal filter dropdown di configured.php punya 4 tier (critical < -30, weak -30 s/d -28, fair -28 s/d -25, good >= -25), tapi badge warna Rx ONU/OLT di configured.php dan onu-detail.php cuma pakai 3 tier (red/orange/green) dengan threshold berbeda (-30 dan -27). Hasil: ONUs dengan sinyal -26 ditampilkan badge hijau ("good") padahal filter mengkategorikannya sebagai "fair", dan -27 ditampilkan hijau padahal masuk range "weak" di filter.

**Fix:** Badge warna Rx ONU+OLT sekarang 4-tier sesuai filter: merah (< -30), oranye (-30 s/d -28), kuning (-28 s/d -25), hijau (>= -25). Berlaku di semua tempat: configured.php (PHP server-side + JS polling), onu-detail.php (PHP initial render + JS realtime + JS Get Status handler). `bg-yellow` CSS class sudah ada di style.css.

**Scope:** Desktop + mobile (badge di tabel/list dan halaman detail).

## 2026-09-16 — Fix GPS coordinates contamination in splitter field

**Commit:** `94ba9bd`

**Masalah:** OLT kadang append GPS coordinates (`lat -7.85 long 111.26`) ke nama splitter di description string. Parser tidak strip ini, sehingga kolom `onus.splitter` bocor koordinat. 10 baris DB terpengaruh.

**Fix:**
1. `db.php`: tambah GPS coords strip `preg_replace` di `parse_structured_description()`.
2. `db.py`: Python parser parity — tambah regex strip yang sama.
3. 10 baris DB sudah dibersihkan via re-parse dari `description` source (backup: `onus_backup_gps_fix`).

## 2026-09-16 — Fix 'None' sentinel leaking ke DB (update-onu-mode.php + parser)

**Commit:** `9b570dd`

**Masalah:**
1. `update-onu-mode.php`: bug identik dengan `auth-onu.php` (fix sebelumnya di9ec291d hanya sentuh auth-onu.php) — variabel zone/splitter/address/contact bisa berisi literal string 'None' saat kosong, langsung di-UPDATE ke kolom DB. Efek: tampilan beda antara render pertama vs setelah SNMP sync.
2. `auth-onu.php`: `$name` tidak punya konversi _db — bisa simpan 'None' ke kolom `name`.
3. `onu-detail.php`: config_preset modal input pakai `value="Tidak ada"` (bukan placeholder) — submit tanpa ubah menyimpan literal "Tidak ada" ke DB.
4. `db.php`/`db.py`: `parse_structured_description()` tidak filter 'None' dari hasil parse — deskripsi dengan `zone_None` mengembalikan string 'None' bukan null.

**Fix:**
1. `update-onu-mode.php`: tambah _db variants, konversi 'None'/'' -> null sebelum UPDATE.
2. `auth-onu.php`: tambah `$name_db`.
3. `onu-detail.php`: config_preset input pakai `placeholder="Tidak ada"`, value pakai raw value (null/empty).
4. `db.php`: semua field di `parse_structured_description()` filter 'None' -> null.
5. `db.py`: Python parser parity — filter 'None' dari semua field.

## 2026-09-16 — Fix silent error swallowing in cron_sync + remove production console.log

**Commit:** `58cade0`

**Masalah:**
1. `cron_sync.py` SNMP fast-sync: `except Exception: pass` pada per-baris DB UPDATE — kegagalan update tidak tercatat, menyulitkan debug di production.
2. `cron_sync.py` seluruh blok SNMP fast-sync dibungkus `except Exception: pass` — kegagalan total sync tidak tercatat sama sekali.
3. `configured.php`: `console.log` di production muncul tiap 60 detik (noise di browser console).

**Fix:**
1. Tambah `print()` logging per-baris yang gagal UPDATE (id ONU + error).
2. Tambah `print()` logging untuk kegagalan total SNMP fast-sync.
3. Tambah summary sukses `SNMP fast-sync: N/M baris diupdate.` setelah selesai.
4. Hapus `console.log` dari `configured.php`.

**Scope:** Aplikasi saja (cron_sync.py + configured.php). Tidak menyentuh driver OLT atau config pelanggan.

---

## 2026-09-16 — Fix flash message HTML tags + role validation + error leakage

**Commit:** `7e9af30`

**Masalah:**
1. 3 flash message di `replace-onu.php` dan `update-onu-mode.php` mengandung tag `<strong>` yang ternyata di-escape oleh `header.php` (htmlspecialchars), sehingga user melihat literal `<strong>` sebagai teks biasa, bukan formatting bold.
2. Parameter `role` di `action/user.php` tidak divalidasi terhadap daftar nilai yang diizinkan (superadmin/bisa). DB ENUM melindungi dari nilai asing, tapi error message MySQL tidak informatif.
3. `sync-olt.php` mengirim `$e->getMessage()` ke response JSON AJAX, berpotensi membocorkan detail internal (DB connection string, stack trace) ke klien.

**Fix:**
1. Hapus tag HTML dari flash messages — gunakan plain text.
2. Tambah `in_array()` whitelist untuk role parameter.
3. Hapus detail exception dari AJAX response — error tetap di-log di server.

**Scope:** Murni app-side, tidak ada risiko ke OLT/pelanggan.


## [81834d6] Fix XSS: splitter names in auth-onu.php innerHTML
**Tanggal:** 2026-09-16 07:40 WIB
**Tier:** 1 (app-side, zero OLT risk)
**Masalah:** `auth-onu.php:283` — splitter option dropdown render `s.name` ke innerHTML
tanpa `esc()`. Nama splitter dari DB (parsed dari OLT description) bisa mengandung
chars `<`, `>`, `&`, `"`. Jalur exploit: stored XSS via nama splitter yang dimasukkan
user lewat halaman Splitters Edit.
**Fix:** wrap `s.name` dengan `esc()` di value attribute dan textContent.
**Cakupan:** Desktop + mobile (dropdown di form Otorisasi ONU).
**Commit:** 81834d6
**Rollback:** `git revert 81834d6` di `/home/kamal/smartolt`, lalu `bash update.sh`.

---

## 2026-09-16 -- XSS innerHTML lanjutan (configured, auth-onu, settings-olt)

**Tanggal:** 2026-09-16 07:25 WIB
**Tier:** 1 (app-side, no OLT impact)
**Masalah:** 3 halaman masih punya innerHTML tanpa escaping:
- `configured.php`: `onu.vlan`, `onu.rx_onu`, `onu.rx_olt` dimasukkan ke innerHTML langsung (sudah punya `esc()` tapi tidak dipakai di 3 lokasi).
- `auth-onu.php`: VLAN options (`v.description`, `id`) dan speed profile options (`p.name`, `p.speed_kbps`) di-render tanpa `esc()`.
- `settings-olt.php`: `data.message` dari test-OLT response dimasukkan ke `title` attribute tanpa escape.
**Fix:**
- `configured.php`: wrap `vlanVal`, `onu.rx_onu`, `onu.rx_olt` dengan `esc()`.
- `auth-onu.php`: tambah `esc()` helper, wrap VLAN option values dan speed profile option values.
- `settings-olt.php`: tambah `esc()` helper, wrap `data.message` di test-OLT title attributes.
**Cakupan:** Desktop + mobile (sama HTML, berlaku semua viewport).
**Commit:** 3c24b98
**Rollback:** `git revert 3c24b98` di `/home/kamal/smartolt`, lalu `bash update.sh`.

---
## [7eb364b] Fix XSS via innerHTML — onu-detail, configured, olt-detail
**Tanggal:** 2026-09-16 07:xx WIB
**Tier:** 1 (app-side, no OLT impact)
**Masalah:** JS innerHTML di 3 halaman memasukkan data dari server tanpa HTML escaping — customer name/address dari OLT, error messages, dan toast notifications bisa dieksekusi sebagai HTML/JS jika mengandung tag.
**Fix:**
- `onu-detail.php`: tambah `esc()` helper, bungkus `cleanVal` di `setChipVal()`, `data.onu_mode`/`data.vlan` di onuModeEl, `data.message` di banner error. Cast `(int)` untuk ethernet/wifi/voip ports.
- `configured.php`: tambah `esc()` helper, bungkus `message` di `showToast()` (tipe info/success/error).
- `olt-detail.php`: sudah punya `esc()`, bungkus `data.message` dan `msg` di errBox health check.
**Cakupan:** Desktop + mobile (sama HTML, berlaku untuk semua viewport).
**Commit:** 7eb364b
**Rollback satu perubahan:** `git revert <hash>` di `/home/kamal/smartolt`, lalu sync
ulang ke live (`bash update.sh` atau copy manual + restart service terkait).

---

## Riwayat


### [2026-09-16 WIB] — Fix XSS in settings-users + olt-detail + search address in configured/export-csv
**File:** `frontend/settings-users.php`, `frontend/olt-detail.php`, `frontend/configured.php`, `frontend/action/export-csv.php`
**Tier:** 1 (app-side, zero OLT risk)
**Commit:** `9a3a1d9`

- **XSS**: `settings-users.php` — `$user['id']` tanpa `(int)` cast di 3 tempat (td, data-attr, hidden input).
- **XSS**: `olt-detail.php:442` — `ucfirst($o['status'])` tanpa `htmlspecialchars()`.
- **Search improvement**: `configured.php` + `export-csv.php` — search bar sekarang juga mencari `onus.address`, bukan cuma name/sn/onu_id/pppoe_username.


### [2026-09-16 WIB] — Fix N+1 in onu-types/get-splitters + XSS escapes + audit logging
**File:** `frontend/onu-types.php`, `frontend/splitters.php`, `frontend/olt-detail.php`, `frontend/action/get-splitters.php`, `frontend/action/update-onu-type.php`, `frontend/action/update-onu-splitter.php`, `frontend/action/vlan.php`, `frontend/action/port-config.php`
**Tier:** 1 (app-side, zero OLT risk)
**Commit:** `9577110`

- **N+1 fix**: onu-types.php dan get-splitters.php pakai correlated subquery `(SELECT COUNT(*) FROM onus WHERE ...)` di setiap row → ganti LEFT JOIN subquery (sama seperti pattern yang sudah diperbaiki di splitters.php sebelumnya).
- **XSS hardening**: cast `(int)` pada semua integer ID yang di-render ke HTML (onu-types, splitters, olt-detail), `htmlspecialchars()` pada pon_capabilities option values di onu-types.
- **Audit logging**: tambah `write_audit_log()` ke 4 mutation action handler yang sebelumnya tidak mencatat aktivitas: `update-onu-type.php` (ONU_TYPE_CHANGE), `update-onu-splitter.php` (ONU_SPLITTER_CHANGE), `vlan.php` (VLAN_ADD/DELETE/SAVE-VLAN-PORTS/PORT-VLAN), `port-config.php` (PORT_CONFIG).


### [2026-09-16 WIB] — Login brute-force + audit log + XSS configured.php
**File:** `frontend/action/login.php`, `frontend/action/logout.php`, `frontend/action/onu-presets.php`, `frontend/configured.php`, `frontend/action/clear-logs.php`
**Masalah:**
1. `login.php` — tidak ada batasan percobaan login. Attacker bisa brute-force tanpa limit.
2. `logout.php` — tidak mencatat audit log saat user logout.
3. `onu-presets.php` — save/delete preset tanpa audit log.
4. `configured.php` line 517 — `$onu['id']` tanpa `(int)` cast, `$onu['status']` tanpa `htmlspecialchars` di attribute. Line 566 — `$onu['id']` tanpa `(int)` cast di href.
5. `clear-logs.php` — tidak ada jejak siapa yang menghapus log (audit log ikut ter-truncate).
**Fix:**
1. Session-based rate limiting: max 5 percobaan per 15 menit per session. Counter reset otomatis setelah window expired. Login lockout di-log ke audit.
2. Tambah `write_audit_log(USER_LOGOUT)` sebelum `session_destroy()`.
3. Tambah `write_audit_log(PRESET_SAVE/PRESET_DELETE)` pada action save dan delete.
4. `$onu['id']` → `(int)$onu['id']`; `$onu['status']` → `htmlspecialchars(...)`.
5. Tambah `error_log()` sebelum TRUNCATE supaya ada jejak di PHP log.
**Klasifikasi:** Eksekusi otomatis (app-side security + audit + XSS, zero OLT risk).


### [2026-09-16 12:30 WIB] — Fix XSS onu-detail + radio button bug + JS context injection
**File:** `frontend/onu-detail.php`, `frontend/header.php`, `frontend/configured.php`, `frontend/dashboard.php`, `frontend/settings-olt.php`, `frontend/settings-users.php`, `frontend/login.php`, `frontend/register.php`
**Masalah:**
1. `onu-detail.php` — 14x `echo $onu['id']` tanpa `htmlspecialchars`/`(int)` di hidden input + JS variable; `$onu['status']` tanpa escape di CSS class; `$onu['olt_id']` tanpa escape; `$cv` VLAN tanpa escape di option value.
2. `onu-detail.php` line 1059 — radio button "Bridging" pakai `'selected'` (atribut `<option>`) bukan `'checked'` (atribut `<input type=radio>`) → ONU mode Bridging TIDAK PERNAH ter-select di UI, selalu tampil Routing walau data DB = Bridging. Bug nyata, user-facing.
3. `header.php` — CSRF token + API token di-inject ke JS context via string concatenation (`'...'`) tanpa `json_encode` → vulnerability jika token mengandung `'` atau `</script>`.
4. `configured.php`, `dashboard.php`, `settings-olt.php`, `settings-users.php` — `$olt['id']` tanpa escape di attribute value.
5. `login.php`, `register.php` — `$SESSION['csrf_token']` tanpa `htmlspecialchars` di hidden input value.
**Fix:**
1. `$onu['id']` → `(int)$onu['id']`; `$onu['status']` → `htmlspecialchars(...)`; `$olt['id']` → `(int)$olt['id']`; `$cv` → `(int)$cv`.
2. `'selected'` → `'checked'` untuk radio button Bridging.
3. `header.php` pakai `json_encode()` untuk CSRF/API token injection.
4. `login.php`/`register.php` pakai `htmlspecialchars()` pada token.
**Klasifikasi:** Eksekusi otomatis (app-side XSS + UI bug, zero OLT risk).

### [2026-09-16 05:15 WIB] — Fix auth bypass in splitters/onu-types + DB indexes + fix N+1 + XSS fix — commit `c3fec68`
**File:** `frontend/splitters.php`, `frontend/onu-types.php`, `frontend/olt-detail.php`, `backend/db.php`
**Masalah:**
1. `splitters.php` dan `onu-types.php` punya POST handler (INSERT/UPDATE/DELETE) yang jalan SEBELUM `header.php` di-include → auth check tidak pernah dieksekusi untuk POST request. Sama dengan pola bug yang sudah diperbaiki di `update-onu-type.php`/`update-onu-splitter.php`.
2. Tabel `onus` (2000+ rows) tanpa index pada kolom yang sering di-query: `status`, `zone`, `splitter`, `pon_port`.
3. `splitters.php` gunakan correlated subquery `(SELECT COUNT(*) FROM onus o WHERE o.splitter = s.name)` di 2 tempat (main list + stats) → N+1 pattern.
4. `olt-detail.php` output `$olt['id']` tanpa `htmlspecialchars()` di 3 tempat (hidden input + JS).
**Fix:**
1. Tambah auth check (superadmin) sebelum POST handler di kedua file.
2. Migration v3: `idx_onus_status`, `idx_onus_zone`, `idx_onus_splitter`, `idx_onus_pon_port`, `idx_onus_olt_status`.
3. Ganti correlated subquery dengan LEFT JOIN subquery.
4. Tambah `htmlspecialchars()` pada `$olt['id']`.
**Klasifikasi:** Eksekusi otomatis (app-side, zero OLT risk).
### [2026-09-16 03:45 WIB] — Fix C320 get_onu_hw_sw parity + fix broken require_login() in snmp-sync/backup-config — commit `3710c77`
**File:** `backend/python_engine/drivers/zte_c320.py`, `frontend/action/snmp-sync.php`, `frontend/action/backup-config.php`
**Masalah:**
1. `zte_c320.py` tidak punya `get_onu_hw_sw` — tombol "SW info" di `onu-detail.php`
   error "tidak didukung" untuk ONU di OLT C320 (driver parity gap, bug yang sama
   dgn speed profile C320 yang pernah hilang sebelumnya).
2. `snmp-sync.php` dan `backup-config.php` memanggil `require_login()` yang TIDAK
   PERNAH didefinisikan di mana pun → PHP fatal error. Kedua fitur (SNMP bulk sync
   dan backup running-config OLT) sepenuhnya tidak bisa dipakai.
**Fix:** Tambah `get_onu_hw_sw()` identik dari C300 (CLI `show gpon remote-onu equip`
+ `capability`, read-only, tidak ubah config OLT). Ganti `require_login()` dengan
auth check standar + tambah `session_write_close()` + `set_time_limit(300)`.
**Klasifikasi:** Tier 1 (app-side, non-driver impact).

### [2026-09-16 03:30 WIB] — Fix C320 configure_onu_full exit-count + authorize_onu error check + set_time_limit — commit `b9594c4`
**File:** `backend/python_engine/drivers/zte_c320.py`, `frontend/action/update-onu-mode.php`, `frontend/action/replace-onu.php`
**Masalah:**
1. C320 `configure_onu_full` hanya 1x `exit` sebelum `write` (pon-onu-mng → config
   = masih 1 level di atas EXEC). C300 sudah benar: 2x exit (pon-onu-mng → config
   → EXEC). Akibat: OLT menolak `write` dari config mode, konfigurasi tidak tersimpan.
2. C320 `authorize_onu` pakai `'Error' not in log` (raw substring) — false positive
   pada error informational, false negative pada error tanpa kata "Error". C300 sudah
   pakai `_vlan_errors()` + filter `%Code 62391`.
3. `update-onu-mode.php` dan `replace-onu.php` tidak punya `set_time_limit(0)`.
   Kedua handler chain 2-3+ SSH round-trip (configure_onu_full + assign_speed_profile;
   delete + authorize + configure) — melebihi PHP default 30s. `auth-onu.php` sudah
   benar sejak sebelumnya.
**Fix:** tambah 1x exit C320 configure_onu_full; replace error check authorize_onu C320
dengan `_vlan_errors()` + filter 62391; tambah `set_time_limit(0)` di kedua handler.
**Klasifikasi:** C320 driver fix by-parity (tidak ada OLT C320 aman untuk tes live);
PHP handler fix = Tier 1 (non-driver).

### [2026-09-16 03:10 WIB] — Fix exit-count kurang di disable/enable ONU (C300+C320) + delete_onu C320 tanpa cek error OLT — Tier 2 — commit `d3f0c9a`
**File:** `backend/python_engine/drivers/zte_c300.py`, `backend/python_engine/drivers/zte_c320.py`
**Masalah:** `disable_onu`/`enable_onu` (shutdown/no shutdown) di kedua driver hanya
1x `exit` sebelum `write`, padahal `configure terminal` → `interface gpon-onu_X:Y`
dua level nested — butuh 2x exit. OLT menolak `write` dengan `%Error 20200: Invalid
input detected at '^' marker` (masih mode config, bukan EXEC). Akibat: perubahan
shutdown/no-shutdown TIDAK PERNAH tersimpan ke flash walau fungsi mengembalikan
`success:true` — bug klasik yang sama dengan yang sudah diperbaiki di
`authorize_onu`/`delete_onu` (C300) dulu, ternyata belum disalin ke method
disable/enable maupun ke driver C320. `delete_onu` C320 juga masih pola lama:
1x exit + hardcoded `success:True` tanpa cek respons OLT.
**Fix:** tambah 1x exit lagi sebelum `write` pada shutdown/no-shutdown (kedua
driver); delete_onu C320 disamakan dengan C300 (2x exit + `_vlan_errors()` check).
**Bukti tes:** ONU test `ZTEGDDBFFDCA`, PON `1/2/6`, OLT id=8 (TGR, C300).
authorize_onu sukses (VLAN 999) → disable_onu SEBELUM fix ditolak `%Error 20200`
→ SESUDAH fix: `write` sukses `[OK]`, `show running-config interface
gpon-onu_1/2/6:107` menunjukkan baris `shutdown` benar tersimpan → enable_onu
SESUDAH fix: `write` sukses, baris `shutdown` hilang dari config, tidak ada
entry ganda. Cleanup: delete_onu_from_olt + DELETE FROM onus — state bersih.
C320 delete_onu tidak dites live (tidak ada OLT C320 yang aman disentuh
selain OLT id=4/GBB yang sudah production) — diperbaiki by-parity dengan
kode C300 yang identik dan sudah tervalidasi.

<!-- FORMAT ENTRI:
### [TANGGAL JAM] — Judul singkat — Tier N — commit `<hash>`
**File:** path/ke/file
**Masalah:** ...
**Fix:** ...
**Bukti tes** (Tier 2 saja): log command + running-config before/after
-->

## 2026-09-16 (manual, atas persetujuan user)
- Fix: `cdata_fd1602sb1.py` `configure_onu_full()` tidak lagi hardcode `success:True`
  — sekarang cek response OLT untuk `failed`/`error`/`invalid` sebelum lapor sukses.
- Security: tambah `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` di
  `backend/config.php` — menutup clickjacking + MIME sniffing di semua halaman.
- Koreksi: proposal "CSRF validation belum ada" DITOLAK — sudah ada sejak awal,
  cron salah analisa (lihat REVIEW.md untuk detail verifikasi).

## 2026-09-17 (02:11) — Fix missing description in ONU authorize + enum sanitization

**Commit:** f49c9c6

### Temuan 1: `description` kolom hilang dari INSERT auth-onu.php (Tier 1 — auto-deploy)
- **Masalah:** `auth-onu.php` menghitung `$structured_desc` tapi tidak memasukkan ke kolom `description` INSERT. ONU baru punya `description=NULL` di DB sampai cron sync SNMP backfill (~1 menit window). Selama window itu, `onu-detail.php` fallback parse dari kolom `name` — bisa salah kalau name berbeda format.
- **Fix:** Tambah `description` + `$structured_desc` ke INSERT.

### Temuan 2: `cli_safe_strict()` dipakai untuk enum fields di update-onu-mode.php (Tier 1)
- **Masalah:** `onu_mode` dan `config_method` pakai `cli_safe_strict()` yang strip spasi — sama pola dengan bug historis `wan_mode='Setup via ONU webpage'` yang pernah corrupt. Saat ini tidak broken ('Routing'/'Bridging'/'OMCI'/'TR069' tidak punya spasi), tapi pola salah dan rentan ke depan.
- **Fix:** Ganti ke `in_array()` whitelist validation (sama seperti `wan_mode` sudah benar).


## 2026-09-17 — Sync configured.php live + repo bidirectional drift fix

**Files:** `frontend/configured.php` (repo→live), `frontend/action/add-olt.php` + `edit-olt.php` (live→repo)
**Masalah:** Live configured.php ketinggalan 4 kolom (Zone, Splitter, Profil DL, Profil UL) yang sudah di-commit ke repo. Sebaliknya, add-olt.php/edit-olt.php di live punya perbaikan (async background pull, SSH port dedup error) yang belum masuk repo.
**Fix:** 1) Copy repo configured.php → live (restore4 kolom hilang). 2) Copy live add-olt/edit-olt.php → repo (commit 41ec96b).
**Scope:** Sync only, no new code changes.

## 2026-09-17 — Remove stale Python dead code from frontend/action/

**Commit:** 0e8912f
**Perubahan:** Hapus 15 file Python stale dari `frontend/action/` (app.py, cli.py, db.py,
helper.py, cron_sync.py, snmp_module.py, sync_one_onu.py, sync_snmp_onus.py,
backup_configs.py, auth.py, registry.py, plus drivers/ subdir). Ini salinan
lama dari file asli di `backend/python_engine/` — tidak dipakai oleh PHP manapun,
tidak di-serve Apache, hanya clutter di repo.
**Scope:** Repo cleanup only, no live impact.

## 2026-09-17 — sync-olt.php GET trigger removal + CSRF #5 closure
- **Fix:** `sync-olt.php` accept GET request (`isset($_GET['olt_id'])`) yang bypass
  CSRF middleware. Frontend configured.php sudah pakai POST, GET path = dead code.
  Dihapus, sekarang POST-only (commit 5848700).
- **REVIEW.md:** Proposal #5 CSRF ditolak — `backend/config.php` middleware (lines 77-97)
  sudah verifikasi CSRF otomatis untuk SEMUA POST action handlers. Proposal berdasarkan
  asumsi keliru bahwa hanya `onu-types.php`/`splitters.php` yang punya CSRF check.

## [2026-09-17] Live-update customer name in configured.php table

**Commit:** 2cfc0cf
**Tier:** 1 (app-side, non-driver)

**Masalah:** `get-signals-db.php` tidak include kolom `name` di query, response JSON,
atau ETag CRC32 hash. Saat cron sync memperbarui nama ONU dari placeholder (`ONU_xxxx`)
menjadi nama pelanggan sesungguhnya, tabel configured.php tidak pernah menampilkan nama
baru — browser dapat 304 Not Modified (ETag unchanged karena name tidak di-hash), dan
JS live-refresh tidak punya data `name` di response. User harus manual refresh halaman.

**Fix:**
1. `get-signals-db.php`: tambah `name` ke ETag CRC32 hash, SELECT query, dan JSON
   response (termasuk `customer_name` pre-extracted via `extract_customer_name()`).
2. `configured.php`: tambah `data-name` attribute ke `<tr>`, `.name-cell` class ke
   `<td>` nama pelanggan, dan JS live-update block yang meng-update nama dari response.
   
**Cakupan:** Desktop + mobile (tabel responsive yang sama).

## [2026-09-17] Fix XSS in GPS onclick handler (onu-detail.php)

**Commit:** 487e1c6
**Tier:** 1 (app-side, security fix)

**Masalah:** `onu-detail.php` line 352 — `latitude`/`longitude` tidak di-escape di
onclick attribute div "Lokasi". Meskipun nilai di-validasi `is_numeric()` saat input,
defense-in-depth mewajibkan escape di setiap output point.

**Fix:** Tambah `htmlspecialchars()` pada kedua nilai koordinat di onclick handler.
