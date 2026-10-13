<?php
// ==============================================================================
# Action Handler: Hapus OLT
// ==============================================================================
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/config.php';

if (!can_access_settings()) {
    $_SESSION['error'] = 'Akses ditolak! Anda tidak memiliki izin untuk mengonfigurasi OLT.';
    header('Location: ../dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $_SESSION['error'] = 'Token keamanan tidak valid. Silakan coba lagi.';
        header('Location: ../settings-olt.php');
        exit;
    }
    $id = (int)$_POST['id'];
    // Admin hanya boleh hapus OLT miliknya
    if ($_SESSION['smartolt_role'] !== 'superadmin' && !in_array($id, get_allowed_olt_ids())) {
        $_SESSION['error'] = 'Anda tidak memiliki akses ke OLT ini.';
        header('Location: ../settings-olt.php');
        exit;
    }

    if ($id > 0) {
        // Ambil info OLT sebelum dihapus untuk Audit Log
        $stmt = $pdo->prepare("SELECT * FROM olts WHERE id = ?");
        $stmt->execute([$id]);
        $olt = $stmt->fetch();

        if ($olt) {
            // Hapus dari database (cascade akan menghapus onus yang terikat)
            $stmt_del = $pdo->prepare("DELETE FROM olts WHERE id = ?");
            $stmt_del->execute([$id]);

            // Catat log audit tanpa mengaitkan ke id OLT (karena sudah dihapus)
            write_audit_log(null, 'OLT_DELETE', "OLT {$olt['name']} ({$olt['ip']}) telah dihapus dari sistem.");

            $_SESSION['success'] = "OLT {$olt['name']} berhasil dihapus.";
        } else {
            $_SESSION['error'] = "OLT tidak ditemukan.";
        }
    }

    header('Location: ../settings-olt.php');
    exit;
}
