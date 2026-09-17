# REVIEW.md — Proposal Menunggu Keputusan User

Agen analisa otomatis jalan tiap 5 menit (cron), kirim laporan tiap siklus ke grup
Telegram (baik yang dieksekusi otomatis maupun yang menunggu di sini). File ini HANYA
berisi perubahan yang TIDAK auto-deploy karena punya risiko nyata ke pelanggan aktif —
semua yang lain langsung jalan otomatis tanpa menunggu approval, tercatat di CHANGELOG.md.

## Kebijakan (direvisi 2026-09-16 — satu sumbu keputusan)

User: "AI atau cron boleh melakukan apapun jika itu tidak berdampak ke koneksi
pelanggan." Tidak ada lagi tingkatan bertahap berdasar "seberapa besar/seberapa
yakin" — hanya satu pertanyaan: **apakah ini berisiko mengganggu koneksi pelanggan
aktif di OLT production?**

**EKSEKUSI OTOMATIS (default, tanpa approval):**
- Semua sisi aplikasi (PHP/Python non-driver): bug, fitur baru sebesar apa pun, UI,
  desain, performa/optimasi, keamanan aplikasi (headers, validasi input), laporan,
  export/import, skema DB (non-billing). Bebas total.
- Analisa read-only ke OLT (SNMP GET, `show` command) — tidak pernah berisiko.
- Perubahan driver OLT (termasuk SNMP SET) yang SUDAH dites bersih di ONU test
  (`ZTEGDDBFFDCA`, PON 1/2/6, OLT id=8) DAN tidak menyentuh ONU pelanggan yang sudah
  aktif/online — termasuk command destruktif sekalipun, ASALKAN target satu-satunya
  adalah ONU test milik user.
- Bukti tes (command log + `show running-config` before/after) tetap wajib dilampirkan
  di commit/CHANGELOG.md, tapi TIDAK perlu menunggu approval user untuk deploy.

**WAJIB REVIEW MANUAL (masuk daftar di bawah, TUNGGU keputusan user, dikirim ke grup):**
- Command/SNMP SET destruktif baru yang belum tervalidasi DAN berpotensi menyentuh
  index/binding milik ONU pelanggan real yang sudah aktif.
- Operasi massal/bulk yang menulis ke banyak ONU pelanggan real sekaligus (bulk baca/
  laporan tetap boleh otomatis).
- Perubahan yang menyentuh konfigurasi ONU pelanggan yang statusnya sudah aktif/online
  (bukan ONU baru/belum teregistrasi), dan agen tidak bisa memvalidasinya di ONU test
  (mis. device vendor lain yang belum ada unit testnya).
- Perubahan skema DB yang berisiko data loss, atau yang butuh testing menyeluruh
  sebelum aman (mis. CSP header — bisa mematahkan inline JS existing).

**DILARANG TOTAL (bukan menunggu review, TIDAK PERNAH diusulkan/dieksekusi sama sekali):**
- Apa pun yang mengubah trafik/forwarding ONU pelanggan yang SUDAH aktif melayani
  production. Contoh eksplisit dari user: **ubah mode port trunk ↔ access**.
  Sama berlaku untuk VLAN, bandwidth profile, PON port config pada interface yang
  sudah melayani ONU real — baik lewat CLI maupun SNMP SET. OLT ini production —
  dampak ke pelanggan = tidak dinegosiasikan, tidak ada level risiko yang bisa
  diterima untuk ini.
- Billing/modul pembayaran.

Scope pengembangan SELAIN dua larangan di atas: **bebas, tidak dibatasi** (user:
"tidak ada batasan sejauh mana, kembangkan sejauh mungkin"). Termasuk fitur baru,
analisa performa, analisa backend/frontend mendalam, riset SNMP vs CLI, bukan cuma
bugfix.

---

## Proposal Selesai / Ditutup

### [2026-09-16] CDATA driver: `configure_onu_full` hardcoded `success:True` — DIEKSEKUSI

