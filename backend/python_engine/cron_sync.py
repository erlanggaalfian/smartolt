import sys
import os
import time
import datetime
import re

# Add current directory to path to import db and drivers
sys.path.append(os.path.dirname(os.path.abspath(__file__)))
import db
from registry import get_driver

# Linux exclusive lock check fallback for Windows compilation safety
try:
    import fcntl
except ImportError:
    fcntl = None

def acquire_lock():
    if not fcntl:
        return None
    lock_file = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'cron_sync.lock')
    try:
        lock_handle = open(lock_file, 'a')
    except Exception as e:
        print(f"ERROR: Gagal buka lock file {lock_file}: {e}")
        sys.exit(1)
        
    try:
        fcntl.flock(lock_handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        return lock_handle
    except IOError:
        print(f"[{datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] WARNING: Proses sinkronisasi lain sedang berjalan. Keluar.")
        sys.exit(0)

def main():
    lock_handle = acquire_lock()
    sync_start_time = datetime.datetime.now()
    print(f"[{sync_start_time.strftime('%Y-%m-%d %H:%M:%S')}] Starting OLT background synchronization...")
    
    conn = None
    try:
        conn = db.get_db_connection()
        
        # 1. Select all registered OLTs
        with conn.cursor() as cursor:
            cursor.execute("SELECT * FROM olts ORDER BY id ASC")
            olts = cursor.fetchall()
            
        if not olts:
            print("No OLTs registered in database.")
            sys.exit(0)
            
        # 2. Check full config sync interval (5 minutes)
        cache_file = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'last_config_sync.txt')
        should_sync_config = False
        now_ts = int(time.time())
        
        if '--force-config' in sys.argv or '--force' in sys.argv:
            should_sync_config = True
            last_sync_time = 0
            print(f"[{datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] Info: Dijalankan dengan argumen --force-config. Sinkronisasi penuh dipaksa.")
        elif not os.path.exists(cache_file):
            should_sync_config = True
            last_sync_time = 0
        else:
            try:
                with open(cache_file, 'r') as f:
                    last_sync_time = int(f.read().strip())
                if now_ts - last_sync_time >= 300:
                    should_sync_config = True
            except Exception:
                should_sync_config = True
                last_sync_time = 0
                
        if should_sync_config:
            print(f"[{datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] Info: Siklus sinkronisasi konfigurasi penuh (VLAN & PPPoE) aktif pada putaran ini.")
        else:
            print(f"[{datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] Info: Melewati sinkronisasi konfigurasi penuh (dijalankan kembali dalam {300 - (now_ts - last_sync_time)} detik).")

        # 3. Process each OLT
        for olt in olts:
            print(f"Processing OLT: {olt['name']} ({olt['ip']})...")
            
            # Decrypt password
            olt_decrypted = olt.copy()
            olt_decrypted['password'] = db.decrypt_password(olt['password'])
            
            # Resolve class name for driver
            with conn.cursor() as cursor:
                # Add driver_class directly to olt dict
                # PHP maps to: find_olt_driver_meta
                # Since Python app.py's get_driver has the same check, we can map it via get_driver
                driver = get_driver(olt_decrypted)
                
            if not driver:
                print(f"  ⚠️ Driver untuk tipe '{olt['type']}' tidak ditemukan.")
                continue
                
            # Fetch config map from device
            ipconfig_map = {}
            name_map = {}
            desc_map = {}
            
            is_demo = olt['ip'] == '127.0.0.1' or olt['ip'].lower() == 'demo'
            
            if should_sync_config and not is_demo:
                print("  Fetching current ONT configurations from device...")
                try:
                    cfg = driver.get_onu_config_maps(olt_decrypted)
                    if cfg.get('success'):
                        ipconfig_map = cfg.get('ipconfig_map', {})
                        name_map = cfg.get('name_map', {})
                        desc_map = cfg.get('desc_map', {})
                    else:
                        print(f"  ⚠️ {cfg.get('message')}")
                except Exception as ex:
                    print(f"  ⚠️ Exception fetching config: {str(ex)}")
                print(f"  Parsed {len(ipconfig_map)} IP configurations.")
                
            # Pull configured list
            print("  Pulling configured ONUs...")
            try:
                pulled = driver.pull_configured_onus(olt_decrypted)
                if not pulled.get('success') or 'onus' not in pulled:
                    print(f"  ⚠️ Gagal pull ONU: {pulled.get('message')}")
                    continue
                pulled_onus = pulled['onus']
            except Exception as ex:
                print(f"  ⚠️ Exception pulling ONUs: {str(ex)}")
                continue
                
            print(f"  Found {len(pulled_onus)} configured ONUs.")

            # Safety: if pull returns 0 ONUs but DB already has rows for this OLT,
            # skip this OLT entirely to prevent mass-delete from a transient connection failure.
            # ponynail: no auto-recovery if OLT genuinely removes all ONUs — next successful
            # pull with non-zero count will resume normal stale cleanup.
            skip_stale_cleanup = False
            if len(pulled_onus) == 0:
                with conn.cursor() as cnt_cur:
                    cnt_cur.execute("SELECT COUNT(*) as c FROM onus WHERE olt_id = %s", (olt['id'],))
                    db_count = cnt_cur.fetchone()['c']
                if db_count > 0:
                    print(f"  ⚠️ Pull returned 0 ONUs but DB has {db_count} for this OLT — skipping stale cleanup to prevent data loss.")
                    skip_stale_cleanup = True

            local_onus_map = []
            active_onu_keys = []

            # Save/update database
            with conn.cursor() as cursor:
                for onu in pulled_onus:
                    key = f"{onu['pon_port']}_{onu['onu_id']}"
                    active_onu_keys.append(key)
                    
                    vlan_val = ipconfig_map.get(key, {}).get('vlan')
                    user_val = ipconfig_map.get(key, {}).get('pppoe_username')
                    pass_val = ipconfig_map.get(key, {}).get('pppoe_password')
                    mode_val = ipconfig_map.get(key, {}).get('wan_mode')
                    
                    name_val = onu['name']
                    desc_val = onu['name']
                    
                    if key in name_map:
                        name_val = name_map[key]
                    if key in desc_map:
                        desc_val = desc_map[key]
                        
                    # Extract name from structured legacy description if needed
                    if 'zone_' in name_val.lower():
                        desc_val = name_val
                        name_val = db.extract_customer_name(name_val)
                        
                    parsed = db.parse_structured_description(desc_val)
                    zone_val = parsed['zone']
                    splitter_val = parsed['splitter']
                    address_val = parsed['address']
                    contact_val = parsed['contact']
                    
                    # Upsert query
                    # PENTING: kolom WAN (vlan, pppoe_*, wan_mode) HANYA di-overwrite kalau
                    # row belum diubah manual SEJAK sync ini mulai (onus.updated_at < sync_start_time).
                    # Snapshot config OLT (ipconfig_map) diambil di AWAL proses -- kalau OLT punya
                    # ratusan ONU, fetch bisa makan waktu lama. Kalau user ubah WAN config manual
                    # DI TENGAH proses itu, snapshot lama jangan sampai menimpa balik perubahan baru.
                    sql_insert = """
                        INSERT INTO onus (
                            olt_id, pon_port, onu_id, name, serial_number, status, vlan, pppoe_username, pppoe_password, wan_mode,
                            zone, splitter, address, contact, last_down_cause, updated_at
                        )
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, CURRENT_TIMESTAMP)
                        ON DUPLICATE KEY UPDATE 
                            name = CASE WHEN VALUES(name) LIKE 'ONU_%%' AND onus.name IS NOT NULL AND onus.name != '' THEN onus.name ELSE VALUES(name) END,
                            serial_number = CASE WHEN VALUES(serial_number) != 'UNKNOWN' AND VALUES(serial_number) != '' THEN VALUES(serial_number) ELSE onus.serial_number END,
                            status = VALUES(status),
                            vlan = CASE WHEN onus.updated_at < %s THEN COALESCE(VALUES(vlan), onus.vlan) ELSE onus.vlan END,
                            pppoe_username = CASE WHEN onus.updated_at < %s THEN COALESCE(VALUES(pppoe_username), onus.pppoe_username) ELSE onus.pppoe_username END,
                            pppoe_password = CASE WHEN onus.updated_at < %s THEN COALESCE(VALUES(pppoe_password), onus.pppoe_password) ELSE onus.pppoe_password END,
                            wan_mode = CASE WHEN onus.updated_at < %s THEN COALESCE(VALUES(wan_mode), onus.wan_mode) ELSE onus.wan_mode END,
                            zone = COALESCE(VALUES(zone), onus.zone),
                            splitter = COALESCE(VALUES(splitter), onus.splitter),
                            address = COALESCE(VALUES(address), onus.address),
                            contact = COALESCE(VALUES(contact), onus.contact),
                            last_down_cause = CASE WHEN %s THEN VALUES(last_down_cause) ELSE onus.last_down_cause END,
                            updated_at = CURRENT_TIMESTAMP
                    """
                    has_down_cause = 'down_cause' in onu  # True hanya utk driver yg dukung (Cdata); ZTE isi via SNMP-sync terpisah di bawah, jangan ditimpa NULL di sini.
                    cursor.execute(sql_insert, (
                        olt['id'], onu['pon_port'], onu['onu_id'], name_val, onu['serial_number'], onu['status'],
                        vlan_val, user_val, pass_val, mode_val, zone_val, splitter_val, address_val, contact_val,
                        onu.get('down_cause'),
                        sync_start_time, sync_start_time, sync_start_time, sync_start_time, has_down_cause
                    ))

                # Single bulk SELECT (replaces per-row SELECT + stale cleanup SELECT)
                cursor.execute("SELECT id, pon_port, onu_id, serial_number, status FROM onus WHERE olt_id = %s", (olt['id'],))
                all_local_onus = cursor.fetchall()
                local_key_set = set(active_onu_keys)

                for db_onu in all_local_onus:
                    if f"{db_onu['pon_port']}_{db_onu['onu_id']}" in local_key_set:
                        local_onus_map.append({
                            'id': db_onu['id'],
                            'pon_port': db_onu['pon_port'],
                            'onu_id': db_onu['onu_id'],
                            'serial_number': db_onu['serial_number'],
                            'status': db_onu['status']
                        })

                # Stale cleanup (skipped if pull returned 0 and DB has rows — safety check above)
                if skip_stale_cleanup:
                    print(f"  Skipping stale cleanup (safety: pull=0, DB has rows).")
                else:
                    for local_onu in all_local_onus:
                        if f"{local_onu['pon_port']}_{local_onu['onu_id']}" not in local_key_set:
                            print(f"  Cleaning up stale ONU ID {local_onu['onu_id']} (Port {local_onu['pon_port']}, SN {local_onu['serial_number']}) from DB.")
                            cursor.execute("DELETE FROM onus WHERE id = %s", (local_onu['id'],))

                conn.commit()
                    
            # 4. Fetch optical signals in bulk or sequentially
            print(f"  Fetching signals in bulk for registered ONUs...")
            bulk_signals = {}
            try:
                bulk_signals = driver.get_onu_signals_bulk(olt_decrypted, local_onus_map)
                print(f"    Successfully fetched bulk signals for {len(bulk_signals)} ONUs.")
            except Exception as ex:
                print(f"    Bulk signal fetch failed or not supported, falling back to sequential: {str(ex)}")

            # Prepare batch update data
            update_params = []
            for onu in local_onus_map:
                key = f"{onu['pon_port']}_{onu['onu_id']}"
                sig = bulk_signals.get(key)
                # ponytail: skip per-ONU fallback (too slow for 1000+ ONUs).
                # Signal reads are on-demand via ONU detail page.
                # Add when: bulk signal works reliably for all OLT types.
                if sig:
                    rx_onu = float(sig['rx_onu']) if sig['rx_onu'] != 'N/A' else None
                    rx_olt = float(sig['rx_olt']) if sig['rx_olt'] != 'N/A' else None
                    status = sig['status']
                    update_params.append((status, rx_onu, rx_olt, onu['id']))
                    onu['_fetched_signal'] = {'rx_onu': rx_onu, 'rx_olt': rx_olt, 'status': status}
                else:
                    onu['_fetched_signal'] = None

            # Execute batch update for all ONUs that have signal data
            if update_params:
                with conn.cursor() as cursor:
                    cursor.executemany(
                        "UPDATE onus SET status=%s, last_rx_power=%s, last_rx_olt_power=%s, updated_at=CURRENT_TIMESTAMP WHERE id=%s",
                        update_params,
                    )
                    conn.commit()
                print(f"    Batch updated {len(update_params)} ONUs with new signal data.")

            # Fallback: individual fetch for online ONUs that bulk missed
            missed_onus = [o for o in local_onus_map if o.get('status') == 'online' and not o.get('_fetched_signal')]
            if missed_onus:
                print(f"    {len(missed_onus)} online ONUs missed by bulk, fetching individually...")
                # Batch commits for missed ONUs instead of per-row commit
                missed_updates = []
                for onu in missed_onus:
                    try:
                        sig = driver.get_onu_signal(olt_decrypted, onu, include_ip=False)
                        if sig.get('success'):
                            rx_onu = sig.get('rx_onu') if isinstance(sig.get('rx_onu'), (int, float)) else None
                            rx_olt = sig.get('rx_olt') if isinstance(sig.get('rx_olt'), (int, float)) else None
                            if rx_onu is not None or rx_olt is not None:
                                missed_updates.append((rx_onu, rx_olt, onu['id']))
                                onu['_fetched_signal'] = {'rx_onu': rx_onu, 'rx_olt': rx_olt, 'status': 'online'}
                                print(f"      {onu['name']}: Rx={rx_onu}, RxOLT={rx_olt}")
                    except Exception as ex:
                        print(f"      {onu['name']}: failed - {str(ex)[:80]}")
                if missed_updates:
                    with conn.cursor() as cursor:
                        cursor.executemany(
                            "UPDATE onus SET last_rx_power=%s, last_rx_olt_power=%s, updated_at=CURRENT_TIMESTAMP WHERE id=%s",
                            missed_updates,
                        )
                        conn.commit()
                    print(f"    Batch updated {len(missed_updates)} individually-fetched ONUs.")

            # Process history — only from bulk signal data (no per-ONU SSH)
            # Batch-fetch last history timestamps to avoid per-ONU SELECT (N+1 fix)
            onu_ids_with_signal = [o['id'] for o in local_onus_map if o.get('_fetched_signal')]
            last_hist_map = {}
            if onu_ids_with_signal:
                with conn.cursor() as cursor:
                    placeholders = ','.join(['%s'] * len(onu_ids_with_signal))
                    cursor.execute(
                        f"SELECT onu_id, MAX(timestamp) as last_ts FROM onu_history WHERE onu_id IN ({placeholders}) GROUP BY onu_id",
                        onu_ids_with_signal
                    )
                    for row in cursor.fetchall():
                        last_hist_map[row['onu_id']] = int(time.mktime(row['last_ts'].timetuple()))

            history_inserts = []
            for onu in local_onus_map:
                sig = onu.get('_fetched_signal')
                if not sig:
                    continue
                # Check history interval (5 minutes)
                should_insert_history = False
                last_ts = last_hist_map.get(onu['id'])
                if last_ts is None:
                    should_insert_history = True
                elif now_ts - last_ts >= 290:
                    should_insert_history = True
                if should_insert_history and sig['status'] == 'online':
                    history_inserts.append((onu['id'], sig['rx_onu'], sig.get('rx_olt')))

            # Batch insert history records
            if history_inserts:
                with conn.cursor() as cursor:
                    cursor.executemany(
                        "INSERT INTO onu_history (onu_id, rx_power, rx_olt_power, timestamp) VALUES (%s, %s, %s, CURRENT_TIMESTAMP)",
                        history_inserts
                    )
                    conn.commit()
                print(f"    Batch inserted {len(history_inserts)} history records.")
            # End of bulk signal handling block

        # Write config sync success timestamp
        if should_sync_config:
            with open(cache_file, 'w') as f:
                f.write(str(now_ts))
                
        # 5. History Pruning & Downsampling (data cleanup)
        with conn.cursor() as cursor:
            # Delete data older than 7 days
            print("Performing history cleanup (deleting records older than 7 days)...")
            cursor.execute("DELETE FROM onu_history WHERE timestamp < DATE_SUB(NOW(), INTERVAL 7 DAY)")
            conn.commit()
            
            # Downsampling older than 1 hour
            print("Performing history downsampling (keeping 1 record per hour for data older than 1 hour)...")
            cursor.execute("""
                DELETE h FROM onu_history h
                WHERE h.timestamp < DATE_SUB(NOW(), INTERVAL 1 HOUR)
                  AND h.id NOT IN (
                      SELECT min_id FROM (
                          SELECT MIN(id) as min_id
                          FROM onu_history
                          WHERE timestamp < DATE_SUB(NOW(), INTERVAL 1 HOUR)
                          GROUP BY onu_id, DATE_FORMAT(timestamp, '%%Y-%%m-%%d %%H')
                      ) as tmp
                  )
            """)
            conn.commit()
            print("Cleanup and downsampling completed.")
            
    except Exception as e:
        print(f"CRITICAL ERROR: {str(e)}")
        sys.exit(1)
    finally:
        if conn:
            conn.close()
        # Lock dilepas di akhir SEMUA proses (setelah SNMP block di bawah),
        # bukan di sini -- supaya CLI-sync + SNMP-sync satu siklus atomik.
            
    print(f"[{datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] OLT background synchronization finished.")
    return lock_handle

