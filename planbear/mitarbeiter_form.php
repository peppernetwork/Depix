<?php
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/zuteilung.php';

session_start_secure();
require_auth(['editor','admin']);

$pdo    = get_pdo();
ensure_location_tables($pdo);
ensure_max_weekly_hours_column($pdo);
ensure_employee_shift_times_table($pdo);
$emp_id = req_int('id', $_GET);
$is_edit = ($emp_id !== null);
$emp     = null;

if ($is_edit) {
    $stmt = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
    $stmt->execute([$emp_id]);
    $emp = $stmt->fetch();
    if (!$emp) {
        flash('error', 'Mitarbeiter nicht gefunden.');
        redirect('mitarbeiter.php');
    }
}

// Load all defined shifts + which are assigned to this employee
$all_shifts = $pdo->query('SELECT * FROM shifts ORDER BY sort_order, id')->fetchAll();
$shift_names = [];
foreach ($all_shifts as $sh) { $shift_names[(int)$sh['id']] = $sh['name']; }

$emp_shift_ids = [];
$emp_shift_times = ['week' => [], 'day' => []];
if ($is_edit && $emp_id) {
    $stmt = $pdo->prepare('SELECT shift_id FROM employee_shifts WHERE employee_id = ?');
    $stmt->execute([$emp_id]);
    $emp_shift_ids = array_column($stmt->fetchAll(), 'shift_id');
    $emp_shift_times = get_employee_shift_times($pdo, $emp_id);
}

// Load all defined locations + this employee's preferred ones
$all_locations = $pdo->query('SELECT * FROM locations ORDER BY sort_order, id')->fetchAll();
$emp_location_ids = ($is_edit && $emp_id) ? get_employee_location_preferences($pdo, $emp_id) : [];

// Load global Betreuungszeit for placeholder (Schichten → Rolle "Kernarbeitszeit" wins, if set)
ensure_shift_slot_column($pdo);
$bz_shift = get_slot_shift($pdo, 'betreuungszeit');
$bz_start = $bz_shift ? substr($bz_shift['time_start'], 0, 5) : get_setting($pdo, 'betreuungszeit_start', '12:00');
$bz_end   = $bz_shift ? substr($bz_shift['time_end'],   0, 5) : get_setting($pdo, 'betreuungszeit_end',   '15:30');

$errors = [];
$form   = [
    'name'               => $is_edit ? decrypt($emp['name_enc']) : '',
    'vacation_hours'     => $is_edit ? (string)$emp['vacation_hours'] : '0',
    'max_weekly_hours'   => $is_edit && $emp['max_weekly_hours'] !== null ? (string)$emp['max_weekly_hours'] : '',
    'available_days'     => $is_edit ? explode(',', $emp['available_days']) : ['0','1','2','3','4'],
    'time_mode'          => $is_edit ? ($emp['time_mode'] ?? 'full') : 'full',
    'shift_week_times'   => $emp_shift_times['week'],
    'shift_day_times'    => $emp_shift_times['day'],
    'pause_minuten'      => $is_edit ? $emp['pause_minuten']      : null,
    'urlaub_zusatz_tage' => $is_edit ? (int)$emp['urlaub_zusatz_tage'] : 0,
];

// System defaults for placeholder text
$sys_pause_min = get_setting($pdo, 'pause_dauer_minuten', '30');
$sys_pause_ab  = get_setting($pdo, 'pause_ab_stunden',    '6');
$sys_url_std   = get_setting($pdo, 'urlaub_standard_tage', '20');

