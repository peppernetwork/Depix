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
        'betreuungszeit_start',
        'betreuungszeit_end',
        'ferien_standard_arbeit',
        'planung_notiz',
        'pause_dauer_minuten',
        'pause_ab_stunden',
        'urlaub_standard_tage',
    ];

    $errors = [];

    // Validate time format
    $bz_start = trim($_POST['betreuungszeit_start'] ?? '');
    $bz_end   = trim($_POST['betreuungszeit_end']   ?? '');
    if (!preg_match('/^\d{2}:\d{2}$/', $bz_start)) {
        $errors[] = 'Betreuungszeit Beginn muss im Format HH:MM sein.';
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $bz_end)) {
        $errors[] = 'Betreuungszeit Ende muss im Format HH:MM sein.';
    }
    if (empty($errors) && $bz_start >= $bz_end) {
        $errors[] = 'Betreuungszeit Beginn muss vor dem Ende liegen.';
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
            if ($key === 'ferien_standard_arbeit') {
                $val = isset($_POST[$key]) && $_POST[$key] === '1' ? '1' : '0';
            }
            if ($key === 'pause_dauer_minuten') $val = (string)$pause_min;
            if ($key === 'pause_ab_stunden')    $val = number_format($pause_ab, 1, '.', '');
            if ($key === 'urlaub_standard_tage') $val = (string)$url_std;
            set_setting($pdo, $key, $val);
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
require __DIR__ . '/../templates/header.php';
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
                Diese Zeiten gelten als <strong>Standard</strong> für alle Mitarbeiter im Modus „Voll".
                Mitarbeiter mit individuellen Zeiten überschreiben diese Werte.
            </p>
            <div class="d-flex align-items-end gap-4 flex-wrap">
                <div>
                    <label class="form-label fw-semibold">Beginn</label>
                    <input type="time" class="form-control" name="betreuungszeit_start"
                           id="bz_start" value="<?= h($s['betreuungszeit_start']) ?>">
                </div>
                <div class="pb-1 text-muted fw-bold fs-5">–</div>
                <div>
                    <label class="form-label fw-semibold">Ende</label>
                    <input type="time" class="form-control" name="betreuungszeit_end"
                           id="bz_end" value="<?= h($s['betreuungszeit_end']) ?>">
                </div>
                <div class="pb-1">
                    <span class="badge bg-secondary fs-6" id="bz-duration">
                        <?php
                            [$sh, $sm] = array_map('intval', explode(':', $s['betreuungszeit_start']));
                            [$eh, $em] = array_map('intval', explode(':', $s['betreuungszeit_end']));
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
        </div>
    </div>

    <!-- ── Planungseinstellungen ────────────────────────────────────────── -->
    <div class="card pb-card mb-4" style="max-width:680px;">
        <div class="card-header">
            <i class="bi bi-calendar-check-fill"></i> Planungseinstellungen
        </div>
        <div class="card-body">
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch"
                           name="ferien_standard_arbeit" id="ferien_arbeit"
                           value="1" <?= $s['ferien_standard_arbeit'] === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="ferien_arbeit">
                        Standardmäßig in den Ferien arbeiten
                    </label>
                </div>
                <div class="form-text">
                    Wenn aktiv, werden Ferien-Wochen als Arbeitszeitraum behandelt (sofern beim Mitarbeiter Ferienstunden &gt; 0).
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Allgemeine Notiz zur Planung</label>
                <textarea class="form-control" name="planung_notiz" rows="3" maxlength="1000"
                          placeholder="Interne Hinweise, die bei der Planungserstellung angezeigt werden…"><?= h($s['planung_notiz']) ?></textarea>
            </div>
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
    const s = document.getElementById('bz_start');
    const e = document.getElementById('bz_end');
    const d = document.getElementById('bz-duration');
    function update() {
        if (!s.value || !e.value) { d.textContent = '—'; return; }
        const [sh, sm] = s.value.split(':').map(Number);
        const [eh, em] = e.value.split(':').map(Number);
        const mins = (eh * 60 + em) - (sh * 60 + sm);
        if (mins <= 0) { d.textContent = '!'; d.className = 'badge bg-danger fs-6'; return; }
        const hh = Math.floor(mins / 60), mm = mins % 60;
        d.textContent = (hh > 0 ? hh + ' h ' : '') + (mm > 0 ? mm + ' min' : '');
        d.className = 'badge bg-secondary fs-6';
    }
    s?.addEventListener('change', update);
    e?.addEventListener('change', update);
})();
</script>

<?php require __DIR__ . '/../templates/footer.php'; ?>
