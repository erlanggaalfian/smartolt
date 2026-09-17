<?php
// Self-check: get_olt_supported_panels() dan get_olt_driver_info() harus bekerja
// TANPA memuat berkas driver PHP sama sekali (data statis dari registry).
// Jalankan: php backend/test_registry_meta.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/driver.php';

function ok($cond, $label) {
    if (!$cond) { fwrite(STDERR, "GAGAL: {$label}\n"); exit(1); }
    echo "  ok  {$label}\n";
}

// 1. Panel CDATA lengkap, bentuknya peta (bukan list) supaya isset() bekerja.
$p = get_olt_supported_panels(['type' => 'CDATA FD1602SB1(GPON)']);
ok(isset($p['olt-details']), 'CDATA: panel olt-details ada');
ok(isset($p['interfaces']) && $p['interfaces']['icon'] === 'ethernet-port', 'CDATA: ikon interfaces benar');
ok(count($p) === 4, 'CDATA: 4 panel');

// 2. Alias tipe lama tetap terpetakan.
ok(get_olt_supported_panels(['type' => 'GPON']) === $p, 'alias GPON -> CDATA');

// 3. ZTE tidak punya panel (versi lama balikan list indeks-0 yang selalu gagal isset).
ok(
    array_keys(get_olt_supported_panels(['type' => 'ZTE C320']))
        === ['olt-details', 'olt-cards', 'pon-ports', 'interfaces'],
    'ZTE: 4 panel'
);

// 4. Tipe asing tidak boleh fatal.
ok(get_olt_supported_panels(['type' => 'Merk Antah Berantah']) === [], 'tipe tak dikenal -> []');
ok(get_olt_supported_panels([]) === [], 'tipe kosong -> []');

// 5. Driver info. pon_type WAJIB ada: onu-detail.php:117 memakainya untuk GPON/EPON.
$i = get_olt_driver_info(['type' => 'CDATA FD1602SB1(GPON)']);
ok($i['brand'] === 'CDATA' && $i['model'] === 'FD1602S-B1', 'CDATA: brand/model');
ok($i['pon_type'] === 'gpon', 'CDATA: pon_type');
ok(get_olt_driver_info(['type' => 'ZTE'])['brand'] === 'ZTE', 'alias ZTE -> brand ZTE');

$u = get_olt_driver_info(['type' => 'Merk Antah Berantah']);
ok($u['brand'] === 'Unknown' && $u['model'] === 'Merk Antah Berantah', 'tipe tak dikenal -> Unknown');
ok($u['pon_type'] === 'gpon', 'tipe tak dikenal -> pon_type tetap ada');

// 6. Driver PHP sudah dihapus (#234). Pastikan tidak ada yang menghidupkannya lagi.
ok(!is_dir(__DIR__ . '/drivers'), 'folder backend/drivers sudah tiada');
ok(!function_exists('get_driver_instance'), 'get_driver_instance() sudah tiada');
foreach (get_included_files() as $f) {
    ok(strpos(str_replace('\\', '/', $f), '/drivers/') === false,
       'tanpa memuat driver PHP: ' . basename($f));
}

// 7. Tipe tak dikenal harus gagal rapi, bukan fatal. Tanpa jalur cadangan PHP,
//    call_driver() wajib tetap mengembalikan struktur balikan yang utuh.
$r = call_driver(['type' => 'Merk Antah Berantah'], 'getVlans', [], ['vlans' => []]);
ok($r['success'] === false, 'tipe tak dikenal -> success false');
ok(isset($r['vlans']) && $r['vlans'] === [], 'tipe tak dikenal -> fallback key tetap ada');
ok(strpos($r['message'], 'tidak dikenali') !== false, 'tipe tak dikenal -> pesan jelas');

echo "test_registry_meta.php: semua pemeriksaan LULUS\n";
