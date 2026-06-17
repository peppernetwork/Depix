<?php
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
require_auth();

$pdo = get_pdo();

$schedule_id = req_int('schedule_id', $_GET);
$revision_id = req_int('revision_id', $_GET);

if (!$schedule_id || !$revision_id) { flash('error', 'Ungültige Parameter.'); redirect('planung.php'); }

$stmt = $pdo->prepare(
    'SELECT s.id, s.name, s.school_year_id, sy.name AS year_name, sy.start_date, sy.end_date
     FROM schedules s JOIN school_years sy ON sy.id=s.school_year_id WHERE s.id=?'
);
$stmt->execute([$schedule_id]);
$schedule = $stmt->fetch();
if (!$schedule) { flash('error', 'Plan nicht gefunden.'); redirect('planung.php'); }

$revisions = $pdo->prepare('SELECT id, revision_number, created_at FROM schedule_revisions WHERE schedule_id=? ORDER BY revision_number');
$revisions->execute([$schedule_id]);
$revisions = $revisions->fetchAll();

$stmt = $pdo->prepare('SELECT id, revision_number, created_at FROM schedule_revisions WHERE id=? AND schedule_id=?');
$stmt->execute([$revision_id, $schedule_id]);
$rev = $stmt->fetch();
if (!$rev) { flash('error', 'Revision nicht gefunden.'); redirect('planung.php'); }

$school_start = new DateTime($schedule['start_date']);
$school_end   = new DateTime($schedule['end_date']);

// Month selection (default: school start month)
$month_str = $_GET['month'] ?? $school_start->format('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month_str)) {
    $month_str = $school_start->format('Y-m');
}
$month_start = new DateTime($month_str . '-01');
$month_end   = (clone $month_start)->modify('last day of this month');

// Clamp to school year
$disp_start = max($month_start, $school_start);
$disp_end   = min($month_end, $school_end);

$prev_month = (clone $month_start)->modify('-1 month');
$next_month = (clone $month_start)->modify('+1 month');

// Build all Mon-Fri working days in this month
$working_days = []; // ['date'=>'Y-m-d','dow'=>1-5,'kw'=>'NN']
$cur = clone $disp_start;
while ($cur <= $disp_end) {
    $dow = (int)$cur->format('N');
    if ($dow <= 5) {
        $working_days[] = [
            'date' => $cur->format('Y-m-d'),
            'dow'  => $dow,
            'kw'   => (int)$cur->format('W'),
            'day'  => (int)$cur->format('j'),
        ];
    }
    $cur->modify('+1 day');
}

if (empty($working_days)) {
    $first_date = $month_start->format('Y-m-d');
    $last_date  = $month_end->format('Y-m-d');
} else {
    $first_date = $working_days[0]['date'];
    $last_date  = end($working_days)['date'];
}

// Holidays
$stmt = $pdo->prepare('SELECT holiday_date, name FROM public_holidays WHERE school_year_id=? AND holiday_date BETWEEN ? AND ?');
$stmt->execute([$schedule['school_year_id'], $first_date, $last_date]);
$holiday_map = [];
foreach ($stmt->fetchAll() as $h) $holiday_map[$h['holiday_date']] = $h['name'];

// Vacation periods
$stmt = $pdo->prepare('SELECT name, start_date, end_date FROM vacation_periods WHERE school_year_id=? AND start_date<=? AND end_date>=?');
$stmt->execute([$schedule['school_year_id'], $last_date, $first_date]);
$vacation_periods_raw = $stmt->fetchAll();
$vacation_map = [];
foreach ($working_days as $wd_item) {
    foreach ($vacation_periods_raw as $vp) {
        if ($wd_item['date'] >= $vp['start_date'] && $wd_item['date'] <= $vp['end_date']) {
            $vacation_map[$wd_item['date']] = $vp['name'];
            break;
        }
    }
}

