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
    <style>
body { margin: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; background: radial-gradient(circle at center, var(--auth-bg) 0%, var(--auth-bg-deep) 100%); font-family: 'Montserrat', sans-serif; font-weight: 500; color: var(--auth-text); }
h2 { font-family: 'Poppins', sans-serif; font-weight: 700; }
.login-container { width: 100%; max-width: 420px; padding: 20px; }
.login-card { background: var(--auth-card); border: 1px solid var(--auth-border); border-radius: 12px; padding: 40px; box-shadow: 0 10px 25px -5px var(--shadow-a50), 0 8px 10px -6px var(--shadow-a50); backdrop-filter: blur(10px); }
.login-logo { text-align: center; margin-bottom: 30px; }
.login-logo h2 { margin: 10px 0 5px; font-size: 1.8rem; font-weight: 700; background: linear-gradient(135deg, var(--auth-accent) 0%, var(--chip-blue-fg) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
.login-logo p { margin: 0; color: var(--auth-muted); font-size: 0.9rem; }
.form-group { margin-bottom: 20px; }
.form-group label { display: block; margin-bottom: 8px; font-size: 0.85rem; font-weight: 500; color: var(--auth-muted); }
.form-group input { width: 100%; padding: 12px 16px; background: var(--auth-bg); border: 1px solid var(--auth-border); border-radius: 6px; color: var(--auth-text); font-family: inherit; font-size: 0.95rem; box-sizing: border-box; transition: all 0.3s ease; }
.form-group input:focus { border-color: var(--auth-accent); box-shadow: 0 0 0 2px var(--tint-teal); outline: none; }
.btn-login { width: 100%; padding: 12px; background: linear-gradient(135deg, var(--auth-accent) 0%, var(--chip-blue-fg) 100%); border: none; border-radius: 6px; color: var(--color-white); font-family: inherit; font-size: 1rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 12px var(--auth-glow); }
.btn-login:hover { transform: translateY(-1px); box-shadow: 0 6px 16px var(--auth-glow-strong); }
.alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 0.85rem; line-height: 1.5; }
.alert-danger { background: var(--tint-danger); border: 1px solid var(--border-danger); color: var(--auth-danger); }
.alert-success { background: var(--auth-success-tint); border: 1px solid var(--auth-success-border); color: var(--auth-success); }
</style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-logo">
                <i data-lucide="shield-check" style="width: 48px; height: 48px; color: #06b6d4;"></i>
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
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Masukkan username" required autofocus>
                </div>
                <div class="form-group">
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
