<?php
// ==============================================================================
// Action Handler: Manajemen User (add | delete)
// Gabungan dari add-user.php + delete-user.php
// Lokasi: /frontend/action/user.php
// ==============================================================================
require_once __DIR__ . '/../../backend/db.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Guard: hanya superadmin yang boleh mengelola user.
if (!isset($_SESSION['smartolt_role']) || $_SESSION['smartolt_role'] !== 'superadmin') {
    if (isset($_GET['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    $_SESSION['error'] = 'Akses ditolak! Hanya Superadmin yang dapat mengelola user.';
    header('Location: ../login.php');
    exit;
}

if ($action === 'get_access') {
    $user_id = (int)($_GET['user_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT olt_id FROM user_olts WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $olt_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'olt_ids' => $olt_ids]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../settings-users.php');
    exit;
}

if (!verify_csrf_token()) {
    $_SESSION['error'] = 'Token keamanan tidak valid. Silakan coba lagi.';
    header('Location: ../settings-users.php');
    exit;
}

if ($action === 'add') {

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role     = in_array($_POST['role'] ?? '', ['superadmin', 'biasa'], true) ? $_POST['role'] : '';

    if ($username === '' || $password === '' || $role === '') {
        $_SESSION['error'] = 'Semua field (Username, Password, Peran) wajib diisi dengan nilai valid!';
        header('Location: ../settings-users.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
        $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT), $role]);
        $new_user_id = $pdo->lastInsertId();

        if ($role === 'biasa' && !empty($_POST['olt_ids'])) {
            $stmt_ins = $pdo->prepare("INSERT INTO user_olts (user_id, olt_id) VALUES (?, ?)");
            foreach ($_POST['olt_ids'] as $olt_id) {
                $stmt_ins->execute([$new_user_id, (int)$olt_id]);
            }
        }

        $pdo->commit();

        write_audit_log(null, 'USER_CREATE', "Superadmin '{$_SESSION['smartolt_username']}' mendaftarkan user baru '{$username}' dengan peran '{$role}'.");

        $_SESSION['success'] = "User baru '{$username}' ({$role}) berhasil ditambahkan!";
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->errorInfo[1] == 1062) {
            $_SESSION['error'] = "Username '{$username}' sudah terdaftar!";
        } else {
            error_log("[SmartOLT user] Database Error (add): " . $e->getMessage());
            $_SESSION['error'] = 'Gagal menyimpan user karena kesalahan sistem internal.';
        }
    }

} elseif ($action === 'delete') {

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        $_SESSION['error'] = 'ID user tidak valid!';
        header('Location: ../settings-users.php');
        exit;
    }

    // Cegah menghapus diri sendiri untuk menghindari lockout.
    if ($id == $_SESSION['smartolt_user_id']) {
        $_SESSION['error'] = 'Gagal! Anda tidak dapat menghapus akun Anda sendiri yang sedang aktif.';
        header('Location: ../settings-users.php');
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT username, role FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if ($user) {
            $stmt_delete = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt_delete->execute([$id]);

            write_audit_log(null, 'USER_DELETE', "Superadmin '{$_SESSION['smartolt_username']}' menghapus user '{$user['username']}' ({$user['role']}).");

            $_SESSION['success'] = "User '{$user['username']}' berhasil dihapus.";
        } else {
            $_SESSION['error'] = 'User tidak ditemukan!';
        }
    } catch (PDOException $e) {
        error_log("[SmartOLT user] Database Error (delete): " . $e->getMessage());
        $_SESSION['error'] = 'Gagal menghapus user karena kesalahan sistem internal.';
    }

} elseif ($action === 'update_access') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $olt_ids = $_POST['olt_ids'] ?? [];

    if ($user_id <= 0) {
        $_SESSION['error'] = 'ID user tidak valid!';
        header('Location: ../settings-users.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt_del = $pdo->prepare("DELETE FROM user_olts WHERE user_id = ?");
        $stmt_del->execute([$user_id]);

        if (!empty($olt_ids)) {
            $stmt_ins = $pdo->prepare("INSERT INTO user_olts (user_id, olt_id) VALUES (?, ?)");
            foreach ($olt_ids as $olt_id) {
                $stmt_ins->execute([$user_id, (int)$olt_id]);
            }
        }

        $pdo->commit();
        $_SESSION['success'] = 'Hak akses OLT user berhasil diperbarui.';
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("[SmartOLT user] Gagal update_access: " . $e->getMessage());
        $_SESSION['error'] = 'Gagal menyimpan hak akses OLT karena kesalahan sistem.';
    }

} else {
    $_SESSION['error'] = 'Aksi tidak dikenal.';
}

header('Location: ../settings-users.php');
exit;
