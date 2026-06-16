<?php
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/scheduler.php';
require_once __DIR__ . '/includes/zuteilung.php';

session_start_secure();
require_auth();

$pdo = get_pdo();
ensure_location_tables($pdo);

$schedule_id = req_int('schedule_id', $_GET);
$revision_id = req_int('revision_id', $_GET);

if (!$schedule_id || !$revision_id) {
    flash('error', 'Ungültige Parameter.');
    redirect('planung.php');
}

// Load schedule
$stmt = $pdo->prepare(
    'SELECT s.id, s.name, s.school_year_id, sy.name AS year_name, sy.start_date, sy.end_date
     FROM schedules s JOIN school_years sy ON sy.id=s.school_year_id
     WHERE s.id=?'
);
$stmt->execute([$schedule_id]);
$schedule = $stmt->fetch();
if (!$schedule) { flash('error', 'Plan nicht gefunden.'); redirect('planung.php'); }

$revisions = $pdo->prepare('SELECT id, revision_number, created_at, notes FROM schedule_revisions WHERE schedule_id=? ORDER BY revision_number');
$revisions->execute([$schedule_id]);
$revisions = $revisions->fetchAll();

$stmt = $pdo->prepare('SELECT id, revision_number, created_at FROM schedule_revisions WHERE id=? AND schedule_id=?');
$stmt->execute([$revision_id, $schedule_id]);
$rev = $stmt->fetch();
if (!$rev) { flash('error', 'Revision nicht gefunden.'); redirect('planung.php'); }

$school_start = new DateTime($schedule['start_date']);
$school_end   = new DateTime($schedule['end_date']);

// Week navigation
$week_date_str = $_GET['week'] ?? $school_start->format('Y-m-d');
try { $week_monday = monday_of_week($week_date_str); }
catch (Exception $e) { $week_monday = clone $school_start; }
if ($week_monday < $school_start) $week_monday = monday_of_week($school_start->format('Y-m-d'));
if ($week_monday > $school_end)   $week_monday = monday_of_week($school_end->format('Y-m-d'));

$week_friday = (clone $week_monday)->modify('+4 days');
$prev_monday = (clone $week_monday)->modify('-7 days');
$next_monday = (clone $week_monday)->modify('+7 days');

$week_dates = [];
for ($i = 0; $i < 5; $i++) {
    $week_dates[] = (clone $week_monday)->modify("+{$i} days")->format('Y-m-d');
}

// ─── Random location assignment for the displayed week ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'zufall_zuteilen') {
    require_auth(['editor','admin']);
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        flash('error', 'Ungültige Anfrage (CSRF).');
    } else {
        $all_location_ids = array_column($pdo->query('SELECT id FROM locations')->fetchAll(), 'id');
        if (empty($all_location_ids)) {
            flash('error', 'Es sind noch keine Orte definiert.');
        } else {
            $entries_stmt = $pdo->prepare(
                'SELECT employee_id, entry_date, time_start, time_end FROM schedule_entries
                 WHERE revision_id=? AND entry_date BETWEEN ? AND ? AND hours > 0'
            );
            $entries_stmt->execute([$revision_id, $week_dates[0], $week_dates[4]]);
            $shifts_stmt = $pdo->prepare(
                'SELECT s.id, s.time_start, s.time_end FROM employee_shifts es
                 JOIN shifts s ON s.id = es.shift_id WHERE es.employee_id = ?'
            );
            $pref_cache  = [];
            $shift_cache = [];
            foreach ($entries_stmt->fetchAll() as $row) {
                $eid = (int)$row['employee_id'];
                if (!isset($pref_cache[$eid])) {
                    $pref_cache[$eid] = get_employee_location_preferences($pdo, $eid);
                }
                if (!isset($shift_cache[$eid])) {
                    $shifts_stmt->execute([$eid]);
                    $shift_cache[$eid] = $shifts_stmt->fetchAll();
                }
                clear_location_segments($pdo, $revision_id, $eid, $row['entry_date']);
                if (!empty($shift_cache[$eid])) {
                    foreach ($shift_cache[$eid] as $sh) {
                        $loc = pick_random_location($all_location_ids, $pref_cache[$eid]);
                        if ($loc !== null) {
                            add_location_segment(
                                $pdo, $revision_id, $eid, $row['entry_date'],
                                (int)$sh['id'], $loc, $sh['time_start'], $sh['time_end']
                            );
                        }
                    }
                } elseif ($row['time_start'] && $row['time_end']) {
                    $loc = pick_random_location($all_location_ids, $pref_cache[$eid]);
                    if ($loc !== null) {
                        add_location_segment(
                            $pdo, $revision_id, $eid, $row['entry_date'],
                            null, $loc, $row['time_start'], $row['time_end']
                        );
                    }
                }
            }
            flash('success', 'Orte für diese Woche zufällig zugeteilt.');
        }
    }
    redirect('planung_view.php?schedule_id=' . $schedule_id . '&revision_id=' . $revision_id . '&week=' . $week_monday->format('Y-m-d'));
}

