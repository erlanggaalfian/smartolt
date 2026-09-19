"""Sinkronisasi SNMP-only untuk OLT ZTE — dipisah dari cron_sync.py (CLI-sync)
supaya tak nunggu telnet OLT selesai. SNMP pakai UDP, independen dari telnet,
jadi jalan di cron entry sendiri dengan lock sendiri (tak overlap CLI-sync)."""
import sys
import os
import datetime

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
import db
import snmp_module

try:
    import fcntl
except ImportError:
    fcntl = None


def acquire_lock():
    if not fcntl:
        return None
    lock_file = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'cron_sync_snmp.lock')
    lock_handle = open(lock_file, 'a')
    try:
        fcntl.flock(lock_handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        return lock_handle
    except IOError:
        print(f"[{datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] WARNING: Proses SNMP-sync lain sedang berjalan. Keluar.")
        sys.exit(0)


def main():
    lock_handle = acquire_lock()
    try:
        conn2 = db.get_db_connection()
        with conn2.cursor() as c2:
            c2.execute("SELECT id, name, ip, snmp_community, snmp_port, type FROM olts")
            all_olts = c2.fetchall()
        conn2.close()

        for olt in all_olts:
            if 'ZTE' not in (olt.get('type') or '').upper():
                continue
            try:
                snmp_onus = snmp_module.get_onu_table(
                    olt['ip'], olt['snmp_community'], olt.get('snmp_port', 161)
                )
                if not snmp_onus:
                    continue
                conn3 = db.get_db_connection()
                db_map = {}
                with conn3.cursor() as c3:
                    c3.execute("SELECT id, pon_port, onu_id, serial_number, name, description, last_down_cause FROM onus WHERE olt_id = %s", (olt['id'],))
                    for r in c3.fetchall():
                        db_map[(r['pon_port'], r['onu_id'])] = r

                updated = 0
                for onu in snmp_onus:
                    pon_port = onu['pon_port'].replace('gpon-olt_', '')
                    key = (pon_port, onu['onu_id'])
                    if key not in db_map:
                        continue
                    db_row = db_map[key]
                    set_parts = []
                    vals = []
                    # phase_state None = walk SNMP timeout/parsial utk ONU ini
                    # (lihat snmp_module.py) -> JANGAN tebak, skip update status
                    # supaya tak salah tulis offline saat fisik online.
                    if onu['phase_state'] is None:
                        new_status = None
                    else:
                        new_status = 'online' if onu['phase_state'] == 3 else ('disabled' if onu['admin_state'] == 2 else 'offline')
                    if onu.get('rx_dbm') and onu['rx_dbm'] != db_row.get('last_rx_power'):
                        set_parts.append('last_rx_power = %s')
                        vals.append(onu['rx_dbm'])
                    if new_status:
                        set_parts.append('status = %s')
                        vals.append(new_status)
                    # Nama & deskripsi dari SNMP (cepat, tak kena timeout CLI seperti
                    # config-sync). Jangan timpa nama asli dengan placeholder ONU_x
                    # kalau SNMP kebetulan kosong/belum-authorize.
                    db_name = db_row.get('name') or ''
                    if onu.get('name') and onu['name'] != db_name and not (onu['name'].startswith('ONU_') and db_name and not db_name.startswith('ONU_')):
                        set_parts.append('name = %s')
                        vals.append(onu['name'])
                    db_desc = db_row.get('description') or ''
                    if onu.get('desc') and onu['desc'] != db_desc:
                        set_parts.append('description = %s')
                        vals.append(onu['desc'])
                    # down_cause=0 ('Normal') / None -> tak sedang down, clear.
                    cause_label = onu.get('down_cause_label')
                    new_cause = cause_label if onu.get('down_cause') else None
                    if new_cause != db_row.get('last_down_cause'):
                        set_parts.append('last_down_cause = %s')
                        vals.append(new_cause)
                    if set_parts:
                        set_parts.append('updated_at = NOW()')
                        vals.append(db_row['id'])
                        sql = f"UPDATE onus SET {', '.join(set_parts)} WHERE id = %s"
                        try:
                            with conn3.cursor() as c3:
                                c3.execute(sql, vals)
                            updated += 1
                        except Exception as row_err:
                            print(f"  ⚠️ SNMP sync: gagal UPDATE onu id={db_row.get('id')}: {row_err}")
                conn3.commit()
                conn3.close()
                print(f"  SNMP fast-sync [{olt['name']}]: {updated}/{len(snmp_onus)} baris diupdate.")
            except Exception as sync_err:
                print(f"  ⚠️ SNMP fast-sync gagal [{olt['name']}]: {sync_err}")
    finally:
        if lock_handle and fcntl:
            fcntl.flock(lock_handle, fcntl.LOCK_UN)
            lock_handle.close()


if __name__ == "__main__":
    main()
