<?php
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/settings.php';

session_start_secure();
require_auth(['admin']); // Only admins may change system settings

$pdo = get_pdo();
ensure_vacation_week_table($pdo);

// ─── Load active school year + vacation periods with per-week breakdown ───
$active_sy   = $pdo->query("SELECT * FROM school_years WHERE is_active=1 LIMIT 1")->fetch();
$vac_periods = [];
$known_week_ids = [];
if ($active_sy) {
    $stmt = $pdo->prepare('SELECT id, name, start_date, end_date FROM vacation_periods WHERE school_year_id = ? ORDER BY start_date');
    $stmt->execute([$active_sy['id']]);
    foreach ($stmt->fetchAll() as $p) {
        sync_vacation_period_weeks($pdo, $p);
        $wstmt = $pdo->prepare('SELECT id, week_number, start_date, end_date, is_work_period FROM vacation_period_weeks WHERE vacation_period_id = ? ORDER BY week_number');
        $wstmt->execute([$p['id']]);
        $p['weeks'] = $wstmt->fetchAll();
        foreach ($p['weeks'] as $w) {
            $known_week_ids[] = (int)$w['id'];
        }
        $vac_periods[] = $p;
    }
}

// ─── POST: save settings ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        flash('error', 'Ungültige Anfrage (CSRF).');
        redirect('einstellungen.php');
    }

    $allowed = [
        'einrichtung_name',
        'einrichtung_adresse',
        'einrichtung_telefon',
        'einrichtung_email',
        'fruehdienst_start',
        'fruehdienst_end',
        'betreuungszeit_start',
        'betreuungszeit_end',
        'spaetdienst_start',
        'spaetdienst_end',
        'planung_notiz',
        'pause_dauer_minuten',
        'pause_ab_stunden',
        'urlaub_standard_tage',
    ];

    $errors = [];

    // Validate time format for all three blocks
    $zeiten = [
        'fruehdienst_start'    => 'Frühdienst Beginn',
        'fruehdienst_end'      => 'Frühdienst Ende',
        'betreuungszeit_start' => 'Kernarbeitszeit Beginn',
        'betreuungszeit_end'   => 'Kernarbeitszeit Ende',
        'spaetdienst_start'    => 'Spätdienst Beginn',
        'spaetdienst_end'      => 'Spätdienst Ende',
    ];
    $zv = [];
    foreach ($zeiten as $key => $label) {
        $zv[$key] = trim($_POST[$key] ?? '');
        if (!preg_match('/^\d{2}:\d{2}$/', $zv[$key])) {
            $errors[] = $label . ' muss im Format HH:MM sein.';
        }
    }
    if (empty($errors)) {
        if ($zv['fruehdienst_start'] >= $zv['fruehdienst_end']) {
            $errors[] = 'Frühdienst Beginn muss vor dem Ende liegen.';
        }
        if ($zv['betreuungszeit_start'] >= $zv['betreuungszeit_end']) {
            $errors[] = 'Kernarbeitszeit Beginn muss vor dem Ende liegen.';
        }
        if ($zv['spaetdienst_start'] >= $zv['spaetdienst_end']) {
            $errors[] = 'Spätdienst Beginn muss vor dem Ende liegen.';
        }
    }
    // Validate numeric fields
    $pause_min = (int)($_POST['pause_dauer_minuten'] ?? 30);
    $pause_ab  = (float)str_replace(',', '.', $_POST['pause_ab_stunden'] ?? '6');
    $url_std   = (int)($_POST['urlaub_standard_tage'] ?? 20);
    if ($pause_min < 0 || $pause_min > 120) {
        $errors[] = 'Pausendauer muss zwischen 0 und 120 Minuten liegen.';
    }
    if ($pause_ab < 0 || $pause_ab > 24) {
        $errors[] = 'Pausenpflicht ab Stunden muss zwischen 0 und 24 liegen.';
    }
    if ($url_std < 0 || $url_std > 365) {
        $errors[] = 'Standard-Urlaubstage muss zwischen 0 und 365 liegen.';
    }

    if (empty($errors)) {
        foreach ($allowed as $key) {
            $val = trim($_POST[$key] ?? '');
            if ($key === 'pause_dauer_minuten') $val = (string)$pause_min;
            if ($key === 'pause_ab_stunden')    $val = number_format($pause_ab, 1, '.', '');
            if ($key === 'urlaub_standard_tage') $val = (string)$url_std;
            set_setting($pdo, $key, $val);
        }

        // Per-week Ferien-Arbeitsplanung: a checked week counts as a working week.
        $checked_weeks = array_map('intval', array_keys($_POST['vac_week'] ?? []));
        $update_week = $pdo->prepare('UPDATE vacation_period_weeks SET is_work_period = ? WHERE id = ?');
        foreach ($known_week_ids as $week_id) {
            $update_week->execute([in_array($week_id, $checked_weeks, true) ? 1 : 0, $week_id]);
        }

        flash('success', 'Einstellungen gespeichert.');
        redirect('einstellungen.php');
    } else {
        foreach ($errors as $e) {
            flash('error', $e);
        }
    }
}