// ─── Add / delete a single location segment ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_location_segment') {
    require_auth(['editor','admin']);
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        flash('error', 'Ungültige Anfrage (CSRF).');
    } else {
        $emp_id_p    = req_int('employee_id', $_POST);
        $entry_date  = $_POST['entry_date'] ?? '';
        $shift_id_p  = req_int('shift_id', $_POST);
        $location_id = req_int('location_id', $_POST);
        $time_start  = $_POST['time_start'] ?? '';
        $time_end    = $_POST['time_end'] ?? '';

        if (!$emp_id_p || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry_date) || !$location_id
            || !preg_match('/^\d{2}:\d{2}$/', $time_start) || !preg_match('/^\d{2}:\d{2}$/', $time_end)
            || $time_end <= $time_start) {
            flash('error', 'Ungültige Eingabe für die Zuteilung.');
        } else {
            add_location_segment(
                $pdo, $revision_id, $emp_id_p, $entry_date,
                $shift_id_p ?: null, $location_id, $time_start, $time_end
            );
            flash('success', 'Ort zugeteilt.');
        }
    }
    redirect('planung_view.php?schedule_id=' . $schedule_id . '&revision_id=' . $revision_id . '&week=' . $week_monday->format('Y-m-d'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_location_segment') {
    require_auth(['editor','admin']);
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        flash('error', 'Ungültige Anfrage (CSRF).');
    } else {
        $segment_id = req_int('segment_id', $_POST);
        if ($segment_id) {
            delete_location_segment($pdo, $segment_id, $revision_id);
            flash('success', 'Zuteilung entfernt.');
        }
    }
    redirect('planung_view.php?schedule_id=' . $schedule_id . '&revision_id=' . $revision_id . '&week=' . $week_monday->format('Y-m-d'));
}

// Holidays
$stmt = $pdo->prepare('SELECT holiday_date, name FROM public_holidays WHERE school_year_id=? AND holiday_date BETWEEN ? AND ?');
$stmt->execute([$schedule['school_year_id'], $week_dates[0], $week_dates[4]]);
$holiday_map = [];
foreach ($stmt->fetchAll() as $h) $holiday_map[$h['holiday_date']] = $h['name'];

// Vacation periods
$stmt = $pdo->prepare('SELECT name, start_date, end_date FROM vacation_periods WHERE school_year_id=? AND start_date<=? AND end_date>=?');
$stmt->execute([$schedule['school_year_id'], $week_dates[4], $week_dates[0]]);
$vacation_periods = $stmt->fetchAll();
$vacation_map = [];
foreach ($week_dates as $wd) {
    foreach ($vacation_periods as $vp) {
        if ($wd >= $vp['start_date'] && $wd <= $vp['end_date']) {
            $vacation_map[$wd] = $vp['name'];
            break;
        }
    }
}

// Employee vacations (annual leave) for this week
$stmt = $pdo->prepare(
    "SELECT employee_id, vacation_date FROM employee_vacations
     WHERE vacation_date BETWEEN ? AND ? AND status IN ('geplant','genehmigt','genommen')"
);
$stmt->execute([$week_dates[0], $week_dates[4]]);
$emp_vacation_map = []; // [emp_id][date] = true
foreach ($stmt->fetchAll() as $r) {
    $emp_vacation_map[(int)$r['employee_id']][$r['vacation_date']] = true;
}

// Employees with shift assignments
$employees = $pdo->query('SELECT id, name_enc FROM employees WHERE is_active=1 ORDER BY id')->fetchAll();

