<?php
// ==============================================================================
// SmartOLT Backend - Config & Environment Loader
// Lokasi: /backend/config.php
// File ini TIDAK berada di dalam DocumentRoot Apache.
// ==============================================================================

/**
 * Memuat variabel dari berkas .env ke dalam environment PHP.
 */
function load_env(string $path): void {
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name  = trim($parts[0]);
            $value = trim(trim($parts[1]), '"\'');
            if (!array_key_exists($name, $_ENV) && !array_key_exists($name, $_SERVER)) {
                putenv("{$name}={$value}");
                $_ENV[$name]    = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

// Muat .env dari root proyek (dua tingkat di atas /backend/)
// Struktur: /var/www/<domain>/.env  (aman, di luar DocumentRoot)
load_env(__DIR__ . '/../.env');

// Security headers dasar (clickjacking, MIME sniffing)
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // HSTS — force browser to use HTTPS for 1 year
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    // CSP — restrict resource origins; 'unsafe-inline' needed for existing inline <script> blocks
    $csp = "default-src 'self'; "
         . "script-src 'self' 'unsafe-inline' https://unpkg.com https://cdn.jsdelivr.net; "
         . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
         . "font-src 'self' https://fonts.gstatic.com; "
         . "img-src 'self' data:; "
         . "connect-src 'self' https://unpkg.com https://cdn.jsdelivr.net; "
         . "frame-ancestors 'self'";
    header("Content-Security-Policy: $csp");
}

// Mulai sesi PHP secara global (aman dipanggil berkali-kali)
if (session_status() === PHP_SESSION_NONE) {
    // Ambil default params dan perbarui dengan opsi keamanan
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0, // session cookie expires when browser is closed
        'path'     => $cookieParams['path'] ?? '/',
        'domain'   => $cookieParams['domain'] ?? '',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Generate CSRF token if empty
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Automatically validate POST requests sent to action scripts
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], '/action/') !== false) {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $is_json = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
                   || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                   || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

        if ($is_json) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Token CSRF tidak valid. Silakan muat ulang halaman.']);
            exit;
        } else {
            $_SESSION['error'] = 'Sesi keamanan (CSRF) kedaluwarsa atau tidak valid. Silakan coba lagi.';
            $referer = $_SERVER['HTTP_REFERER'] ?? '../dashboard.php';
            header('Location: ' . get_safe_redirect($referer, '../dashboard.php'));
            exit;
        }
    }
}

// Zona Waktu
date_default_timezone_set('Asia/Jakarta');

/**
 * Mencatat audit log ke tabel `logs` di database.
 * Membutuhkan $pdo telah di-inisialisasi oleh db.php sebelumnya.
 *
 * @param int|null $olt_id  ID OLT yang berkaitan (null jika tidak spesifik)
 * @param string   $action  Kode aksi (misal: OLT_ADD, ONU_AUTHORIZE, ONU_REBOOT)
 * @param string   $message Pesan detail aktivitas
 */
function write_audit_log(?int $olt_id, string $action, string $message): void {
    global $pdo;
    if (!isset($pdo)) return;
    try {
        $stmt = $pdo->prepare("INSERT INTO logs (olt_id, action, message) VALUES (?, ?, ?)");
        $stmt->execute([$olt_id, $action, $message]);
    } catch (Exception $e) {
        // Gagal log tidak boleh mengganggu alur utama aplikasi
        error_log("[SmartOLT] Gagal menulis audit log: " . $e->getMessage());
    }
}

/**
 * Helper to sanitize strings intended for OLT CLI commands.
 * Only allows alphanumeric characters, spaces, dots, underscores, and dashes.
 */
function cli_safe(?string $str): string {
    if ($str === null) return '';
    return preg_replace('/[^A-Za-z0-9 ._\-]/', '', $str);
}

/**
 * Helper to sanitize PPPoE usernames (allows @ symbol).
 */
function cli_safe_email(?string $str): string {
    if ($str === null) return '';
    return preg_replace('/[^A-Za-z0-9._@\-]/', '', $str);
}

/**
 * Helper to sanitize strict alphanumeric inputs (no spaces).
 */
function cli_safe_strict(?string $str): string {
    if ($str === null) return '';
    return preg_replace('/[^A-Za-z0-9._\-]/', '', $str);
}

/**
 * Helper to sanitize PPPoE passwords for CLI interpolation.
 * Allows common password characters (@!#$%&*+=?^~) while stripping
 * control chars (newlines, null) that could inject CLI commands.
 */
function cli_safe_password(?string $str): string {
    if ($str === null) return '';
    // Strip ALL control chars including \n (\x0A) and \r (\x0D) to prevent
    // CLI command injection when password is interpolated into OLT telnet commands
    // (e.g. "pppoe 1 nat enable user X password Y\nmalicious_cmd").
    return preg_replace('/[\x00-\x1F\x7F]/', '', $str);
}

/**
 * Verify CSRF token from POST form field or X-CSRF-Token header.
 * header.php JS auto-injects the token; this validates it server-side.
 * Returns true if valid, false otherwise.
 */
function verify_csrf_token(): bool {
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['csrf_token']) || empty($token)) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Encrypt a plaintext password using AES-256-CBC.
 */
function encrypt_password(string $plaintext): string {
    $key = getenv('APP_KEY') ?: 'some-default-long-secret-key-change-me';
    $encryption_key = hash('sha256', $key, true);
    
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    $iv = random_bytes($iv_length);
    
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $encryption_key, OPENSSL_RAW_DATA, $iv);
    
    return base64_encode($iv . $ciphertext);
}

