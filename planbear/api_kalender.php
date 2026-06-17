<?php
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();

if (empty($_SESSION['user_id'])) {
    json_response(['error' => 'Unauthorized'], 401);
}

$pdo         = get_pdo();
$revision_id = req_int('revision_id', $_GET);

if (!$revision_id) {
    json_response(['error' => 'revision_id fehlt'], 400);
}

// Get school year for this revision
$stmt = $pdo->prepare(
    'SELECT sy.id AS sy_id, sy.start_date, sy.end_date
     FROM schedule_revisions r
     JOIN schedules s ON s.id = r.schedule_id
     JOIN school_years sy ON sy.id = s.school_year_id
     WHERE r.id = ?'
);
$stmt->execute([$revision_id]);
$sy = $stmt->fetch();
if (!$sy) {
    json_response(['error' => 'Revision nicht gefunden'], 404);
}

$events = [];

// -------------------------------------------------------------------
// 1. Employee schedule entries
// -------------------------------------------------------------------
// Load employees for color mapping
$emp_rows = $pdo->query('SELECT id, name_enc FROM employees WHERE is_active=1')->fetchAll();
$emp_names = [];
foreach ($emp_rows as $e) {
    $emp_names[$e['id']] = decrypt($e['name_enc']);
}

// Assign a color per employee (cycle through palette)
$palette = [
    '#2e7d32','#1565c0','#6a1b9a','#bf360c','#00695c',
    '#4527a0','#ad1457','#558b2f','#0277bd','#37474f',
];
$emp_colors = [];
$ci = 0;
foreach ($emp_names as $eid => $ename) {
    $emp_colors[$eid] = $palette[$ci % count($palette)];
    $ci++;
}

$stmt = $pdo->prepare(
    'SELECT employee_id, entry_date, hours FROM schedule_entries
     WHERE revision_id=? ORDER BY entry_date, employee_id'
);
$stmt->execute([$revision_id]);
$entries = $stmt->fetchAll();

// Group by date to create stacked summary events
$by_date = [];
foreach ($entries as $e) {
    $by_date[$e['entry_date']][] = $e;
}

foreach ($by_date as $date => $day_entries) {
    foreach ($day_entries as $e) {
        $eid   = $e['employee_id'];
        $ename = $emp_names[$eid] ?? 'Unbekannt';
        $h     = number_format((float)$e['hours'], 2, ',', '.');
        $events[] = [
            'id'               => 'e_' . $eid . '_' . $date,
            'title'            => $ename . ' ' . $h . 'h',
            'start'            => $date,
            'allDay'           => true,
            'backgroundColor'  => $emp_colors[$eid] ?? '#5C3317',
            'borderColor'      => $emp_colors[$eid] ?? '#5C3317',
            'textColor'        => '#fff',
            'extendedProps'    => [
                'description' => $ename . ': ' . $h . ' Stunden',
            ],
        ];
    }
}

// -------------------------------------------------------------------
// 2. Public holidays (red background)
// -------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT holiday_date, name FROM public_holidays WHERE school_year_id=?');
$stmt->execute([$sy['sy_id']]);
foreach ($stmt->fetchAll() as $hol) {
    $events[] = [
        'id'              => 'h_' . $hol['holiday_date'],
        'title'           => '🎌 ' . $hol['name'],
        'start'           => $hol['holiday_date'],
        'allDay'          => true,
        'display'         => 'background',
        'backgroundColor' => '#ffccbc',
        'borderColor'     => '#e64a19',
    ];
}

// -------------------------------------------------------------------
// 3. Vacation periods (yellow background)
// -------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT name, start_date, end_date FROM vacation_periods WHERE school_year_id=?');
$stmt->execute([$sy['sy_id']]);
foreach ($stmt->fetchAll() as $vac) {
    // FullCalendar end date is exclusive, so add 1 day
    $end_exclusive = (new DateTime($vac['end_date']))->modify('+1 day')->format('Y-m-d');
    $events[] = [
        'id'              => 'v_' . $vac['start_date'],
        'title'           => '☀️ ' . $vac['name'],
        'start'           => $vac['start_date'],
        'end'             => $end_exclusive,
        'allDay'          => true,
        'display'         => 'background',
        'backgroundColor' => '#fff9c4',
        'borderColor'     => '#f9a825',
    ];
}

json_response($events);