$emp_shifts_all = [];
$rows = $pdo->query(
    'SELECT es.employee_id, s.id AS shift_id, s.name, s.short_name, s.color, s.time_start, s.time_end
     FROM employee_shifts es JOIN shifts s ON s.id=es.shift_id ORDER BY s.sort_order'
)->fetchAll();
foreach ($rows as $r) $emp_shifts_all[(int)$r['employee_id']][] = $r;

// Schedule entries with time info
$stmt = $pdo->prepare(
    'SELECT employee_id, entry_date, hours, pause_minuten, time_start, time_end, is_vacation_period
     FROM schedule_entries WHERE revision_id=? AND entry_date BETWEEN ? AND ?'
);
$stmt->execute([$revision_id, $week_dates[0], $week_dates[4]]);
$entry_map = [];
foreach ($stmt->fetchAll() as $e) {
    $entry_map[$e['employee_id']][$e['entry_date']] = $e;
}

// Locations (Zuteilung)
$locations = $pdo->query('SELECT * FROM locations ORDER BY sort_order, id')->fetchAll();

// Location/time segments (multi-location-per-day Zuteilung) for this week
$location_segments = get_location_segments_map($pdo, $revision_id, $week_dates[0], $week_dates[4]);

// All defined shifts, for the "add segment" modal's shift selector
$all_shifts = $pdo->query('SELECT id, name, short_name, time_start, time_end FROM shifts ORDER BY sort_order')->fetchAll();

$de_days = ['Mo', 'Di', 'Mi', 'Do', 'Fr'];

// Week total per employee
$week_totals = [];
foreach ($employees as $emp) {
    $total = 0;
    foreach ($week_dates as $wd) {
        $total += (float)($entry_map[$emp['id']][$wd]['hours'] ?? 0);
    }
    $week_totals[$emp['id']] = $total;
}

$page_title = 'Plan: ' . $schedule['name'];
$active_nav = 'planung';
require __DIR__ . '/templates/header.php';
?>
<input type="hidden" id="csrf-token-value" value="<?= h(generate_csrf_token()) ?>">

