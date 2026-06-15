<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();

// Already logged in → redirect
if (!empty($_SESSION['user_id'])) {
    redirect('dashboard.php');
}

$error   = '';
$locked  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        $error = 'Ungültige Anfrage. Bitte die Seite neu laden.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $ip       = get_client_ip();

        if ($username === '' || $password === '') {
            $error = 'Bitte Benutzername und Passwort eingeben.';
        } else {
            // Check if account is locked before attempting login_user()
            $pdo  = get_pdo();
            $stmt = $pdo->prepare('SELECT locked_until, is_active FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $row = $stmt->fetch();

            if ($row && $row['locked_until'] && new DateTime() < new DateTime($row['locked_until'])) {
                $locked = true;
                $until  = (new DateTime($row['locked_until']))->format('H:i \U\h\r');
                $error  = 'Konto vorübergehend gesperrt wegen zu vieler Fehlversuche. Bitte versuchen Sie es nach ' . h($until) . ' wieder.';
            } elseif ($row && !$row['is_active']) {
                $error = 'Dieses Konto ist deaktiviert.';
            } else {
                if (login_user($username, $password, $ip)) {
                    // Regenerate CSRF token after login
                    unset($_SESSION['csrf_token']);
                    redirect('dashboard.php');
                } else {
                    $error = 'Ungültige Anmeldedaten.';
                }
            }
        }
    }
}

$csrf = generate_csrf_token();
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Anmelden &mdash; <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="/assets/css/planbear.css">
</head>
<body>
<div class="pb-login-wrapper">
    <div class="pb-login-card card shadow-lg p-4">
        <div class="card-body">
            <div class="pb-login-logo">🐻</div>
            <div class="pb-login-title mb-4"><?= h(APP_NAME) ?></div>

            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <?php if ($locked): ?>
                        <i class="bi bi-lock-fill"></i>
                    <?php else: ?>
                        <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php endif; ?>
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="login.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

                <div class="mb-3">
                    <label for="username" class="form-label fw-semibold">Benutzername</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                        <input type="text" class="form-control" id="username" name="username"
                               value="<?= h($_POST['username'] ?? '') ?>"
                               autocomplete="username" required autofocus
                               <?= $locked ? 'disabled' : '' ?>>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label fw-semibold">Passwort</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               autocomplete="current-password" required
                               <?= $locked ? 'disabled' : '' ?>>
                    </div>
                </div>

                <button type="submit" class="btn btn-pb-primary w-100 py-2 fw-bold"
                        <?= $locked ? 'disabled' : '' ?>>
                    <i class="bi bi-box-arrow-in-right"></i> Anmelden
                </button>
            </form>
        </div>
        <div class="card-footer text-center text-muted small bg-transparent border-0 pt-0">
            <?= h(APP_NAME) ?> v<?= h(APP_VERSION) ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmK0qYZf4/9NcX3fGDFvHzPaFPu" crossorigin="anonymous"></script>
</body>
</html>
