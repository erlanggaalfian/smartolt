<?php
/**
 * Self-check: check_olt_connection() harus menentukan status SNMP dari uji
 * SNMP sungguhan, bukan dari mencocokkan string pesan driver.
 *
 * Regresi #238: driver PHP lama memanggil check_snmp_ping() di dalam
 * checkConnection(). Saat porting ke Python, uji SNMP itu hilang; driver
 * Python hanya menulis "SNMP Status: Terhubung via SSH/Telnet". Sementara
 * get-olt-health.php hanya menerima teks "SNMP Status: Koneksi Berhasil"
 * (yang cuma ada di cabang demo), sehingga SNMP selalu tampil Timeout / Error.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/driver.php';

$fail = 0;
function cek(string $label, bool $ok): void {
    global $fail;
    if (!$ok) { $fail++; echo "GAGAL: $label\n"; }
}

// 1. check_snmp_ping() harus ada sebelum dipakai check_olt_connection()
cek('check_snmp_ping() terdefinisi', function_exists('check_snmp_ping'));

$src = file_get_contents(__DIR__ . '/driver.php');

// 2. check_olt_connection() wajib memanggil uji SNMP nyata
$body = '';
if (preg_match('/function check_olt_connection\(.*?\n\}/s', $src, $m)) {
    $body = $m[0];
}
cek('check_olt_connection() ditemukan', $body !== '');
cek('check_olt_connection() memanggil check_snmp_ping()',
    strpos($body, 'check_snmp_ping(') !== false);

// 3. Klaim SNMP dari driver harus dibuang, bukan diteruskan apa adanya
cek('pesan SNMP driver dibuang', strpos($body, 'SNMP Status') !== false
    && strpos($body, 'preg_replace') !== false);

// 4. Teks hasil harus cocok dengan pola yang dibaca get-olt-health.php
$health = file_get_contents(__DIR__ . '/../frontend/action/get-olt-health.php');
cek('get-olt-health.php masih mencari "SNMP Status: Koneksi Berhasil"',
    strpos($health, 'SNMP Status: Koneksi Berhasil') !== false);
cek('driver.php menghasilkan teks yang sama',
    strpos($body, 'SNMP Status: Koneksi Berhasil!') !== false);

// 5. Regex pembuang harus benar-benar menghapus klaim driver Python,
//    dan tidak ikut menghapus baris CPU/RAM/Uptime.
$pesan_driver = "Koneksi SSH berhasil!\n\n"
    . "--- Info Kesehatan OLT ZTE ZXA10 C320 ---\n"
    . "• CPU Load: 45%\n"
    . "• RAM Usage: 31%\n"
    . "• Uptime: 49 days, 8 hours, 26 minutes\n"
    . "• SNMP Status: Terhubung via SSH/Telnet";
$bersih = rtrim(preg_replace('/^.*SNMP Status\s*:.*$/mu', '', $pesan_driver));
cek('klaim SNMP driver terhapus', strpos($bersih, 'Terhubung via SSH/Telnet') === false);
cek('CPU tetap utuh', strpos($bersih, '• CPU Load: 45%') !== false);
cek('RAM tetap utuh', strpos($bersih, '• RAM Usage: 31%') !== false);
cek('Uptime tetap utuh', strpos($bersih, '• Uptime: 49 days, 8 hours, 26 minutes') !== false);

// 6. Setelah ditambah hasil uji, regex get-olt-health.php harus cocok
$final_ok = $bersih . "\n\n• SNMP Status: Koneksi Berhasil!";
cek('status Connected terbaca', strpos($final_ok, 'SNMP Timeout') === false
    && strpos($final_ok, 'SNMP Status: Koneksi Berhasil') !== false);

$final_bad = $bersih . "\n\n⚠️ Info: SNMP Timeout. Periksa Community SNMP OLT atau pastikan service snmp enable.";
cek('status Timeout terbaca', strpos($final_bad, 'SNMP Timeout') !== false);

// 7. Parser CPU/RAM di get-olt-health.php tetap dapat nilainya
preg_match('/CPU Load\s*:\s*([^\n\r]+)/i', $final_ok, $mc);
cek('parser CPU dapat 45%', isset($mc[1]) && trim($mc[1]) === '45%');
preg_match('/RAM Usage\s*:\s*([^\n\r]+)/i', $final_ok, $mr);
cek('parser RAM dapat 31%', isset($mr[1]) && trim($mr[1]) === '31%');

// 8. Kegagalan driver tidak boleh dilanjutkan ke uji SNMP
cek('kegagalan driver dikembalikan lebih awal',
    strpos($body, "empty(\$res['success'])") !== false);

// 9. Tidak ada driver yang boleh mengklaim status SNMP sendiri.
//    Klaim palsu inilah yang bikin regresi #238 lolos tanpa error.
foreach (glob(__DIR__ . '/python_engine/drivers/*.py') as $drv) {
    $isi = file_get_contents($drv);
    cek(basename($drv) . ' tidak mengklaim status SNMP',
        strpos($isi, 'SNMP Status') === false);
}

echo $fail === 0
    ? "test_snmp_status.php: semua pemeriksaan LULUS\n"
    : "test_snmp_status.php: $fail pemeriksaan GAGAL\n";
exit($fail === 0 ? 0 : 1);
