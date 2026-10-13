# AntiSlop Re-Audit: Temuan TERVERIFIKASI

Re-audit dilakukan langsung ke kode sumber (read-only), bukan percaya laporan lama mentah-mentah.

---

## Status Tahap

| Tahap | Status | Detail |
|-------|--------|--------|
| 1 — Security | DONE | Sudah di-commit+push ke main (vpn-tunnels.php, tr069-management.php) |
| 2 — Dead Code | DONE | Commit `feaac73` di branch `antislop-tahap2-5` (belum di-push) |
| 3 — Aksesibilitas | FINDINGS | 10 temuan (1 HIGH, 8 MEDIUM, 1 LOW) |
| 4 — Token/Responsive | FINDINGS | CSRF: 1 HIGH + 1 LOW. Responsive: 4 temuan (1 MED, 3 LOW) |
| 5 — Komentar Basi | FINDINGS | 7 temuan (5 MEDIUM, 2 LOW) |
| CSS/Responsive Scan | FINDINGS | 42 temuan (18 kontras, 11 responsive, 8 form, 5 touch) |
| Bug Fungsional | FINDINGS | 2 HIGH (snmp-sync.php + backup-config.php path salah) |

---

## False Positive Dibuang

### ~~CSRF splitters.php rusak~~ → SALAH

Klaim lama: "splitters.php tidak punya CSRF protection."
Kenyataan:
- **splitters.php baris 11-12** punya server-side CSRF check sendiri
- **header.php baris 85-114** auto-inject CSRF token ke SEMUA `<form>` dan `fetch()` POST secara global
- Middleware `backend/config.php:78` validasi semua POST ke `/action/`
- Tiga lapis proteksi. Tidak ada masalah.

---

## Tahap 2: Dead Code — DONE (Commit feaac73)

4 true positive sudah diperbaiki:
- settings-olt.php: tombol test koneksi dari tabel (40 baris JS orphaned)
- header.php: debug CLI modal tanpa trigger (70 baris orphaned)
- onu-detail.php: sync banner CSS+JS tanpa elemen HTML (22 baris)
- sidebar.php: file deprecated 5 baris, tidak di-include

---

## Tahap 3: Aksesibilitas — 10 Temuan

Detail lengkap: `docs/antislop-tahap3-4-5-recheck.md`

| Severity | Temuan |
|----------|--------|
| HIGH | onu-detail.php: 17 div onclick tanpa role/tabindex/keyboard handler |
| MEDIUM | logs.php: tombol search tanpa aria-label/title |
| MEDIUM | onu-detail.php: 2 toggle eye tanpa aria-label |
| MEDIUM | logs.php: 3 filter tanpa label/aria-label |
| MEDIUM | configured.php: 8 filter tanpa label/aria-label |
| MEDIUM | Semua halaman: nol `<h1>`, heading terbalik di dashboard/vpn |
| MEDIUM | 180 modal tanpa role=dialog/aria-modal/aria-labelledby |
| MEDIUM | configured.php: toast tanpa role=status/aria-live |
| LOW | configured.php: select tanpa label |
| LOW | header.php: modal tanpa role=dialog, tombol close tanpa aria-label |

---

## Tahap 4: CSRF + Responsive

Detail lengkap: `docs/antislop-tahap3-4-5-recheck.md` (CSRF) + `docs/antislop-css-responsive-scan.md` (responsive)

### CSRF

| Severity | Temuan |
|----------|--------|
| HIGH | api-vpn-cert-toggle.php: POST handler tanpa CSRF (tulis .cert-access) |
| LOW | vpn-tunnels.php: token pakai `!==` bukan `hash_equals()` |

### Responsive

| Severity | Temuan |
|----------|--------|
| MEDIUM | logs.php: form filter flex tanpa wrap, overflow di <500px |
| MEDIUM | logs.php: class form-input tidak terdefinisi (harus form-control) |
| MEDIUM | 2 stylesheet berbeda (public/style.css vs style.css), orphan 562 baris |
| LOW | CSS sidebar mati di media query |
| LOW | Breakpoint <600px tidak ada penyesuaian padding |

---

## Tahap 5: Komentar Basi / Kode Mati — 7 Temuan

Detail lengkap: `docs/antislop-tahap3-4-5-recheck.md`

| Severity | Temuan |
|----------|--------|
| MEDIUM | CSS "2-Column Card Layout" self-declared unused (nol referensi) |
| MEDIUM | CSS sidebar deprecated + overlay/dropdown (nol referensi) |
| MEDIUM | action/get-debug-cli.php orphan (29 baris, akibat feaac73) |
| MEDIUM | action/test-olt.php orphan (35 baris, akibat feaac73) |
| MEDIUM | backend/db.php.bak ter-commit (data pelanggan + beda dari db.php) |
| LOW | vpn-tunnels.php: komentar HTML menandai field yang dihapus |
| LOW | index.php + settings-olt.php: inkonsistensi komentar # vs // |

---

## CSS/Responsive/Contrast Scan — 42 Temuan

Detail lengkap: `docs/antislop-css-responsive-scan.md`

### Top 5 Prioritas

1. **CONTRAST-08**: settings-users.php:121,152 — background #f8fafc hardcoded di dark mode → teks hilang (1.05:1). Fix: 2 baris.
2. **R-06 + R-07**: 6 CSS var dipakai tapi tidak didefinisikan (--bg-main, --text-primary, --text-color, --red, --text-secondary, --primary-color). Form-group label duplikat. Warna input di seluruh app broken.
3. **R-01/R-02/R-03**: 20 tabel tanpa .table-responsive wrapper (onu-detail, olt-detail, onu-types). Data terpotong permanen di mobile.
4. **CONTRAST-07**: Topnav light mode bar #3081d1 terlalu terang untuk teks putih (2.99-4.05:1).
5. **F-01/F-02**: splitters.php (23 input) dan onu-types.php (23 input) — nol label association.

---

## Bug Fungsional (di luar cakupan 3/4/5)

| Severity | Temuan |
|----------|--------|
| HIGH | snmp-sync.php: require path salah (/../ bukan /../../), fitur mati total |
| HIGH | backup-config.php: require path sama, fitur mati total |

---

## Verifikasi Regresi feaac73

Tidak ada regresi aksesibilitas, responsive, atau CSRF dari commit fixer.
Dua efek samping = kode mati baru (2 endpoint orphan: get-debug-cli.php, test-olt.php), bukan kerusakan.

---

## Ringkasan Akhir

| Kategori | Temuan | HIGH | MED | LOW |
|----------|--------|------|-----|-----|
| Tahap 2 (Dead Code) | 4 (FIXED) | 0 | 0 | 0 |
| Tahap 3 (Aksesibilitas) | 10 | 1 | 8 | 1 |
| Tahap 4 (CSRF) | 2 | 1 | 0 | 1 |
| Tahap 4 (Responsive) | 5 | 0 | 2 | 3 |
| Tahap 5 (Komentar) | 7 | 0 | 5 | 2 |
| CSS/Contrast scan | 42 | 7 | 9 | 2 |
| Bug fungsional | 2 | 2 | 0 | 0 |
| **Total** | **72** | **11** | **24** | **9** |

Note: banyak temuan overlap antar kategori (misal responsive di tahap 4 dan scan CSS),
angka unik diperlukan dedup.