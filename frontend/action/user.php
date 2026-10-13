<?php
// ==============================================================================
// Action Handler: Manajemen User (add | delete)
// Gabungan dari add-user.php + delete-user.php
// Lokasi: /frontend/action/user.php
// ==============================================================================
require_once __DIR__ . '/../../backend/db.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Guard: hanya superadmin yang boleh mengelola user.
if (!can_access_settings()) {
    if (isset($_GET['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    $_SESSION['error'] = 'Akses ditolak! Hanya Superadmin yang dapat mengelola user.';
    header('Location: ../login.php');
    exit;
}

if ($action === 'get_user') {
    header('Content-Type: application/json; charset=utf-8');
    $user_id = (int)($_GET['user_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $u = $stmt->fetch();
    if (!$u || !can_manage_target_user($user_id, $u['role'])) {
        echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
        exit;
    }
    // OLT IDs
    $stmt2 = $pdo->prepare("SELECT olt_id FROM user_olts WHERE user_id = ?");
    $stmt2->execute([$user_id]);
    $u['olt_ids'] = $stmt2->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['success' => true, 'user' => $u]);
    exit;
}

if ($action === 'edit') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = in_array($_POST['role'] ?? '', ['superadmin', 'admin', 'user'], true) ? $_POST['role'] : '';

    if ($id <= 0 || $username === '' || $role === '') {
        echo json_encode(['success' => false, 'message' => 'Field wajib diisi.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target || !can_manage_target_user($id, $target['role'])) {
        echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
        exit;
    }
    if ($role === 'superadmin' && $_SESSION['smartolt_role'] !== 'superadmin') {
        echo json_encode(['success' => false, 'message' => 'Hanya Superadmin yang bisa set role superadmin.']);
        exit;
    }

    try {
        if ($password !== '') {
            $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ?, role = ? WHERE id = ?");
            $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT), $role, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET username = ?, role = ? WHERE id = ?");
            $stmt->execute([$username, $role, $id]);
        }
        write_audit_log(null, 'USER_EDIT', "'{$_SESSION['smartolt_username']}' mengedit user '{$username}' (id={$id}, role={$role}).");
        echo json_encode(['success' => true, 'message' => "User '{$username}' berhasil diupdate."]);
    } catch (PDOException $e) {
        if ($e->errorInfo[1] == 1062) {
            echo json_encode(['success' => false, 'message' => "Username '{$username}' sudah dipakai."]);
        } else {
            error_log("[SmartOLT user] DB Error (edit): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Gagal update user.']);
        }
    }
    exit;
}

if ($action === 'get_access') {
    $user_id = (int)($_GET['user_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $target = $stmt->fetch();
    header('Content-Type: application/json; charset=utf-8');
    if (!$target || !can_manage_target_user($user_id, $target['role'])) {
        echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT olt_id FROM user_olts WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $olt_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
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
    $role     = in_array($_POST['role'] ?? '', ['superadmin', 'admin', 'user'], true) ? $_POST['role'] : '';

    if ($username === '' || $password === '' || $role === '') {
        $_SESSION['error'] = 'Semua field (Username, Password, Peran) wajib diisi dengan nilai valid!';
        header('Location: ../settings-users.php');
        exit;
    }

    // Hanya superadmin yang boleh membuat akun superadmin baru.
    if ($role === 'superadmin' && $_SESSION['smartolt_role'] !== 'superadmin') {
        $_SESSION['error'] = 'Hanya Superadmin yang dapat membuat akun Superadmin baru.';
        header('Location: ../settings-users.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
        $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT), $role]);
        $new_user_id = $pdo->lastInsertId();

        if ($role !== 'superadmin' && !empty($_POST['olt_ids'])) {
            $allowed_olts = get_allowed_olt_ids();
            $stmt_ins = $pdo->prepare("INSERT INTO user_olts (user_id, olt_id) VALUES (?, ?)");
            foreach ($_POST['olt_ids'] as $olt_id) {
                $olt_id = (int)$olt_id;
                // admin hanya boleh assign OLT miliknya sendiri; superadmin boleh assign apapun.
                if ($_SESSION['smartolt_role'] === 'admin' && !in_array($olt_id, $allowed_olts)) {
                    continue;
                }
                $stmt_ins->execute([$new_user_id, $olt_id]);
            }
        }

        $pdo->commit();

        write_audit_log(null, 'USER_CREATE', "'{$_SESSION['smartolt_username']}' mendaftarkan user baru '{$username}' dengan peran '{$role}'.");

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

    $stmt = $pdo->prepare("SELECT username, role FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user || !can_manage_target_user($id, $user['role'])) {
        $_SESSION['error'] = 'Akses ditolak! User ini di luar grup Anda.';
        header('Location: ../settings-users.php');
        exit;
    }

    try {
        $stmt_delete = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt_delete->execute([$id]);

        write_audit_log(null, 'USER_DELETE', "'{$_SESSION['smartolt_username']}' menghapus user '{$user['username']}' ({$user['role']}).");

        $_SESSION['success'] = "User '{$user['username']}' berhasil dihapus.";
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

    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $target = $stmt->fetch();

    if (!$target || !can_manage_target_user($user_id, $target['role'])) {
        $_SESSION['error'] = 'Akses ditolak! User ini di luar grup Anda.';
        header('Location: ../settings-users.php');
        exit;
    }

    // admin hanya boleh assign OLT miliknya sendiri; superadmin boleh assign apapun.
    if ($_SESSION['smartolt_role'] === 'admin') {
        $allowed_olts = get_allowed_olt_ids();
        $olt_ids = array_values(array_intersect(array_map('intval', $olt_ids), $allowed_olts));
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
