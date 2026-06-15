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
require_auth();

$pdo  = get_pdo();
$user = current_user();

// ─── Load school years ─────────────────────────────────────────────────────
$school_years = $pdo->query('SELECT * FROM school_years ORDER BY start_date DESC')->fetchAll();
$active_sy    = $pdo->query('SELECT * FROM school_years WHERE is_active=1 LIMIT 1')->fetch();
$sel_sy_id    = req_int('schuljahr', $_GET) ?? ($active_sy['id'] ?? null);

$sel_sy = null;
if ($sel_sy_id) {
    $stmt = $pdo->prepare('SELECT * FROM school_years WHERE id=?');
    $stmt->execute([$sel_sy_id]);
    $sel_sy = $stmt->fetch();
}
if (!$sel_sy && !empty($school_years)) {
    $sel_sy    = $school_years[0];
    $sel_sy_id = (int)$sel_sy['id'];
}

$std_tage = (int)get_setting($pdo, 'urlaub_standard_tage', '20');

// ─── Load holidays (to exclude from vacation counting) ────────────────────
$holiday_dates = [];
if ($sel_sy_id) {
    $stmt = $pdo->prepare('SELECT holiday_date FROM public_holidays WHERE school_year_id=?');
    $stmt->execute([$sel_sy_id]);
    foreach ($stmt->fetchAll() as $r) {
        $holiday_dates[$r['holiday_date']] = true;
    }
}

// ─── POST actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_role('editor','admin')) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        flash('error', 'Ungültige Anfrage (CSRF).');
        redirect('urlaub.php' . ($sel_sy_id ? '?schuljahr=' . $sel_sy_id : ''));
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add' && $sel_sy_id) {
        $emp_id_post  = req_int('employee_id', $_POST);
        $date_from    = trim($_POST['date_from'] ?? '');
        $date_to      = trim($_POST['date_to']   ?? $date_from);
        $notes        = trim($_POST['notes']      ?? '');

        if (!$emp_id_post) {
            flash('error', 'Bitte Mitarbeiter auswählen.');
        } elseif (!$date_from || !strtotime($date_from)) {
            flash('error', 'Ungültiges Datum.');
        } else {
            $dt_from = new DateTime($date_from);
            $dt_to   = new DateTime($date_to ?: $date_from);
            if ($dt_to < $dt_from) $dt_to = clone $dt_from;

            $inserted = 0;
            $skipped  = 0;
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO employee_vacations
                 (employee_id, school_year_id, vacation_date, notes, created_by)
                 VALUES (?,?,?,?,?)'
            );
            $cur = clone $dt_from;
            while ($cur <= $dt_to) {
                $dow = (int)$cur->format('N'); // 1=Mon..7=Sun
                $ds  = $cur->format('Y-m-d');
                if ($dow <= 5 && !isset($holiday_dates[$ds])) {
                    $stmt->execute([$emp_id_post, $sel_sy_id, $ds, $notes ?: null, (int)$user['id']]);
                    if ($pdo->lastInsertId()) $inserted++;
                    else $skipped++;
                }
                $cur->modify('+1 day');
            }
            flash('success', $inserted . ' Urlaubstag(e) eingetragen.' . ($skipped > 0 ? " ({$skipped} bereits vorhanden/übersprungen)" : ''));
        }
        redirect('urlaub.php?schuljahr=' . $sel_sy_id);
    }

    if ($action === 'set_status') {
        $vac_id    = req_int('id', $_POST);
        $newstatus = $_POST['status'] ?? '';
        if ($vac_id && in_array($newstatus, ['geplant','genehmigt','genommen'], true)) {
            $pdo->prepare('UPDATE employee_vacations SET status=? WHERE id=?')
                ->execute([$newstatus, $vac_id]);
            flash('success', 'Status aktualisiert.');
        }
        redirect('urlaub.php?schuljahr=' . $sel_sy_id . '&ma=' . ($_POST['emp_id'] ?? ''));
    }

    if ($action === 'delete') {
        $vac_id = req_int('id', $_POST);
        if ($vac_id) {
            $pdo->prepare('DELETE FROM employee_vacations WHERE id=?')->execute([$vac_id]);
            flash('success', 'Urlaubstag gelöscht.');
        }
        redirect('urlaub.php?schuljahr=' . $sel_sy_id . '&ma=' . ($_POST['emp_id'] ?? ''));
    }
}

