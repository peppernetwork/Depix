<?php
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
require_auth(['admin']); // Admin only

$pdo = get_pdo();
$me  = current_user();

$errors = [];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        flash('error', 'Ungültige Anfrage (CSRF).');
        redirect('benutzer.php');
    }

    $action = $_POST['action'] ?? '';

    // ---- ADD USER ----
    if ($action === 'add_user') {
        $uname    = trim($_POST['username'] ?? '');
        $dname    = trim($_POST['display_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $role     = $_POST['role'] ?? 'viewer';
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';

        if ($uname === '' || $dname === '' || $password === '') {
            $errors[] = 'Benutzername, Name und Passwort sind Pflichtfelder.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.\-]{3,50}$/', $uname)) {
            $errors[] = 'Benutzername darf nur Buchstaben, Zahlen, _, ., - enthalten (3–50 Zeichen).';
        } elseif (strlen($password) < 8) {
            $errors[] = 'Passwort muss mindestens 8 Zeichen lang sein.';
        } elseif ($password !== $password2) {
            $errors[] = 'Passwörter stimmen nicht überein.';
        } elseif (!in_array($role, ['admin','editor','viewer'], true)) {
            $errors[] = 'Ungültige Rolle.';
        } else {
            // Check username unique
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username=?');
            $stmt->execute([$uname]);
            if ($stmt->fetch()) {
                $errors[] = 'Dieser Benutzername ist bereits vergeben.';
            } else {
                $hash      = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $name_enc  = encrypt($dname);
                $email_enc = $email ? encrypt($email) : null;
                $stmt = $pdo->prepare(
                    'INSERT INTO users (username, name_enc, email_enc, password_hash, role) VALUES (?,?,?,?,?)'
                );
                $stmt->execute([$uname, $name_enc, $email_enc, $hash, $role]);
                flash('success', 'Benutzer "' . $uname . '" angelegt.');
                redirect('benutzer.php');
            }
        }
    }

    // ---- CHANGE ROLE ----
    if ($action === 'change_role') {
        $uid  = req_int('user_id', $_POST);
        $role = $_POST['role'] ?? '';
        if ($uid && $uid != $me['id'] && in_array($role, ['admin','editor','viewer'], true)) {
            $pdo->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role, $uid]);
            flash('success', 'Rolle aktualisiert.');
        } else {
            flash('error', 'Ungültige Aktion.');
        }
        redirect('benutzer.php');
    }

    // ---- TOGGLE ACTIVE ----
    if ($action === 'toggle_active') {
        $uid = req_int('user_id', $_POST);
        $cur = req_int('current', $_POST);
        if ($uid && $uid != $me['id']) {
            $pdo->prepare('UPDATE users SET is_active=? WHERE id=?')->execute([$cur ? 0 : 1, $uid]);
            flash('success', $cur ? 'Benutzer deaktiviert.' : 'Benutzer aktiviert.');
        }
        redirect('benutzer.php');
    }

    // ---- RESET PASSWORD ----
    if ($action === 'reset_password') {
        $uid = req_int('user_id', $_POST);
        $pw  = $_POST['new_password'] ?? '';
        $pw2 = $_POST['new_password2'] ?? '';
        if (!$uid) {
            flash('error', 'Ungültiger Benutzer.');
        } elseif (strlen($pw) < 8) {
            flash('error', 'Passwort muss mindestens 8 Zeichen lang sein.');
        } elseif ($pw !== $pw2) {
            flash('error', 'Passwörter stimmen nicht überein.');
        } else {
            $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare('UPDATE users SET password_hash=?, failed_logins=0, locked_until=NULL WHERE id=?')
                ->execute([$hash, $uid]);
            flash('success', 'Passwort zurückgesetzt.');
        }
        redirect('benutzer.php');
    }
}

// Load users
$users = $pdo->query(
    'SELECT id, username, name_enc, email_enc, role, is_active, last_login, failed_logins, locked_until, created_at
     FROM users ORDER BY id'
)->fetchAll();

$page_title = 'Benutzerverwaltung';
$active_nav = 'benutzer';
require __DIR__ . '/templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="fw-bold mb-0" style="color:var(--pb-dark);">
        <i class="bi bi-shield-person"></i> Benutzerverwaltung
    </h1>
    <button class="btn btn-pb-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="bi bi-person-plus-fill"></i> Neuer Benutzer
    </button>
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

