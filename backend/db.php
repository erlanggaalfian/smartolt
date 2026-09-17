<?php
// ==============================================================================
// SmartOLT Backend - PDO MySQL Connection
// Lokasi: /backend/db.php
// ==============================================================================

require_once __DIR__ . '/config.php';

// Baca variabel koneksi dari environment (.env)
$db_host = getenv('DB_HOST')      ?: 'localhost';
$db_port = getenv('DB_PORT')      ?: '3306';
$db_name = getenv('DB_NAME')      ?: 'smartoltdb';
$db_user = getenv('DB_USER')      ?: 'smartoltuser';
$db_pass = getenv('DB_PASSWORD')  ?: '';

/**
 * Mengekstrak nama pelanggan asli dari deskripsi terstruktur OLT.
 * Contoh: "zone_SOC_descr_Shelter..._name_ODB01A0003-PelangganTiga" -> "ODB01A0003-PelangganTiga"
 */
function extract_customer_name(?string $desc): string {
    $desc = (string)$desc;
    if (preg_match('/^name_(.*?)(?:_(?:zone|descr|odb|authd|contact)_|$)/i', $desc, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/_name_(.*?)(?:_(?:zone|descr|odb|authd|contact|name)_|$)/i', $desc, $m)) {
        return trim($m[1]);
    }
    return $desc;
}

/**
 * Mem-parse data deskripsi OLT terstruktur menjadi array key-value.
 */
function parse_structured_description(?string $desc): array {
    $desc = str_replace(["\r\n", "\r", "\n"], '', (string)$desc);
    $res = [
        'zone' => null,
        'address' => null,
        'splitter' => null,
        'contact' => null,
        'external_id' => null
    ];
    if (preg_match('/zone_([^_]+)/i', $desc, $m)) {
        $val = trim($m[1]);
        $res['zone'] = ($val !== '' && $val !== 'None') ? $val : null;
    }
    if (preg_match('/descr_(.*?)(_odb_|_authd_|_contact_|_name_|$)/i', $desc, $m)) {
        $val = trim($m[1]);
        $res['address'] = ($val !== '' && $val !== 'None') ? $val : null;
    }
    if (preg_match('/_odb_(.*?)(?:_authd|_auth|_extid|$)/i', $desc, $m)) {
        $splitter_val = str_replace('_', ' ', trim($m[1]));
        // Buang prefix "ODP " berlebih (OLT kadang tulis odb_ODP_TGR-... jadi "ODP TGR-...")
        $splitter_val = preg_replace('/^ODP\s+/i', '', $splitter_val);
        // Buang GPS coordinates contamination (OLT kadang append " lat -X.XXX long XXX.XXX")
        $splitter_val = preg_replace('/\s*lat\s+-?\d+\.?\d*\s+long\s+-?\d+\.?\d*.*$/i', '', $splitter_val);
        $res['splitter'] = ($splitter_val !== '' && $splitter_val !== 'None') ? $splitter_val : null;
    }
    if (preg_match('/contact_(.*?)(_descr_|_authd_|_odb_|_name_|$)/i', $desc, $m)) {
        $val = trim($m[1]);
        $res['contact'] = ($val !== '' && $val !== 'None') ? $val : null;
    }
    if (preg_match('/_extid_([^_]+)/i', $desc, $m)) {
        $val = trim($m[1]);
        $res['external_id'] = ($val !== '' && $val !== 'None') ? $val : null;
    }
    return $res;
}

try {
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);

    // Migration guard: only run once per deploy
    static $_migrations_done = false;
    if (!$_migrations_done) {
        $_migrations_done = true;
        $mig_version = 0;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version INT PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
            $row = $pdo->query("SELECT MAX(version) as v FROM schema_migrations")->fetch();
            $mig_version = (int)($row['v'] ?? 0);
        } catch (Exception $e) {}
        
        if ($mig_version < 2) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS olts (
                        id              INT AUTO_INCREMENT PRIMARY KEY,
                        name            VARCHAR(100) NOT NULL,
                        ip              VARCHAR(45)  NOT NULL UNIQUE,
                        ssh_port        SMALLINT     NOT NULL DEFAULT 22,
                        username        VARCHAR(64)  NOT NULL,
                        password        VARCHAR(128) NOT NULL,
                        protocol        VARCHAR(10)  NOT NULL DEFAULT 'SSH',
                        snmp_port       SMALLINT     NOT NULL DEFAULT 161,
                        snmp_community  VARCHAR(64)  NOT NULL DEFAULT 'public',
                        snmp_community_rw VARCHAR(64) NOT NULL DEFAULT 'public',
                        type            VARCHAR(50)  NOT NULL DEFAULT 'GPON',
                        created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
            
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS onus (
                        id              INT AUTO_INCREMENT PRIMARY KEY,
                        olt_id          INT          NOT NULL,
                        pon_port        VARCHAR(16)  NOT NULL,
                        onu_id          SMALLINT     NOT NULL,
                        name            VARCHAR(128) NOT NULL,
                        serial_number   VARCHAR(32)  NOT NULL UNIQUE,
                        vlan            SMALLINT,
                        status          ENUM('online','offline','disabled') NOT NULL DEFAULT 'offline',
                        last_rx_power   DECIMAL(6,2),
                        created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        FOREIGN KEY (olt_id) REFERENCES olts(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
            
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS onu_history (
                        id              INT AUTO_INCREMENT PRIMARY KEY,
                        onu_id          INT          NOT NULL,
                        rx_power        DECIMAL(6,2),
                        rx_olt_power    DECIMAL(6,2),
                        rx_bytes        BIGINT UNSIGNED,
                        tx_bytes        BIGINT UNSIGNED,
                        rx_packets      BIGINT UNSIGNED,
                        tx_packets      BIGINT UNSIGNED,
                        timestamp       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (onu_id) REFERENCES onus(id) ON DELETE CASCADE,
                        INDEX idx_onu_timestamp (onu_id, timestamp)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
            
                // Tambahkan kolom cache status kesehatan ke tabel olts
                try { $pdo->exec("ALTER TABLE olts ADD COLUMN last_cpu VARCHAR(10) NULL"); } catch (PDOException $e) {}
                try { $pdo->exec("ALTER TABLE olts ADD COLUMN last_ram VARCHAR(10) NULL"); } catch (PDOException $e) {}
                try { $pdo->exec("ALTER TABLE olts ADD COLUMN last_temp VARCHAR(10) NULL"); } catch (PDOException $e) {}
                try { $pdo->exec("ALTER TABLE olts ADD COLUMN last_uptime VARCHAR(255) NULL"); } catch (PDOException $e) {}
                try { $pdo->exec("ALTER TABLE olts ADD COLUMN last_snmp_status VARCHAR(32) NULL"); } catch (PDOException $e) {}
                try { $pdo->exec("ALTER TABLE olts ADD COLUMN last_health_check DATETIME NULL"); } catch (PDOException $e) {}
                try { $pdo->exec("ALTER TABLE onus ADD COLUMN last_rx_olt_power DECIMAL(6,2) NULL AFTER last_rx_power"); } catch (PDOException $e) {}
            
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS logs (
                        id          INT AUTO_INCREMENT PRIMARY KEY,
                        olt_id      INT,
                        action      VARCHAR(64)  NOT NULL,
                        message     TEXT         NOT NULL,
                        timestamp   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_olt_id (olt_id),
                        INDEX idx_timestamp (timestamp)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
            
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS users (
                        id              INT AUTO_INCREMENT PRIMARY KEY,
                        username        VARCHAR(64)  NOT NULL UNIQUE,
                        password        VARCHAR(255) NOT NULL,
                        role            ENUM('superadmin','biasa') NOT NULL DEFAULT 'biasa',
                        created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
            
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS user_olts (
                        user_id         INT NOT NULL,
                        olt_id          INT NOT NULL,
                        PRIMARY KEY (user_id, olt_id),
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (olt_id) REFERENCES olts(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
            
                // Migrasi: Cek apakah kolom created_at ada di tabel logs, jika ada ubah ke timestamp
                try {
                    $check = $pdo->query("SHOW COLUMNS FROM logs LIKE 'created_at'")->fetch();
                    if ($check) {
                        $pdo->exec("ALTER TABLE logs CHANGE created_at timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
                    }
                } catch (Exception $e) {
                    // Abaikan jika ada kegagalan migrasi
                }
            
                // Migrasi: Cek apakah kolom snmp_community_rw ada di tabel olts, jika tidak ada tambahkan
                try {
                    $check = $pdo->query("SHOW COLUMNS FROM olts LIKE 'snmp_community_rw'")->fetch();
                    if (!$check) {
                        $pdo->exec("ALTER TABLE olts ADD COLUMN snmp_community_rw VARCHAR(64) NOT NULL DEFAULT 'public' AFTER snmp_community");
                    }
                } catch (Exception $e) {
                    // Abaikan
                }
            
                // Migrasi: Cek apakah kolom protocol ada di tabel olts, jika tidak ada tambahkan
                try {
                    $check = $pdo->query("SHOW COLUMNS FROM olts LIKE 'protocol'")->fetch();
                    if (!$check) {
                        $pdo->exec("ALTER TABLE olts ADD COLUMN protocol VARCHAR(10) NOT NULL DEFAULT 'SSH' AFTER password");
                    }
                } catch (Exception $e) {
                    // Abaikan
                }
            
                // Migrasi: Ubah kolom status di onus agar mendukung 'disabled'
                try {
                    $pdo->exec("ALTER TABLE onus MODIFY COLUMN status ENUM('online','offline','disabled') NOT NULL DEFAULT 'offline'");
                } catch (Exception $e) {
                    // Abaikan
                }
            
                // Migrasi: Ubah kolom type dari ENUM ke VARCHAR(50)
                try {
                    $pdo->exec("ALTER TABLE olts MODIFY COLUMN type VARCHAR(50) NOT NULL DEFAULT 'GPON'");
                } catch (Exception $e) {
                    // Abaikan
                }
            
                // Migrasi: Tambah kolom detail ONU baru ke onus
                $new_onus_cols = [
                    'pppoe_username'    => "VARCHAR(128) NULL",
                    'pppoe_password'    => "VARCHAR(128) NULL",
                    'onu_type'          => "VARCHAR(64) NULL DEFAULT 'ALL-ONT'",
                    'config_preset'     => "VARCHAR(64) NULL DEFAULT 'None'",
                    'zone'              => "VARCHAR(64) NULL",
                    'splitter'          => "VARCHAR(128) NULL",
                    'odb_port'          => "VARCHAR(32) NULL",
                    'address'           => "TEXT NULL",
                    'contact'           => "VARCHAR(64) NULL",
                    'latitude'          => "DECIMAL(10,7) NULL",
                    'longitude'         => "DECIMAL(10,7) NULL",
                    'onu_mode'          => "VARCHAR(32) NULL DEFAULT 'Routing'",
                    'wan_mode'          => "VARCHAR(32) NULL DEFAULT 'PPPoE'",
                    'config_method'     => "VARCHAR(32) NULL DEFAULT 'OMCI'",
                    'ip_protocol'       => "VARCHAR(32) NULL DEFAULT 'IPv4'",
                    'wan_remote_access' => "VARCHAR(10) NULL DEFAULT 'yes'",
                    'mgmt_ip_mode'      => "VARCHAR(32) NULL DEFAULT 'Inactive'",
                    'mgmt_ip'           => "VARCHAR(45) NULL",
                    'mgmt_vlan'         => "SMALLINT NULL",
                    'allow_remote_mgmt' => "VARCHAR(10) NULL DEFAULT 'no'",
                    'pppoe_ip'          => "VARCHAR(45) NULL",
                    'last_rx_olt_power' => "DECIMAL(6,2) NULL",
                    'last_status_cli'   => "MEDIUMTEXT NULL",
                    'last_running_config' => "MEDIUMTEXT NULL",
                    'download_profile'  => "VARCHAR(128) NULL",
                    'upload_profile'    => "VARCHAR(128) NULL",
                    'description'       => "TEXT NULL",
                    'last_down_cause'   => "VARCHAR(64) NULL"
                ];
                foreach ($new_onus_cols as $col => $definition) {
                    try {
                        $check = $pdo->query("SHOW COLUMNS FROM onus LIKE '{$col}'")->fetch();
                        if (!$check) {
                            $pdo->exec("ALTER TABLE onus ADD COLUMN {$col} {$definition}");
                        }
                    } catch (Exception $e) {
                        // Abaikan
                    }
                }
            
            try { $pdo->exec("INSERT IGNORE INTO schema_migrations (version) VALUES (2)"); } catch (Exception $e) {}
        }

        if ($mig_version < 3) {
            $idx_cols = [
                "idx_onus_status" => "status",
                "idx_onus_zone" => "zone",
                "idx_onus_splitter" => "splitter",
                "idx_onus_pon_port" => "pon_port",
                "idx_onus_olt_status" => "olt_id, status",
            ];
            foreach ($idx_cols as $idx_name => $cols) {
                try { $pdo->exec("CREATE INDEX $idx_name ON onus ($cols)"); } catch (Exception $e) {}
            }
            try { $pdo->exec("INSERT IGNORE INTO schema_migrations (version) VALUES (3)"); } catch (Exception $e) {}
        }
    }

} catch (PDOException $e) {
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Warning: Database connection failed: " . $e->getMessage() . "\n");
        $pdo = null; // Set to null and continue execution
    } else {
        // Tampilkan halaman error yang informatif
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(503);
        echo <<<HTML
        <!DOCTYPE html>
        <html lang="id">
        <head><meta charset="UTF-8"><title>SmartOLT - Koneksi Database Gagal</title>
        <style>
            body{margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;
                 background:#0f172a;font-family:sans-serif;}
            .card{background:#1e293b;border:1px solid #ef4444;border-radius:10px;padding:32px 40px;
                  max-width:520px;text-align:center;}
            h2{color:#ef4444;margin-top:0}
            p{color:#94a3b8;line-height:1.6}
            code{background:#0f172a;color:#f87171;padding:2px 6px;border-radius:4px;font-size:.9em}
        </style>
        </head>
        <body>
          <div class="card">
            <h2>&#10060; Gagal Terhubung ke Database MySQL</h2>
            <p><strong style="color:#f1f5f9">{$e->getMessage()}</strong></p>
            <p>Pastikan konfigurasi di berkas <code>.env</code> sudah benar dan server <code>MySQL/MariaDB</code> sedang berjalan.</p>
          </div>
        </body>
        </html>
        HTML;
        exit;
    }
}