// ─── POST handler ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Ungültige Anfrage (CSRF).';
    } else {
        $name           = trim($_POST['name'] ?? '');
        $vacay          = $_POST['vacation_hours'] ?? '0';
        $max_weekly_raw = trim($_POST['max_weekly_hours'] ?? '');
        $days           = $_POST['available_days'] ?? [];
        $time_mode      = $_POST['time_mode'] ?? 'full';
        // Pause: empty string → NULL (use system default)
        $pause_raw      = trim($_POST['pause_minuten'] ?? '');
        $pause_val      = ($pause_raw === '') ? null : (int)$pause_raw;
        // Urlaub
        $url_zusatz     = max(0, (int)($_POST['urlaub_zusatz_tage'] ?? 0));

        // Collect and validate selected shifts (needed up-front: the per-shift
        // time overrides below are only relevant/required for selected shifts).
        $selected_shift_ids = array_map('intval', array_filter($_POST['shift_ids'] ?? [], 'is_numeric'));
        $valid_shift_ids    = array_column($all_shifts, 'id');
        $selected_shift_ids = array_values(array_intersect($selected_shift_ids, $valid_shift_ids));

        // Collect per-shift time overrides from POST ('week' mode: one time per
        // shift for the whole week; 'day' mode: one time per shift per weekday).
        $posted_shift_week_times = [];
        $posted_shift_day_times  = [];
        foreach ($selected_shift_ids as $sid) {
            $ss = trim($_POST["shift_week_time_start_{$sid}"] ?? '');
            $se = trim($_POST["shift_week_time_end_{$sid}"]   ?? '');
            if ($ss !== '' && $se !== '') {
                $posted_shift_week_times[$sid] = ['start' => $ss, 'end' => $se];
            }
            foreach (['0','1','2','3','4'] as $d) {
                $ds = trim($_POST["shift_day_time_start_{$d}_{$sid}"] ?? '');
                $de = trim($_POST["shift_day_time_end_{$d}_{$sid}"]   ?? '');
                if ($ds !== '' && $de !== '') {
                    $posted_shift_day_times[(int)$d][$sid] = ['start' => $ds, 'end' => $de];
                }
            }
        }

        // Validate
        if ($name === '') {
            $errors[] = 'Name ist ein Pflichtfeld.';
        }
        if (!is_numeric($vacay) || (float)$vacay < 0) {
            $errors[] = 'Ferienstunden muss eine positive Zahl oder 0 sein.';
        }
        $max_weekly_hours = null;
        if ($max_weekly_raw !== '') {
            if (!is_numeric($max_weekly_raw) || (float)$max_weekly_raw <= 0) {
                $errors[] = 'Maximale Arbeitszeit pro Woche muss eine Zahl größer 0 sein (oder leer = kein Limit).';
            } else {
                $max_weekly_hours = (float)$max_weekly_raw;
            }
        }
        $valid_days = ['0','1','2','3','4'];
        $days       = array_intersect((array)$days, $valid_days);
        if (empty($days)) {
            $errors[] = 'Mindestens ein verfügbarer Tag muss ausgewählt sein.';
        }
        if (!in_array($time_mode, ['full','week','day'], true)) {
            $time_mode = 'full';
        }
        // Validate time ranges for 'week' mode: requires at least one selected
        // shift, with a time entered per shift (no more generic fallback time).
        if ($time_mode === 'week') {
            if (empty($selected_shift_ids)) {
                $errors[] = 'Für „Individuell (ganze Woche)" muss mindestens ein Dienst ausgewählt sein.';
            } else {
                foreach ($selected_shift_ids as $sid) {
                    $sname = $shift_names[$sid] ?? "Dienst #{$sid}";
                    if (!isset($posted_shift_week_times[$sid])) {
                        $errors[] = 'Bitte Zeit für ' . $sname . ' angeben.';
                    } elseif ($posted_shift_week_times[$sid]['start'] >= $posted_shift_week_times[$sid]['end']) {
                        $errors[] = 'Startzeit muss vor Endzeit liegen (' . $sname . ').';
                    }
                }
            }
        }
        // Validate per-day times for 'day' mode: same idea, per shift per weekday.
        if ($time_mode === 'day') {
            $day_names = ['Mo','Di','Mi','Do','Fr'];
            if (empty($selected_shift_ids)) {
                $errors[] = 'Für „Individuell (pro Tag)" muss mindestens ein Dienst ausgewählt sein.';
            } else {
                foreach ($days as $d) {
                    $di = (int)$d;
                    foreach ($selected_shift_ids as $sid) {
                        $sname = $shift_names[$sid] ?? "Dienst #{$sid}";
                        if (!isset($posted_shift_day_times[$di][$sid])) {
                            $errors[] = 'Bitte Zeit für ' . $sname . ' am ' . $day_names[$di] . ' angeben.';
                        } elseif ($posted_shift_day_times[$di][$sid]['start'] >= $posted_shift_day_times[$di][$sid]['end']) {
                            $errors[] = 'Startzeit muss vor Endzeit liegen (' . $sname . ', ' . $day_names[$di] . ').';
                        }
                    }
                }
            }
        }

        $form = [
            'name'               => $name,
            'vacation_hours'     => $vacay,
            'max_weekly_hours'   => $max_weekly_raw,
            'available_days'     => $days,
            'time_mode'          => $time_mode,
            'shift_week_times'   => $posted_shift_week_times,
            'shift_day_times'    => $posted_shift_day_times,
            'pause_minuten'      => $pause_val,
            'urlaub_zusatz_tage' => $url_zusatz,
        ];

        // Collect and validate preferred locations
        $selected_location_ids = array_map('intval', array_filter($_POST['location_ids'] ?? [], 'is_numeric'));
        $valid_location_ids    = array_column($all_locations, 'id');
        $selected_location_ids = array_values(array_intersect($selected_location_ids, $valid_location_ids));

        if (empty($errors)) {
            $name_enc = encrypt($name);
            $days_str = implode(',', $days);

            $pdo->beginTransaction();
            try {
                if ($is_edit) {
                    $stmt = $pdo->prepare(
                        'UPDATE employees SET name_enc=?, vacation_hours=?, max_weekly_hours=?, available_days=?,
                         time_mode=?, week_time_start=?, week_time_end=?,
                         pause_minuten=?, urlaub_zusatz_tage=? WHERE id=?'
                    );
                    $stmt->execute([
                        $name_enc, (float)$vacay, $max_weekly_hours, $days_str,
                        $time_mode,
                        null,
                        null,
                        $pause_val,
                        $url_zusatz,
                        $emp_id,
                    ]);
                    $pdo->prepare('DELETE FROM employee_day_times WHERE employee_id=?')->execute([$emp_id]);
                    $pdo->prepare('DELETE FROM employee_shifts WHERE employee_id=?')->execute([$emp_id]);
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO employees (name_enc, vacation_hours, max_weekly_hours, available_days,
                         time_mode, week_time_start, week_time_end,
                         pause_minuten, urlaub_zusatz_tage) VALUES (?,?,?,?,?,?,?,?,?)'
                    );
                    $stmt->execute([
                        $name_enc, (float)$vacay, $max_weekly_hours, $days_str,
                        $time_mode,
                        null,
                        null,
                        $pause_val,
                        $url_zusatz,
                    ]);
                    $emp_id = (int)$pdo->lastInsertId();
                }

                // Save shift assignments
                if (!empty($selected_shift_ids)) {
                    $sh_stmt = $pdo->prepare('INSERT IGNORE INTO employee_shifts (employee_id, shift_id) VALUES (?,?)');
                    foreach ($selected_shift_ids as $sid) {
                        $sh_stmt->execute([$emp_id, $sid]);
                    }
                }

                // Save per-shift time overrides (only meaningful in 'week'/'day'
                // mode with shifts selected; 'full' mode always uses the shift's
                // own global time).
                $pdo->prepare('DELETE FROM employee_shift_times WHERE employee_id=?')->execute([$emp_id]);
                if (!empty($selected_shift_ids)) {
                    $st_stmt = $pdo->prepare(
                        'INSERT INTO employee_shift_times (employee_id, shift_id, day_of_week, time_start, time_end) VALUES (?,?,?,?,?)'
                    );
                    if ($time_mode === 'week') {
                        foreach ($selected_shift_ids as $sid) {
                            if (isset($posted_shift_week_times[$sid])) {
                                $st_stmt->execute([$emp_id, $sid, null, $posted_shift_week_times[$sid]['start'], $posted_shift_week_times[$sid]['end']]);
                            }
                        }
                    } elseif ($time_mode === 'day') {
                        foreach ($days as $d) {
                            $di = (int)$d;
                            foreach ($selected_shift_ids as $sid) {
                                if (isset($posted_shift_day_times[$di][$sid])) {
                                    $st_stmt->execute([$emp_id, $sid, $di, $posted_shift_day_times[$di][$sid]['start'], $posted_shift_day_times[$di][$sid]['end']]);
                                }
                            }
                        }
                    }
                }

                // Save preferred locations
                set_employee_location_preferences($pdo, $emp_id, $selected_location_ids);

                $pdo->commit();
                flash('success', $is_edit ? 'Mitarbeiter aktualisiert.' : 'Mitarbeiter angelegt.');
                redirect('mitarbeiter.php');
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Datenbankfehler: ' . $e->getMessage();
            }
        }
        // Keep selections for re-display after error
        $emp_shift_ids    = $selected_shift_ids;
        $emp_location_ids = $selected_location_ids;
    }
}

