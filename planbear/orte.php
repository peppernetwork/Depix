<?php
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/zuteilung.php';

session_start_secure();
require_auth(['editor','admin']);

$pdo = get_pdo();
ensure_location_tables($pdo);

// ─── POST actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        flash('error', 'Ungültige Anfrage (CSRF).');
        redirect('orte.php');
    }

    $action = $_POST['action'] ?? '';

    // ── Add new location ──
    if ($action === 'add') {
        $name  = trim($_POST['name']  ?? '');
        $color = trim($_POST['color'] ?? '#6c757d');

        $errors = [];
        if ($name === '') $errors[] = 'Name ist ein Pflichtfeld.';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#6c757d';

        if (empty($errors)) {
            $max_order = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM locations')->fetchColumn();
            $pdo->prepare('INSERT INTO locations (name, color, sort_order) VALUES (?,?,?)')
                ->execute([$name, $color, $max_order + 1]);
            flash('success', 'Ort "' . $name . '" angelegt.');
        } else {
            foreach ($errors as $e) flash('error', $e);
        }
        redirect('orte.php');
    }

    // ── Update location ──
    if ($action === 'update') {
        $id    = req_int('id', $_POST);
        $name  = trim($_POST['name']  ?? '');
        $color = trim($_POST['color'] ?? '#6c757d');

        $errors = [];
        if (!$id)         $errors[] = 'Ungültige ID.';
        if ($name === '') $errors[] = 'Name ist ein Pflichtfeld.';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#6c757d';

        if (empty($errors)) {
            $pdo->prepare('UPDATE locations SET name=?, color=? WHERE id=?')->execute([$name, $color, $id]);
            flash('success', 'Ort aktualisiert.');
        } else {
            foreach ($errors as $e) flash('error', $e);
        }
        redirect('orte.php');
    }

    // ── Delete location ──
    if ($action === 'delete') {
        require_auth(['admin']);
        $id = req_int('id', $_POST);
        if ($id) {
            $pdo->prepare('DELETE FROM locations WHERE id=?')->execute([$id]);
            flash('success', 'Ort gelöscht.');
        }
        redirect('orte.php');
    }
}

// ─── Load locations ────────────────────────────────────────────────────────
$locations = $pdo->query(
    'SELECT l.*, COUNT(DISTINCT elp.employee_id) AS pref_count
     FROM locations l
     LEFT JOIN employee_location_preferences elp ON elp.location_id = l.id
     GROUP BY l.id
     ORDER BY l.sort_order, l.id'
)->fetchAll();

$page_title = 'Orte';
$active_nav = 'orte';
require __DIR__ . '/templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="fw-bold mb-0" style="color:var(--pb-dark);">
        <i class="bi bi-geo-alt-fill"></i> Orte
    </h1>
</div>

<div class="row g-4">

    <!-- ── Orte-Liste ─────────────────────────────────────────────────── -->
    <div class="col-lg-8">
        <div class="card pb-card">
            <div class="card-header">
                <i class="bi bi-list-ul"></i> Definierte Orte
            </div>
            <div class="card-body p-0">
                <?php if (empty($locations)): ?>
                    <div class="p-4 text-muted">Noch keine Orte definiert.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-pb table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Ort</th>
                                <th>Präferiert von</th>
                                <?php if (has_role('editor','admin')): ?>
                                <th class="text-end">Aktionen</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($locations as $loc): ?>
                        <tr>
                            <td>
                                <span class="badge me-2" style="background:<?= h($loc['color']) ?>;">&nbsp;</span>
                                <strong><?= h($loc['name']) ?></strong>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?= (int)$loc['pref_count'] ?> MA</span>
                            </td>
                            <?php if (has_role('editor','admin')): ?>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-secondary"
                                        onclick="openEditLocation(<?= (int)$loc['id'] ?>,
                                            '<?= addslashes(h($loc['name'])) ?>',
                                            '<?= h($loc['color']) ?>')">
                                    <i class="bi bi-pencil-fill"></i>
                                </button>
                                <?php if (has_role('admin')): ?>
                                <form method="post" class="d-inline">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$loc['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            data-confirm="Ort „<?= h($loc['name']) ?>" wirklich löschen?">
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

    <!-- ── Neuer Ort ──────────────────────────────────────────────────── -->
    <?php if (has_role('editor','admin')): ?>
    <div class="col-lg-4">
        <div class="card pb-card" id="location-form-card">
            <div class="card-header" id="location-form-title">
                <i class="bi bi-plus-circle-fill"></i> Neuer Ort
            </div>
            <div class="card-body">
                <form method="post" action="orte.php" novalidate id="location-form">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="add" id="location-action">
                    <input type="hidden" name="id"     value=""    id="location-id">

                    <div class="mb-2">
                        <label class="form-label fw-semibold small">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" name="name" id="lf-name"
                               placeholder="z.B. Lernzeit, Mensa, Pausenhof" maxlength="100" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Farbe</label>
                        <div class="d-flex align-items-center gap-2">
                            <input type="color" class="form-control form-control-color" name="color" id="lf-color"
                                   value="#6c757d" style="width:48px;height:36px;">
                            <span class="badge" id="lf-preview" style="background:#6c757d;">Vorschau</span>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-pb-primary btn-sm flex-grow-1" id="lf-submit">
                            <i class="bi bi-plus-lg"></i> Anlegen
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="lf-cancel"
                                style="display:none;" onclick="resetLocationForm()">
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
function openEditLocation(id, name, color) {
    document.getElementById('location-action').value = 'update';
    document.getElementById('location-id').value     = id;
    document.getElementById('lf-name').value          = name;
    document.getElementById('lf-color').value         = color;
    document.getElementById('lf-preview').style.background = color;
    document.getElementById('location-form-title').innerHTML = '<i class="bi bi-pencil-fill"></i> Ort bearbeiten';
    document.getElementById('lf-submit').innerHTML = '<i class="bi bi-check-lg"></i> Speichern';
    document.getElementById('lf-cancel').style.display = '';
    document.getElementById('location-form-card').scrollIntoView({behavior:'smooth'});
}

function resetLocationForm() {
    document.getElementById('location-action').value = 'add';
    document.getElementById('location-id').value     = '';
    document.getElementById('lf-name').value          = '';
    document.getElementById('lf-color').value         = '#6c757d';
    document.getElementById('lf-preview').style.background = '#6c757d';
    document.getElementById('location-form-title').innerHTML = '<i class="bi bi-plus-circle-fill"></i> Neuer Ort';
    document.getElementById('lf-submit').innerHTML = '<i class="bi bi-plus-lg"></i> Anlegen';
    document.getElementById('lf-cancel').style.display = 'none';
}

// Live color preview
document.getElementById('lf-color')?.addEventListener('input', function () {
    document.getElementById('lf-preview').style.background = this.value;
});
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
