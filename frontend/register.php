<?php
// ==============================================================================
// SmartOLT Initial Administrator Setup Page
// Lokasi: /frontend/register.php
// ==============================================================================
require_once __DIR__ . '/../backend/db.php';

// Cek jumlah user di database
try {
    $user_count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
} catch (Exception $e) {
    $user_count = 0;
}

// Jika sudah ada user, tidak boleh diakses! Redirect ke login
if ($user_count > 0) {
    header('Location: login.php');
    exit;
}

$error = isset($_SESSION['error']) ? $_SESSION['error'] : '';
$success = isset($_SESSION['success']) ? $_SESSION['success'] : '';
unset($_SESSION['error'], $_SESSION['success']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Pertama - SmartOLT</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&family=Montserrat:wght@500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@1.46.0/dist/umd/lucide.min.js" integrity="sha384-1avDoIZZ5mKtAlvTbQ2jge+TZA4z+qwhMFHdCpATww/SLVM8wKs7sROOEKMOizyV" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="public/style.css">
</head>
<body class="auth-page">
    <div class="register-container">
        <div class="register-card">
            <div class="register-logo">
                <i data-lucide="key-round" class="auth-brand-icon"></i>
                <h2>Setup Administrator</h2>
                <p>Buat akun Superadmin pertama untuk memulai</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form action="action/register.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Masukkan username" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Masukkan password" required>
                </div>
                <div class="form-group">
                    <label for="role">Peran / Hak Akses</label>
                    <select id="role" name="role" readonly>
                        <option value="superadmin">Superadmin (Akses Penuh & Set Config OLT)</option>
                    </select>
                </div>
                <button type="submit" class="btn-register">Simpan & Masuk</button>
            </form>
        </div>
    </div>
    <script>
        lucide.createIcons();
    </script>
</body>
</html>
