<?php
// Authentication and authorization functions

/**
 * Configure and start the PHP session with secure settings.
 * Call at the very top of every page (before any output).
 */
function session_start_secure(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return; // Already started
    }

    $secure   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $lifetime = SESSION_TIMEOUT;

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_name('PLANBEAR_SESS');
    session_start();

    // Enforce session timeout
    if (isset($_SESSION['last_activity'])) {
        if ((time() - $_SESSION['last_activity']) > $lifetime) {
            session_unset();
            session_destroy();
            session_start();
        }
    }
    $_SESSION['last_activity'] = time();
}

/**
 * Require the user to be authenticated (and optionally in one of the given roles).
 * Redirects to login.php if not authenticated.
 * Sends 403 if role is insufficient.
 *
 * @param array $roles  Allowed roles. Empty = any authenticated user.
 */
function require_auth(array $roles = []): void {
    if (empty($_SESSION['user_id'])) {
        redirect('/planbear/public/login.php');
    }
    if (!empty($roles) && !has_role(...$roles)) {
        http_response_code(403);
        require __DIR__ . '/../templates/header.php';
        echo '<div class="container mt-5"><div class="alert alert-danger"><strong>Zugriff verweigert.</strong> Sie haben keine Berechtigung für diese Seite.</div></div>';
        require __DIR__ . '/../templates/footer.php';
        exit;
    }
}

/**
 * Attempt to log a user in.
 * Checks rate-limiting, verifies password, logs the attempt.
 *
 * @return bool True on success.
 */
function login_user(string $username, string $password, string $ip): bool {
    $pdo = get_pdo();

    // Fetch user record
    $stmt = $pdo->prepare('SELECT id, password_hash, role, is_active, failed_logins, locked_until FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    $user_id = $user ? $user['id'] : null;
    $success = false;

    if ($user && $user['is_active']) {
        // Check lockout
        if ($user['locked_until'] && new DateTime() < new DateTime($user['locked_until'])) {
            log_login($user_id, $ip, false);
            return false; // Still locked
        }

        // Verify password
        if (password_verify($password, $user['password_hash'])) {
            // Reset failed logins, update last_login
            $upd = $pdo->prepare('UPDATE users SET failed_logins=0, locked_until=NULL, last_login=NOW() WHERE id=?');
            $upd->execute([$user['id']]);

            // Regenerate session to prevent session fixation
            session_regenerate_id(true);

            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $username;
            $_SESSION['role']      = $user['role'];

            $success = true;
        } else {
            // Increment failed logins
            $failed = (int)$user['failed_logins'] + 1;
            if ($failed >= 5) {
                $locked = date('Y-m-d H:i:s', time() + 900); // 15 minutes
                $upd = $pdo->prepare('UPDATE users SET failed_logins=?, locked_until=? WHERE id=?');
                $upd->execute([$failed, $locked, $user['id']]);
            } else {
                $upd = $pdo->prepare('UPDATE users SET failed_logins=? WHERE id=?');
                $upd->execute([$failed, $user['id']]);
            }
        }
    }

    log_login($user_id, $ip, $success);
    return $success;
}

/**
 * Log a login attempt to login_log.
 */
function log_login(?int $user_id, string $ip, bool $success): void {
    try {
        $pdo  = get_pdo();
        $stmt = $pdo->prepare('INSERT INTO login_log (user_id, ip_address, success) VALUES (?, ?, ?)');
        $stmt->execute([$user_id, $ip, $success ? 1 : 0]);
    } catch (Exception $e) {
        error_log('log_login failed: ' . $e->getMessage());
    }
}

/**
 * Destroy session and log out.
 */
function logout_user(): void {
    session_unset();
    session_destroy();
}

/**
 * Return current user data from session, or null.
 */
function current_user(): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id'       => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role'     => $_SESSION['role'],
    ];
}

/**
 * Check if the current session user has at least one of the given roles.
 */
function has_role(string ...$roles): bool {
    if (empty($_SESSION['role'])) {
        return false;
    }
    return in_array($_SESSION['role'], $roles, true);
}

/**
 * Generate a CSRF token, store in session, return it.
 */
function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a submitted CSRF token against the session token.
 */
function verify_csrf_token(string $token): bool {
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Return an HTML hidden input with the current CSRF token.
 */
function csrf_input(): string {
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . h($token) . '">';
}

/**
 * Get client IP address (best effort).
 */
function get_client_ip(): string {
    $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            // Take the first IP from a comma-separated list
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}
