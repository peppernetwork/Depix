<?php
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';

session_start_secure();
require_auth(['editor','admin']);

$pdo    = get_pdo();
$emp_id = req_int('id', $_GET);
$is_edit = ($emp_id !== null);
$emp     = null;
$day_times_db = []; // per-day times for this employee

if ($is_edit) {
    $stmt = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
    $stmt->execute([$emp_id]);
    $emp = $stmt->fetch();
    if (!$emp) {
        flash('error', 'Mitarbeiter nicht gefunden.');
        redirect('mitarbeiter.php');
    }
    // Load per-day times
    $stmt = $pdo->prepare('SELECT day_of_week, time_start, time_end FROM employee_day_times WHERE employee_id = ?');
    $stmt->execute([$emp_id]);
    foreach ($stmt->fetchAll() as $row) {
        $day_times_db[(int)$row['day_of_week']] = [
            'start' => substr($row['time_start'], 0, 5),
            'end'   => substr($row['time_end'],   0, 5),
        ];
    }
}

// Load all defined shifts + which are assigned to this employee
$all_shifts = $pdo->query('SELECT * FROM shifts ORDER BY sort_order, id')->fetchAll();
$emp_shift_ids = [];
if ($is_edit && $emp_id) {
    $stmt = $pdo->prepare('SELECT shift_id FROM employee_shifts WHERE employee_id = ?');
    $stmt->execute([$emp_id]);
    $emp_shift_ids = array_column($stmt->fetchAll(), 'shift_id');
}

// Load global Betreuungszeit for placeholder
$bz_start = get_setting($pdo, 'betreuungszeit_start', '12:00');
$bz_end   = get_setting($pdo, 'betreuungszeit_end',   '15:30');

$errors = [];
$form   = [
    'name'           => $is_edit ? decrypt($emp['name_enc']) : '',
    'vacation_hours' => $is_edit ? (string)$emp['vacation_hours'] : '0',
    'available_days' => $is_edit ? explode(',', $emp['available_days']) : ['0','1','2','3','4'],
    'time_mode'      => $is_edit ? ($emp['time_mode'] ?? 'full') : 'full',
    'week_time_start'=> $is_edit ? substr($emp['week_time_start'] ?? '', 0, 5) : $bz_start,
    'week_time_end'  => $is_edit ? substr($emp['week_time_end']   ?? '', 0, 5) : $bz_end,
    'day_times'      => $day_times_db,
];

