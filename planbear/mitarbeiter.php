<?php
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
require_auth(); // All roles can view

$pdo = get_pdo();

// Handle DELETE (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    require_auth(['admin']);
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        flash('error', 'Ungültige Anfrage (CSRF).');
        redirect('mitarbeiter.php');
    }
    $id = req_int('id', $_POST);
    if ($id) {
        $stmt = $pdo->prepare('UPDATE employees SET is_active=0 WHERE id=?');
        $stmt->execute([$id]);
        flash('success', 'Mitarbeiter deaktiviert.');
    }
    redirect('mitarbeiter.php');
}

// Load all employees with time info
$employees = $pdo->query(
    'SELECT id, name_enc, weekly_hours, vacation_hours, available_days,
            time_mode, week_time_start, week_time_end, is_active
     FROM employees ORDER BY id'
)->fetchAll();

// Load per-day times keyed by employee_id + day
$day_times_all = [];
foreach ($pdo->query('SELECT employee_id, day_of_week, time_start, time_end FROM employee_day_times') as $r) {
    $day_times_all[(int)$r['employee_id']][(int)$r['day_of_week']] = [
        substr($r['time_start'], 0, 5), substr($r['time_end'], 0, 5)
    ];
}

// Load shift assignments per employee: [emp_id => [shift, ...]]
$emp_shifts_all = [];
$rows = $pdo->query(
    'SELECT es.employee_id, s.name, s.short_name, s.color, s.time_start, s.time_end
     FROM employee_shifts es
     JOIN shifts s ON s.id = es.shift_id
     ORDER BY s.sort_order, s.id'
)->fetchAll();
foreach ($rows as $r) {
    $emp_shifts_all[(int)$r['employee_id']][] = $r;
}

$page_title = 'Mitarbeiter';
$active_nav = 'mitarbeiter';
require __DIR__ . '/../templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="fw-bold mb-0" style="color:var(--pb-dark);">
        <i class="bi bi-people-fill"></i> Mitarbeiter
    </h1>
    <?php if (has_role('editor','admin')): ?>
    <a href="mitarbeiter_form.php" class="btn btn-pb-primary">
        <i class="bi bi-person-plus-fill"></i> Neu anlegen
    </a>
    <?php endif; ?>
</div>

<?php if (empty($employees)): ?>
    <div class="alert alert-info">
        Noch keine Mitarbeiter angelegt.
        <?php if (has_role('editor','admin')): ?>
            <a href="mitarbeiter_form.php">Jetzt ersten Mitarbeiter anlegen</a>.
        <?php endif; ?>
    </div>
<?php else: ?>
<div class="card pb-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-pb table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Dienste</th>
                        <th>Arbeitszeit</th>
                        <th>Ferien-Std.</th>
                        <th>Verf. Tage</th>
                        <th>Status</th>
                        <?php if (has_role('editor','admin')): ?>
                        <th class="text-end">Aktionen</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $emp): ?>
                    <tr>
                        <td class="text-muted small"><?= h((string)$emp['id']) ?></td>
                        <td class="fw-semibold"><?= h(decrypt($emp['name_enc'])) ?></td>
                        <td>
                            <?php
                            $eid = (int)$emp['id'];
                            if (!empty($emp_shifts_all[$eid])) {
                                foreach ($emp_shifts_all[$eid] as $sh) {
                                    echo '<span class="badge me-1" style="background:' . h($sh['color']) . ';">'
                                        . h($sh['short_name']) . '</span>';
                                }
                            } else {
                                echo '<span class="text-muted small">—</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            $mode = $emp['time_mode'] ?? 'full';
                            $eid  = (int)$emp['id'];
                            $day_labels_short = ['Mo','Di','Mi','Do','Fr'];
                            if ($mode === 'full') {
                                echo '<span class="badge bg-secondary">Voll</span>';
                            } elseif ($mode === 'week') {
                                $ts = substr($emp['week_time_start'] ?? '', 0, 5);
                                $te = substr($emp['week_time_end']   ?? '', 0, 5);
                                echo '<span class="text-nowrap small">' . h($ts) . '–' . h($te) . ' Uhr</span>';
                            } else {
                                // day mode — show compact per-day
                                $parts = [];
                                foreach ($day_times_all[$eid] ?? [] as $di => $t) {
                                    $parts[] = '<span class="text-nowrap">' . $day_labels_short[$di] . ' ' . h($t[0]) . '–' . h($t[1]) . '</span>';
                                }
                                echo $parts ? implode('<br>', $parts) : '<span class="text-muted small">—</span>';
                            }
                            ?>
                        </td>
                        <td><?= h(number_format((float)$emp['vacation_hours'], 2, ',', '.')) ?> h</td>
                        <td>
                            <?php foreach (parse_available_days($emp['available_days']) as $day): ?>
                                <span class="day-badge"><?= h($day) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?php if ($emp['is_active']): ?>
                                <span class="badge bg-success">Aktiv</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inaktiv</span>
                            <?php endif; ?>
                        </td>
                        <?php if (has_role('editor','admin')): ?>
                        <td class="text-end">
                            <a href="mitarbeiter_form.php?id=<?= (int)$emp['id'] ?>"
                               class="btn btn-sm btn-outline-secondary me-1">
                                <i class="bi bi-pencil-fill"></i> Bearbeiten
                            </a>
                            <?php if (has_role('admin')): ?>
                            <form method="post" action="mitarbeiter.php" class="d-inline">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        data-confirm="Mitarbeiter wirklich deaktivieren?">
                                    <i class="bi bi-person-dash-fill"></i> Deaktivieren
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../templates/footer.php'; ?>