**File:** `backend/python_engine/drivers/cdata_fd1602sb1.py`
**Fix:** tambah pengecekan `failed`/`error`/`invalid` di response OLT sebelum
melaporkan sukses (pola sama seperti `authorize_onu`/`delete_onu` di file yang sama).
**Kenapa aman dieksekusi tanpa live test:** perubahan murni logika pembacaan response,
tidak mengubah command SSH yang dikirim ke OLT. Tidak ada OLT CDATA aktif di sistem
untuk validasi live — kalau nanti ada, jalankan Prosedur Test ONU dulu untuk
memastikan pola pesan error CDATA cocok dengan filter yang dipakai.
**Dieksekusi:** 2026-09-16, manual atas persetujuan user, di-restart service Python.

### [2026-09-16] Security headers dasar — DIEKSEKUSI

**File:** `backend/config.php`
**Fix:** tambah `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`,
`Referrer-Policy: strict-origin-when-cross-origin` (menutup clickjacking + MIME
sniffing). Diverifikasi live via `curl -I` — header muncul di response.
**Sengaja TIDAK ditambahkan:** `Content-Security-Policy` — `header.php` punya banyak
inline `<script>`, CSP ketat akan mematahkannya tanpa refactor nonce/hash dulu.
Kalau mau dikerjakan, masukkan sebagai proposal terpisah dengan testing menyeluruh
semua halaman (bukan quick win seperti 3 header di atas).

### [2026-09-16] CSRF validation — DITOLAK, sudah ada sebelumnya (analisa cron keliru)

Cron sempat 2x mengusulkan "tambah CSRF validation" karena mengira tidak ada
handler yang memvalidasi token. **Salah** — middleware CSRF sudah ada sejak commit
awal (`6f2f52d`, 2026-09-01) terpusat di `backend/config.php`, otomatis berlaku ke
semua file `/frontend/action/*.php` (semua include `db.php` → `config.php`).
Dites live: POST ke `/action/delete-olt.php` tanpa token → redirect ke dashboard,
tidak diproses. **Cron cycle berikutnya: cek middleware pusat di config.php/db.php
dulu sebelum re-propose fitur yang kelihatannya "belum ada".**

<!--
Template proposal baru (copy ke bawah "## Proposal Menunggu Review" section):

### [TANGGAL] — Judul singkat

**Kategori:** Bug / Perbaikan / Fitur baru / Keamanan
**File terdampak:** path/ke/file
**Kenapa wajib review (bukan auto-deploy):** ...

**Masalah:**
...

**Usulan:**
```diff
...
```

**Analisa dampak ke OLT/ONU real:**
...

**Status:** MENUNGGU REVIEW
-->

---

## Proposal Menunggu Review

### [2026-09-16] WAN remote access dropdown = silent no-op (driver ignores setting)

**Status:** ✅ SUDAH DISELESAIKAN (siklus sebelumnya, bukan proposal ini yang menyelesaikan)

`configure_onu_full()` di C300 (line 1122) dan C320 (line 1119) sudah membaca
`wan.get('wan_remote_access', 'no')` dan mengirim `security-mgmt 1/2` commands
(ingress-type wan) sesuai nilai. `update-onu-mode.php` sudah mengirim `wan_remote_access`
di array `$wan`. Proposal ini stale — tidak perlu dikerjakan lagi.

---

## Roadmap Fokus: Unconfigured → Authorize → ONU Detail → Manajemen Pelanggan (2026-09-16)

Dari riset banding fungsi dengan SmartOLT SaaS asli (mitrafiber.smartolt.com, ONU ZTE
online id=216, SN ZTEGD158E85F). User: "dari pertama installasi sampai jadi pelanggan,
management pelanggan, migrasi layanan... kembangkan sebebas-bebasnya."

Sudah ADA di app (verified via grep onu-detail.php/unconfigured.php):
- Scan/authorize ONU per-OLT, Reboot, Enable/Disable, Delete, Update ONU mode,
  TR069 Profile, Mgmt IP, VLAN, Configuration Preset, Speed profiles, WAN PPPoE/DHCP,
  Replace ONU by SN, History (riwayat sinyal/traffic dari tabel onu_history).

BELUM ADA — kandidat pengembangan (semua di sisi aplikasi, TIDAK ada yang wajib
menyentuh config ONU pelanggan aktif untuk membangunnya, jadi AMAN dieksekusi otomatis
sesuai kebijakan "boleh apapun asal tidak berdampak ke koneksi pelanggan"):