// Employee annual vacations (Urlaub)
$stmt = $pdo->prepare(
    "SELECT employee_id, vacation_date FROM employee_vacations
     WHERE vacation_date BETWEEN ? AND ? AND status IN ('geplant','genehmigt','genommen')"
);
$stmt->execute([$first_date, $last_date]);
$emp_vacation_map = [];
foreach ($stmt->fetchAll() as $r) {
    $emp_vacation_map[(int)$r['employee_id']][$r['vacation_date']] = true;
}

// Employees + shifts
$employees = $pdo->query('SELECT id, name_enc FROM employees WHERE is_active=1 ORDER BY id')->fetchAll();
$emp_shifts_all = [];
foreach ($pdo->query('SELECT es.employee_id, s.short_name, s.color FROM employee_shifts es JOIN shifts s ON s.id=es.shift_id ORDER BY s.sort_order')->fetchAll() as $r) {
    $emp_shifts_all[(int)$r['employee_id']][] = $r;
}

// Schedule entries
$stmt = $pdo->prepare('SELECT employee_id, entry_date, hours, pause_minuten, is_vacation_period FROM schedule_entries WHERE revision_id=? AND entry_date BETWEEN ? AND ?');
$stmt->execute([$revision_id, $first_date, $last_date]);
$entry_map = [];
foreach ($stmt->fetchAll() as $e) {
    $entry_map[$e['employee_id']][$e['entry_date']] = $e;
}

