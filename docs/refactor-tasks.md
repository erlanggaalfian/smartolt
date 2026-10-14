# Refactor Task Spec — Design Consistency Fix
## ARAHAN KETAT
- JANGAN ubah warna/palet/skema — hanya STRUKTUR/KONSISTENSI
- Kanon: tombol .btn + modifier, urutan "btn btn-<variant> btn-<size>", aksi tabel btn-xs
- Semua panel detail pakai .content-card
- Semua modal pakai struktur modal kanon (.modal > .modal-content > header/body/footer, toggle .open)
- Filter bar pakai .filter-panel
- Fix duplikat .bg-green/red/orange (pilih SATU makna per konteks)
- Inline style → CSS class
- Hapus dead CSS, class broken → definisikan

## TOKENS YANG HARUS ADA DI :root (tambah setelah --radius-lg)
```css
--radius-xs: 4px;
--radius-pill: 999px;
--header-height: 56px;
--control-height: 40px;
--space-1: 4px; --space-2: 8px; --space-3: 12px; --space-4: 16px; --space-5: 20px; --space-6: 24px; --space-7: 32px;
--fs-xs: 0.75rem; --fs-sm: 0.8125rem; --fs-base: 0.875rem; --fs-lg: 1rem; --fs-xl: 1.125rem; --fs-2xl: 1.5rem;
--icon-sm: 14px; --icon-md: 18px; --icon-lg: 22px;
--modal-sm: 450px; --modal-md: 600px; --modal-lg: 800px;
```

## FIX .bg-green/red/orange CONFLICT
Baris 206-209 (.bg-blue/green/red/orange) untuk .stat-icon (solid fill) — scope jadi `.stat-icon.bg-blue` dst.
Baris 253-257 (.bg-green/red/orange/yellow/gray) untuk .badge (tint+border) — biarkan, ini kanon badge.

## DEAD CSS (25 class — HAPUS)
```
.action-buttons-group  .detail-container  .ont-image-gpon
.badge-pulse  .detail-table  .physical-ont-image-container
.bg-gray  .flex-column-center  .signal-gauge-container
.card-title  .form-control-static  .signal-reading
.chevron-icon  .motion-slide-left  .stat-label
.chip-edit  .notification-badge  .stat-value
.col-12  .ont-image-epon  .tab-active-indicator
.col-6  .collapse-content
.content-area  .detail-card-header
```
HATI-HATI: .bg-yellow, .icon-red, .icon-gray BUKAN dead (dipakai dinamis configured.php)

## DUPLICATE SELECTORS — MERGE
- .stat-card:hover (baris 204 vs 496) — keep translateY(-2px) dari 496, hapus 204
- .data-table tbody tr (246 vs 476) — merge transition
- .data-table tbody tr:hover (248 vs 477) — merge background + transform
- .form-control:focus (274 vs 489) — keep 489 (lebih lengkap), hapus 274
- .badge (252 vs 491) — merge transition ke 252, hapus 491

## REMOVE SELECTOR (berbahaya, override .form-control)
Hapus `.form-group input, .form-group select, .form-group textarea` (baris 427-431) — ini override .form-control dan bikin tinggi field beda.

## CLASS YANG HARUS DIDEFINISIKAN (9 broken + utilities baru)
```css
/* Badge status → chip mapping */
.badge-online { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:20px; font-size:0.78rem; font-weight:600; background:var(--chip-green-bg); color:var(--chip-green-fg); border:1px solid var(--chip-green-bd); }
.badge-offline { /* same pattern, chip-red */ }
.badge-warning { /* same pattern, chip-amber */ }
.badge-blue { /* same pattern, chip-blue */ }
.badge-green { /* same pattern, chip-green */ }
.badge-red { /* same pattern, chip-red */ }

/* Button helpers */
.btn-action { display:inline-flex; align-items:center; justify-content:center; padding:6px 10px; font-size:12px; font-weight:600; border-radius:8px; border:1px solid transparent; cursor:pointer; transition:var(--transition-fast); background:var(--bg-tertiary); border-color:var(--border-color); color:var(--text-main); }
.btn-block { display:block; width:100%; }
.btn-port-configure { display:inline-flex; align-items:center; justify-content:center; padding:4px 10px; font-size:12px; font-weight:600; border-radius:8px; border:1px solid var(--border-color); cursor:pointer; transition:var(--transition-fast); background:var(--bg-tertiary); color:var(--text-main); }
.btn.disabled, .btn:disabled { opacity:0.5; cursor:not-allowed; pointer-events:none; }
.close-btn { background:none; border:none; color:var(--text-muted); font-size:24px; cursor:pointer; } /* alias .modal-close */

/* Utilities */
.empty-state { text-align:center; color:var(--text-muted); padding:24px; font-size:14px; }
.text-muted { color:var(--text-muted); }
.icon-sm svg, .icon-sm i, .icon-sm { width:var(--icon-sm); height:var(--icon-sm); }
.icon-md svg, .icon-md i, .icon-md { width:var(--icon-md); height:var(--icon-md); }
.icon-lg svg, .icon-lg i, .icon-lg { width:var(--icon-lg); height:var(--icon-lg); }
.btn-icon { padding:8px; width:40px; height:40px; }
.btn-icon.btn-xs { padding:6px; width:32px; height:32px; }

/* Spinner small */
.spinner-sm { width:20px; height:20px; border:2px solid var(--border-color); border-top-color:var(--text-accent); border-radius:50%; animation:spin 1s linear infinite; }
.spinner-small { /* alias */ width:20px; height:20px; border:2px solid var(--border-color); border-top-color:var(--text-accent); border-radius:50%; animation:spin 1s linear infinite; }

/* Pagination */
.pagination-bar { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; border-top:1px solid var(--border-color); font-size:13px; color:var(--text-muted); }
.pagination-buttons { display:flex; gap:8px; }

/* Data table compact variant */
.data-table--compact th, .data-table--compact td { padding:6px 10px; font-size:12px; }

/* Modal size variants */
.modal-sm .modal-content { max-width:var(--modal-sm); }
.modal-lg .modal-content { max-width:var(--modal-lg); }

/* btn-sm fix — use token */
.btn-sm { padding:4px 10px; font-size:var(--fs-sm); }
```

## FIX FALLBACK VALUES
Baris 489: `rgba(99, 102, 241, 0.15)` → `var(--text-accent-glow)`

## BORDER-ACCENT HILANG DI 3 HALAMAN
(card-header.border-accent sudah ada di style.css baris 225 — class `.border-accent`)
Yang perlu ditambah di PHP: settings-speed-profiles.php, splitters.php, vpn-tunnels.php