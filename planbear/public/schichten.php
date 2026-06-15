<?php
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/auth.php';

session_start_secure();
require_auth(['editor','admin']);

$pdo = get_pdo();

// ─── POST actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        flash('error', 'Ungültige Anfrage (CSRF).');
        redirect('schichten.php');
    }

    $action = $_POST['action'] ?? '';

    // ── Add new shift ──
    if ($action === 'add') {
        $name       = trim($_POST['name']       ?? '');
        $short_name = strtoupper(trim($_POST['short_name'] ?? ''));
        $t_start    = trim($_POST['time_start'] ?? '');
        $t_end      = trim($_POST['time_end']   ?? '');
        $color      = trim($_POST['color']      ?? '#6c757d');

        $errors = [];
        if ($name === '')                             $errors[] = 'Name ist ein Pflichtfeld.';
        if ($short_name === '')                       $errors[] = 'Kürzel ist ein Pflichtfeld.';
        if (!preg_match('/^\d{2}:\d{2}$/', $t_start)) $errors[] = 'Startzeit ungültig (HH:MM).';
        if (!preg_match('/^\d{2}:\d{2}$/', $t_end))   $errors[] = 'Endzeit ungültig (HH:MM).';
        if (empty($errors) && $t_start >= $t_end)     $errors[] = 'Startzeit muss vor der Endzeit liegen.';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#6c757d';

        if (empty($errors)) {
            $max_order = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM shifts')->fetchColumn();
            $pdo->prepare('INSERT INTO shifts (name, short_name, time_start, time_end, color, sort_order) VALUES (?,?,?,?,?,?)')
                ->execute([$name, $short_name, $t_start, $t_end, $color, $max_order + 1]);
            flash('success', 'Schicht "' . $name . '" angelegt.');
        } else {
            foreach ($errors as $e) flash('error', $e);
        }
        redirect('schichten.php');
    }

    // ── Update shift ──
    if ($action === 'update') {
        $id      = req_int('id', $_POST);
        $name    = trim($_POST['name']       ?? '');
        $short   = strtoupper(trim($_POST['short_name'] ?? ''));
        $t_start = trim($_POST['time_start'] ?? '');
        $t_end   = trim($_POST['time_end']   ?? '');
        $color   = trim($_POST['color']      ?? '#6c757d');

        $errors = [];
        if (!$id)                                       $errors[] = 'Ungültige ID.';
        if ($name === '')                               $errors[] = 'Name ist ein Pflichtfeld.';
        if (!preg_match('/^\d{2}:\d{2}$/', $t_start))  $errors[] = 'Startzeit ungültig.';
        if (!preg_match('/^\d{2}:\d{2}$/', $t_end))    $errors[] = 'Endzeit ungültig.';
        if (empty($errors) && $t_start >= $t_end)       $errors[] = 'Startzeit muss vor der Endzeit liegen.';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#6c757d';

        if (empty($errors)) {
            $pdo->prepare('UPDATE shifts SET name=?, short_name=?, time_start=?, time_end=?, color=? WHERE id=?')
                ->execute([$name, $short, $t_start, $t_end, $color, $id]);
            flash('success', "Schicht aktualisiert.");
        } else {
            foreach ($errors as $e) flash('error', $e);
        }
        redirect('schichten.php');
    }

    // ── Delete shift ──
    if ($action === 'delete') {
        require_auth(['admin']);
        $id = req_int('id', $_POST);
        if ($id) {
            // Check if any employees still use this shift
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM employee_shifts WHERE shift_id=?');
            $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn() > 0) {
                flash('error', 'Diese Schicht ist noch Mitarbeitern zugewiesen und kann nicht gelöscht werden.');
            } else {
                $pdo->prepare('DELETE FROM shifts WHERE id=?')->execute([$id]);
                flash('success', 'Schicht gelöscht.');
            }
        }
        redirect('schichten.php');
    }
}

// ─── Load shifts ───────────────────────────────────────────────────────────
$shifts = $pdo->query(
    'SELECT s.*, COUNT(es.employee_id) AS emp_count
     FROM shifts s
     LEFT JOIN employee_shifts es ON es.shift_id = s.id
     GROUP BY s.id
     ORDER BY s.sort_order, s.id'
)->fetchAll();

$page_title = 'Schichten';
$active_nav = 'schichten';
require __DIR__ . '/../templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="fw-bold mb-0" style="color:var(--pb-dark);">
        <i class="bi bi-clock-history"></i> Schichten
    </h1>
</div>

