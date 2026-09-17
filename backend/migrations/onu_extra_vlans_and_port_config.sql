-- VLAN yang di-allow (attached) untuk suatu ONU, dipilih dari VLAN yang tersedia di OLT-nya.
-- Popup "VLAN Terpasang": checklist VLAN mana saja yang boleh dipakai ONU ini.
CREATE TABLE IF NOT EXISTS onu_extra_vlans (
    onu_id      INT NOT NULL,
    vlan_id     INT NOT NULL,
    attached_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (onu_id, vlan_id),
    FOREIGN KEY (onu_id) REFERENCES onus(id) ON DELETE CASCADE
);

-- Mode tiap port Ethernet fisik ONU (eth_0/1..4): ikut WAN (default, perilaku lama)
-- atau Bridging ke salah satu VLAN yang sudah di-attach (onu_extra_vlans).
CREATE TABLE IF NOT EXISTS onu_port_config (
    onu_id    INT NOT NULL,
    port      TINYINT NOT NULL,          -- 1..4 (eth_0/1..eth_0/4)
    mode      ENUM('wan','bridge') NOT NULL DEFAULT 'wan',
    vlan_id   INT NULL,                  -- diisi kalau mode='bridge', harus ada di onu_extra_vlans
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (onu_id, port),
    FOREIGN KEY (onu_id) REFERENCES onus(id) ON DELETE CASCADE
);