if __name__ == "__main__":
    _main_lock = main()

# --- SNMP bulk sync (fast, runs after CLI sync) ---
# Reuse lock yg sama dari main() (_main_lock) -- satu siklus penuh atomik,
# supaya CLI-sync + SNMP-sync antar instance cron tak overlap sama sekali.
try:
    import snmp_module
    conn2 = db.get_db_connection()
    with conn2.cursor() as c2:
        c2.execute("SELECT id, name, ip, snmp_community, snmp_port, type FROM olts")
        all_olts = c2.fetchall()
    conn2.close()

    for olt in all_olts:
        if 'ZTE' in (olt.get('type') or '').upper():
            try:
                snmp_onus = snmp_module.get_onu_table(
                    olt['ip'], olt['snmp_community'], olt.get('snmp_port', 161)
                )
                if snmp_onus:
                    conn3 = db.get_db_connection()
                    db_map = {}
                    with conn3.cursor() as c3:
                        c3.execute("SELECT id, pon_port, onu_id, serial_number, name, description FROM onus WHERE olt_id = %s", (olt['id'],))
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
                    print(f"  SNMP fast-sync: {updated}/{len(snmp_onus)} baris diupdate.")
            except Exception as sync_err:
                print(f"  ⚠️ SNMP fast-sync gagal: {sync_err}")
except ImportError:
    pass
finally:
    if _main_lock and fcntl:
        fcntl.flock(_main_lock, fcntl.LOCK_UN)
        _main_lock.close()
