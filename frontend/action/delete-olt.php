<?php
// ==============================================================================
# Action Handler: Hapus OLT
// ==============================================================================
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/config.php';

if (!isset($_SESSION['smartolt_role']) || $_SESSION['smartolt_role'] !== 'superadmin') {
    $_SESSION['error'] = 'Akses ditolak! Anda tidak memiliki izin untuk mengonfigurasi OLT.';
    header('Location: ../dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)$_POST['id'];

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
