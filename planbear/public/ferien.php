<?php
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/auth.php';

session_start_secure();
require_auth(); // All roles can view

$pdo = get_pdo();

// Active school year
$sy = $pdo->query('SELECT * FROM school_years WHERE is_active=1 LIMIT 1')->fetch();
$all_sy = $pdo->query('SELECT * FROM school_years ORDER BY start_date DESC')->fetchAll();

$sy_id = req_int('sy_id', $_GET) ?? ($sy ? (int)$sy['id'] : null);
if (!$sy_id && !empty($all_sy)) {
    $sy_id = (int)$all_sy[0]['id'];
}

// Handle POST actions (editor/admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_auth(['editor','admin']);
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        flash('error', 'Ungültige Anfrage (CSRF).');
        redirect("ferien.php?sy_id={$sy_id}");
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_holiday') {
        $date = $_POST['holiday_date'] ?? '';
        $name = trim($_POST['holiday_name'] ?? '');
        $sid  = req_int('school_year_id', $_POST);
        if (!$date || !$name || !$sid) {
            flash('error', 'Alle Felder sind Pflicht.');
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO public_holidays (school_year_id, holiday_date, name) VALUES (?,?,?)');
                $stmt->execute([$sid, $date, $name]);
                flash('success', 'Feiertag hinzugefügt.');
            } catch (PDOException $e) {
                flash('error', 'Dieser Feiertag existiert für das Schuljahr bereits.');
            }
        }
    }

    if ($action === 'delete_holiday') {
        $id = req_int('id', $_POST);
        if ($id) {
            $pdo->prepare('DELETE FROM public_holidays WHERE id=?')->execute([$id]);
            flash('success', 'Feiertag gelöscht.');
        }
    }

    if ($action === 'add_vacation') {
        $vname   = trim($_POST['vac_name'] ?? '');
        $vstart  = $_POST['vac_start'] ?? '';
        $vend    = $_POST['vac_end']   ?? '';
        $vwork   = isset($_POST['vac_work']) ? 1 : 0;
        $sid     = req_int('school_year_id', $_POST);
        if (!$vname || !$vstart || !$vend || !$sid) {
            flash('error', 'Alle Felder sind Pflicht.');
        } elseif ($vend < $vstart) {
            flash('error', 'Enddatum muss nach dem Startdatum liegen.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO vacation_periods (school_year_id, name, start_date, end_date, is_work_period) VALUES (?,?,?,?,?)');
            $stmt->execute([$sid, $vname, $vstart, $vend, $vwork]);
            flash('success', 'Ferienzeit hinzugefügt.');
        }
    }

    if ($action === 'delete_vacation') {
        $id = req_int('id', $_POST);
        if ($id) {
            $pdo->prepare('DELETE FROM vacation_periods WHERE id=?')->execute([$id]);
            flash('success', 'Ferienzeit gelöscht.');
        }
    }

    if ($action === 'toggle_work_period') {
        $id  = req_int('id', $_POST);
        $cur = req_int('current', $_POST);
        if ($id !== null && $cur !== null) {
            $pdo->prepare('UPDATE vacation_periods SET is_work_period=? WHERE id=?')->execute([$cur ? 0 : 1, $id]);
            flash('success', 'Aktualisiert.');
        }
    }

    redirect("ferien.php?sy_id={$sy_id}");
}

// Load data for current school year
$holidays  = [];
$vacations = [];
if ($sy_id) {
    $stmt = $pdo->prepare('SELECT * FROM public_holidays WHERE school_year_id=? ORDER BY holiday_date');
    $stmt->execute([$sy_id]);
    $holidays = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM vacation_periods WHERE school_year_id=? ORDER BY start_date');
    $stmt->execute([$sy_id]);
    $vacations = $stmt->fetchAll();
}

$current_sy = null;
foreach ($all_sy as $s) {
    if ((int)$s['id'] === $sy_id) {
        $current_sy = $s;
        break;
    }
}

