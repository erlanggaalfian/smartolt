#!/usr/bin/env python3
"""
backup_configs.py — Backup OLT running-config to DB.
Creates `config_backups` table if not exists, saves `show running-config` output.

Usage: python3 backup_configs.py <olt_id|all>
"""
import sys
import os
import logging
from datetime import datetime

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
import db
import helper

logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
log = logging.getLogger(__name__)


def ensure_table():
    conn = db.get_db_connection()
    with conn.cursor() as c:
        c.execute("""
            CREATE TABLE IF NOT EXISTS config_backups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                olt_id INT NOT NULL,
                config LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_olt_created (olt_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """)
    conn.commit()
    conn.close()


def backup_olt(olt):
    """Fetch running-config via CLI and save to DB."""
    protocol = (olt.get('protocol') or 'SSH').upper()
    commands = ['terminal length 0', 'show running-config']

    if protocol == 'TELNET':
        raw = helper.execute_telnet_commands(
            olt['ip'], olt.get('ssh_port', 23),
            olt['username'], olt['password'], commands
        )
    else:
        raw = helper.execute_ssh_commands(olt, commands)

    if not raw or len(raw) < 100:
        log.warning(f"  Config too short ({len(raw or '')} chars), skipping.")
        return False

    conn = db.get_db_connection()
    with conn.cursor() as c:
        c.execute(
            "INSERT INTO config_backups (olt_id, config, created_at) VALUES (%s, %s, %s)",
            (olt['id'], raw, datetime.now())
        )
    conn.commit()
    conn.close()
    log.info(f"  Saved {len(raw)} chars")
    return True


def main():
    if len(sys.argv) < 2:
        print("Usage: python3 backup_configs.py <olt_id|all>")
        sys.exit(1)

    target = sys.argv[1]
    ensure_table()

    conn = db.get_db_connection()
    with conn.cursor() as c:
        if target == 'all':
            c.execute("SELECT * FROM olts")
        else:
            c.execute("SELECT * FROM olts WHERE id = %s", (int(target),))
        olts = c.fetchall()
    conn.close()

    for olt in olts:
        log.info(f"Backup: {olt['name']} ({olt['ip']})")
        try:
            backup_olt(olt)
        except Exception as e:
            log.error(f"  Failed: {e}")

    log.info("Done.")


if __name__ == '__main__':
    main()
