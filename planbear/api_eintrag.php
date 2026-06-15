<?php
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();

// Must be authenticated and have editor or admin role
if (empty($_SESSION['user_id'])) {
    json_response(['success' => false, 'error' => 'Nicht angemeldet'], 401);
}
if (!has_role('editor', 'admin')) {
    json_response(['success' => false, 'error' => 'Keine Berechtigung'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Nur POST erlaubt'], 405);
}

// CSRF
$token = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($token)) {
    json_response(['success' => false, 'error' => 'Ungültiges CSRF-Token'], 403);
}

$revision_id = req_int('revision_id', $_POST);
$employee_id = req_int('employee_id', $_POST);
$entry_date  = $_POST['entry_date'] ?? '';
$hours_raw   = $_POST['hours'] ?? '';

if (!$revision_id || !$employee_id || !$entry_date || $hours_raw === '') {
    json_response(['success' => false, 'error' => 'Fehlende Parameter'], 400);
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry_date)) {
    json_response(['success' => false, 'error' => 'Ungültiges Datumsformat'], 400);
}
// Validate hours
$hours = round((float)$hours_raw, 2);
if ($hours < 0 || $hours > 24) {
    json_response(['success' => false, 'error' => 'Stunden außerhalb des erlaubten Bereichs (0–24)'], 400);
}

$pdo = get_pdo();

// Verify revision belongs to a real schedule (authorization check)
$stmt = $pdo->prepare('SELECT r.id FROM schedule_revisions r JOIN schedules s ON s.id=r.schedule_id WHERE r.id=?');
$stmt->execute([$revision_id]);
if (!$stmt->fetch()) {
    json_response(['success' => false, 'error' => 'Revision nicht gefunden'], 404);
}

// Verify employee exists
$stmt = $pdo->prepare('SELECT id FROM employees WHERE id=?');
$stmt->execute([$employee_id]);
if (!$stmt->fetch()) {
    json_response(['success' => false, 'error' => 'Mitarbeiter nicht gefunden'], 404);
}

try {
    if ($hours <= 0) {
        // Delete the entry
        $stmt = $pdo->prepare('DELETE FROM schedule_entries WHERE revision_id=? AND employee_id=? AND entry_date=?');
        $stmt->execute([$revision_id, $employee_id, $entry_date]);
    } else {
        // Upsert
        $stmt = $pdo->prepare(
            'INSERT INTO schedule_entries (revision_id, employee_id, entry_date, hours, is_vacation_period)
             VALUES (?, ?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE hours = VALUES(hours)'
        );
        $stmt->execute([$revision_id, $employee_id, $entry_date, $hours]);
    }
    json_response(['success' => true, 'hours' => $hours]);
} catch (PDOException $e) {
    error_log('api_eintrag: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Datenbankfehler'], 500);
}