$page_title = 'Ferien & Feiertage';
$active_nav = 'ferien';
require __DIR__ . '/../templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="fw-bold mb-0" style="color:var(--pb-dark);">
        <i class="bi bi-sun-fill"></i> Ferien &amp; Feiertage
    </h1>
    <!-- School year selector -->
    <form method="get" action="ferien.php" class="d-flex gap-2 align-items-center">
        <label class="form-label mb-0 fw-semibold text-nowrap">Schuljahr:</label>
        <select name="sy_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ($all_sy as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $s['id'] == $sy_id ? 'selected' : '' ?>>
                    <?= h($s['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if (!$sy_id): ?>
    <div class="alert alert-warning">Kein Schuljahr vorhanden. Bitte zuerst via Install einrichten.</div>
<?php else: ?>

<div class="row g-4">
    <!-- HOLIDAYS -->
    <div class="col-lg-6">
        <div class="card pb-card h-100">
            <div class="card-header">
                <i class="bi bi-calendar-x-fill"></i> Gesetzliche Feiertage
                <?php if ($current_sy): ?>
                    <small class="ms-2 opacity-75"><?= h($current_sy['name']) ?></small>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($holidays)): ?>
                    <p class="text-muted">Keine Feiertage eingetragen.</p>
                <?php else: ?>
                <ul class="list-group list-group-flush mb-3">
                    <?php foreach ($holidays as $hol): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <div>
                            <span class="fw-semibold"><?= h($hol['name']) ?></span><br>
                            <small class="text-muted"><?= h(format_date_de($hol['holiday_date'])) ?></small>
                        </div>
                        <?php if (has_role('editor','admin')): ?>
                        <form method="post" action="ferien.php?sy_id=<?= $sy_id ?>" class="d-inline">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete_holiday">
                            <input type="hidden" name="id" value="<?= (int)$hol['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    data-confirm="Feiertag „<?= h($hol['name']) ?>" löschen?">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <?php if (has_role('editor','admin')): ?>
                <hr>
                <h6 class="fw-semibold">Feiertag hinzufügen</h6>
                <form method="post" action="ferien.php?sy_id=<?= $sy_id ?>">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="add_holiday">
                    <input type="hidden" name="school_year_id" value="<?= $sy_id ?>">
                    <div class="mb-2">
                        <input type="text" class="form-control form-control-sm" name="holiday_name"
                               placeholder="Name des Feiertags" maxlength="100" required>
                    </div>
                    <div class="mb-2">
                        <input type="date" class="form-control form-control-sm" name="holiday_date" required>
                    </div>
                    <button type="submit" class="btn btn-sm btn-pb-primary">
                        <i class="bi bi-plus-lg"></i> Hinzufügen
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- VACATION PERIODS -->
    <div class="col-lg-6">
        <div class="card pb-card h-100">
            <div class="card-header">
                <i class="bi bi-umbrella-fill"></i> Schulferien
                <?php if ($current_sy): ?>
                    <small class="ms-2 opacity-75"><?= h($current_sy['name']) ?></small>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($vacations)): ?>
                    <p class="text-muted">Keine Schulferien eingetragen.</p>
                <?php else: ?>
                <ul class="list-group list-group-flush mb-3">
                    <?php foreach ($vacations as $vac): ?>
                    <li class="list-group-item px-0">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="fw-semibold"><?= h($vac['name']) ?></span><br>
                                <small class="text-muted">
                                    <?= h(format_date_de($vac['start_date'])) ?> –
                                    <?= h(format_date_de($vac['end_date'])) ?>
                                </small><br>
                                <?php if ($vac['is_work_period']): ?>
                                    <span class="badge bg-warning text-dark">
                                        <i class="bi bi-briefcase-fill"></i> Wird gearbeitet
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">
                                        <i class="bi bi-moon-fill"></i> Kein Dienst
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if (has_role('editor','admin')): ?>
                            <div class="d-flex gap-1">
                                <form method="post" action="ferien.php?sy_id=<?= $sy_id ?>" class="d-inline">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="toggle_work_period">
                                    <input type="hidden" name="id" value="<?= (int)$vac['id'] ?>">
                                    <input type="hidden" name="current" value="<?= (int)$vac['is_work_period'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary"
                                            title="Arbeitsstatus umschalten">
                                        <i class="bi bi-toggle-<?= $vac['is_work_period'] ? 'on' : 'off' ?>"></i>
                                    </button>
                                </form>
                                <form method="post" action="ferien.php?sy_id=<?= $sy_id ?>" class="d-inline">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="delete_vacation">
                                    <input type="hidden" name="id" value="<?= (int)$vac['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            data-confirm="Ferienzeit „<?= h($vac['name']) ?>" löschen?">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <?php if (has_role('editor','admin')): ?>
                <hr>
                <h6 class="fw-semibold">Schulferien hinzufügen</h6>
                <form method="post" action="ferien.php?sy_id=<?= $sy_id ?>">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="add_vacation">
                    <input type="hidden" name="school_year_id" value="<?= $sy_id ?>">
                    <div class="mb-2">
                        <input type="text" class="form-control form-control-sm" name="vac_name"
                               placeholder="Name (z.B. Herbstferien)" maxlength="100" required>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col">
                            <input type="date" class="form-control form-control-sm" name="vac_start" required placeholder="Von">
                        </div>
                        <div class="col">
                            <input type="date" class="form-control form-control-sm" name="vac_end" required placeholder="Bis">
                        </div>
                    </div>
                    <div class="form-check mb-2">
                        <input type="checkbox" class="form-check-input" name="vac_work" id="vac_work" value="1">
                        <label class="form-check-label" for="vac_work">Wird gearbeitet (Feriendienst)</label>
                    </div>
                    <button type="submit" class="btn btn-sm btn-pb-primary">
                        <i class="bi bi-plus-lg"></i> Hinzufügen
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../templates/footer.php'; ?>