1. **Move ONU** (pindah ONU ke port/OLT lain tanpa reset penuh) — fitur migrasi layanan,
   relevan langsung dengan permintaan user. Harus HATI-HATI: eksekusi command-nya
   (kalau menyentuh ONU real yang sedang aktif) masuk kategori wajib-review, tapi
   MEMBANGUN UI+endpoint-nya (form, validasi, draft command) aman dikerjakan duluan.
2. **Change allocated ONU ID / Update GPON channel** — ganti port/channel ONU (mirip Move).
3. **Configure ethernet port / Configure WiFi port per-port** (ON/OFF per port LAN/WiFi,
   bukan cuma ganti profile) — field `ethernet_ports`/`wifi_ssids` sudah ada di DB
   tapi UI kontrol per-port belum terlihat granular seperti SaaS asli.
4. **Firmware upgrade** — belum ada. Perlu riset CLI command ZTE (tidak ada method
   `firmware_upgrade`/`upgrade_firmware` di driver saat ini).
7. **Enable/Disable VoIP** sebagai toggle terpisah — belum ada. Perlu riset CLI command
   (tidak ada method VoIP di driver saat ini). Field `voip_ports` di `onu_types` hanya
   tampilan, bukan kontrol.

**Sudah selesai (verified di codebase, 2026-09-17):**
- ~~5. Restore to factory defaults~~ → `action/restore-factory.php` + `driver.restore_factory()` ✓
- ~~6. Update location + Maps~~ → identity modal (lat/long + GPS button) + Google Maps link ✓
- ~~8. Change ONU type~~ → Edit ONU Type modal (`change_scope=onu_type`) ✓

Sudah ada tapi mungkin kurang lengkap — WAJIB dicek detail dulu sebelum diklaim gap:
- Update ONU external ID
- Configure speed profiles (cek apakah sudah granular download/upload terpisah)
- Update attached VLANs (cek apakah update, bukan cuma tampilan read-only)

**Instruksi untuk cron siklus berikutnya:** prioritaskan kategori "BELUM ADA" di atas
untuk pengembangan fitur baru, mulai dari yang risiko rendah dulu (Firmware
upgrade/Restore factory cuma tombol trigger + command existing driver, Update location
+ maps cuma field DB + link Google Maps, VoIP toggle cek dulu ada/tidak). Move ONU dan
Change GPON channel BOLEH dibangun (UI+endpoint) tapi eksekusi command real ke ONU aktif
ikuti aturan wajib-review kalau berisiko downtime.

---

## Proposal: Progress indicator untuk proses authorize ONU (2026-09-16)

**Kategori:** UX, tidak menyentuh OLT sama sekali -- aman eksekusi otomatis, TIDAK
perlu menunggu keputusan user, dicatat di sini hanya sebagai referensi hasil analisa.

**Root cause "authorize lambat" (terukur, bukan tebakan):**
Timing per-command (26 perintah config authorize ZTE C300/C320 via telnet, OLT id=8):
- 26 command konfigurasi: ~0.33 detik/command (wajar)
- Command terakhir `write` (OLT commit config ke flash fisik): **34.49 detik SENDIRI
  -- 79% dari total waktu authorize (43.68 detik)**

`write` lambat karena keterbatasan hardware OLT menulis flash -- di luar kendali
aplikasi, TIDAK bisa dipercepat dari sisi kode. Sudah dipangkas bagian yang bisa
diperbaiki (drain socket overhead 0.3s->0.05s per command, commit 246362a, hemat
~8 detik).

**Kenapa user merasa "lambat" secara berlebihan:** UI tidak kasih feedback selama
34 detik proses `write` -- terlihat macet/hang padahal OLT sedang bekerja normal.
User sempat klik authorize berkali-kali karena mengira gagal, memicu bug terpisah
(false-negative "already existed", sudah diperbaiki commit eb5d70e).

**Fix yang diusulkan (UX only):**
1. Saat form authorize disubmit, tampilkan progress text bertahap: "Mengirim
   konfigurasi..." -> "Menyimpan ke OLT (bisa 30-40 detik)..." -> "Selesai".
2. Disable tombol submit selama request berjalan (cegah double-submit).
3. Opsional: ubah pesan sukses supaya jelas kalau OLT butuh waktu commit flash,
   supaya ekspektasi user sesuai kenyataan hardware.

**Analisa dampak ke OLT/ONU real:** Tidak ada. Ini murni JS/UI di sisi browser,
tidak mengubah command atau timing yang dikirim ke OLT.