<div class="card pb-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-pb table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Benutzername</th>
                        <th>Name</th>
                        <th>Rolle</th>
                        <th>Status</th>
                        <th>Letzter Login</th>
                        <th>Erstellt</th>
                        <th class="text-end">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <?php $is_me = ($u['id'] == $me['id']); ?>
                    <tr <?= !$u['is_active'] ? 'class="table-secondary opacity-75"' : '' ?>>
                        <td class="fw-semibold">
                            <?= h($u['username']) ?>
                            <?= $is_me ? '<span class="badge bg-info text-dark ms-1">Ich</span>' : '' ?>
                        </td>
                        <td><?= h(decrypt($u['name_enc'])) ?></td>
                        <td>
                            <span class="role-chip role-<?= h($u['role']) ?>"><?= h(ucfirst($u['role'])) ?></span>
                        </td>
                        <td>
                            <?php if ($u['locked_until'] && new DateTime() < new DateTime($u['locked_until'])): ?>
                                <span class="badge bg-warning text-dark">
                                    <i class="bi bi-lock-fill"></i> Gesperrt bis <?= h((new DateTime($u['locked_until']))->format('H:i')) ?>
                                </span>
                            <?php elseif ($u['is_active']): ?>
                                <span class="badge bg-success">Aktiv</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inaktiv</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted">
                            <?= $u['last_login'] ? h(format_date_de($u['last_login'])) : '—' ?>
                        </td>
                        <td class="small text-muted"><?= h(format_date_de($u['created_at'])) ?></td>
                        <td class="text-end">
                            <?php if (!$is_me): ?>
                            <!-- Change role -->
                            <form method="post" action="benutzer.php" class="d-inline">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="change_role">
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <select name="role" class="form-select form-select-sm d-inline-block w-auto"
                                        onchange="this.form.submit()" title="Rolle ändern">
                                    <?php foreach (['admin','editor','viewer'] as $r): ?>
                                        <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <!-- Toggle active -->
                            <form method="post" action="benutzer.php" class="d-inline ms-1">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <input type="hidden" name="current" value="<?= (int)$u['is_active'] ?>">
                                <button type="submit" class="btn btn-sm <?= $u['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                        data-confirm="Benutzer <?= $u['is_active'] ? 'deaktivieren' : 'aktivieren' ?>?">
                                    <i class="bi bi-<?= $u['is_active'] ? 'person-dash' : 'person-check' ?>-fill"></i>
                                </button>
                            </form>
                            <!-- Reset password -->
                            <button class="btn btn-sm btn-outline-secondary ms-1"
                                    data-bs-toggle="modal"
                                    data-bs-target="#resetPwModal"
                                    data-uid="<?= (int)$u['id'] ?>"
                                    data-uname="<?= h($u['username']) ?>">
                                <i class="bi bi-key-fill"></i>
                            </button>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="benutzer.php">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="add_user">
                <div class="modal-header" style="background:var(--pb-dark);color:var(--pb-light);">
                    <h5 class="modal-title"><i class="bi bi-person-plus-fill"></i> Neuer Benutzer</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Benutzername <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="username" required maxlength="50"
                               pattern="[a-zA-Z0-9_.\-]{3,50}" placeholder="min. 3 Zeichen">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Anzeigename <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="display_name" required maxlength="200">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">E-Mail</label>
                        <input type="email" class="form-control" name="email" maxlength="200">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Rolle</label>
                        <select class="form-select" name="role">
                            <option value="viewer">Viewer</option>
                            <option value="editor">Editor</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Passwort <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="password" required minlength="8"
                               placeholder="Min. 8 Zeichen">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Passwort wiederholen <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="password2" required minlength="8">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-pb-primary">
                        <i class="bi bi-person-plus-fill"></i> Anlegen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPwModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="benutzer.php">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetPwUserId">
                <div class="modal-header" style="background:var(--pb-dark);color:var(--pb-light);">
                    <h5 class="modal-title">
                        <i class="bi bi-key-fill"></i>
                        Passwort zurücksetzen: <span id="resetPwUsername"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Neues Passwort <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="new_password" required minlength="8"
                               placeholder="Min. 8 Zeichen">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Wiederholen <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="new_password2" required minlength="8">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-pb-primary">
                        <i class="bi bi-check-lg"></i> Passwort setzen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Populate reset password modal
document.getElementById('resetPwModal').addEventListener('show.bs.modal', function(e) {
    var btn = e.relatedTarget;
    document.getElementById('resetPwUserId').value   = btn.dataset.uid;
    document.getElementById('resetPwUsername').textContent = btn.dataset.uname;
});
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
