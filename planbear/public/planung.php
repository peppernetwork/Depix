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

$pdo  = get_pdo();
$user = current_user();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_auth(['editor','admin']);

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        flash('error', 'Ungültige Anfrage (CSRF).');
        redirect('planung.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_plan') {
        $sy_id = req_int('school_year_id', $_POST);
        $name  = trim($_POST['plan_name'] ?? '');
        if (!$sy_id || $name === '') {
            flash('error', 'Schuljahr und Name sind Pflichtfelder.');
        } else {
            try {
                [$sched_id, $rev_id, $count] = create_schedule_with_revision($pdo, $sy_id, $name, $user['id']);
                flash('success', 'Plan "' . $name . '" erstellt mit ' . $count . ' Einträgen (Revision 1).');
                redirect('planung_view.php?schedule_id=' . $sched_id . '&revision_id=' . $rev_id);
            } catch (Exception $e) {
                flash('error', 'Fehler beim Erstellen: ' . $e->getMessage());
            }
        }
    }

    if ($action === 'new_revision') {
        $sched_id = req_int('schedule_id', $_POST);
        if ($sched_id) {
            try {
                [$rev_id, $count] = add_revision($pdo, $sched_id, $user['id'], 'Automatisch generiert');
                flash('success', "Neue Revision mit {$count} Einträgen erstellt.");
                redirect('planung_view.php?schedule_id=' . $sched_id . '&revision_id=' . $rev_id);
            } catch (Exception $e) {
                flash('error', 'Fehler: ' . $e->getMessage());
            }
        }
    }

    if ($action === 'delete_schedule') {
        require_auth(['admin']);
        $sched_id = req_int('schedule_id', $_POST);
        if ($sched_id) {
            $stmt = $pdo->prepare('DELETE FROM schedules WHERE id=?');
            $stmt->execute([$sched_id]);
            flash('success', 'Plan gelöscht.');
        }
    }

    redirect('planung.php');
}

// Load school years
$school_years = $pdo->query('SELECT id, name FROM school_years ORDER BY start_date DESC')->fetchAll();

// Load schedules with revision info
$schedules = $pdo->query(
    'SELECT s.id, s.name, sy.name AS year_name,
            COUNT(r.id) AS rev_count,
            MAX(r.created_at) AS last_rev,
            MAX(r.id) AS latest_rev_id
     FROM schedules s
     JOIN school_years sy ON sy.id = s.school_year_id
     LEFT JOIN schedule_revisions r ON r.schedule_id = s.id
     WHERE s.is_active = 1
     GROUP BY s.id, s.name, sy.name
     ORDER BY s.id DESC'
)->fetchAll();

$page_title = 'Planung';
$active_nav = 'planung';
require __DIR__ . '/../templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="fw-bold mb-0" style="color:var(--pb-dark);">
        <i class="bi bi-calendar-week"></i> Planung
    </h1>
    <?php if (has_role('editor','admin')): ?>
    <button class="btn btn-pb-primary" data-bs-toggle="modal" data-bs-target="#createPlanModal">
        <i class="bi bi-plus-circle-fill"></i> Neuen Plan erstellen
    </button>
    <?php endif; ?>
</div>

<?php if (empty($schedules)): ?>
    <div class="alert alert-info">
        Noch keine Pläne vorhanden.
        <?php if (has_role('editor','admin')): ?>
            Erstellen Sie jetzt den ersten Plan.
        <?php endif; ?>
    </div>
<?php else: ?>
<div class="card pb-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-pb table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Schuljahr</th>
                        <th>Revisionen</th>
                        <th>Letzte Revision</th>
                        <th class="text-end">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedules as $s): ?>
                    <tr>
                        <td class="fw-semibold"><?= h($s['name']) ?></td>
                        <td><?= h($s['year_name']) ?></td>
                        <td><span class="badge bg-secondary"><?= (int)$s['rev_count'] ?></span></td>
                        <td><?= $s['last_rev'] ? h(format_date_de($s['last_rev'])) : '—' ?></td>
                        <td class="text-end">
                            <?php if ($s['latest_rev_id']): ?>
                            <a href="planung_view.php?schedule_id=<?= (int)$s['id'] ?>&revision_id=<?= (int)$s['latest_rev_id'] ?>"
                               class="btn btn-sm btn-pb-outline me-1">
                                <i class="bi bi-eye"></i> Ansehen
                            </a>
                            <?php endif; ?>
                            <?php if (has_role('editor','admin')): ?>
                            <form method="post" action="planung.php" class="d-inline">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="new_revision">
                                <input type="hidden" name="schedule_id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary me-1">
                                    <i class="bi bi-arrow-clockwise"></i> Neue Revision
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php if (has_role('admin')): ?>
                            <form method="post" action="planung.php" class="d-inline">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="delete_schedule">
                                <input type="hidden" name="schedule_id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        data-confirm="Plan „<?= h($s['name']) ?>" wirklich löschen?">
                                    <i class="bi bi-trash3-fill"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Create Plan Modal -->
<?php if (has_role('editor','admin')): ?>
<div class="modal fade" id="createPlanModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="planung.php">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="create_plan">
                <div class="modal-header" style="background:var(--pb-dark);color:var(--pb-light);">
                    <h5 class="modal-title"><i class="bi bi-calendar-plus"></i> Neuen Plan erstellen</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="school_year_id" class="form-label fw-semibold">Schuljahr</label>
                        <select class="form-select" id="school_year_id" name="school_year_id" required>
                            <option value="">— bitte wählen —</option>
                            <?php foreach ($school_years as $sy): ?>
                                <option value="<?= (int)$sy['id'] ?>"><?= h($sy['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="plan_name" class="form-label fw-semibold">Planname</label>
                        <input type="text" class="form-control" id="plan_name" name="plan_name"
                               placeholder="z.B. Hauptplan 2026/2027" maxlength="100" required>
                    </div>
                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-info-circle"></i>
                        Der Plan wird automatisch auf Basis der Mitarbeiter und Feriendaten generiert.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-pb-primary">
                        <i class="bi bi-gear-fill"></i> Generieren
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../templates/footer.php'; ?>