/**
 * Decrypt a base64 encoded ciphertext using AES-256-CBC.
 * Falls back to returning the input string if decryption fails or format is invalid.
 */
function decrypt_password(string $ciphertext_b64): string {
    $key = getenv('APP_KEY') ?: 'some-default-long-secret-key-change-me';
    $encryption_key = hash('sha256', $key, true);
    
    $data = base64_decode($ciphertext_b64, true);
    if ($data === false) {
        return $ciphertext_b64;
    }
    
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    if (strlen($data) <= $iv_length) {
        return $ciphertext_b64;
    }
    
    $iv = substr($data, 0, $iv_length);
    $ciphertext = substr($data, $iv_length);
    
    $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $encryption_key, OPENSSL_RAW_DATA, $iv);
    
    if ($decrypted === false) {
        return $ciphertext_b64;
    }
    
    return $decrypted;
}

/**
 * Membuat token HMAC berumur pendek untuk Python Engine.
 *
 * Python tidak bisa membaca sesi PHP, jadi identitas pengguna dikirim sebagai
 * token bertanda tangan APP_KEY (kembaran backend/python_engine/auth.py).
 * Format: base64url(payload_json) + "." + base64url(hmac_sha256).
 */
function engine_token(?int $user_id = null, ?string $role = null, int $ttl = 3600): string {
    $uid  = $user_id ?? (int)($_SESSION['smartolt_user_id'] ?? 0);
    $role = $role ?? (string)($_SESSION['smartolt_role'] ?? '');

    if ($role !== 'superadmin' && $role !== 'biasa') {
        return '';
    }

    $key = getenv('APP_KEY') ?: 'some-default-long-secret-key-change-me';

    // Kunci payload WAJIB urut abjad (exp, role, uid) agar sama persis dengan
    // json.dumps(sort_keys=True) di sisi Python.
    $payload_json = json_encode(
        ['exp' => time() + $ttl, 'role' => $role, 'uid' => $uid],
        JSON_UNESCAPED_SLASHES
    );

    $b64 = function (string $raw): string {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    };

    $payload = $b64($payload_json);
    $sig     = hash_hmac('sha256', $payload, $key, true);

    return $payload . '.' . $b64($sig);
}

/**
 * Validate and filter redirect URLs to prevent Open Redirect vulnerabilities.
 * Allows relative paths (excluding protocol-relative) and URLs with hosts matching the current server.
 */
function get_safe_redirect(string $url, string $fallback = '../dashboard.php'): string {
    $parsed = parse_url($url);
    
    if (empty($parsed['host'])) {
        if (strpos($url, '//') === 0) {
            return $fallback;
        }
        return $url;
    }
    
    if (isset($_SERVER['HTTP_HOST']) && strtolower($parsed['host']) === strtolower(parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST))) {
        return $url;
    }
    
    return $fallback;
}

/**
 * Mendapatkan daftar ID OLT yang diizinkan untuk diakses user yang sedang login.
 */
function get_allowed_olt_ids(): array {
    global $pdo;
    if (!isset($pdo) || !isset($_SESSION['smartolt_role'])) {
        return [];
    }
    
    if ($_SESSION['smartolt_role'] === 'superadmin') {
        return $pdo->query("SELECT id FROM olts")->fetchAll(PDO::FETCH_COLUMN);
    }
    
    $stmt = $pdo->prepare("SELECT olt_id FROM user_olts WHERE user_id = ?");
    $stmt->execute([$_SESSION['smartolt_user_id']]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Mendapatkan semua baris OLT yang diizinkan untuk diakses user yang sedang login.
 */
function get_allowed_olts(): array {
    global $pdo;
    if (!isset($pdo) || !isset($_SESSION['smartolt_role'])) {
        return [];
    }
    
    if ($_SESSION['smartolt_role'] === 'superadmin') {
        return $pdo->query("SELECT * FROM olts ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $stmt = $pdo->prepare("
        SELECT o.* 
        FROM olts o
        JOIN user_olts uo ON o.id = uo.olt_id
        WHERE uo.user_id = ?
        ORDER BY o.name ASC
    ");
    $stmt->execute([$_SESSION['smartolt_user_id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Mengecek apakah user memiliki akses ke OLT tertentu.
 */
function has_olt_access(int $olt_id): bool {
    if (!isset($_SESSION['smartolt_role'])) {
        return false;
    }
    if ($_SESSION['smartolt_role'] === 'superadmin') {
        return true;
    }
    $allowed = get_allowed_olt_ids();
    return in_array($olt_id, $allowed);
}

/**
 * Mengalihkan halaman dengan pesan error jika user tidak memiliki akses ke OLT.
 */
function check_olt_access_or_redirect(int $olt_id, string $redirect_to = '../dashboard.php'): void {
    if (!has_olt_access($olt_id)) {
        $_SESSION['error'] = 'Akses ditolak! Anda tidak memiliki izin untuk mengakses OLT ini.';
        header('Location: ' . $redirect_to);
        exit;
    }
}

/**
 * Mengirim respon JSON error dan keluar jika user tidak memiliki akses ke OLT.
 */
function check_olt_access_json(int $olt_id): void {
    if (!has_olt_access($olt_id)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Akses ditolak! Anda tidak memiliki izin untuk mengakses OLT ini.']);
        exit;
    }
}
