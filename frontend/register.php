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
    <style>
body { margin: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; background: radial-gradient(circle at center, var(--auth-bg) 0%, var(--auth-bg-deep) 100%); font-family: 'Montserrat', sans-serif; font-weight: 500; color: var(--auth-text); }
h2 { font-family: 'Poppins', sans-serif; font-weight: 700; }
.register-container { width: 100%; max-width: 440px; padding: 20px; }
.register-card { background: var(--auth-card); border: 1px solid var(--auth-border); border-radius: 12px; padding: 40px; box-shadow: 0 10px 25px -5px var(--shadow-a50), 0 8px 10px -6px var(--shadow-a50); backdrop-filter: blur(10px); }
.register-logo { text-align: center; margin-bottom: 25px; }
.register-logo h2 { margin: 10px 0 5px; font-size: 1.8rem; font-weight: 700; background: linear-gradient(135deg, var(--auth-accent) 0%, var(--chip-blue-fg) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
.register-logo p { margin: 0; color: var(--auth-muted); font-size: 0.9rem; }
.form-group { margin-bottom: 18px; }
.form-group label { display: block; margin-bottom: 8px; font-size: 0.85rem; font-weight: 500; color: var(--auth-muted); }
.form-group input, .form-group select { width: 100%; padding: 12px 16px; background: var(--auth-bg); border: 1px solid var(--auth-border); border-radius: 6px; color: var(--auth-text); font-family: inherit; font-size: 0.95rem; box-sizing: border-box; transition: all 0.3s ease; }
.form-group input:focus, .form-group select:focus { border-color: var(--auth-accent); box-shadow: 0 0 0 2px var(--tint-teal); outline: none; }
.btn-register { width: 100%; padding: 12px; background: linear-gradient(135deg, var(--auth-accent) 0%, var(--chip-blue-fg) 100%); border: none; border-radius: 6px; color: var(--color-white); font-family: inherit; font-size: 1rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 12px var(--auth-glow); margin-top: 10px; }
.btn-register:hover { transform: translateY(-1px); box-shadow: 0 6px 16px var(--auth-glow-strong); }
.alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 0.85rem; line-height: 1.5; }
.alert-danger { background: var(--tint-danger); border: 1px solid var(--border-danger); color: var(--auth-danger); }
.alert-success { background: var(--auth-success-tint); border: 1px solid var(--auth-success-border); color: var(--auth-success); }
</style>
</head>
<body>
    <div class="register-container">
        <div class="register-card">
            <div class="register-logo">
                <i data-lucide="key-round" style="width: 48px; height: 48px; color: #06b6d4;"></i>
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