<div class="row g-4">

    <!-- ── Schichten-Liste ────────────────────────────────────────────── -->
    <div class="col-lg-8">
        <div class="card pb-card">
            <div class="card-header">
                <i class="bi bi-list-ul"></i> Definierte Schichten
            </div>
            <div class="card-body p-0">
                <?php if (empty($shifts)): ?>
                    <div class="p-4 text-muted">Noch keine Schichten definiert.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-pb table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Schicht</th>
                                <th>Zeit</th>
                                <th>Dauer</th>
                                <th>MA</th>
                                <?php if (has_role('editor','admin')): ?>
                                <th class="text-end">Aktionen</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($shifts as $sh):
                            [$sh_h, $sh_m] = array_map('intval', explode(':', substr($sh['time_start'],0,5)));
                            [$eh, $em]     = array_map('intval', explode(':', substr($sh['time_end'],  0,5)));
                            $dur_min = ($eh*60+$em) - ($sh_h*60+$sh_m);
                            $dur_h2  = intdiv($dur_min, 60);
                            $dur_m2  = $dur_min % 60;
                            $dur_str = ($dur_h2 > 0 ? $dur_h2 . 'h ' : '') . ($dur_m2 > 0 ? $dur_m2 . 'min' : '');
                        ?>
                        <tr>
                            <td>
                                <span class="badge me-2" style="background:<?= h($sh['color']) ?>;">
                                    <?= h($sh['short_name']) ?>
                                </span>
                                <strong><?= h($sh['name']) ?></strong>
                            </td>
                            <td class="text-nowrap">
                                <?= h(substr($sh['time_start'],0,5)) ?> &ndash; <?= h(substr($sh['time_end'],0,5)) ?> Uhr
                            </td>
                            <td class="text-muted small"><?= h(trim($dur_str)) ?></td>
                            <td>
                                <span class="badge bg-secondary"><?= (int)$sh['emp_count'] ?></span>
                            </td>
                            <?php if (has_role('editor','admin')): ?>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-secondary"
                                        onclick="openEditShift(<?= (int)$sh['id'] ?>,
                                            '<?= addslashes(h($sh['name'])) ?>',
                                            '<?= h($sh['short_name']) ?>',
                                            '<?= h(substr($sh['time_start'],0,5)) ?>',
                                            '<?= h(substr($sh['time_end'],  0,5)) ?>',
                                            '<?= h($sh['color']) ?>')">
                                    <i class="bi bi-pencil-fill"></i>
                                </button>
                                <?php if (has_role('admin')): ?>
                                <form method="post" class="d-inline">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$sh['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            data-confirm="Schicht „<?= h($sh['name']) ?>" wirklich löschen?"
                                            <?= $sh['emp_count'] > 0 ? 'title="Erst alle Mitarbeiter entfernen" disabled' : '' ?>>
                                        <i class="bi bi-trash3-fill"></i>
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
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Neue Schicht ───────────────────────────────────────────────── -->
    <?php if (has_role('editor','admin')): ?>
    <div class="col-lg-4">
        <div class="card pb-card" id="shift-form-card">
            <div class="card-header" id="shift-form-title">
                <i class="bi bi-plus-circle-fill"></i> Neue Schicht
            </div>
            <div class="card-body">
                <form method="post" action="schichten.php" novalidate id="shift-form">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="add" id="shift-action">
                    <input type="hidden" name="id"     value=""    id="shift-id">

                    <div class="mb-2">
                        <label class="form-label fw-semibold small">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" name="name" id="sf-name"
                               placeholder="z.B. Frühdienst" maxlength="50" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold small">Kürzel <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" name="short_name" id="sf-short"
                               placeholder="FD" maxlength="10" style="text-transform:uppercase" required>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col">
                            <label class="form-label fw-semibold small">Von <span class="text-danger">*</span></label>
                            <input type="time" class="form-control form-control-sm" name="time_start" id="sf-start">
                        </div>
                        <div class="col">
                            <label class="form-label fw-semibold small">Bis <span class="text-danger">*</span></label>
                            <input type="time" class="form-control form-control-sm" name="time_end" id="sf-end">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Farbe</label>
                        <div class="d-flex align-items-center gap-2">
                            <input type="color" class="form-control form-control-color" name="color" id="sf-color"
                                   value="#6c757d" style="width:48px;height:36px;">
                            <span class="badge" id="sf-preview" style="background:#6c757d;">Vorschau</span>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-pb-primary btn-sm flex-grow-1" id="sf-submit">
                            <i class="bi bi-plus-lg"></i> Anlegen
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="sf-cancel"
                                style="display:none;" onclick="resetShiftForm()">
                            Abbrechen
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Edit modal via JavaScript — reuses the sidebar form -->
<script>
function openEditShift(id, name, short_name, t_start, t_end, color) {
    document.getElementById('shift-action').value = 'update';
    document.getElementById('shift-id').value     = id;
    document.getElementById('sf-name').value      = name;
    document.getElementById('sf-short').value     = short_name;
    document.getElementById('sf-start').value     = t_start;
    document.getElementById('sf-end').value       = t_end;
    document.getElementById('sf-color').value     = color;
    document.getElementById('sf-preview').style.background = color;
    document.getElementById('sf-preview').textContent      = short_name || 'Vorschau';
    document.getElementById('shift-form-title').innerHTML  = '<i class="bi bi-pencil-fill"></i> Schicht bearbeiten';
    document.getElementById('sf-submit').innerHTML = '<i class="bi bi-check-lg"></i> Speichern';
    document.getElementById('sf-cancel').style.display = '';
    document.getElementById('shift-form-card').scrollIntoView({behavior:'smooth'});
}

function resetShiftForm() {
    document.getElementById('shift-action').value = 'add';
    document.getElementById('shift-id').value     = '';
    document.getElementById('sf-name').value      = '';
    document.getElementById('sf-short').value     = '';
    document.getElementById('sf-start').value     = '';
    document.getElementById('sf-end').value       = '';
    document.getElementById('sf-color').value     = '#6c757d';
    document.getElementById('sf-preview').style.background = '#6c757d';
    document.getElementById('sf-preview').textContent      = 'Vorschau';
    document.getElementById('shift-form-title').innerHTML  = '<i class="bi bi-plus-circle-fill"></i> Neue Schicht';
    document.getElementById('sf-submit').innerHTML = '<i class="bi bi-plus-lg"></i> Anlegen';
    document.getElementById('sf-cancel').style.display = 'none';
}

// Live color preview
document.getElementById('sf-color')?.addEventListener('input', function () {
    document.getElementById('sf-preview').style.background = this.value;
});
</script>

<?php require __DIR__ . '/../templates/footer.php'; ?>
