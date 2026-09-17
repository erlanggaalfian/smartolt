-- Tambah kolom untuk simpan konfigurasi Static IP WAN (sudah live di database).
ALTER TABLE onus
    ADD COLUMN static_ip VARCHAR(45) NULL AFTER pppoe_ip,
    ADD COLUMN static_netmask VARCHAR(45) NULL AFTER static_ip,
    ADD COLUMN static_gateway VARCHAR(45) NULL AFTER static_netmask,
    ADD COLUMN static_dns_primary VARCHAR(45) NULL AFTER static_gateway,
    ADD COLUMN static_dns_secondary VARCHAR(45) NULL AFTER static_dns_primary;
