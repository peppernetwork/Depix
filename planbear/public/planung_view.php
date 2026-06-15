<?php
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/scheduler.php';

session_start_secure();
require_auth(); // All roles

$pdo = get_pdo();

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
if (!$schedule) {
    flash('error', 'Plan nicht gefunden.');
    redirect('planung.php');
}

// Load revisions for selector
$revisions = $pdo->prepare('SELECT id, revision_number, created_at, notes FROM schedule_revisions WHERE schedule_id=? ORDER BY revision_number');
$revisions->execute([$schedule_id]);
$revisions = $revisions->fetchAll();

// Load current revision info
$stmt = $pdo->prepare('SELECT id, revision_number, created_at FROM schedule_revisions WHERE id=? AND schedule_id=?');
$stmt->execute([$revision_id, $schedule_id]);
$rev = $stmt->fetch();
if (!$rev) {
    flash('error', 'Revision nicht gefunden.');
    redirect('planung.php');
}

// Week navigation
$school_start = new DateTime($schedule['start_date']);
$school_end   = new DateTime($schedule['end_date']);

// Determine current week from GET, default to school start
$week_date_str = $_GET['week'] ?? $school_start->format('Y-m-d');
try {
    $week_monday = monday_of_week($week_date_str);
} catch (Exception $e) {
    $week_monday = clone $school_start;
}

// Clamp to school year
if ($week_monday < $school_start) {
    $week_monday = monday_of_week($school_start->format('Y-m-d'));
}
if ($week_monday > $school_end) {
    $week_monday = monday_of_week($school_end->format('Y-m-d'));
}

$week_friday  = (clone $week_monday)->modify('+4 days');
$prev_monday  = (clone $week_monday)->modify('-7 days');
$next_monday  = (clone $week_monday)->modify('+7 days');

// Build Mon-Fri date list
$week_dates = [];
for ($i = 0; $i < 5; $i++) {
    $d = (clone $week_monday)->modify("+{$i} days");
    $week_dates[] = $d->format('Y-m-d');
}

// Load holidays for the week
$stmt = $pdo->prepare(
    'SELECT holiday_date, name FROM public_holidays
     WHERE school_year_id=? AND holiday_date BETWEEN ? AND ?'
);
$stmt->execute([$schedule['school_year_id'], $week_dates[0], $week_dates[4]]);
$holiday_map = [];
foreach ($stmt->fetchAll() as $h) {
    $holiday_map[$h['holiday_date']] = $h['name'];
}

// Load vacation periods overlapping this week
$stmt = $pdo->prepare(
    'SELECT name, start_date, end_date FROM vacation_periods
     WHERE school_year_id=? AND start_date <= ? AND end_date >= ?'
);
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

// Load active employees
$employees = $pdo->query('SELECT id, name_enc FROM employees WHERE is_active=1 ORDER BY id')->fetchAll();

// Load entries for this revision + week
$stmt = $pdo->prepare(
    'SELECT employee_id, entry_date, hours FROM schedule_entries
     WHERE revision_id=? AND entry_date BETWEEN ? AND ?'
);
$stmt->execute([$revision_id, $week_dates[0], $week_dates[4]]);
$entry_map = [];
foreach ($stmt->fetchAll() as $e) {
    $entry_map[$e['employee_id']][$e['entry_date']] = $e['hours'];
}

$de_days = ['Mo', 'Di', 'Mi', 'Do', 'Fr'];

$page_title = 'Plan: ' . $schedule['name'];
$active_nav = 'planung';
require __DIR__ . '/../templates/header.php';
?>

