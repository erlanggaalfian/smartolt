<?php
// ==============================================================================
# Action Handler: Bersihkan Semua Log Aktivitas
// ==============================================================================
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/config.php';

if (!isset($_SESSION['smartolt_role']) || $_SESSION['smartolt_role'] !== 'superadmin') {
    $_SESSION['error'] = 'Akses ditolak! Hanya Superadmin yang dapat membersihkan log.';
    header('Location: ../dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $username = $_SESSION['smartolt_username'] ?? 'unknown';
        $pdo->query("TRUNCATE TABLE logs");
        error_log("[SmartOLT clear-logs] Semua log dibersihkan oleh user '{$username}'.");
        $_SESSION['success'] = "Seluruh log aktivitas berhasil dibersihkan.";
    } catch (PDOException $e) {
        error_log("[SmartOLT clear-logs] Database Error: " . $e->getMessage());
        $_SESSION['error'] = "Gagal membersihkan log karena kesalahan sistem internal.";
    }
    header('Location: ../logs.php');
    exit;
}
