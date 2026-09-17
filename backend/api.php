<?php
// SmartOLT — JS API Client Helper
// PHP frontend calls JS API for all write operations.
// Read operations still use direct PDO for speed.

require_once __DIR__ . '/config.php';

define('JS_API_URL', 'http://127.0.0.1:' . (getenv('JS_API_PORT') ?: '3001') . '/api');

/**
 * Get JWT token for current session user (cached per request).
 */
function get_api_token(): string {
    static $token = null;
    if ($token !== null) return $token;

    $key = getenv('APP_KEY') ?: 'smartolt-default-secret-change-me';
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64url_encode(json_encode([
        'uid' => (int)($_SESSION['smartolt_user_id'] ?? 0),
        'role' => $_SESSION['smartolt_role'] ?? 'biasa',
        'exp' => time() + 3600,
    ]));
    $sig = base64url_encode(hash_hmac('sha256', "$header.$payload", $key, true));
    $token = "$header.$payload.$sig";
    return $token;
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Call JS API endpoint. Returns decoded JSON array.
 */
function api_call(string $method, string $path, array $data = []): array {
    $url = JS_API_URL . $path;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . get_api_token(),
        ],
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $response) {
        $decoded = json_decode($response, true);
        if (is_array($decoded)) return $decoded;
    }
    return ['success' => false, 'message' => "API error (HTTP $http_code)"];
}

// Convenience wrappers
function api_get(string $path) { return api_call('GET', $path); }
function api_post(string $path, array $data = []) { return api_call('POST', $path, $data); }
function api_put(string $path, array $data = []) { return api_call('PUT', $path, $data); }
function api_delete(string $path) { return api_call('DELETE', $path); }
