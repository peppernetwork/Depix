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
$emp_id  = req_int('id', $_GET);
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

$errors = [];
$form   = [
    'name'           => $is_edit ? decrypt($emp['name_enc']) : '',
    'weekly_hours'   => $is_edit ? $emp['weekly_hours']   : '0',
    'vacation_hours' => $is_edit ? $emp['vacation_hours'] : '0',
    'available_days' => $is_edit ? explode(',', $emp['available_days']) : ['0','1','2','3','4'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Ungültige Anfrage (CSRF).';
    } else {
        $name    = trim($_POST['name'] ?? '');
        $weekly  = $_POST['weekly_hours']   ?? '0';
        $vacay   = $_POST['vacation_hours'] ?? '0';
        $days    = $_POST['available_days'] ?? [];

        if ($name === '') {
            $errors[] = 'Name ist ein Pflichtfeld.';
        }
        if (!is_numeric($weekly) || (float)$weekly < 0) {
            $errors[] = 'Wochenstunden muss eine positive Zahl sein.';
        }
        if (!is_numeric($vacay) || (float)$vacay < 0) {
            $errors[] = 'Ferienstunden muss eine positive Zahl sein.';
        }
        // Validate days
        $valid_days = ['0','1','2','3','4'];
        $days       = array_intersect((array)$days, $valid_days);
        if (empty($days)) {
            $errors[] = 'Mindestens ein verfügbarer Tag muss ausgewählt sein.';
        }

        $form = [
            'name'           => $name,
            'weekly_hours'   => $weekly,
            'vacation_hours' => $vacay,
            'available_days' => $days,
        ];

        if (empty($errors)) {
            $name_enc   = encrypt($name);
            $days_str   = implode(',', $days);

            if ($is_edit) {
                $stmt = $pdo->prepare(
                    'UPDATE employees SET name_enc=?, weekly_hours=?, vacation_hours=?, available_days=? WHERE id=?'
                );
                $stmt->execute([$name_enc, (float)$weekly, (float)$vacay, $days_str, $emp_id]);
                flash('success', 'Mitarbeiter aktualisiert.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO employees (name_enc, weekly_hours, vacation_hours, available_days) VALUES (?,?,?,?)'
                );
                $stmt->execute([$name_enc, (float)$weekly, (float)$vacay, $days_str]);
                flash('success', 'Mitarbeiter angelegt.');
            }
            redirect('mitarbeiter.php');
        }
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

<div class="card pb-card" style="max-width:600px;">
    <div class="card-header">
        <i class="bi bi-person-lines-fill"></i>
        <?= $is_edit ? 'Daten bearbeiten' : 'Neuen Mitarbeiter anlegen' ?>
    </div>
    <div class="card-body">
        <form method="post" action="mitarbeiter_form.php<?= $is_edit ? '?id=' . $emp_id : '' ?>" novalidate>
            <?= csrf_input() ?>

            <div class="mb-3">
                <label for="name" class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="name" name="name"
                       value="<?= h($form['name']) ?>" required maxlength="200">
            </div>

            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label for="weekly_hours" class="form-label fw-semibold">Wochenstunden</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="weekly_hours" name="weekly_hours"
                               value="<?= h((string)$form['weekly_hours']) ?>"
                               min="0" max="60" step="0.5" required>
                        <span class="input-group-text">h</span>
                    </div>
                </div>
                <div class="col-6">
                    <label for="vacation_hours" class="form-label fw-semibold">Ferienstunden</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="vacation_hours" name="vacation_hours"
                               value="<?= h((string)$form['vacation_hours']) ?>"
                               min="0" max="60" step="0.5" required>
                        <span class="input-group-text">h</span>
                    </div>
                    <div class="form-text">0 = kein Dienst in Ferien</div>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold">Verfügbare Tage <span class="text-danger">*</span></label>
                <div class="d-flex gap-3">
                    <?php foreach ($day_labels as $idx => $label): ?>
                    <div class="form-check">
                        <input class="form-check-input day-check" type="checkbox"
                               name="available_days[]" value="<?= $idx ?>"
                               id="day_<?= $idx ?>"
                               <?= in_array((string)$idx, array_map('strval', $form['available_days'])) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="day_<?= $idx ?>"><?= $label ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-pb-primary">
                    <i class="bi bi-check-lg"></i> <?= $is_edit ? 'Speichern' : 'Anlegen' ?>
                </button>
                <a href="mitarbeiter.php" class="btn btn-outline-secondary">Abbrechen</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>