// German month names
$month_names = ['','Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
$month_label = $month_names[(int)$month_start->format('n')] . ' ' . $month_start->format('Y');
$de_days_short = [1=>'Mo',2=>'Di',3=>'Mi',4=>'Do',5=>'Fr'];

$page_title = 'Plan: ' . $schedule['name'] . ' – ' . $month_label;
$active_nav = 'planung';
require __DIR__ . '/templates/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h1 class="fw-bold mb-0" style="color:var(--pb-dark);">
            <i class="bi bi-calendar-month"></i> <?= h($schedule['name']) ?>
            <span class="fw-normal fs-5 text-muted ms-2"><?= h($month_label) ?></span>
        </h1>
        <small class="text-muted"><?= h($schedule['year_name']) ?> &mdash; Revision <?= (int)$rev['revision_number'] ?></small>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <form method="get" action="planung_monat.php" class="d-flex gap-1 align-items-center">
            <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
            <input type="hidden" name="revision_id" value="<?= $revision_id ?>">
            <select name="revision_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($revisions as $rv): ?>
                    <option value="<?= (int)$rv['id'] ?>" <?= $rv['id'] == $revision_id ? 'selected' : '' ?>>
                        Rev. <?= (int)$rv['revision_number'] ?> (<?= h(format_date_de($rv['created_at'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <div class="btn-group btn-group-sm">
            <a href="planung_view.php?schedule_id=<?= $schedule_id ?>&revision_id=<?= $revision_id ?>&week=<?= h($month_start->format('Y-m-d')) ?>"
               class="btn btn-pb-outline"><i class="bi bi-calendar-week"></i> Woche</a>
            <a href="planung_monat.php?schedule_id=<?= $schedule_id ?>&revision_id=<?= $revision_id ?>&month=<?= h($month_str) ?>"
               class="btn btn-pb-primary active"><i class="bi bi-calendar-month"></i> Monat</a>
        </div>

        <a href="export_pdf.php?type=monat&schedule_id=<?= $schedule_id ?>&revision_id=<?= $revision_id ?>&month=<?= h($month_str) ?>"
           target="_blank" class="btn btn-sm btn-outline-danger">
            <i class="bi bi-file-earmark-pdf-fill"></i> PDF Monat
        </a>

        <a href="planung.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Übersicht
        </a>
    </div>
</div>

<!-- Month navigation -->
<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
    <?php if ($prev_month >= (new DateTime($school_start->format('Y-m') . '-01'))): ?>
    <a href="planung_monat.php?schedule_id=<?= $schedule_id ?>&revision_id=<?= $revision_id ?>&month=<?= $prev_month->format('Y-m') ?>"
       class="btn btn-sm btn-pb-outline"><i class="bi bi-chevron-left"></i> Vormonat</a>
    <?php else: ?>
    <button class="btn btn-sm btn-outline-secondary" disabled><i class="bi bi-chevron-left"></i> Vormonat</button>
    <?php endif; ?>

    <form method="get" action="planung_monat.php" class="d-flex align-items-center gap-2">
        <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
        <input type="hidden" name="revision_id" value="<?= $revision_id ?>">
        <input type="month" name="month" class="form-control form-control-sm" style="width:auto;"
               value="<?= h($month_str) ?>"
               min="<?= h($school_start->format('Y-m')) ?>"
               max="<?= h($school_end->format('Y-m')) ?>"
               onchange="this.form.submit()">
    </form>

    <?php if ($next_month <= new DateTime($school_end->format('Y-m') . '-01')): ?>
    <a href="planung_monat.php?schedule_id=<?= $schedule_id ?>&revision_id=<?= $revision_id ?>&month=<?= $next_month->format('Y-m') ?>"
       class="btn btn-sm btn-pb-outline">Nächster Monat <i class="bi bi-chevron-right"></i></a>
    <?php else: ?>
    <button class="btn btn-sm btn-outline-secondary" disabled>Nächster Monat <i class="bi bi-chevron-right"></i></button>
    <?php endif; ?>

    <span class="text-muted small"><?= count($working_days) ?> Arbeitstage</span>
</div>

<!-- Legend -->
<div class="d-flex gap-2 mb-3 flex-wrap">
    <span class="badge bg-danger bg-opacity-75">Feiertag</span>
    <span class="badge" style="background:#ffe08a;color:#7a5c00;">Schulferien</span>
    <span class="badge bg-info text-dark">Urlaub</span>
    <span class="badge bg-success bg-opacity-75">Einsatz</span>
</div>

<?php if (empty($working_days)): ?>
    <div class="alert alert-warning">Keine Arbeitstage in diesem Zeitraum (außerhalb des Schuljahres).</div>
<?php else: ?>
<div class="text-muted small mb-1 d-md-none">
    <i class="bi bi-arrow-left-right"></i> Tabelle nach links/rechts wischen
</div>

<!-- Monthly schedule table (horizontal scroll) -->
<div class="table-responsive">
    <table class="table pb-month-table table-bordered align-middle" style="font-size:0.78rem;min-width:<?= 150 + count($working_days) * 44 ?>px;">
        <thead>
            <!-- KW row -->
            <tr>
                <th rowspan="2" class="pb-col-emp-month" style="vertical-align:middle;">Mitarbeiter</th>
                <?php
                $prev_kw = null;
                $kw_colspan = 0;
                $kw_cells = [];
                foreach ($working_days as $wd_item) {
                    if ($wd_item['kw'] !== $prev_kw) {
                        if ($prev_kw !== null) $kw_cells[] = [$prev_kw, $kw_colspan];
                        $prev_kw = $wd_item['kw'];
                        $kw_colspan = 1;
                    } else {
                        $kw_colspan++;
                    }
                }
                if ($prev_kw !== null) $kw_cells[] = [$prev_kw, $kw_colspan];
                foreach ($kw_cells as [$kw, $cs]):
                ?>
                <th class="text-center" colspan="<?= $cs ?>" style="font-size:0.7rem;padding:2px 4px;">
                    KW&nbsp;<?= $kw ?>
                </th>
                <?php endforeach; ?>
                <th rowspan="2" class="text-center pb-col-total-month" style="vertical-align:middle;">∑ h</th>
            </tr>
            <!-- Day row -->
            <tr>
                <?php foreach ($working_days as $wd_item):
                    $is_h = isset($holiday_map[$wd_item['date']]);
                    $is_v = isset($vacation_map[$wd_item['date']]);
                ?>
                <th class="text-center <?= $is_h ? 'table-danger' : ($is_v ? 'table-warning' : '') ?>"
                    style="padding:2px 2px;min-width:40px;"
                    title="<?= h($is_h ? $holiday_map[$wd_item['date']] : ($is_v ? $vacation_map[$wd_item['date']] : '')) ?>">
                    <div class="fw-bold"><?= $de_days_short[$wd_item['dow']] ?></div>
                    <div><?= $wd_item['day'] ?>.</div>
                </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($employees as $emp):
                $eid   = (int)$emp['id'];
                $name  = decrypt($emp['name_enc']);
                $shifts = $emp_shifts_all[$eid] ?? [];
                $month_total = 0;
            ?>
            <tr>
                <td class="emp-name" style="font-size:0.82rem;">
                    <div><?= h($name) ?></div>
                    <?php foreach ($shifts as $sh): ?>
                        <span class="badge" style="background:<?= h($sh['color']) ?>;font-size:0.6rem;"><?= h($sh['short_name']) ?></span>
                    <?php endforeach; ?>
                </td>
                <?php foreach ($working_days as $wd_item):
                    $wd = $wd_item['date'];
                    $is_holiday = isset($holiday_map[$wd]);
                    $is_ferien  = isset($vacation_map[$wd]);
                    $is_urlaub  = isset($emp_vacation_map[$eid][$wd]);
                    $entry      = $entry_map[$eid][$wd] ?? null;
                    if ($entry) $month_total += (float)$entry['hours'];
                ?>
                    <?php if ($is_holiday): ?>
                    <td class="text-center" style="background:#ffe0e0;padding:2px;" title="<?= h($holiday_map[$wd]) ?>">
                        <span style="font-size:0.75rem;color:#a00;">FT</span>
                    </td>
                    <?php elseif ($is_urlaub): ?>
                    <td class="text-center" style="background:#e8f4fd;padding:2px;" title="Urlaub">
                        <span style="font-size:0.75rem;color:#0a58ca;">U</span>
                    </td>
                    <?php elseif ($entry): ?>
                    <td class="text-center" style="background:#eaf4e8;padding:2px;">
                        <div style="font-size:0.72rem;font-weight:600;color:#1a5c1a;">
                            <?= h(number_format((float)$entry['hours'], 1, ',', '.')) ?>h
                        </div>
                        <?php if ($is_ferien): ?>
                            <div style="font-size:0.6rem;color:#806010;">F</div>
                        <?php endif; ?>
                    </td>
                    <?php elseif ($is_ferien): ?>
                    <td class="text-center" style="background:#fff8dc;padding:2px;">
                        <span style="font-size:0.7rem;color:#806010;">F</span>
                    </td>
                    <?php else: ?>
                    <td class="text-center text-muted" style="padding:2px;">
                        <span style="font-size:0.85rem;">–</span>
                    </td>
                    <?php endif; ?>
                <?php endforeach; ?>
                <td class="text-center fw-bold" style="font-size:0.8rem;">
                    <?= $month_total > 0 ? h(number_format($month_total, 1, ',', '.')) : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="table-light">
                <td class="fw-semibold" style="font-size:0.78rem;">Summe</td>
                <?php
                $grand_total = 0;
                foreach ($working_days as $wd_item):
                    $wd = $wd_item['date'];
                    $day_sum = 0;
                    foreach ($employees as $emp) {
                        $day_sum += (float)($entry_map[$emp['id']][$wd]['hours'] ?? 0);
                    }
                    $grand_total += $day_sum;
                ?>
                <td class="text-center fw-semibold" style="font-size:0.75rem;padding:2px;">
                    <?= $day_sum > 0 ? h(number_format($day_sum, 1, ',', '.')) : '' ?>
                </td>
                <?php endforeach; ?>
                <td class="text-center fw-bold">
                    <?= $grand_total > 0 ? h(number_format($grand_total, 1, ',', '.')) : '—' ?>
                </td>
            </tr>
        </tfoot>
    </table>
</div>

<div class="mt-2 text-muted small">
    <strong>Legende:</strong>
    FT = Feiertag &nbsp;|&nbsp; U = Urlaub (MA) &nbsp;|&nbsp; F = Schulferien &nbsp;|&nbsp; — = kein Einsatz
</div>
<?php endif; ?>

<?php require __DIR__ . '/templates/footer.php'; ?>