// ─── Load current settings ─────────────────────────────────────────────────
$s = array_merge(default_settings(), get_all_settings($pdo));

$page_title = 'Systemeinstellungen';
$active_nav = 'einstellungen';
require __DIR__ . '/templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="fw-bold mb-0" style="color:var(--pb-dark);">
        <i class="bi bi-gear-fill"></i> Systemeinstellungen
    </h1>
</div>

<form method="post" action="einstellungen.php" novalidate>
    <?= csrf_input() ?>

    <!-- ── Allgemeine Einstellungen ─────────────────────────────────────── -->
    <div class="card pb-card mb-4" style="max-width:680px;">
        <div class="card-header">
            <i class="bi bi-building"></i> Einrichtung
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label fw-semibold">Name der Einrichtung</label>
                <input type="text" class="form-control" name="einrichtung_name"
                       value="<?= h($s['einrichtung_name']) ?>" maxlength="200"
                       placeholder="z.B. Nachschulische Betreuung Mustergrundschule">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Adresse</label>
                <input type="text" class="form-control" name="einrichtung_adresse"
                       value="<?= h($s['einrichtung_adresse']) ?>" maxlength="300"
                       placeholder="Straße, PLZ Ort">
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Telefon</label>
                    <input type="tel" class="form-control" name="einrichtung_telefon"
                           value="<?= h($s['einrichtung_telefon']) ?>" maxlength="50">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">E-Mail</label>
                    <input type="email" class="form-control" name="einrichtung_email"
                           value="<?= h($s['einrichtung_email']) ?>" maxlength="200">
                </div>
            </div>
        </div>
    </div>

    <!-- ── Betreuungszeiten ─────────────────────────────────────────────── -->
    <div class="card pb-card mb-4" style="max-width:680px;">
        <div class="card-header">
            <i class="bi bi-clock-fill"></i> Betreuungszeiten
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                Frühdienst, Kernarbeitszeit und Spätdienst bilden zusammen die <strong>Gesamtzeit</strong> der Betreuung.
                Diese Zeiten gelten als Standard für Mitarbeiter im Modus „Voll" (sofern keine Schichten zugewiesen sind).
                Mitarbeiter mit individuellen Zeiten oder Schichten überschreiben diese Werte.
            </p>

            <?php
                $zeit_bloecke = [
                    ['key' => 'fruehdienst',    'label' => 'Frühdienst',      'icon' => 'bi-sunrise-fill'],
                    ['key' => 'betreuungszeit', 'label' => 'Kernarbeitszeit', 'icon' => 'bi-sun-fill'],
                    ['key' => 'spaetdienst',    'label' => 'Spätdienst',      'icon' => 'bi-sunset-fill'],
                ];
            ?>
            <?php foreach ($zeit_bloecke as $zb): ?>
            <div class="d-flex align-items-end gap-4 flex-wrap mb-3 pb-3 border-bottom">
                <div style="min-width:160px;">
                    <span class="fw-semibold"><i class="bi <?= $zb['icon'] ?>"></i> <?= h($zb['label']) ?></span>
                </div>
                <div>
                    <label class="form-label small mb-1">Beginn</label>
                    <input type="time" class="form-control zb-start" name="<?= $zb['key'] ?>_start"
                           id="<?= $zb['key'] ?>_start" value="<?= h($s[$zb['key'] . '_start']) ?>">
                </div>
                <div class="pb-1 text-muted fw-bold fs-5">–</div>
                <div>
                    <label class="form-label small mb-1">Ende</label>
                    <input type="time" class="form-control zb-end" name="<?= $zb['key'] ?>_end"
                           id="<?= $zb['key'] ?>_end" value="<?= h($s[$zb['key'] . '_end']) ?>">
                </div>
                <div class="pb-1">
                    <span class="badge bg-secondary fs-6" id="<?= $zb['key'] ?>-duration">
                        <?php
                            [$sh, $sm] = array_map('intval', explode(':', $s[$zb['key'] . '_start']));
                            [$eh, $em] = array_map('intval', explode(':', $s[$zb['key'] . '_end']));
                            $mins = ($eh * 60 + $em) - ($sh * 60 + $sm);
                            if ($mins > 0) {
                                $hh = intdiv($mins, 60);
                                $mm = $mins % 60;
                                echo h(($hh > 0 ? $hh . ' h ' : '') . ($mm > 0 ? $mm . ' min' : ''));
                            } else {
                                echo '—';
                            }
                        ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="d-flex align-items-center gap-3">
                <span class="fw-bold">Gesamtzeit:</span>
                <span class="badge bg-pb fs-6" id="gesamtzeit-duration" style="background-color:var(--pb-medium);">
                    <?php
                        [$sh, $sm] = array_map('intval', explode(':', $s['fruehdienst_start']));
                        [$eh, $em] = array_map('intval', explode(':', $s['spaetdienst_end']));
                        $total_mins = ($eh * 60 + $em) - ($sh * 60 + $sm);
                        if ($total_mins > 0) {
                            $hh = intdiv($total_mins, 60);
                            $mm = $total_mins % 60;
                            echo h($s['fruehdienst_start'] . ' – ' . $s['spaetdienst_end'] . '  (' . ($hh > 0 ? $hh . ' h ' : '') . ($mm > 0 ? $mm . ' min' : '') . ')');
                        } else {
                            echo '—';
                        }
                    ?>
                </span>
            </div>
        </div>
    </div>

    <!-- ── Planungseinstellungen ────────────────────────────────────────── -->
    <div class="card pb-card mb-4" style="max-width:680px;">
        <div class="card-header">
            <i class="bi bi-calendar-check-fill"></i> Planungseinstellungen
        </div>
        <div class="card-body">
            <div class="mb-0">
                <label class="form-label fw-semibold">Allgemeine Notiz zur Planung</label>
                <textarea class="form-control" name="planung_notiz" rows="3" maxlength="1000"
                          placeholder="Interne Hinweise, die bei der Planungserstellung angezeigt werden…"><?= h($s['planung_notiz']) ?></textarea>
            </div>
        </div>
    </div>

    <!-- ── Ferien-Arbeitsplanung ────────────────────────────────────────── -->
    <div class="card pb-card mb-4" style="max-width:680px;">
        <div class="card-header">
            <i class="bi bi-umbrella-fill"></i> Ferien-Arbeitsplanung
        </div>
        <div class="card-body">
            <?php if (!$active_sy): ?>
                <p class="text-muted small mb-0">Kein aktives Schuljahr gefunden.</p>
            <?php elseif (empty($vac_periods)): ?>
                <p class="text-muted small mb-0">Keine Ferienzeiten für das aktive Schuljahr hinterlegt.</p>
            <?php else: ?>
                <p class="text-muted small mb-3">
                    Pro Ferienwoche kann ein Haken gesetzt werden. Ist der Haken aktiv, zählt diese Woche
                    als Arbeitswoche statt als Ferienzeit.
                </p>
                <?php foreach ($vac_periods as $vp): ?>
                    <div class="mb-3 pb-3 border-bottom">
                        <div class="fw-semibold mb-2">
                            <?= h($vp['name']) ?>
                            <span class="text-muted small">
                                (<?= h(format_date_de($vp['start_date'])) ?> – <?= h(format_date_de($vp['end_date'])) ?>)
                            </span>
                        </div>
                        <?php foreach ($vp['weeks'] as $w): ?>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       name="vac_week[<?= (int)$w['id'] ?>]" id="vac_week_<?= (int)$w['id'] ?>"
                                       value="1" <?= $w['is_work_period'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="vac_week_<?= (int)$w['id'] ?>">
                                    Woche <?= (int)$w['week_number'] ?>
                                    <span class="text-muted small">
                                        (<?= h(format_date_de($w['start_date'])) ?> – <?= h(format_date_de($w['end_date'])) ?>)
                                    </span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Pausen ───────────────────────────────────────────────────────── -->
    <div class="card pb-card mb-4" style="max-width:680px;">
        <div class="card-header">
            <i class="bi bi-cup-hot-fill"></i> Pausenzeiten
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                Systemweite Pausenregel. Mitarbeiter können davon individuell abweichen.
            </p>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Pausendauer</label>
                    <div class="input-group" style="max-width:180px;">
                        <input type="number" class="form-control" name="pause_dauer_minuten"
                               value="<?= h($s['pause_dauer_minuten']) ?>"
                               min="0" max="120" step="5">
                        <span class="input-group-text">min</span>
                    </div>
                    <div class="form-text">Länge der Pause in Minuten</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Pausenpflicht ab</label>
                    <div class="input-group" style="max-width:180px;">
                        <input type="number" class="form-control" name="pause_ab_stunden"
                               value="<?= h($s['pause_ab_stunden']) ?>"
                               min="0" max="24" step="0.5">
                        <span class="input-group-text">Std.</span>
                    </div>
                    <div class="form-text">Bei &ge; X Stunden Arbeitszeit ist die Pause vorgeschrieben. 0 = immer.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Urlaub ────────────────────────────────────────────────────────── -->
    <div class="card pb-card mb-4" style="max-width:680px;">
        <div class="card-header">
            <i class="bi bi-umbrella-fill"></i> Urlaubsanspruch
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                Gesetzlicher Mindesturlaub (BUrlG). Mitarbeiter k&ouml;nnen zus&auml;tzliche Tage erhalten.
            </p>
            <div class="mb-0">
                <label class="form-label fw-semibold">Standard-Urlaubstage pro Jahr</label>
                <div class="input-group" style="max-width:180px;">
                    <input type="number" class="form-control" name="urlaub_standard_tage"
                           value="<?= h($s['urlaub_standard_tage']) ?>"
                           min="20" max="365" step="1">
                    <span class="input-group-text">Tage</span>
                </div>
                <div class="form-text">Gesetzliches Minimum: 20 Tage (5-Tage-Woche).</div>
            </div>
        </div>
    </div>

    <div style="max-width:680px;">
        <button type="submit" class="btn btn-pb-primary btn-lg">
            <i class="bi bi-check-lg"></i> Einstellungen speichern
        </button>
    </div>
</form>

<script>
(function () {
    const blocks = ['fruehdienst', 'betreuungszeit', 'spaetdienst'];
    const gesamt = document.getElementById('gesamtzeit-duration');

    function toMins(val) {
        if (!val) return null;
        const [h, m] = val.split(':').map(Number);
        return h * 60 + m;
    }

    function updateBlock(key) {
        const s = document.getElementById(key + '_start');
        const e = document.getElementById(key + '_end');
        const d = document.getElementById(key + '-duration');
        const sm = toMins(s.value), em = toMins(e.value);
        if (sm === null || em === null) { d.textContent = '—'; return; }
        const mins = em - sm;
        if (mins <= 0) { d.textContent = '!'; d.className = 'badge bg-danger fs-6'; return; }
        const hh = Math.floor(mins / 60), mm = mins % 60;
        d.textContent = (hh > 0 ? hh + ' h ' : '') + (mm > 0 ? mm + ' min' : '');
        d.className = 'badge bg-secondary fs-6';
    }

    function updateGesamt() {
        const fdStart = document.getElementById('fruehdienst_start').value;
        const sdEnd   = document.getElementById('spaetdienst_end').value;
        const sm = toMins(fdStart), em = toMins(sdEnd);
        if (sm === null || em === null || em <= sm) { gesamt.textContent = '—'; return; }
        const mins = em - sm;
        const hh = Math.floor(mins / 60), mm = mins % 60;
        gesamt.textContent = fdStart + ' – ' + sdEnd + '  (' + (hh > 0 ? hh + ' h ' : '') + (mm > 0 ? mm + ' min' : '') + ')';
    }

    function updateAll() {
        blocks.forEach(updateBlock);
        updateGesamt();
    }

    blocks.forEach(key => {
        document.getElementById(key + '_start')?.addEventListener('change', updateAll);
        document.getElementById(key + '_end')?.addEventListener('change', updateAll);
    });
})();
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