// ─── POST handler ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Ungültige Anfrage (CSRF).';
    } else {
        $name       = trim($_POST['name'] ?? '');
        $vacay      = $_POST['vacation_hours'] ?? '0';
        $days       = $_POST['available_days'] ?? [];
        $time_mode  = $_POST['time_mode'] ?? 'full';
        $wts        = trim($_POST['week_time_start'] ?? '');
        $wte        = trim($_POST['week_time_end']   ?? '');

        // Collect per-day times from POST
        $posted_day_times = [];
        foreach (['0','1','2','3','4'] as $d) {
            $ds = trim($_POST["day_time_start_{$d}"] ?? '');
            $de = trim($_POST["day_time_end_{$d}"]   ?? '');
            if ($ds !== '' && $de !== '') {
                $posted_day_times[(int)$d] = ['start' => $ds, 'end' => $de];
            }
        }

        // Validate
        if ($name === '') {
            $errors[] = 'Name ist ein Pflichtfeld.';
        }
        if (!is_numeric($vacay) || (float)$vacay < 0) {
            $errors[] = 'Ferienstunden muss eine positive Zahl oder 0 sein.';
        }
        $valid_days = ['0','1','2','3','4'];
        $days       = array_intersect((array)$days, $valid_days);
        if (empty($days)) {
            $errors[] = 'Mindestens ein verfügbarer Tag muss ausgewählt sein.';
        }
        if (!in_array($time_mode, ['full','week','day'], true)) {
            $time_mode = 'full';
        }
        // Validate time ranges for 'week' mode
        if ($time_mode === 'week') {
            if (!preg_match('/^\d{2}:\d{2}$/', $wts) || !preg_match('/^\d{2}:\d{2}$/', $wte)) {
                $errors[] = 'Bitte gültige Zeiten für die Wochenzeit angeben (HH:MM).';
            } elseif ($wts >= $wte) {
                $errors[] = 'Startzeit muss vor der Endzeit liegen.';
            }
        }
        // Validate per-day times for 'day' mode
        if ($time_mode === 'day') {
            foreach ($days as $d) {
                $di = (int)$d;
                if (!isset($posted_day_times[$di])) {
                    $day_names = ['Mo','Di','Mi','Do','Fr'];
                    $errors[] = 'Bitte Zeiten für ' . $day_names[$di] . ' angeben.';
                } elseif ($posted_day_times[$di]['start'] >= $posted_day_times[$di]['end']) {
                    $day_names = ['Mo','Di','Mi','Do','Fr'];
                    $errors[] = 'Startzeit muss vor Endzeit liegen (' . $day_names[$di] . ').';
                }
            }
        }

        $form = [
            'name'           => $name,
            'vacation_hours' => $vacay,
            'available_days' => $days,
            'time_mode'      => $time_mode,
            'week_time_start'=> $wts,
            'week_time_end'  => $wte,
            'day_times'      => $posted_day_times,
        ];

        // Collect and validate selected shifts
        $selected_shift_ids = array_map('intval', array_filter($_POST['shift_ids'] ?? [], 'is_numeric'));
        $valid_shift_ids    = array_column($all_shifts, 'id');
        $selected_shift_ids = array_values(array_intersect($selected_shift_ids, $valid_shift_ids));

        if (empty($errors)) {
            $name_enc = encrypt($name);
            $days_str = implode(',', $days);

            $pdo->beginTransaction();
            try {
                if ($is_edit) {
                    $stmt = $pdo->prepare(
                        'UPDATE employees SET name_enc=?, vacation_hours=?, available_days=?,
                         time_mode=?, week_time_start=?, week_time_end=? WHERE id=?'
                    );
                    $stmt->execute([
                        $name_enc, (float)$vacay, $days_str,
                        $time_mode,
                        $time_mode === 'week' ? $wts : null,
                        $time_mode === 'week' ? $wte : null,
                        $emp_id,
                    ]);
                    $pdo->prepare('DELETE FROM employee_day_times WHERE employee_id=?')->execute([$emp_id]);
                    $pdo->prepare('DELETE FROM employee_shifts WHERE employee_id=?')->execute([$emp_id]);
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO employees (name_enc, vacation_hours, available_days,
                         time_mode, week_time_start, week_time_end) VALUES (?,?,?,?,?,?)'
                    );
                    $stmt->execute([
                        $name_enc, (float)$vacay, $days_str,
                        $time_mode,
                        $time_mode === 'week' ? $wts : null,
                        $time_mode === 'week' ? $wte : null,
                    ]);
                    $emp_id = (int)$pdo->lastInsertId();
                }

                // Save per-day times if mode = 'day'
                if ($time_mode === 'day' && !empty($posted_day_times)) {
                    $dt_stmt = $pdo->prepare(
                        'INSERT INTO employee_day_times (employee_id, day_of_week, time_start, time_end)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE time_start=VALUES(time_start), time_end=VALUES(time_end)'
                    );
                    foreach ($days as $d) {
                        $di = (int)$d;
                        if (isset($posted_day_times[$di])) {
                            $dt_stmt->execute([$emp_id, $di, $posted_day_times[$di]['start'], $posted_day_times[$di]['end']]);
                        }
                    }
                }

                // Save shift assignments
                if (!empty($selected_shift_ids)) {
                    $sh_stmt = $pdo->prepare('INSERT IGNORE INTO employee_shifts (employee_id, shift_id) VALUES (?,?)');
                    foreach ($selected_shift_ids as $sid) {
                        $sh_stmt->execute([$emp_id, $sid]);
                    }
                }

                $pdo->commit();
                flash('success', $is_edit ? 'Mitarbeiter aktualisiert.' : 'Mitarbeiter angelegt.');
                redirect('mitarbeiter.php');
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Datenbankfehler: ' . $e->getMessage();
            }
        }
        // Keep selected shifts for re-display after error
        $emp_shift_ids = $selected_shift_ids;
    }
}