<!-- Header bar -->
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h1 class="fw-bold mb-0" style="color:var(--pb-dark);">
            <i class="bi bi-calendar-week"></i> <?= h($schedule['name']) ?>
        </h1>
        <small class="text-muted"><?= h($schedule['year_name']) ?> &mdash;
            Revision <?= h((string)$rev['revision_number']) ?>
            (<?= h(format_date_de($rev['created_at'])) ?>)
        </small>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <!-- Revision selector -->
        <form method="get" action="planung_view.php" class="d-flex gap-1 align-items-center">
            <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
            <input type="hidden" name="week" value="<?= h($week_monday->format('Y-m-d')) ?>">
            <select name="revision_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($revisions as $rv): ?>
                    <option value="<?= (int)$rv['id'] ?>" <?= $rv['id'] == $revision_id ? 'selected' : '' ?>>
                        Rev. <?= (int)$rv['revision_number'] ?> (<?= h(format_date_de($rv['created_at'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <!-- View toggle -->
        <div class="btn-group btn-group-sm">
            <a href="planung_view.php?schedule_id=<?= $schedule_id ?>&revision_id=<?= $revision_id ?>&week=<?= h($week_monday->format('Y-m-d')) ?>"
               class="btn btn-pb-primary active">
                <i class="bi bi-calendar-week"></i> Woche
            </a>
            <a href="planung_monat.php?schedule_id=<?= $schedule_id ?>&revision_id=<?= $revision_id ?>&month=<?= h($week_monday->format('Y-m')) ?>"
               class="btn btn-pb-outline">
                <i class="bi bi-calendar-month"></i> Monat
            </a>
        </div>

        <!-- PDF export -->
        <a href="export_pdf.php?type=woche&schedule_id=<?= $schedule_id ?>&revision_id=<?= $revision_id ?>&week=<?= h($week_monday->format('Y-m-d')) ?>"
           target="_blank" class="btn btn-sm btn-outline-danger">
            <i class="bi bi-file-earmark-pdf-fill"></i> PDF Woche
        </a>

        <?php if (has_role('editor','admin') && !empty($locations)): ?>
        <form method="post" action="planung_view.php" class="d-inline"
              onsubmit="return confirm('Orte für alle geplanten Einsätze dieser Woche zufällig zuteilen? Bereits gesetzte Orte werden überschrieben.');">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="zufall_zuteilen">
            <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
            <input type="hidden" name="revision_id" value="<?= $revision_id ?>">
            <button type="submit" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-shuffle"></i> Orte zufällig zuteilen
            </button>
        </form>
        <?php endif; ?>

        <a href="planung.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Übersicht
        </a>
    </div>
</div>

<!-- Week navigation -->
<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
    <?php if ($prev_monday >= $school_start): ?>
    <a href="planung_view.php?schedule_id=<?= $schedule_id ?>&revision_id=<?= $revision_id ?>&week=<?= $prev_monday->format('Y-m-d') ?>"
       class="btn btn-sm btn-pb-outline"><i class="bi bi-chevron-left"></i> Vorwoche</a>
    <?php else: ?>
    <button class="btn btn-sm btn-outline-secondary" disabled><i class="bi bi-chevron-left"></i> Vorwoche</button>
    <?php endif; ?>

    <div class="fw-semibold">
        KW&nbsp;<?= $week_monday->format('W') ?>
        &mdash;
        <?= h(format_date_de($week_dates[0])) ?> bis <?= h(format_date_de($week_dates[4])) ?>
    </div>

    <form method="get" action="planung_view.php" class="d-flex align-items-center gap-2">
        <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
        <input type="hidden" name="revision_id" value="<?= $revision_id ?>">
        <input type="week" name="week" class="form-control form-control-sm" style="width:auto;"
               value="<?= $week_monday->format('Y') . '-W' . str_pad($week_monday->format('W'), 2, '0', STR_PAD_LEFT) ?>"
               onchange="
                 var p=this.value.split('-W');
                 var d=new Date(p[0],0,1+(p[1]-1)*7);
                 d.setDate(d.getDate()-(d.getDay()===0?6:d.getDay()-1));
                 window.location='planung_view.php?schedule_id=<?= $schedule_id ?>&revision_id=<?= $revision_id ?>&week='+d.toISOString().slice(0,10);">
    </form>

    <?php if ($next_monday <= $school_end): ?>
    <a href="planung_view.php?schedule_id=<?= $schedule_id ?>&revision_id=<?= $revision_id ?>&week=<?= $next_monday->format('Y-m-d') ?>"
       class="btn btn-sm btn-pb-outline">Nächste Woche <i class="bi bi-chevron-right"></i></a>
    <?php else: ?>
    <button class="btn btn-sm btn-outline-secondary" disabled>Nächste Woche <i class="bi bi-chevron-right"></i></button>
    <?php endif; ?>

    <?php if (!empty($vacation_map)): ?>
        <span class="badge" style="background:#ffe08a;color:#7a5c00;">
            <i class="bi bi-sun-fill"></i> <?= h(array_values($vacation_map)[0]) ?>
        </span>
    <?php endif; ?>
</div>

<!-- Legend -->
<div class="d-flex gap-2 mb-3 flex-wrap">
    <span class="badge bg-danger bg-opacity-75">Feiertag</span>
    <span class="badge" style="background:#ffe08a;color:#7a5c00;">Schulferien</span>
    <span class="badge bg-info text-dark">Urlaub MA</span>
    <span class="badge bg-success bg-opacity-75">Einsatz geplant</span>
</div>

<!-- Weekly schedule table -->
<div class="table-responsive">
    <table class="table pb-schedule-table table-bordered align-middle">
        <thead>
            <tr>
                <th style="min-width:140px;">Mitarbeiter</th>
                <?php foreach ($week_dates as $i => $wd): ?>
                <th class="text-center" style="min-width:110px;">
                    <div class="fw-bold"><?= $de_days[$i] ?></div>
                    <div class="small"><?= h(format_date_de($wd)) ?></div>
                    <?php if (isset($holiday_map[$wd])): ?>
                        <div><span class="badge bg-danger small"><?= h($holiday_map[$wd]) ?></span></div>
                    <?php elseif (isset($vacation_map[$wd])): ?>
                        <div><span class="badge small" style="background:#ffe08a;color:#7a5c00;">Schulferien</span></div>
                    <?php endif; ?>
                </th>
                <?php endforeach; ?>
                <th class="text-center" style="min-width:70px;">Gesamt</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($employees as $emp):
                $eid   = (int)$emp['id'];
                $name  = decrypt($emp['name_enc']);
                $shifts = $emp_shifts_all[$eid] ?? [];
            ?>
            <tr>
                <td class="emp-name">
                    <div><?= h($name) ?></div>
                </td>

                <?php foreach ($week_dates as $di => $wd):
                    $is_holiday  = isset($holiday_map[$wd]);
                    $is_ferien   = isset($vacation_map[$wd]);
                    $is_urlaub   = isset($emp_vacation_map[$eid][$wd]);
                    $entry       = $entry_map[$eid][$wd] ?? null;
                    $can_edit    = has_role('editor','admin');
                    $day_shifts  = $shifts;
                    $day_segs    = $location_segments[$eid][$wd] ?? [];
                ?>
                    <?php if ($is_holiday): ?>
                    <td class="holiday text-center small">
                        <i class="bi bi-calendar-x text-danger"></i><br>Feiertag
                    </td>

                    <?php elseif ($is_urlaub): ?>
                    <td class="text-center small" style="background:#e8f4fd;color:#0a58ca;">
                        <i class="bi bi-umbrella-fill"></i><br>Urlaub
                    </td>

                    <?php elseif ($entry): ?>
                    <td class="entry text-center small <?= $can_edit ? 'editable' : '' ?>"
                        <?php if ($can_edit): ?>
                            data-hours="<?= h(number_format((float)$entry['hours'], 2, '.', '')) ?>"
                            data-revision-id="<?= $revision_id ?>"
                            data-employee-id="<?= $eid ?>"
                            data-date="<?= h($wd) ?>"
                        <?php endif; ?>>
                        <?php if ($entry['time_start'] && $entry['time_end']): ?>
                            <div class="fw-semibold text-nowrap">
                                <?= h(substr($entry['time_start'],0,5)) ?>–<?= h(substr($entry['time_end'],0,5)) ?>
                            </div>
                        <?php endif; ?>
                        <div class="fw-bold"><?= h(number_format((float)$entry['hours'], 2, ',', '.')) ?> h</div>
                        <?php if ((int)$entry['pause_minuten'] > 0): ?>
                            <div class="text-muted" style="font-size:0.72rem;">
                                <i class="bi bi-cup-hot"></i> <?= (int)$entry['pause_minuten'] ?> min Pause
                            </div>
                        <?php endif; ?>
                        <?php if ($is_ferien): ?>
                            <div class="text-warning-emphasis" style="font-size:0.7rem;">Ferien</div>
                        <?php endif; ?>

                        <?php if (!empty($day_shifts)): ?>
                        <div class="mt-1 d-flex flex-wrap gap-1 justify-content-center">
                            <?php foreach ($day_shifts as $sh): ?>
                                <span class="badge" style="background:<?= h($sh['color']) ?>;font-size:0.62rem;">
                                    <?= h($sh['short_name']) ?> <?= h(substr($sh['time_start'],0,5)) ?>–<?= h(substr($sh['time_end'],0,5)) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($day_segs)): ?>
                        <div class="mt-1 d-flex flex-wrap gap-1 justify-content-center">
                            <?php foreach ($day_segs as $seg): ?>
                                <span class="badge" style="background:<?= h($seg['location_color']) ?>;font-size:0.62rem;">
                                    <i class="bi bi-geo-alt-fill"></i> <?= h($seg['location_name']) ?>
                                    <?= h(substr($seg['time_start'],0,5)) ?>–<?= h(substr($seg['time_end'],0,5)) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($can_edit && !empty($locations)): ?>
                        <div class="mt-1" onclick="event.stopPropagation();">
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" style="font-size:0.62rem;"
                                    data-bs-toggle="modal" data-bs-target="#locSegModal"
                                    data-employee-id="<?= $eid ?>"
                                    data-employee-name="<?= h($name) ?>"
                                    data-date="<?= h($wd) ?>"
                                    data-date-label="<?= h($de_days[$di] . ' ' . format_date_de($wd)) ?>"
                                    data-segments='<?= h(json_encode(array_map(fn($s) => [
                                        'id'               => (int)$s['id'],
                                        'location_name'    => $s['location_name'],
                                        'location_color'   => $s['location_color'],
                                        'shift_short_name' => $s['shift_short_name'],
                                        'time_start'       => substr($s['time_start'], 0, 5),
                                        'time_end'         => substr($s['time_end'], 0, 5),
                                    ], $day_segs))) ?>'>
                                <i class="bi bi-geo-alt"></i> Orte
                            </button>
                        </div>
                        <?php endif; ?>
                    </td>

                    <?php elseif ($is_ferien && !$entry): ?>
                    <td class="vacation text-center small">
                        <i class="bi bi-sun-fill"></i><br>Ferien
                    </td>

                    <?php else: ?>
                    <td class="text-center text-muted <?= $can_edit ? 'editable' : '' ?>"
                        <?php if ($can_edit): ?>
                            data-hours="0" data-revision-id="<?= $revision_id ?>"
                            data-employee-id="<?= $eid ?>" data-date="<?= h($wd) ?>"
                        <?php endif; ?>>
                        <span style="font-size:1.1rem;">—</span>
                    </td>
                    <?php endif; ?>

                <?php endforeach; ?>

                <td class="text-center fw-bold small">
                    <?php $t = $week_totals[$eid] ?? 0; ?>
                    <?= $t > 0 ? h(number_format($t, 2, ',', '.')) . ' h' : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="table-light">
                <td class="fw-semibold small">Summe</td>
                <?php foreach ($week_dates as $wd):
                    $day_total = 0;
                    foreach ($employees as $emp) {
                        $day_total += (float)($entry_map[$emp['id']][$wd]['hours'] ?? 0);
                    }
                ?>
                <td class="text-center fw-semibold small">
                    <?= $day_total > 0 ? h(number_format($day_total, 1, ',', '.')) . ' h' : '—' ?>
                </td>
                <?php endforeach; ?>
                <td class="text-center fw-bold small">
                    <?php $grand = array_sum($week_totals); ?>
                    <?= $grand > 0 ? h(number_format($grand, 1, ',', '.')) . ' h' : '—' ?>
                </td>
            </tr>
        </tfoot>
    </table>
