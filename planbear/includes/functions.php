<?php
// General helper functions

/**
 * XSS-safe output helper.
 */
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Set a flash message in session (shown once on next page load).
 */
function flash(string $type, string $message): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }
}

/**
 * Render and clear flash messages. Call inside templates.
 */
function render_flash(): string {
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $html = '';
    foreach ($_SESSION['flash'] as $msg) {
        $type  = ($msg['type'] === 'success') ? 'success' : (($msg['type'] === 'warning') ? 'warning' : 'danger');
        $label = ($msg['type'] === 'success') ? 'Erfolg' : (($msg['type'] === 'warning') ? 'Hinweis' : 'Fehler');
        $html .= '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">';
        $html .= '<strong>' . h($label) . ':</strong> ' . h($msg['message']);
        $html .= '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        $html .= '</div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

/**
 * Redirect and exit.
 */
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

/**
 * Return JSON response and exit (for API endpoints).
 */
function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Parse available_days string (e.g. "0,1,2,3,4") into a labelled array.
 */
function parse_available_days(string $days_str): array {
    $map = [0 => 'Mo', 1 => 'Di', 2 => 'Mi', 3 => 'Do', 4 => 'Fr'];
    $selected = array_map('intval', explode(',', $days_str));
    return array_map(fn($d) => $map[$d] ?? '?', $selected);
}

/**
 * Format a PHP DateTime (or date string) as German short date.
 */
function format_date_de(string $date): string {
    $dt = new DateTime($date);
    return $dt->format('d.m.Y');
}

/**
 * Get week number (ISO) and year for a given date string.
 */
function iso_week_of(string $date): array {
    $dt = new DateTime($date);
    return [(int)$dt->format('W'), (int)$dt->format('o')];
}

/**
 * Return the Monday of the week containing the given date.
 */
function monday_of_week(string $date): DateTime {
    $dt = new DateTime($date);
    $dow = (int)$dt->format('N'); // 1=Mon..7=Sun
    if ($dow > 1) {
        $dt->modify('-' . ($dow - 1) . ' days');
    }
    return $dt;
}

/**
 * German day abbreviations for 0=Mon..6=Sun (PHP date N is 1=Mon..7=Sun).
 */
function day_abbr(int $n): string {
    return ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'][$n] ?? '?';
}

/**
 * Sanitize integer from request, return null if missing/invalid.
 */
function req_int(string $key, ?array $arr = null): ?int {
    $arr ??= $_REQUEST;
    if (!isset($arr[$key]) || !is_numeric($arr[$key])) {
        return null;
    }
    return (int)$arr[$key];
}

/**
 * Split a date range into Monday-Friday school weeks, aligned the same way
 * the scheduler iterates weeks over the school year.
 * Returns a list of ['start' => 'Y-m-d', 'end' => 'Y-m-d'].
 */
function split_into_school_weeks(string $start_date, string $end_date): array {
    $start = monday_of_week($start_date);
    $end   = new DateTime($end_date);

    $weeks  = [];
    $cursor = clone $start;
    while ($cursor <= $end) {
        $fri = (clone $cursor)->modify('+4 days');
        $weeks[] = ['start' => $cursor->format('Y-m-d'), 'end' => $fri->format('Y-m-d')];
        $cursor->modify('+7 days');
    }
    return $weeks;
}

/**
 * Lazily create the vacation_period_weeks table (so already-installed systems
 * pick up this feature without a manual migration step).
 */
function ensure_vacation_week_table(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS vacation_period_weeks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vacation_period_id INT NOT NULL,
            week_number INT NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            is_work_period TINYINT(1) NOT NULL DEFAULT 0,
            FOREIGN KEY (vacation_period_id) REFERENCES vacation_periods(id) ON DELETE CASCADE,
            UNIQUE KEY unique_period_week (vacation_period_id, week_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * Ensure week rows exist for a vacation period. Only inserts missing weeks;
 * never overwrites an already-set is_work_period flag.
 */
function sync_vacation_period_weeks(PDO $pdo, array $vacation_period): void {
    $weeks = split_into_school_weeks($vacation_period['start_date'], $vacation_period['end_date']);
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO vacation_period_weeks (vacation_period_id, week_number, start_date, end_date, is_work_period)
         VALUES (?, ?, ?, ?, 0)'
    );
    foreach ($weeks as $i => $week) {
        $stmt->execute([(int)$vacation_period['id'], $i + 1, $week['start'], $week['end']]);
    }
}

/** Valid shift "slot" roles that feed the global Betreuungszeiten in den Einstellungen. */
function shift_slot_labels(): array {
    return [
        'fruehdienst'    => 'Frühdienst',
        'betreuungszeit' => 'Kernarbeitszeit',
        'spaetdienst'    => 'Spätdienst',
    ];
}

/**
 * Lazily add the `slot` column to `shifts` (so already-installed systems pick
 * up the Schichten-as-source-of-truth feature without a manual migration).
 */
function ensure_shift_slot_column(PDO $pdo): void {
    $col = $pdo->query("SHOW COLUMNS FROM shifts LIKE 'slot'")->fetch();
    if (!$col) {
        $pdo->exec('ALTER TABLE shifts ADD COLUMN slot VARCHAR(20) DEFAULT NULL');
        $pdo->exec('ALTER TABLE shifts ADD UNIQUE KEY unique_slot (slot)');
    }
}

/**
 * Assign a shift to a slot role, releasing that role from any other shift
 * first (a slot may only be held by one shift at a time; NULL clears it).
 */
function assign_shift_slot(PDO $pdo, int $shift_id, ?string $slot): void {
    if ($slot !== null) {
        $pdo->prepare('UPDATE shifts SET slot = NULL WHERE slot = ? AND id != ?')->execute([$slot, $shift_id]);
    }
    $pdo->prepare('UPDATE shifts SET slot = ? WHERE id = ?')->execute([$slot, $shift_id]);
}

/** Fetch the shift currently assigned to a slot role, or null if none. */
function get_slot_shift(PDO $pdo, string $slot): ?array {
    $stmt = $pdo->prepare('SELECT * FROM shifts WHERE slot = ? LIMIT 1');
    $stmt->execute([$slot]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Lazily add `request_group_id` to `employee_vacations` (so already-installed
 * systems pick up the per-period vacation approval feature without a manual
 * migration). All dates inserted from a single "Urlaub eintragen" submission
 * share one request_group_id, allowing them to be approved/deleted together.
 */
function ensure_vacation_request_group_column(PDO $pdo): void {
    $col = $pdo->query("SHOW COLUMNS FROM employee_vacations LIKE 'request_group_id'")->fetch();
    if (!$col) {
        $pdo->exec('ALTER TABLE employee_vacations ADD COLUMN request_group_id INT DEFAULT NULL');
        $pdo->exec('ALTER TABLE employee_vacations ADD KEY idx_request_group (request_group_id)');
    }
}

/**
 * Lazily add `max_weekly_hours` to `employees` (so already-installed systems
 * pick up the weekly-hours-limit warning feature without a manual migration).
 * NULL means no limit is configured for that employee.
 */
function ensure_max_weekly_hours_column(PDO $pdo): void {
    $col = $pdo->query("SHOW COLUMNS FROM employees LIKE 'max_weekly_hours'")->fetch();
    if (!$col) {
        $pdo->exec('ALTER TABLE employees ADD COLUMN max_weekly_hours DECIMAL(5,2) DEFAULT NULL');
    }
}
