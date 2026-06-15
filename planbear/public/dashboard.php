<?php
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/auth.php';

session_start_secure();
require_auth(); // Any authenticated role

$pdo  = get_pdo();
$user = current_user();

// Stats
$emp_count = (int)$pdo->query('SELECT COUNT(*) FROM employees WHERE is_active=1')->fetchColumn();

$sy = $pdo->query('SELECT name FROM school_years WHERE is_active=1 LIMIT 1')->fetch();
$sy_name = $sy ? $sy['name'] : 'Kein aktives Schuljahr';

$active_plan = $pdo->query(
    'SELECT s.name, MAX(r.revision_number) AS rev_num, MAX(r.created_at) AS rev_date
     FROM schedules s
     JOIN schedule_revisions r ON r.schedule_id = s.id
     WHERE s.is_active=1
     ORDER BY s.id DESC LIMIT 1'
)->fetch();

$user_count = has_role('admin')
    ? (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_active=1')->fetchColumn()
    : null;

$page_title = 'Dashboard';
$active_nav = 'dashboard';
require __DIR__ . '/../templates/header.php';
?>

<h1 class="mb-4 fw-bold" style="color:var(--pb-dark);">
    <i class="bi bi-speedometer2"></i> Dashboard
</h1>

<!-- Stats row -->
<div class="row g-3 mb-5">
    <div class="col-6 col-md-3">
        <div class="pb-stat pb-stat-1">
            <div class="stat-value"><?= h($sy_name) ?></div>
            <div class="stat-label">Aktives Schuljahr</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="pb-stat pb-stat-2">
            <div class="stat-value"><?= $emp_count ?></div>
            <div class="stat-label">Aktive Mitarbeiter</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="pb-stat pb-stat-3">
            <?php if ($active_plan): ?>
                <div class="stat-value"><?= h($active_plan['name']) ?></div>
                <div class="stat-label">Aktueller Plan (Rev. <?= h((string)$active_plan['rev_num']) ?>)</div>
            <?php else: ?>
                <div class="stat-value">—</div>
                <div class="stat-label">Kein aktiver Plan</div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($user_count !== null): ?>
    <div class="col-6 col-md-3">
        <div class="pb-stat pb-stat-4">
            <div class="stat-value"><?= $user_count ?></div>
            <div class="stat-label">Aktive Benutzer</div>
        </div>
    </div>
    <?php else: ?>
    <div class="col-6 col-md-3">
        <div class="pb-stat pb-stat-4">
            <div class="stat-value"><?= h(ucfirst($user['role'])) ?></div>
            <div class="stat-label">Ihre Rolle</div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Quick actions -->
<h5 class="mb-3 text-muted">Schnellzugriff</h5>
<div class="row g-3">
    <div class="col-6 col-md-4 col-lg-3">
        <a href="mitarbeiter.php" class="pb-action-card card p-3 text-center d-block">
            <div class="pb-action-icon"><i class="bi bi-people-fill"></i></div>
            <div class="mt-2 fw-semibold">Mitarbeiter</div>
            <div class="text-muted small">Mitarbeiterliste anzeigen</div>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="planung.php" class="pb-action-card card p-3 text-center d-block">
            <div class="pb-action-icon"><i class="bi bi-calendar-week"></i></div>
            <div class="mt-2 fw-semibold">Planung</div>
            <div class="text-muted small">Schichtpläne verwalten</div>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="kalender.php" class="pb-action-card card p-3 text-center d-block">
            <div class="pb-action-icon"><i class="bi bi-calendar3"></i></div>
            <div class="mt-2 fw-semibold">Kalender</div>
            <div class="text-muted small">FullCalendar-Ansicht</div>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="ferien.php" class="pb-action-card card p-3 text-center d-block">
            <div class="pb-action-icon"><i class="bi bi-sun-fill"></i></div>
            <div class="mt-2 fw-semibold">Ferien &amp; Feiertage</div>
            <div class="text-muted small">Schulkalender pflegen</div>
        </a>
    </div>
    <?php if (has_role('editor','admin')): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="mitarbeiter_form.php" class="pb-action-card card p-3 text-center d-block">
            <div class="pb-action-icon"><i class="bi bi-person-plus-fill"></i></div>
            <div class="mt-2 fw-semibold">Mitarbeiter anlegen</div>
            <div class="text-muted small">Neuen Eintrag erstellen</div>
        </a>
    </div>
    <?php endif; ?>
    <?php if (has_role('admin')): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="benutzer.php" class="pb-action-card card p-3 text-center d-block">
            <div class="pb-action-icon"><i class="bi bi-shield-person"></i></div>
            <div class="mt-2 fw-semibold">Benutzerverwaltung</div>
            <div class="text-muted small">Konten &amp; Rollen</div>
        </a>
    </div>
    <?php endif; ?>
</div>

<?php if ($active_plan): ?>
<div class="mt-5">
    <div class="card pb-card">
        <div class="card-header"><i class="bi bi-clock-history"></i> Letzter aktiver Plan</div>
        <div class="card-body">
            <p class="mb-1"><strong>Plan:</strong> <?= h($active_plan['name']) ?></p>
            <p class="mb-1"><strong>Revision:</strong> <?= h((string)$active_plan['rev_num']) ?></p>
            <p class="mb-3"><strong>Erstellt:</strong> <?= h(format_date_de($active_plan['rev_date'])) ?></p>
            <a href="planung.php" class="btn btn-pb-primary btn-sm">
                <i class="bi bi-eye"></i> Plan ansehen
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../templates/footer.php'; ?>
