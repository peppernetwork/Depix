<?php
// Entry point — redirect to dashboard or login
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();

if (!empty($_SESSION['user_id'])) {
    redirect('dashboard.php');
} else {
    redirect('login.php');
}
