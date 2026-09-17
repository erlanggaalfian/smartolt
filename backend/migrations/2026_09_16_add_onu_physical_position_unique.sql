-- Fix duplikasi baris ONU: serial_number UNIQUE KEY saja tidak cukup untuk
-- mencegah duplikat posisi fisik ONU (olt_id+pon_port+onu_id). Saat SN
-- sebuah ONU berubah (mis. dari UNKNOWN ke SN asli), sync sebelumnya bikin
-- baris baru alih-alih update baris lama di posisi yang sama -> data
-- pelanggan (PPPoE, alamat, zone) bisa kepisah jadi 2 baris berbeda.
--
-- Ditemukan & di-fix: 2026-09-16.

ALTER TABLE onus ADD UNIQUE KEY idx_physical_position (olt_id, pon_port, onu_id);
