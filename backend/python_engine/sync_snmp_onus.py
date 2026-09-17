#!/usr/bin/env python3
"""
sync_snmp_onus.py — Bulk sync ONU data via SNMP (fast, no CLI).
Updates: status, rx_power, sn, name, type, phase_state for all ONUs of an OLT.

Usage: python3 sync_snmp_onus.py <olt_id>
       python3 sync_snmp_onus.py all
"""
import sys
import os
import time
import logging

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
import db
import snmp_module as snmp

logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
log = logging.getLogger(__name__)


def sync_olt(olt: dict):
    """Sync all ONUs for one OLT via SNMP."""
    ip = olt['ip']
    community = olt['snmp_community']
    rw_community = olt['snmp_community_rw'] or community
    snmp_port = int(olt.get('snmp_port') or 161)
    olt_id = olt['id']
    
    log.info(f"SNMP walk ONU table: {olt['name']} ({ip})")
    start = time.time()
    
    snmp_onus = snmp.get_onu_table(ip, community, snmp_port)
    
    if not snmp_onus:
        log.warning(f"No ONUs found via SNMP for {olt['name']}")
        return
    
    elapsed = time.time() - start
    log.info(f"  Found {len(snmp_onus)} ONUs in {elapsed:.1f}s")
    
    conn = db.get_db_connection()
    
    # Get existing ONUs for this OLT
    with conn.cursor() as c:
        c.execute("SELECT id, pon_port, onu_id, serial_number, name FROM onus WHERE olt_id = %s", (olt_id,))
        db_onus = {}
        for row in c.fetchall():
            key = f"{row['pon_port']}:{row['onu_id']}"
            db_onus[key] = row
    
    updated = 0
    matched = 0
    
    for onu in snmp_onus:
        # DB stores pon_port as "1/2/1" (without gpon-olt_ prefix)
        pon_port = onu['pon_port'].replace('gpon-olt_', '')
        onu_id = onu['onu_id']
        key = f"{pon_port}:{onu_id}"
        
        if key not in db_onus:
            continue  # ONU not in DB (unconfigured or different OLT)
        
        db_onu = db_onus[key]
        matched += 1
        
        # Determine status from phase_state
        if onu['phase_state'] == 3:  # Working
            status = 'online'
        elif onu['admin_state'] == 2:  # Disabled
            status = 'disabled'
        else:
            status = 'offline'
        
        # Update fields
        updates = {
            'status': status,
            'last_rx_power': onu['rx_dbm'],
            'updated_at': None,  # will use NOW()
        }
        
        # Also update SN/name/type if changed
        if onu['sn'] and onu['sn'] != db_onu['serial_number']:
            updates['serial_number'] = onu['sn']
        if onu['name'] and onu['name'] != db_onu.get('name', ''):
            updates['name'] = onu['name']
        if onu['type']:
            updates['onu_type'] = onu['type']
        if onu.get('desc') and onu['desc'] != db_onu.get('description', ''):
            updates['description'] = onu['desc']
            # Parse structured description (zone_..._descr_..._odb_..._authd_...)
            # so Zone/Splitter/Address stay in sync via SNMP too, not just telnet.
            parsed = db.parse_structured_description(onu['desc'])
            if parsed['zone'] and parsed['zone'] != 'None':
                updates['zone'] = parsed['zone']
            if parsed['splitter'] and parsed['splitter'] != 'None':
                updates['splitter'] = parsed['splitter']
            if parsed['address'] and parsed['address'] != 'None':
                updates['address'] = parsed['address']
        # Last down cause (only for offline/dying_gasp)
        if onu.get('down_cause') and onu['down_cause'] != 0:
            updates['last_down_cause'] = onu['down_cause_label']
        elif onu['phase_state'] == 3:  # Working — clear cause
            updates['last_down_cause'] = None
        
        # Build UPDATE query
        set_clauses = []
        values = []
        for field, val in updates.items():
            if val is None and field == 'updated_at':
                set_clauses.append("updated_at = NOW()")
            elif val is not None:
                set_clauses.append(f"{field} = %s")
                values.append(val)
        
        if set_clauses:
            values.append(db_onu['id'])
            sql = f"UPDATE onus SET {', '.join(set_clauses)} WHERE id = %s"
            try:
                with conn.cursor() as c:
                    c.execute(sql, values)
                updated += 1
            except Exception as e:
                if 'Duplicate' in str(e):
                    log.warning(f"  Skip SN conflict: {row['serial_number']} at {pon_port}:{onu_id}")
                else:
                    raise
    
    conn.commit()
    conn.close()
    
    log.info(f"  Matched: {matched}, Updated: {updated}")


def main():
    if len(sys.argv) < 2:
        print("Usage: python3 sync_snmp_onus.py <olt_id|all>")
        sys.exit(1)
    
    target = sys.argv[1]
    conn = db.get_db_connection()
    
    if target == 'all':
        with conn.cursor() as c:
            c.execute("SELECT * FROM olts WHERE snmp_community != ''")
            olts = c.fetchall()
    else:
        with conn.cursor() as c:
            c.execute("SELECT * FROM olts WHERE id = %s", (int(target),))
            olts = c.fetchall()
    
    conn.close()
    
    if not olts:
        log.error("No OLTs found")
        sys.exit(1)
    
    for olt in olts:
        try:
            sync_olt(olt)
        except Exception as e:
            log.error(f"Failed to sync {olt['name']}: {e}")
    
    log.info("Done.")


if __name__ == '__main__':
    main()