</div>

<?php if (has_role('editor','admin')): ?>
<div class="mt-2 text-muted small">
    <i class="bi bi-pencil-square"></i> Zelle anklicken, um Stunden direkt zu bearbeiten.
</div>

<!-- Location/time segment management modal -->
<div class="modal fade" id="locSegModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--pb-dark);color:var(--pb-light);">
                <h5 class="modal-title">
                    <i class="bi bi-geo-alt-fill"></i> Orte zuteilen
                    &mdash; <span id="locSegEmpName"></span>, <span id="locSegDateLabel"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="locSegList" class="mb-3"></div>
                <hr>
                <form method="post" action="planung_view.php" id="locSegAddForm" class="row g-2">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="add_location_segment">
                    <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
                    <input type="hidden" name="revision_id" value="<?= $revision_id ?>">
                    <input type="hidden" name="employee_id" id="locSegEmployeeId" value="">
                    <input type="hidden" name="entry_date" id="locSegEntryDate" value="">
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Schicht (optional)</label>
                        <select name="shift_id" id="locSegShift" class="form-select form-select-sm">
                            <option value="">— keine —</option>
                            <?php foreach ($all_shifts as $sh): ?>
                                <option value="<?= (int)$sh['id'] ?>"
                                        data-start="<?= h(substr($sh['time_start'], 0, 5)) ?>"
                                        data-end="<?= h(substr($sh['time_end'], 0, 5)) ?>">
                                    <?= h($sh['name']) ?> (<?= h(substr($sh['time_start'], 0, 5)) ?>–<?= h(substr($sh['time_end'], 0, 5)) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Von</label>
                        <input type="time" name="time_start" id="locSegTimeStart" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Bis</label>
                        <input type="time" name="time_end" id="locSegTimeEnd" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Ort</label>
                        <select name="location_id" id="locSegLocation" class="form-select form-select-sm" required>
                            <option value="">— bitte wählen —</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?= (int)$loc['id'] ?>"><?= h($loc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" class="btn btn-sm btn-pb-primary"><i class="bi bi-plus-circle"></i> Hinzufügen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<form method="post" action="planung_view.php" id="locSegDeleteForm" class="d-none">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="delete_location_segment">
    <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
    <input type="hidden" name="revision_id" value="<?= $revision_id ?>">
    <input type="hidden" name="segment_id" id="locSegDeleteId" value="">
</form>
<?php endif; ?>

<?php require __DIR__ . '/templates/footer.php'; ?>
