CREATE TABLE IF NOT EXISTS olt_vlans (
    olt_id      INT NOT NULL,
    vlan_id    INT NOT NULL,
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (olt_id, vlan_id),
    FOREIGN KEY (olt_id) REFERENCES olts(id) ON DELETE CASCADE
);
