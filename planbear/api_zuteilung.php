<?php
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/zuteilung.php';

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

$revision_id  = req_int('revision_id', $_POST);
$employee_id  = req_int('employee_id', $_POST);
$entry_date   = $_POST['entry_date'] ?? '';
$location_raw = $_POST['location_id'] ?? '';

if (!$revision_id || !$employee_id || !$entry_date) {
    json_response(['success' => false, 'error' => 'Fehlende Parameter'], 400);
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry_date)) {
    json_response(['success' => false, 'error' => 'Ungültiges Datumsformat'], 400);
}

$location_id = ($location_raw === '' || !ctype_digit((string)$location_raw) || (int)$location_raw <= 0)
    ? null
    : (int)$location_raw;

$pdo = get_pdo();
ensure_location_tables($pdo);

// Verify revision belongs to a real schedule (authorization check)
$stmt = $pdo->prepare('SELECT r.id FROM schedule_revisions r JOIN schedules s ON s.id=r.schedule_id WHERE r.id=?');
$stmt->execute([$revision_id]);
if (!$stmt->fetch()) {
    json_response(['success' => false, 'error' => 'Revision nicht gefunden'], 404);
}

// Verify location exists, if one was given
if ($location_id !== null) {
    $stmt = $pdo->prepare('SELECT id FROM locations WHERE id=?');
    $stmt->execute([$location_id]);
    if (!$stmt->fetch()) {
        json_response(['success' => false, 'error' => 'Ort nicht gefunden'], 404);
    }
}

// Verify the entry exists (location can only be set on an already-scheduled day)
$stmt = $pdo->prepare('SELECT id FROM schedule_entries WHERE revision_id=? AND employee_id=? AND entry_date=?');
$stmt->execute([$revision_id, $employee_id, $entry_date]);
if (!$stmt->fetch()) {
    json_response(['success' => false, 'error' => 'Kein Eintrag für diesen Tag vorhanden'], 404);
}

try {
    $stmt = $pdo->prepare(
        'UPDATE schedule_entries SET location_id=? WHERE revision_id=? AND employee_id=? AND entry_date=?'
    );
    $stmt->execute([$location_id, $revision_id, $employee_id, $entry_date]);
    json_response(['success' => true, 'location_id' => $location_id]);
} catch (PDOException $e) {
    error_log('api_zuteilung: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Datenbankfehler'], 500);
}
