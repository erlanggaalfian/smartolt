<?php
// ==============================================================================
// SmartOLT Login Page
// Lokasi: /frontend/login.php
// ==============================================================================
require_once __DIR__ . '/../backend/db.php';

// Jika sudah login, alihkan ke dashboard
if (isset($_SESSION['smartolt_user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Cek apakah belum ada user sama sekali
try {
    $user_count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
} catch (Exception $e) {
    $user_count = 0;
}
if ($user_count == 0) {
    header('Location: register.php');
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
    <title>Login - SmartOLT Management System</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&family=Montserrat:wght@500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@1.46.0/dist/umd/lucide.min.js" integrity="sha384-1avDoIZZ5mKtAlvTbQ2jge+TZA4z+qwhMFHdCpATww/SLVM8wKs7sROOEKMOizyV" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="public/style.css">
</head>
<body class="auth-page">
    <div class="login-container">
        <div class="login-card motion-scale-in">
            <div class="login-logo motion-fade-up">
                <i data-lucide="shield-check" class="auth-brand-icon"></i>
                <h2>SmartOLT Panel</h2>
                <p>Silakan masuk untuk mengelola OLT & pelanggan</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form action="action/login.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <div class="form-group motion-fade-up">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Masukkan username" required autofocus>
                </div>
                <div class="form-group motion-fade-up">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Masukkan password" required>
                </div>
                <button type="submit" class="btn-login">Masuk ke Panel</button>
            </form>
        </div>
    </div>
    <script>
        lucide.createIcons();
    </script>
</body>
</html>