<!-- CSRF token for inline JS editing -->
<input type="hidden" id="csrf-token-value" value="<?= h(generate_csrf_token()) ?>">

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 class="fw-bold mb-0" style="color:var(--pb-dark);">
            <i class="bi bi-calendar-week"></i> <?= h($schedule['name']) ?>
        </h1>
        <small class="text-muted"><?= h($schedule['year_name']) ?> &mdash; Revision <?= h((string)$rev['revision_number']) ?> (<?= h(format_date_de($rev['created_at'])) ?>)</small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <!-- Revision selector -->
        <form method="get" action="planung_view.php" class="d-flex gap-1 align-items-center">
            <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
            <input type="hidden" name="week" value="<?= h($week_monday->format('Y-m-d')) ?>">
            <select name="revision_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($revisions as $rv): ?>
                    <option value="<?= (int)$rv['id'] ?>"
                            <?= $rv['id'] == $revision_id ? 'selected' : '' ?>>
                        Rev. <?= (int)$rv['revision_number'] ?> (<?= h(format_date_de($rv['created_at'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <a href="planung.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Übersicht
        </a>
        <a href="kalender.php?revision_id=<?= $revision_id ?>" class="btn btn-sm btn-pb-outline">
            <i class="bi bi-calendar3"></i> Kalender
        </a>
    </div>
</div>

<!-- Week navigation -->
<div class="d-flex align-items-center gap-3 mb-3">
    <?php if ($prev_monday >= $school_start): ?>
    <a href="planung_view.php?schedule_id=<?= $schedule_id ?>&revision_id=<?= $revision_id ?>&week=<?= $prev_monday->format('Y-m-d') ?>"
       class="btn btn-sm btn-pb-outline">
        <i class="bi bi-chevron-left"></i> Vorwoche
    </a>
    <?php else: ?>
    <button class="btn btn-sm btn-outline-secondary" disabled><i class="bi bi-chevron-left"></i> Vorwoche</button>
    <?php endif; ?>

    <form method="get" action="planung_view.php" class="d-flex align-items-center gap-2">
        <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
        <input type="hidden" name="revision_id" value="<?= $revision_id ?>">
        <label class="form-label mb-0 fw-semibold text-nowrap">KW <?= $week_monday->format('W/Y') ?></label>
        <input type="week" name="week" class="form-control form-control-sm"
               value="<?= $week_monday->format('Y') . '-W' . str_pad($week_monday->format('W'), 2, '0', STR_PAD_LEFT) ?>"
               min="<?= $school_start->format('Y') . '-W' . str_pad($school_start->format('W'), 2, '0', STR_PAD_LEFT) ?>"
               max="<?= $school_end->format('Y') . '-W' . str_pad($school_end->format('W'), 2, '0', STR_PAD_LEFT) ?>"
               onchange="
                 var parts = this.value.split('-W');
                 var d = new Date(parts[0], 0, 1 + (parts[1]-1)*7);
                 d.setDate(d.getDate() - (d.getDay() === 0 ? 6 : d.getDay()-1));
                 var ds = d.toISOString().slice(0,10);
                 window.location = 'planung_view.php?schedule_id=<?= $schedule_id ?>&revision_id=<?= $revision_id ?>&week='+ds;
               ">
    </form>

    <?php if ($next_monday <= $school_end): ?>
    <a href="planung_view.php?schedule_id=<?= $schedule_id ?>&revision_id=<?= $revision_id ?>&week=<?= $next_monday->format('Y-m-d') ?>"
       class="btn btn-sm btn-pb-outline">
        Nächste Woche <i class="bi bi-chevron-right"></i>
    </a>
    <?php else: ?>
    <button class="btn btn-sm btn-outline-secondary" disabled>Nächste Woche <i class="bi bi-chevron-right"></i></button>
    <?php endif; ?>

    <?php if (!empty($vacation_map)): ?>
        <span class="badge" style="background:#ffe08a;color:#7a5c00;">
            <i class="bi bi-sun-fill"></i> <?= h(array_values($vacation_map)[0]) ?>
        </span>
    <?php endif; ?>
</div>

<!-- Schedule table -->
<div class="table-responsive">
    <table class="table pb-schedule-table table-bordered align-middle">
        <thead>
            <tr>
                <th style="min-width:160px;">Mitarbeiter</th>
                <?php foreach ($week_dates as $i => $wd): ?>
                <th class="text-center" style="min-width:100px;">
                    <?= $de_days[$i] ?><br>
                    <small><?= h(format_date_de($wd)) ?></small>
                    <?php if (isset($holiday_map[$wd])): ?>
                        <br><span class="badge bg-danger" style="font-size:0.65rem;"><?= h($holiday_map[$wd]) ?></span>
                    <?php elseif (isset($vacation_map[$wd])): ?>
                        <br><span class="badge" style="background:#ffe08a;color:#7a5c00;font-size:0.65rem;">Ferien</span>
                    <?php endif; ?>
                </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($employees as $emp): ?>
            <?php $name = decrypt($emp['name_enc']); ?>
            <tr>
                <td class="emp-name"><?= h($name) ?></td>
                <?php foreach ($week_dates as $wd): ?>
                    <?php
                    $is_holiday = isset($holiday_map[$wd]);
                    $is_vacation = isset($vacation_map[$wd]);
                    $hours = $entry_map[$emp['id']][$wd] ?? null;
                    $can_edit = has_role('editor','admin');

                    if ($is_holiday): ?>
                        <td class="holiday text-center">
                            <i class="bi bi-calendar-x"></i> Feiertag
                        </td>
                    <?php elseif ($is_vacation && $hours === null): ?>
                        <td class="vacation text-center">
                            <i class="bi bi-umbrella-fill"></i> Ferien
                        </td>
                    <?php elseif ($hours !== null): ?>
                        <td class="entry text-center <?= $can_edit ? 'editable' : '' ?>"
                            <?php if ($can_edit): ?>
                                data-hours="<?= h(number_format((float)$hours, 2, '.', '')) ?>"
                                data-revision-id="<?= $revision_id ?>"
                                data-employee-id="<?= (int)$emp['id'] ?>"
                                data-date="<?= h($wd) ?>"
                            <?php endif; ?>>
                            <?= h(number_format((float)$hours, 2, ',', '.')) ?> h
                        </td>
                    <?php else: ?>
                        <td class="text-center text-muted <?= $can_edit ? 'editable' : '' ?>"
                            <?php if ($can_edit): ?>
                                data-hours="0"
                                data-revision-id="<?= $revision_id ?>"
                                data-employee-id="<?= (int)$emp['id'] ?>"
                                data-date="<?= h($wd) ?>"
                            <?php endif; ?>>
                            &mdash;
                        </td>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if (has_role('editor','admin')): ?>
<div class="mt-2 text-muted small">
    <i class="bi bi-pencil-square"></i> Klicken Sie auf eine Zelle, um die Stundenzahl direkt zu bearbeiten.
</div>
<?php endif; ?>

<?php require __DIR__ . '/../templates/footer.php'; ?>