$day_labels = [0=>'Mo', 1=>'Di', 2=>'Mi', 3=>'Do', 4=>'Fr'];
$page_title = $is_edit ? 'Mitarbeiter bearbeiten' : 'Mitarbeiter anlegen';
$active_nav = 'mitarbeiter';
require __DIR__ . '/../templates/header.php';
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
                    Wenn Dienste gewählt, werden die Zeiten unten ignoriert.
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

            <!-- Wochenzeit (für mode = 'week') -->
            <div id="section-week" class="card bg-light border mb-3 p-3" style="display:none;">
                <label class="form-label fw-semibold mb-2">Arbeitszeit (für alle Tage)</label>
                <div class="d-flex align-items-center gap-3">
                    <div>
                        <label class="form-label small text-muted mb-1">Von</label>
                        <input type="time" class="form-control" name="week_time_start" id="week_time_start"
                               value="<?= h($form['week_time_start']) ?>">
                    </div>
                    <div class="pt-3 text-muted fw-bold">–</div>
                    <div>
                        <label class="form-label small text-muted mb-1">Bis</label>
                        <input type="time" class="form-control" name="week_time_end" id="week_time_end"
                               value="<?= h($form['week_time_end']) ?>">
                    </div>
                    <div class="pt-3">
                        <span class="text-muted small" id="week-duration"></span>
                    </div>
                </div>
            </div>

            <!-- Pro-Tag-Zeiten (für mode = 'day') -->
            <div id="section-day" class="mb-3" style="display:none;">
                <label class="form-label fw-semibold mb-2">Arbeitszeiten pro Tag</label>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle" style="max-width:480px;">
                        <thead class="table-light">
                            <tr>
                                <th>Tag</th>
                                <th>Von</th>
                                <th>Bis</th>
                                <th>Dauer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($day_labels as $idx => $label):
                                $dt_s = $form['day_times'][$idx]['start'] ?? $bz_start;
                                $dt_e = $form['day_times'][$idx]['end']   ?? $bz_end;
                            ?>
                            <tr class="day-time-row" data-day="<?= $idx ?>"
                                <?= !in_array((string)$idx, array_map('strval', $form['available_days'])) ? 'style="opacity:0.35;pointer-events:none;"' : '' ?>>
                                <td class="fw-semibold"><?= $label ?></td>
                                <td>
                                    <input type="time" class="form-control form-control-sm day-time-start"
                                           name="day_time_start_<?= $idx ?>"
                                           data-day="<?= $idx ?>"
                                           value="<?= h($dt_s) ?>">
                                </td>
                                <td>
                                    <input type="time" class="form-control form-control-sm day-time-end"
                                           name="day_time_end_<?= $idx ?>"
                                           data-day="<?= $idx ?>"
                                           value="<?= h($dt_e) ?>">
                                </td>
                                <td class="text-muted small day-duration" id="dur-<?= $idx ?>">—</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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

    // Week duration
    const wts = document.getElementById('week_time_start');
    const wte = document.getElementById('week_time_end');
    const wdur = document.getElementById('week-duration');
    function updateWeekDur() { if (wdur) wdur.textContent = timeDiff(wts?.value, wte?.value); }
    wts?.addEventListener('change', updateWeekDur);
    wte?.addEventListener('change', updateWeekDur);
    updateWeekDur();

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

    // Per-day durations
    document.querySelectorAll('.day-time-start, .day-time-end').forEach(el => {
        el.addEventListener('change', function () {
            const d = this.dataset.day;
            const s = document.querySelector(`.day-time-start[data-day="${d}"]`)?.value;
            const e = document.querySelector(`.day-time-end[data-day="${d}"]`)?.value;
            const dur = document.getElementById(`dur-${d}`);
            if (dur) dur.textContent = timeDiff(s, e);
        });
    });
    // Init all day durations
    document.querySelectorAll('.day-time-start').forEach(el => el.dispatchEvent(new Event('change')));
})();
</script>

<?php require __DIR__ . '/../templates/footer.php'; ?>
