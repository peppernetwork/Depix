<?php
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/auth.php';

session_start_secure();
require_auth(); // All roles

$pdo = get_pdo();

// Revision selector
$revision_id = req_int('revision_id', $_GET);

// Load available revisions (with plan names)
$all_revisions = $pdo->query(
    'SELECT r.id, r.revision_number, r.created_at, s.name AS plan_name, s.id AS schedule_id
     FROM schedule_revisions r
     JOIN schedules s ON s.id = r.schedule_id
     WHERE s.is_active=1
     ORDER BY s.id DESC, r.revision_number DESC'
)->fetchAll();

if (!$revision_id && !empty($all_revisions)) {
    $revision_id = (int)$all_revisions[0]['id'];
}

// Default calendar date: school year start for this revision
$initial_date = date('Y-m-d');
if ($revision_id) {
    $stmt = $pdo->prepare(
        'SELECT sy.start_date FROM schedule_revisions r
         JOIN schedules s ON s.id=r.schedule_id
         JOIN school_years sy ON sy.id=s.school_year_id
         WHERE r.id=?'
    );
    $stmt->execute([$revision_id]);
    $row = $stmt->fetch();
    if ($row) {
        $initial_date = $row['start_date'];
    }
}

$page_title = 'Kalender';
$active_nav = 'kalender';
require __DIR__ . '/../templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="fw-bold mb-0" style="color:var(--pb-dark);">
        <i class="bi bi-calendar3"></i> Kalender
    </h1>
    <form method="get" action="kalender.php" class="d-flex gap-2 align-items-center">
        <label class="form-label mb-0 fw-semibold text-nowrap">Revision:</label>
        <select name="revision_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ($all_revisions as $rv): ?>
                <option value="<?= (int)$rv['id'] ?>"
                        <?= $rv['id'] == $revision_id ? 'selected' : '' ?>>
                    <?= h($rv['plan_name']) ?> — Rev. <?= (int)$rv['revision_number'] ?>
                    (<?= h(format_date_de($rv['created_at'])) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if (!$revision_id): ?>
    <div class="alert alert-warning">Kein Plan verfügbar. Bitte zuerst einen Plan erstellen.</div>
<?php else: ?>

<!-- FullCalendar -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/locales/de.global.min.js"></script>

<div id="pb-calendar"></div>

<div class="mt-3 d-flex flex-wrap gap-3 small">
    <span><span style="display:inline-block;width:14px;height:14px;background:#ffe0b2;border:1px solid #e65100;border-radius:2px;"></span> Feiertag</span>
    <span><span style="display:inline-block;width:14px;height:14px;background:#fff9c4;border:1px solid #f57f17;border-radius:2px;"></span> Schulferien</span>
    <span><span style="display:inline-block;width:14px;height:14px;background:#c8e6c9;border:1px solid #388e3c;border-radius:2px;"></span> Mitarbeiterdienst</span>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var calendarEl = document.getElementById('pb-calendar');
    var revisionId = <?= (int)$revision_id ?>;

    var calendar = new FullCalendar.Calendar(calendarEl, {
        locale: 'de',
        initialView: 'dayGridMonth',
        initialDate: '<?= h($initial_date) ?>',
        headerToolbar: {
            left:   'prev,next today',
            center: 'title',
            right:  'dayGridMonth,timeGridWeek,listWeek'
        },
        height: 'auto',
        events: '/planbear/public/api_kalender.php?revision_id=' + revisionId,
        eventDidMount: function(info) {
            // Show employee name + hours in tooltip
            if (info.event.extendedProps.description) {
                info.el.title = info.event.extendedProps.description;
            }
        },
        eventDisplay: 'block',
        dayMaxEvents: 5,
    });
    calendar.render();
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../templates/footer.php'; ?>