$day_labels = [0=>'Mo', 1=>'Di', 2=>'Mi', 3=>'Do', 4=>'Fr'];
$page_title = $is_edit ? 'Mitarbeiter bearbeiten' : 'Mitarbeiter anlegen';
$active_nav = 'mitarbeiter';
require __DIR__ . '/templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="fw-bold mb-0" style="color:var(--pb-dark);">
        <i class="bi bi-person-<?= $is_edit ? 'gear' : 'plus' ?>-fill"></i>
        <?= $is_edit ? 'Mitarbeiter bearbeiten' : 'Neuer Mitarbeiter' ?>
    </h1>
    <a href="mitarbeiter.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
                <li><?= h($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card pb-card" style="max-width:680px;">
    <div class="card-header">
        <i class="bi bi-person-lines-fill"></i>
        <?= $is_edit ? 'Daten bearbeiten' : 'Neuen Mitarbeiter anlegen' ?>
    </div>
    <div class="card-body">
        <form method="post" action="mitarbeiter_form.php<?= $is_edit ? '?id=' . $emp_id : '' ?>" novalidate id="emp-form">
            <?= csrf_input() ?>

            <!-- Name -->
            <div class="mb-3">
                <label for="name" class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="name" name="name"
                       value="<?= h($form['name']) ?>" required maxlength="200"
                       placeholder="Vor- und Nachname">
            </div>

            <!-- Ferienstunden -->
            <div class="mb-3">
                <label for="vacation_hours" class="form-label fw-semibold">Ferienstunden (pro Woche)</label>
                <div class="input-group" style="max-width:200px;">
                    <input type="number" class="form-control" id="vacation_hours" name="vacation_hours"
                           value="<?= h((string)$form['vacation_hours']) ?>"
                           min="0" max="60" step="0.5" required>
                    <span class="input-group-text">h</span>
                </div>
                <div class="form-text">0 = kein Dienst in Schulferien</div>
            </div>

            <!-- Maximale Arbeitszeit pro Woche -->
            <div class="mb-3">
                <label for="max_weekly_hours" class="form-label fw-semibold">Maximale Arbeitszeit (pro Woche)</label>
                <div class="input-group" style="max-width:200px;">
                    <input type="number" class="form-control" id="max_weekly_hours" name="max_weekly_hours"
                           value="<?= h($form['max_weekly_hours']) ?>"
                           min="0.5" max="60" step="0.5" placeholder="kein Limit">
                    <span class="input-group-text">h</span>
                </div>
                <div class="form-text">Leer = kein Limit. Bei der Planung wird gewarnt, wenn zu viel oder zu wenig eingeplant ist.</div>
            </div>

            <hr class="my-4">

            <!-- Verfügbare Tage -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Verfügbare Arbeitstage <span class="text-danger">*</span></label>
                <div class="d-flex gap-3 flex-wrap">
                    <?php foreach ($day_labels as $idx => $label): ?>
                    <div class="form-check">
                        <input class="form-check-input day-check" type="checkbox"
                               name="available_days[]" value="<?= $idx ?>"
                               id="day_<?= $idx ?>"
                               <?= in_array((string)$idx, array_map('strval', $form['available_days'])) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold fs-5" for="day_<?= $idx ?>"><?= $label ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <hr class="my-4">

            <!-- ── Dienste (Schichten) ─────────────────────────────── -->
            <?php if (!empty($all_shifts)): ?>
            <div class="mb-3">
                <label class="form-label fw-semibold">
                    Dienste / Schichten
                    <span class="text-muted fw-normal small">(1, 2 oder 3 Dienste wählbar)</span>
                </label>
                <div class="d-flex flex-column gap-2" id="shift-checkboxes">
                    <?php foreach ($all_shifts as $sh):
                        $checked = in_array((int)$sh['id'], array_map('intval', $emp_shift_ids));
                        [$ss_h,$ss_m] = array_map('intval', explode(':', substr($sh['time_start'],0,5)));
                        [$se_h,$se_m] = array_map('intval', explode(':', substr($sh['time_end'],  0,5)));
                        $dur_min = ($se_h*60+$se_m) - ($ss_h*60+$ss_m);
                        $dur_h   = intdiv($dur_min, 60);
                        $dur_m   = $dur_min % 60;
                        $dur_str = ($dur_h > 0 ? $dur_h.'h ' : '') . ($dur_m > 0 ? $dur_m.'min' : '');
                    ?>
                    <div class="form-check d-flex align-items-center gap-2">
                        <input class="form-check-input shift-check" type="checkbox"
                               name="shift_ids[]" value="<?= (int)$sh['id'] ?>"
                               id="shift_<?= (int)$sh['id'] ?>"
                               <?= $checked ? 'checked' : '' ?>>
                        <label class="form-check-label d-flex align-items-center gap-2 cursor-pointer"
                               for="shift_<?= (int)$sh['id'] ?>">
                            <span class="badge" style="background:<?= h($sh['color']) ?>;min-width:2.5rem;">
                                <?= h($sh['short_name']) ?>
                            </span>
                            <span class="fw-semibold"><?= h($sh['name']) ?></span>
                            <span class="text-muted small">
                                <?= h(substr($sh['time_start'],0,5)) ?>–<?= h(substr($sh['time_end'],0,5)) ?> Uhr
                                (<?= h(trim($dur_str)) ?>)
                            </span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-text mt-1">
                    Gesamt: <strong id="shift-total-hours">—</strong>
                    &nbsp;·&nbsp;
                    Bei „Voll" gelten die Standardzeiten der Dienste. Für „Individuell" muss hier mindestens ein Dienst ausgewählt sein &ndash; die Zeit wird dann unten je Dienst festgelegt.
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Bevorzugte Orte (Zuteilung) ─────────────────────── -->
            <?php if (!empty($all_locations)): ?>
            <div class="mb-3">
                <label class="form-label fw-semibold">
                    Bevorzugte Orte
                    <span class="text-muted fw-normal small">(für die Zuteilung, Mehrfachauswahl möglich)</span>
                </label>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($all_locations as $loc):
                        $checked = in_array((int)$loc['id'], array_map('intval', $emp_location_ids));
                    ?>
                    <div class="form-check d-flex align-items-center gap-2">
                        <input class="form-check-input" type="checkbox"
                               name="location_ids[]" value="<?= (int)$loc['id'] ?>"
                               id="loc_<?= (int)$loc['id'] ?>"
                               <?= $checked ? 'checked' : '' ?>>
                        <label class="form-check-label d-flex align-items-center gap-2 cursor-pointer"
                               for="loc_<?= (int)$loc['id'] ?>">
                            <span class="badge" style="background:<?= h($loc['color']) ?>;">&nbsp;</span>
                            <?= h($loc['name']) ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-text mt-1">
                    Ohne Auswahl wird bei der Zufallszuteilung aus allen Orten gewählt.
                </div>
            </div>
            <?php endif; ?>

            <hr class="my-4">

            <!-- Zeitmodus -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Arbeitszeit-Modus <span class="text-danger">*</span></label>
                <div class="d-flex flex-column gap-2">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="time_mode" id="tm_full" value="full"
                               <?= $form['time_mode'] === 'full' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="tm_full">
                            <strong>Voll</strong> &mdash; Globale Betreuungszeit
                            <span class="text-muted small">(aktuell <?= h($bz_start) ?>–<?= h($bz_end) ?> Uhr)</span>
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="time_mode" id="tm_week" value="week"
                               <?= $form['time_mode'] === 'week' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="tm_week">
                            <strong>Individuell (ganze Woche)</strong> &mdash; Eine feste Zeit für alle Arbeitstage
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="time_mode" id="tm_day" value="day"
                               <?= $form['time_mode'] === 'day' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="tm_day">
                            <strong>Individuell (pro Tag)</strong> &mdash; Eigene Zeit je Wochentag
                        </label>
                    </div>
                </div>
            </div>

            <!-- Wochenzeit (für mode = 'week'): eine Zeit je ausgewähltem Dienst -->
            <div id="section-week" class="card bg-light border mb-3 p-3" style="display:none;">
                <label class="form-label fw-semibold mb-2">Arbeitszeit je Dienst (für alle Tage)</label>
                <div class="form-text mb-2" id="week-no-shift-hint" style="display:none;">
                    Bitte oben mindestens einen Dienst auswählen.
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0" style="max-width:520px;">
                        <thead class="table-light">
                            <tr><th>Dienst</th><th>Von</th><th>Bis</th><th>Dauer</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_shifts as $sh):
                                $sid = (int)$sh['id'];
                                $sv_s = $form['shift_week_times'][$sid]['start'] ?? substr($sh['time_start'], 0, 5);
                                $sv_e = $form['shift_week_times'][$sid]['end']   ?? substr($sh['time_end'],   0, 5);
                            ?>
                            <tr class="shift-time-row" data-shift-id="<?= $sid ?>" style="display:none;">
                                <td>
                                    <span class="badge" style="background:<?= h($sh['color']) ?>;"><?= h($sh['short_name']) ?></span>
                                    <?= h($sh['name']) ?>
                                </td>
                                <td>
                                    <input type="time" class="form-control form-control-sm shift-week-time-start"
                                           name="shift_week_time_start_<?= $sid ?>" data-shift-id="<?= $sid ?>"
                                           value="<?= h($sv_s) ?>">
                                </td>
                                <td>
                                    <input type="time" class="form-control form-control-sm shift-week-time-end"
                                           name="shift_week_time_end_<?= $sid ?>" data-shift-id="<?= $sid ?>"
                                           value="<?= h($sv_e) ?>">
                                </td>
                                <td class="text-muted small shift-week-duration" id="shift-week-dur-<?= $sid ?>">—</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pro-Tag-Zeiten (für mode = 'day'): eine Zeit je ausgewähltem Dienst und Tag -->
            <div id="section-day" class="mb-3" style="display:none;">
                <label class="form-label fw-semibold mb-2">Arbeitszeiten pro Tag und Dienst</label>
                <div class="form-text mb-2" id="day-no-shift-hint" style="display:none;">
                    Bitte oben mindestens einen Dienst auswählen.
                </div>

                <?php foreach ($day_labels as $idx => $label): ?>
                <div class="day-time-row card bg-light border mb-2 p-2" data-day="<?= $idx ?>"
                     <?= !in_array((string)$idx, array_map('strval', $form['available_days'])) ? 'style="opacity:0.35;pointer-events:none;"' : '' ?>>
                    <div class="fw-semibold mb-1"><?= $label ?></div>

                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <tbody>
                                <?php foreach ($all_shifts as $sh):
                                    $sid  = (int)$sh['id'];
                                    $sv_s = $form['shift_day_times'][$idx][$sid]['start'] ?? substr($sh['time_start'], 0, 5);
                                    $sv_e = $form['shift_day_times'][$idx][$sid]['end']   ?? substr($sh['time_end'],   0, 5);
                                ?>
                                <tr class="shift-time-row" data-shift-id="<?= $sid ?>" style="display:none;">
                                    <td class="align-middle">
                                        <span class="badge" style="background:<?= h($sh['color']) ?>;"><?= h($sh['short_name']) ?></span>
                                    </td>
                                    <td>
                                        <input type="time" class="form-control form-control-sm shift-day-time-start"
                                               name="shift_day_time_start_<?= $idx ?>_<?= $sid ?>"
                                               data-day="<?= $idx ?>" data-shift-id="<?= $sid ?>" value="<?= h($sv_s) ?>">
                                    </td>
                                    <td>
                                        <input type="time" class="form-control form-control-sm shift-day-time-end"
                                               name="shift_day_time_end_<?= $idx ?>_<?= $sid ?>"
                                               data-day="<?= $idx ?>" data-shift-id="<?= $sid ?>" value="<?= h($sv_e) ?>">
                                    </td>
                                    <td class="text-muted small shift-day-duration" id="shift-day-dur-<?= $idx ?>-<?= $sid ?>">—</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <hr class="my-4">

            <!-- ── Pausenzeit ──────────────────────────────────────── -->
            <div class="mb-3">
                <label class="form-label fw-semibold">
                    <i class="bi bi-cup-hot-fill text-secondary"></i>
                    Pausenzeit (individuell)
                </label>
                <div class="d-flex align-items-center gap-3">
                    <div class="input-group" style="max-width:180px;">
                        <input type="number" class="form-control" name="pause_minuten"
                               id="pause_minuten"
                               value="<?= $form['pause_minuten'] !== null ? h((string)$form['pause_minuten']) : '' ?>"
                               min="0" max="120" step="5"
                               placeholder="Standard">
                        <span class="input-group-text">min</span>
                    </div>
                    <div class="text-muted small">
                        Leer lassen = Systemstandard
                        (<?= h($sys_pause_min) ?> min ab <?= h($sys_pause_ab) ?> Std.)
                    </div>
                </div>
                <div class="form-text">0 = keine Pause</div>
            </div>

            <!-- ── Urlaubsanspruch ─────────────────────────────────── -->
            <div class="mb-4">
                <label class="form-label fw-semibold">
                    <i class="bi bi-umbrella-fill text-info"></i>
                    Zusätzliche Urlaubstage
                </label>
                <div class="d-flex align-items-center gap-3">
                    <div class="input-group" style="max-width:180px;">
                        <span class="input-group-text">+</span>
                        <input type="number" class="form-control" name="urlaub_zusatz_tage"
                               value="<?= h((string)$form['urlaub_zusatz_tage']) ?>"
                               min="0" max="365" step="1">
                        <span class="input-group-text">Tage</span>
                    </div>
                    <div class="text-muted small">
                        Gesamt: <strong><?= h($sys_url_std) ?> + X Tage</strong>
                        Standardanspruch (<?= h($sys_url_std) ?> Tage) gilt systemweit.
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-pb-primary">
                    <i class="bi bi-check-lg"></i> <?= $is_edit ? 'Speichern' : 'Anlegen' ?>
                </button>
                <a href="mitarbeiter.php" class="btn btn-outline-secondary">Abbrechen</a>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';

    // --- Time mode toggle ---
    const radios = document.querySelectorAll('input[name="time_mode"]');
    const secWeek = document.getElementById('section-week');
    const secDay  = document.getElementById('section-day');

    function updateModeVisibility() {
        const mode = document.querySelector('input[name="time_mode"]:checked')?.value ?? 'full';
        secWeek.style.display = mode === 'week' ? '' : 'none';
        secDay.style.display  = mode === 'day'  ? '' : 'none';
    }
    radios.forEach(r => r.addEventListener('change', updateModeVisibility));
    updateModeVisibility();

    // --- Day-check → dim/enable per-day rows ---
    const dayChecks = document.querySelectorAll('.day-check');
    function updateDayRows() {
        dayChecks.forEach(cb => {
            const row = document.querySelector(`.day-time-row[data-day="${cb.value}"]`);
            if (!row) return;
            if (cb.checked) {
                row.style.opacity = '';
                row.style.pointerEvents = '';
            } else {
                row.style.opacity = '0.35';
                row.style.pointerEvents = 'none';
            }
        });
    }
    dayChecks.forEach(cb => cb.addEventListener('change', updateDayRows));
    updateDayRows();

    // --- Duration display helpers ---
    function timeDiff(startVal, endVal) {
        if (!startVal || !endVal) return '—';
        const [sh, sm] = startVal.split(':').map(Number);
        const [eh, em] = endVal.split(':').map(Number);
        const mins = (eh * 60 + em) - (sh * 60 + sm);
        if (mins <= 0) return '!';
        const h = Math.floor(mins / 60);
        const m = mins % 60;
        return h > 0 ? `${h}h ${m > 0 ? m + 'min' : ''}` : `${m} min`;
    }

    // --- Shift total hours ---
    const shiftData = {
        <?php foreach ($all_shifts as $sh):
            [$ss_h,$ss_m] = array_map('intval', explode(':', substr($sh['time_start'],0,5)));
            [$se_h,$se_m] = array_map('intval', explode(':', substr($sh['time_end'],  0,5)));
            $mins = ($se_h*60+$se_m) - ($ss_h*60+$ss_m);
        ?>
        <?= (int)$sh['id'] ?>: <?= max(0, $mins) ?>,
        <?php endforeach; ?>
    };
    function updateShiftTotal() {
        let total = 0;
        document.querySelectorAll('.shift-check:checked').forEach(cb => {
            total += shiftData[parseInt(cb.value)] || 0;
        });
        const el = document.getElementById('shift-total-hours');
        if (!el) return;
        if (total <= 0) { el.textContent = '—'; return; }
        const h = Math.floor(total/60), m = total%60;
        el.textContent = (h > 0 ? h+'h ' : '') + (m > 0 ? m+'min' : '') + ' pro Tag';
    }
    document.querySelectorAll('.shift-check').forEach(cb => {
        cb.addEventListener('change', updateShiftTotal);
    });
    updateShiftTotal();

    // --- Individuelle Zeiten je Dienst (Woche/Tag-Modus) ---
    // Only show time inputs for shifts that are actually selected above.
    function updateShiftTimeRows() {
        const checkedIds = Array.from(document.querySelectorAll('.shift-check:checked')).map(cb => cb.value);
        const anyChecked = checkedIds.length > 0;

        document.querySelectorAll('.shift-time-row').forEach(row => {
            row.style.display = checkedIds.includes(row.dataset.shiftId) ? '' : 'none';
        });

        const weekHint = document.getElementById('week-no-shift-hint');
        if (weekHint) weekHint.style.display = anyChecked ? 'none' : '';
        const dayHint = document.getElementById('day-no-shift-hint');
        if (dayHint) dayHint.style.display = anyChecked ? 'none' : '';
    }
    document.querySelectorAll('.shift-check').forEach(cb => {
        cb.addEventListener('change', updateShiftTimeRows);
    });
    updateShiftTimeRows();

    // Durations for per-shift week/day time rows
    document.querySelectorAll('.shift-week-time-start, .shift-week-time-end').forEach(el => {
        el.addEventListener('change', function () {
            const sid = this.dataset.shiftId;
            const s = document.querySelector(`.shift-week-time-start[data-shift-id="${sid}"]`)?.value;
            const e = document.querySelector(`.shift-week-time-end[data-shift-id="${sid}"]`)?.value;
            const dur = document.getElementById(`shift-week-dur-${sid}`);
            if (dur) dur.textContent = timeDiff(s, e);
        });
    });
    document.querySelectorAll('.shift-week-time-start').forEach(el => el.dispatchEvent(new Event('change')));

    document.querySelectorAll('.shift-day-time-start, .shift-day-time-end').forEach(el => {
        el.addEventListener('change', function () {
            const d = this.dataset.day, sid = this.dataset.shiftId;
            const s = document.querySelector(`.shift-day-time-start[data-day="${d}"][data-shift-id="${sid}"]`)?.value;
            const e = document.querySelector(`.shift-day-time-end[data-day="${d}"][data-shift-id="${sid}"]`)?.value;
            const dur = document.getElementById(`shift-day-dur-${d}-${sid}`);
            if (dur) dur.textContent = timeDiff(s, e);
        });
    });
    document.querySelectorAll('.shift-day-time-start').forEach(el => el.dispatchEvent(new Event('change')));
})();
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