**Status:** ✅ Sudah dikerjakan (commit staged progress feedback 2026-09-16)

---

## SELESAI: Skip 'write' per-aksi + batch flush (2026-09-16, atas permintaan user)

**Hasil ukur nyata (ONU test, OLT id=8, via web browser sungguhan):**
- Sebelum: authorize ONU 35-55 detik (dominan command 'write' 34s = 79% waktu)
- Sesudah: authorize ONU sekitar **5 detik** (redirect ke configured.php = sukses)
- 'write' flush terpisah oleh timer (0.24 detik, koneksi pool sudah terbuka)

**Cara kerja:** Command config OLT ZTE berlaku langsung ke running-config tanpa
'write' (ONU aktif seketika). 'write' cuma soal persistensi ke flash kalau OLT
kehilangan daya/restart. `execute_ssh_commands()` di helper.py sekarang otomatis
membuang 'write' dari command list apapun aksinya (authorize/delete/disable/
enable/dll -- filter di titik tunggal, bukan per-fungsi) dan menandai OLT itu
"pending write" di file `pending_writes.json`. Systemd timer
`smartolt-flush-write` (tiap 10 menit, sesuai keputusan user) baca file itu
dan kirim 'write' sekali per OLT yang pending.

**Trade-off yang disetujui user:** kalau OLT kehilangan daya SEBELUM flush
berikutnya (window maks 10 menit), config di window itu hilang -- ONU yang
baru diauth dalam window itu perlu authorize ulang. Config pelanggan yang
sudah lama aktif (sudah ke-flush di siklus sebelumnya) TIDAK terdampak.

**File terkait:** `backend/python_engine/helper.py` (filter+marker),
`backend/python_engine/flush_pending_write.py` (skrip flush baru),
`backend/python_engine/drivers/zte_c300.py` + `zte_c320.py` (hapus retry-write
logic yang sudah usang), `deploy/systemd/smartolt-flush-write.{service,timer}`.

**Verifikasi:** `systemctl list-timers | grep smartolt-flush` aktif, log
`journalctl -u smartolt-flush-write` menunjukkan flush sukses 0.24 detik,
authorize via browser sungguhan terkonfirmasi 5 detik dan sukses tersimpan
ke DB + OLT.

---

## Proposal #5: CSRF Token Verification on All POST Action Handlers

**Status:** MENUNGGU KEPUTUSAN
**Klasifikasi:** WAJIB REVIEW (security, menyentuh semua action handler)
**Diajukan:** 2026-09-17 (auto-analyst cycle)

### Masalah

`header.php` sudah meng-inject CSRF token ke semua `<form>` POST dan semua
`fetch()` POST via JS interceptor (lines 85-110). Namun HANYA 2 dari ~12 POST
action handler yang benar-benar VERIFY token server-side:

**Sudah verify:** `onu-types.php`, `splitters.php`
**TIDAK verify (vulnerable):** `auth-onu.php`, `update-onu-mode.php`,
`onu-state.php`, `delete-onu.php`, `replace-onu.php`, `update-onu-type.php`,
`update-onu-splitter.php`, `sync-olt.php`, `reboot-onu.php`, `restore-factory.php`

Implikasi: tanpa verifikasi server-side, token CSRF yang sudah di-inject client-side
tidak berguna. Attacker bisa membuat halaman HTML yang auto-submit form POST ke
action handler SmartOLT selama user sudah login di browser yang sama.

### Risiko

- Tidak berdampak ke OLT/pelanggan (pure app-side security)
- Perubahan menyentuh banyak file — perlu hati-hati supaya tidak break flow existing

### Proposal Fix

Tambah helper function CSRF verify di `backend/db.php` (atau `backend/api.php`):

```php
function verify_csrf() {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF token tidak valid.']);
        exit;
    }
}
```

Lalu tambah `verify_csrf();` di awal setiap POST handler yang belum punya.
Pattern yang sama persis dengan `onu-types.php` line 15-16.

### Catatan

Perubahan ini TIDAK menyentuh OLT/pelanggan sama sekali, tapi karena menyentuh
banyak action handler sekaligus, perlu testing menyeluruh sebelum deploy.
User bisa putuskan apakah mau dieksekusi otomatis atau perlu staging.