// ─── Load employees with vacation stats ───────────────────────────────────
$employees = [];
if ($sel_sy_id) {
    $emps = $pdo->query(
        'SELECT id, name_enc, urlaub_zusatz_tage FROM employees WHERE is_active=1 ORDER BY id'
    )->fetchAll();

    // Count vacation days per employee per status
    $stmt = $pdo->prepare(
        'SELECT employee_id, status, COUNT(*) AS cnt
         FROM employee_vacations WHERE school_year_id=?
         GROUP BY employee_id, status'
    );
    $stmt->execute([$sel_sy_id]);
    $vac_counts = [];
    foreach ($stmt->fetchAll() as $r) {
        $vac_counts[(int)$r['employee_id']][$r['status']] = (int)$r['cnt'];
    }

    foreach ($emps as $e) {
        $eid      = (int)$e['id'];
        $gesamt   = $std_tage + (int)$e['urlaub_zusatz_tage'];
        $geplant  = $vac_counts[$eid]['geplant']  ?? 0;
        $genehm   = $vac_counts[$eid]['genehmigt']?? 0;
        $genommen = $vac_counts[$eid]['genommen'] ?? 0;
        $verbraucht = $genehm + $genommen;
        $employees[] = [
            'id'            => $eid,
            'name'          => decrypt($e['name_enc']),
            'gesamt_tage'   => $gesamt,
            'zusatz_tage'   => (int)$e['urlaub_zusatz_tage'],
            'geplant'       => $geplant,
            'genehmigt'     => $genehm,
            'genommen'      => $genommen,
            'verbraucht'    => $verbraucht,
            'verbleibend'   => max(0, $gesamt - $verbraucht - $geplant),
        ];
    }
}

// ─── Load vacation entries for selected employee ───────────────────────────
$sel_emp_id = req_int('ma', $_GET);
$sel_emp    = null;
$vac_entries = [];

if ($sel_emp_id && $sel_sy_id) {
    foreach ($employees as $e) {
        if ($e['id'] === $sel_emp_id) {
            $sel_emp = $e;
            break;
        }
    }
    $stmt = $pdo->prepare(
        'SELECT v.*, u.username AS created_by_name
         FROM employee_vacations v
         LEFT JOIN users u ON u.id = v.created_by
         WHERE v.employee_id=? AND v.school_year_id=?
         ORDER BY v.vacation_date'
    );
    $stmt->execute([$sel_emp_id, $sel_sy_id]);
    $vac_entries = $stmt->fetchAll();
}

$status_labels = ['geplant'=>'Geplant','genehmigt'=>'Genehmigt','genommen'=>'Genommen'];
$status_colors = ['geplant'=>'secondary','genehmigt'=>'primary','genommen'=>'success'];

