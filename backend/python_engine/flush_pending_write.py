#!/usr/bin/env python3
"""
Batch-flush 'write' ke OLT yang punya config pending (belum di-simpan ke flash).

Dipanggil systemd timer tiap 10 menit (smartolt-flush-write.timer).
Baca pending_writes.json (ditulis helper.py._mark_pending_write saat
authorize/delete/dll berlangsung), kirim 'write' sekali per OLT, lalu hapus
entry yang berhasil.

ponytail: kalau OLT mati/reboot SEBELUM flush ini jalan, config dalam window
~10 menit terakhir hilang (balik ke config ter-flush terakhir). Trade-off
disetujui user 2026-09-16 demi authorize ONU jauh lebih cepat (~35s -> ~1s).
"""
import sys
import os
import json
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from helper import execute_ssh_commands
from db import get_db_connection, decrypt_password

PENDING_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'pending_writes.json')


def load_pending() -> dict:
    if not os.path.exists(PENDING_FILE):
        return {}
    try:
        with open(PENDING_FILE, 'r') as f:
            return json.load(f)
    except Exception:
        return {}


def save_pending(data: dict):
    with open(PENDING_FILE, 'w') as f:
        json.dump(data, f)


def main():
    pending = load_pending()
    if not pending:
        print("Tidak ada OLT dengan config pending write.")
        return

    conn = get_db_connection()
    try:
        with conn.cursor() as cursor:
            for olt_id_str, marked_at in list(pending.items()):
                olt_id = int(olt_id_str)
                cursor.execute("SELECT * FROM olts WHERE id = %s", (olt_id,))
                olt = cursor.fetchone()
                if not olt:
                    # OLT sudah dihapus dari DB — buang entry pending-nya.
                    del pending[olt_id_str]
                    continue

                olt_type = str(olt.get('type', '')).upper()
                if 'ZTE' not in olt_type and 'C300' not in olt_type and 'C320' not in olt_type:
                    # Skip-write cuma diterapkan untuk driver ZTE saat ini.
                    del pending[olt_id_str]
                    continue

                olt['password'] = decrypt_password(olt.get('password', ''))
                age = time.time() - marked_at
                t0 = time.time()
                log = execute_ssh_commands(olt, ['write'])
                elapsed = time.time() - t0
                ok = 'error' not in log.lower() and 'refused' not in log.lower()
                status = 'OK' if ok else 'GAGAL'
                print(f"OLT id={olt_id} ({olt.get('name')}): write {status} "
                      f"({elapsed:.2f}s, pending sejak {age:.0f}s lalu)")
                if ok:
                    del pending[olt_id_str]
    finally:
        conn.close()

    save_pending(pending)


if __name__ == '__main__':
    main()
