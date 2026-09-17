import sys
import os
import re

# Add current directory to path to import db and drivers
sys.path.append(os.path.dirname(os.path.abspath(__file__)))
import db
from registry import get_driver

def val(v):
    if v is None or v == 'N/A' or v == '' or str(v).lower() == 'none':
        return None
    return v

def main():
    if len(sys.argv) < 2:
        print("Usage: python3 sync_one_onu.py <onu_id>")
        sys.exit(1)
        
    try:
        onu_id = int(sys.argv[1])
    except ValueError:
        print("Invalid ONU ID.")
        sys.exit(1)
        
    if onu_id <= 0:
        print("Invalid ONU ID.")
        sys.exit(1)
        
    conn = None
    try:
        conn = db.get_db_connection()
        
        # 1. Fetch ONU info
        with conn.cursor() as cursor:
            cursor.execute("SELECT * FROM onus WHERE id = %s", (onu_id,))
            onu = cursor.fetchone()
            
        if not onu:
            print("ONU not found.")
            sys.exit(1)
            
        # 2. Fetch OLT info
        with conn.cursor() as cursor:
            cursor.execute("SELECT * FROM olts WHERE id = %s", (onu['olt_id'],))
            olt = cursor.fetchone()
            
        if not olt:
            print("OLT not found.")
            sys.exit(1)
            
        # Decrypt password
        olt_decrypted = olt.copy()
        olt_decrypted['password'] = db.decrypt_password(olt['password'])
        
        # 3. Resolve driver and run sync config
        driver = get_driver(olt_decrypted)
        if not driver:
            print(f"Driver for OLT type '{olt['type']}' not found.")
            sys.exit(1)
            
        d = driver.sync_onu_config(olt_decrypted, onu)
        if not d.get('success'):
            print(f"Sync config failed: {d.get('message', 'Unknown error')}")
            sys.exit(1)
            
        # 4. Clean name
        clean_name = val(d.get('onu_name'))
        if clean_name:
            clean_name = re.sub(r'^(?:zone_|name_)[^_]+_descr_', '', clean_name, flags=re.I)
            clean_name = re.sub(r'_odb_.*$', '', clean_name, flags=re.I)
            clean_name = clean_name.replace('_', ' ').strip()
            
        # 5. Parse description
        zone_val = None
        splitter_val = None
        address_val = None
        contact_val = None
        external_id_val = None
        
        desc = val(d.get('onu_description'))
        if desc:
            parsed = db.parse_structured_description(desc)
            zone_val = parsed.get('zone')
            splitter_val = parsed.get('splitter')
            address_val = parsed.get('address')
            contact_val = parsed.get('contact')
            external_id_val = parsed.get('external_id')
            
        # 6. Update database
        with conn.cursor() as cursor:
            cursor.execute("""
                UPDATE onus SET
                    vlan              = COALESCE(%s, vlan),
                    pppoe_username    = COALESCE(%s, pppoe_username),
                    pppoe_password    = COALESCE(%s, pppoe_password),
                    onu_mode          = COALESCE(%s, onu_mode),
                    wan_mode          = COALESCE(%s, wan_mode),
                    wan_remote_access = COALESCE(%s, wan_remote_access),
                    mgmt_ip           = COALESCE(%s, mgmt_ip),
                    allow_remote_mgmt = COALESCE(%s, allow_remote_mgmt),
                    download_profile  = COALESCE(%s, download_profile),
                    upload_profile    = COALESCE(%s, upload_profile),
                    name              = COALESCE(%s, name),
                    zone              = COALESCE(%s, zone),
                    splitter          = COALESCE(%s, splitter),
                    address           = COALESCE(%s, address),
                    contact           = COALESCE(%s, contact),
                    external_id       = COALESCE(%s, external_id),
                    updated_at        = CURRENT_TIMESTAMP
                WHERE id = %s
            """, (
                val(d.get('vlan')),
                val(d.get('pppoe_username')),
                val(d.get('pppoe_password')),
                val(d.get('onu_mode')),
                val(d.get('wan_mode')),
                val(d.get('wan_remote_access')),
                val(d.get('mgmt_ip')),
                val(d.get('allow_remote_mgmt')),
                val(d.get('download_profile')),
                val(d.get('upload_profile')),
                clean_name,
                zone_val,
                splitter_val,
                address_val,
                contact_val,
                external_id_val,
                onu_id
            ))
            conn.commit()
            
        print(f"Sync successful for ONU ID {onu_id}.")
        
    except Exception as e:
        print(f"ERROR: {str(e)}")
        sys.exit(1)
    finally:
        if conn:
            conn.close()

if __name__ == "__main__":
    main()