$page_title = 'Urlaubsplanung';
$active_nav = 'urlaub';
require __DIR__ . '/../templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="fw-bold mb-0" style="color:var(--pb-dark);">
        <i class="bi bi-umbrella-fill"></i> Urlaubsplanung
    </h1>
    <!-- School year selector -->
    <form method="get" action="urlaub.php" class="d-flex align-items-center gap-2">
        <label class="fw-semibold small text-muted">Schuljahr:</label>
        <select name="schuljahr" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
            <?php foreach ($school_years as $sy): ?>
                <option value="<?= (int)$sy['id'] ?>" <?= $sy['id'] == $sel_sy_id ? 'selected' : '' ?>>
                    <?= h($sy['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<!-- ── Overview table ────────────────────────────────────────────────────── -->
<?php if (!empty($employees)): ?>
<div class="card pb-card mb-4">
    <div class="card-header">
        <i class="bi bi-table"></i> Übersicht &mdash; Schuljahr <?= h($sel_sy['name'] ?? '') ?>
        <span class="text-muted fw-normal small ms-2">(Standard: <?= $std_tage ?> Tage)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-pb table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Mitarbeiter</th>
                        <th class="text-center">Gesamt</th>
                        <th class="text-center text-warning-emphasis">Geplant</th>
                        <th class="text-center text-primary">Genehmigt</th>
                        <th class="text-center text-success">Genommen</th>
                        <th class="text-center">Verbleibend</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $e): ?>
                    <tr <?= $e['id'] === $sel_emp_id ? 'class="table-active"' : '' ?>>
                        <td class="fw-semibold">
                            <?= h($e['name']) ?>
                            <?php if ($e['zusatz_tage'] > 0): ?>
                                <span class="badge bg-info ms-1 small">+<?= $e['zusatz_tage'] ?> extra</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center fw-bold"><?= $e['gesamt_tage'] ?></td>
                        <td class="text-center">
                            <?php if ($e['geplant'] > 0): ?>
                                <span class="badge bg-warning text-dark"><?= $e['geplant'] ?></span>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($e['genehmigt'] > 0): ?>
                                <span class="badge bg-primary"><?= $e['genehmigt'] ?></span>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($e['genommen'] > 0): ?>
                                <span class="badge bg-success"><?= $e['genommen'] ?></span>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php
                                $pct = $e['gesamt_tage'] > 0
                                    ? min(100, round(($e['verbraucht'] + $e['geplant']) / $e['gesamt_tage'] * 100))
                                    : 0;
                                $color = $e['verbleibend'] <= 0 ? 'danger' : ($pct >= 80 ? 'warning' : 'success');
                            ?>
                            <div class="d-flex align-items-center gap-1 justify-content-center">
                                <div class="progress flex-grow-1" style="height:8px;min-width:60px;max-width:80px;">
                                    <div class="progress-bar bg-<?= $color ?>" style="width:<?= $pct ?>%"></div>
                                </div>
                                <span class="fw-semibold text-<?= $color ?>"><?= $e['verbleibend'] ?></span>
                            </div>
                        </td>
                        <td>
                            <a href="urlaub.php?schuljahr=<?= $sel_sy_id ?>&ma=<?= $e['id'] ?>"
                               class="btn btn-sm <?= $e['id'] === $sel_emp_id ? 'btn-pb-primary' : 'btn-outline-secondary' ?>">
                                <i class="bi bi-calendar3"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php elseif (!$sel_sy): ?>
    <div class="alert alert-warning">Kein Schuljahr ausgewählt oder vorhanden.</div>
<?php else: ?>
    <div class="alert alert-info">Keine aktiven Mitarbeiter vorhanden.</div>
<?php endif; ?>

<!-- ── Detail: selected employee ─────────────────────────────────────────── -->
<?php if ($sel_emp): ?>
<div class="row g-4">

    <!-- Einträge -->
    <div class="col-lg-8">
        <div class="card pb-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-calendar2-week-fill"></i>
                    Urlaubstage &mdash; <strong><?= h($sel_emp['name']) ?></strong>
                </span>
                <span class="badge bg-secondary">
                    <?= count($vac_entries) ?> Einträge
                </span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($vac_entries)): ?>
                    <div class="p-4 text-muted">Noch keine Urlaubstage eingetragen.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-pb table-sm mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Wochentag</th>
                                <th>Status</th>
                                <th>Notiz</th>
                                <th class="text-end">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $dow_names = ['', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
                        foreach ($vac_entries as $ve):
                            $dow = (int)(new DateTime($ve['vacation_date']))->format('N');
                        ?>
                        <tr>
                            <td class="fw-semibold text-nowrap">
                                <?= h(date('d.m.Y', strtotime($ve['vacation_date']))) ?>
                            </td>
                            <td class="text-muted small"><?= $dow_names[$dow] ?? '' ?></td>
                            <td>
                                <span class="badge bg-<?= $status_colors[$ve['status']] ?? 'secondary' ?>">
                                    <?= h($status_labels[$ve['status']] ?? $ve['status']) ?>
                                </span>
                            </td>
                            <td class="small text-muted"><?= h($ve['notes'] ?? '') ?></td>
                            <td class="text-end text-nowrap">
                                <?php if (has_role('editor','admin')): ?>
                                <!-- Status change -->
                                <form method="post" class="d-inline">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action"     value="set_status">
                                    <input type="hidden" name="id"         value="<?= (int)$ve['id'] ?>">
                                    <input type="hidden" name="emp_id"     value="<?= $sel_emp_id ?>">
                                    <?php
                                    $next_statuses = [
                                        'geplant'    => ['genehmigt','genommen'],
                                        'genehmigt'  => ['genommen','geplant'],
                                        'genommen'   => ['geplant'],
                                    ];
                                    foreach ($next_statuses[$ve['status']] ?? [] as $ns):
                                    ?>
                                    <button type="submit" name="status" value="<?= $ns ?>"
                                            class="btn btn-sm btn-outline-<?= $status_colors[$ns] ?? 'secondary' ?> me-1">
                                        <?= h($status_labels[$ns]) ?>
                                    </button>
                                    <?php endforeach; ?>
                                </form>
                                <!-- Delete -->
                                <form method="post" class="d-inline">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id"     value="<?= (int)$ve['id'] ?>">
                                    <input type="hidden" name="emp_id" value="<?= $sel_emp_id ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            data-confirm="Urlaubstag <?= h(date('d.m.Y', strtotime($ve['vacation_date']))) ?> wirklich löschen?">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Eintragen -->
    <?php if (has_role('editor','admin')): ?>
    <div class="col-lg-4">
        <div class="card pb-card">
            <div class="card-header">
                <i class="bi bi-calendar-plus-fill"></i> Urlaub eintragen
            </div>
            <div class="card-body">
                <div class="alert alert-info small py-2 mb-3">
                    <strong><?= h($sel_emp['name']) ?></strong><br>
                    Anspruch: <strong><?= $sel_emp['gesamt_tage'] ?></strong> Tage &nbsp;|&nbsp;
                    Verbleibend: <strong class="text-<?= $sel_emp['verbleibend'] <= 0 ? 'danger' : 'success' ?>">
                        <?= $sel_emp['verbleibend'] ?>
                    </strong> Tage
                </div>
                <form method="post" action="urlaub.php?schuljahr=<?= $sel_sy_id ?>&ma=<?= $sel_emp_id ?>" novalidate>
                    <?= csrf_input() ?>
                    <input type="hidden" name="action"      value="add">
                    <input type="hidden" name="employee_id" value="<?= $sel_emp_id ?>">

                    <div class="mb-2">
                        <label class="form-label fw-semibold small">Von <span class="text-danger">*</span></label>
                        <input type="date" class="form-control form-control-sm" name="date_from" id="url_from"
                               min="<?= h($sel_sy['start_date'] ?? '') ?>"
                               max="<?= h($sel_sy['end_date']   ?? '') ?>"
                               required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold small">Bis (optional, für Zeitraum)</label>
                        <input type="date" class="form-control form-control-sm" name="date_to" id="url_to"
                               min="<?= h($sel_sy['start_date'] ?? '') ?>"
                               max="<?= h($sel_sy['end_date']   ?? '') ?>">
                        <div class="form-text">Wochenenden und Feiertage werden automatisch übersprungen.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Notiz</label>
                        <input type="text" class="form-control form-control-sm" name="notes"
                               placeholder="Optional" maxlength="255">
                    </div>
                    <button type="submit" class="btn btn-pb-primary btn-sm w-100">
                        <i class="bi bi-plus-lg"></i> Eintragen
                    </button>
                </form>
            </div>
        </div>

        <!-- Legende -->
        <div class="card pb-card mt-3">
            <div class="card-body py-2">
                <div class="small text-muted fw-semibold mb-1">Status-Legende</div>
                <div class="d-flex flex-column gap-1 small">
                    <div><span class="badge bg-warning text-dark me-1">Geplant</span> Eingeplant, noch nicht genehmigt</div>
                    <div><span class="badge bg-primary me-1">Genehmigt</span> Vom Vorgesetzten bestätigt</div>
                    <div><span class="badge bg-success me-1">Genommen</span> Urlaub wurde durchgeführt</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php endif; ?>

<script>
// Auto-set "Bis" if "Von" is set and "Bis" is empty
document.getElementById('url_from')?.addEventListener('change', function () {
    const to = document.getElementById('url_to');
    if (to && !to.value) to.value = this.value;
});
</script>

<?php require __DIR__ . '/../templates/footer.php'; ?>
