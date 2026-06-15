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
