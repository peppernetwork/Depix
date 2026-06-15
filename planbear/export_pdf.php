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
require_auth();

$pdo  = get_pdo();
$user = current_user();
$type = $_GET['type'] ?? 'woche'; // 'woche' or 'monat'

$schedule_id = req_int('schedule_id', $_GET);
$revision_id = req_int('revision_id', $_GET);
if (!$schedule_id || !$revision_id) { http_response_code(400); exit('Ungültige Parameter.'); }

$stmt = $pdo->prepare('SELECT s.*, sy.name AS year_name, sy.start_date, sy.end_date FROM schedules s JOIN school_years sy ON sy.id=s.school_year_id WHERE s.id=?');
$stmt->execute([$schedule_id]);
$schedule = $stmt->fetch();
if (!$schedule) { http_response_code(404); exit('Plan nicht gefunden.'); }

$stmt = $pdo->prepare('SELECT id, revision_number, created_at FROM schedule_revisions WHERE id=? AND schedule_id=?');
$stmt->execute([$revision_id, $schedule_id]);
$rev = $stmt->fetch();
if (!$rev) { http_response_code(404); exit('Revision nicht gefunden.'); }

$school_start = new DateTime($schedule['start_date']);
$school_end   = new DateTime($schedule['end_date']);
$einrichtung  = get_setting($pdo, 'einrichtung_name', APP_NAME);
$de_days_full = ['', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag'];
$de_days_abbr = [1=>'Mo', 2=>'Di', 3=>'Mi', 4=>'Do', 5=>'Fr'];
$month_names  = ['','Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];

// ── Load employees + shifts ────────────────────────────────────────────────
$employees = $pdo->query('SELECT id, name_enc FROM employees WHERE is_active=1 ORDER BY id')->fetchAll();
$emp_shifts_all = [];
foreach ($pdo->query('SELECT es.employee_id, s.short_name, s.color FROM employee_shifts es JOIN shifts s ON s.id=es.shift_id ORDER BY s.sort_order')->fetchAll() as $r) {
    $emp_shifts_all[(int)$r['employee_id']][] = $r;
}

// ── WOCHE ──────────────────────────────────────────────────────────────────
if ($type === 'woche') {
    $week_str = $_GET['week'] ?? $school_start->format('Y-m-d');
    try { $week_monday = monday_of_week($week_str); }
    catch (Exception $e) { $week_monday = clone $school_start; }
    if ($week_monday < $school_start) $week_monday = monday_of_week($school_start->format('Y-m-d'));
    if ($week_monday > $school_end)   $week_monday = monday_of_week($school_end->format('Y-m-d'));

    $week_dates = [];
    for ($i = 0; $i < 5; $i++) {
        $week_dates[$i+1] = (clone $week_monday)->modify("+{$i} days")->format('Y-m-d');
    }

    // Holidays + vacation periods
    $stmt = $pdo->prepare('SELECT holiday_date, name FROM public_holidays WHERE school_year_id=? AND holiday_date BETWEEN ? AND ?');
    $stmt->execute([$schedule['school_year_id'], $week_dates[1], $week_dates[5]]);
    $holiday_map = [];
    foreach ($stmt->fetchAll() as $h) $holiday_map[$h['holiday_date']] = $h['name'];

    $stmt = $pdo->prepare('SELECT name, start_date, end_date FROM vacation_periods WHERE school_year_id=? AND start_date<=? AND end_date>=?');
    $stmt->execute([$schedule['school_year_id'], $week_dates[5], $week_dates[1]]);
    $vacation_map = [];
    foreach ($stmt->fetchAll() as $vp) {
        foreach ($week_dates as $wd) {
            if ($wd >= $vp['start_date'] && $wd <= $vp['end_date']) {
                $vacation_map[$wd] = $vp['name'];
            }
        }
    }

    $stmt = $pdo->prepare("SELECT employee_id, vacation_date FROM employee_vacations WHERE vacation_date BETWEEN ? AND ? AND status IN ('geplant','genehmigt','genommen')");
    $stmt->execute([$week_dates[1], $week_dates[5]]);
    $emp_vac_map = [];
    foreach ($stmt->fetchAll() as $r) $emp_vac_map[(int)$r['employee_id']][$r['vacation_date']] = true;

    $stmt = $pdo->prepare('SELECT employee_id, entry_date, hours, pause_minuten, time_start, time_end FROM schedule_entries WHERE revision_id=? AND entry_date BETWEEN ? AND ?');
    $stmt->execute([$revision_id, $week_dates[1], $week_dates[5]]);
    $entry_map = [];
    foreach ($stmt->fetchAll() as $e) $entry_map[$e['employee_id']][$e['entry_date']] = $e;

    $pdf_title = 'Wochenplan KW ' . $week_monday->format('W/Y');
    $pdf_sub   = format_date_de($week_dates[1]) . ' – ' . format_date_de($week_dates[5]);
}

// ── MONAT ──────────────────────────────────────────────────────────────────
if ($type === 'monat') {
    $month_str = $_GET['month'] ?? $school_start->format('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month_str)) $month_str = $school_start->format('Y-m');
    $month_start_dt = new DateTime($month_str . '-01');
    $month_end_dt   = (clone $month_start_dt)->modify('last day of this month');
    $disp_start     = max($month_start_dt, $school_start);
    $disp_end       = min($month_end_dt, $school_end);

    $working_days = [];
    $cur = clone $disp_start;
    while ($cur <= $disp_end) {
        $dow = (int)$cur->format('N');
        if ($dow <= 5) {
            $working_days[] = ['date'=>$cur->format('Y-m-d'),'dow'=>$dow,'kw'=>(int)$cur->format('W'),'day'=>(int)$cur->format('j')];
        }
        $cur->modify('+1 day');
    }
    $first_date = !empty($working_days) ? $working_days[0]['date'] : $disp_start->format('Y-m-d');
    $last_date  = !empty($working_days) ? end($working_days)['date'] : $disp_end->format('Y-m-d');

    $stmt = $pdo->prepare('SELECT holiday_date, name FROM public_holidays WHERE school_year_id=? AND holiday_date BETWEEN ? AND ?');
    $stmt->execute([$schedule['school_year_id'], $first_date, $last_date]);
    $holiday_map = [];
    foreach ($stmt->fetchAll() as $h) $holiday_map[$h['holiday_date']] = $h['name'];

    $stmt = $pdo->prepare('SELECT name, start_date, end_date FROM vacation_periods WHERE school_year_id=? AND start_date<=? AND end_date>=?');
    $stmt->execute([$schedule['school_year_id'], $last_date, $first_date]);
    $vacation_map = [];
    foreach ($stmt->fetchAll() as $vp) {
        foreach ($working_days as $wd_item) {
            if ($wd_item['date'] >= $vp['start_date'] && $wd_item['date'] <= $vp['end_date']) {
                $vacation_map[$wd_item['date']] = $vp['name'];
            }
        }
    }

    $stmt = $pdo->prepare("SELECT employee_id, vacation_date FROM employee_vacations WHERE vacation_date BETWEEN ? AND ? AND status IN ('geplant','genehmigt','genommen')");
    $stmt->execute([$first_date, $last_date]);
    $emp_vac_map = [];
    foreach ($stmt->fetchAll() as $r) $emp_vac_map[(int)$r['employee_id']][$r['vacation_date']] = true;

    $stmt = $pdo->prepare('SELECT employee_id, entry_date, hours, pause_minuten FROM schedule_entries WHERE revision_id=? AND entry_date BETWEEN ? AND ?');
    $stmt->execute([$revision_id, $first_date, $last_date]);
    $entry_map = [];
    foreach ($stmt->fetchAll() as $e) $entry_map[$e['employee_id']][$e['entry_date']] = $e;

    $mn = (int)$month_start_dt->format('n');
    $pdf_title = 'Monatsplan ' . $month_names[$mn] . ' ' . $month_start_dt->format('Y');
    $pdf_sub   = count($working_days) . ' Arbeitstage';
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pdf_title) ?> — PlanBär</title>
<style>
/* ── Screen styles ── */
body {
    font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
    font-size: 11pt;
    color: #1a1a1a;
    background: #f5f0ea;
    padding: 20px;
}
.pdf-wrapper {
    background: #fff;
    max-width: 1100px;
    margin: 0 auto;
    padding: 24px 28px;
    border-radius: 8px;
    box-shadow: 0 2px 20px rgba(0,0,0,.15);
}
.pdf-header {
    border-bottom: 3px solid #5C3317;
    padding-bottom: 10px;
    margin-bottom: 16px;
}
.pdf-header h1 { font-size: 16pt; color: #5C3317; margin: 0 0 2px; }
.pdf-header .sub { font-size: 10pt; color: #888; }
.pdf-header .org  { font-size: 9pt; color: #A0522D; font-weight: 600; }
table { width: 100%; border-collapse: collapse; margin-top: 8px; }
th, td { border: 1px solid #c8b89a; padding: 4px 5px; vertical-align: middle; }
thead th { background: #5C3317; color: #DEB887; font-size: 9pt; text-align: center; }
thead th.emp-col { background: #3d2410; text-align: left; }
tbody td.emp-cell { font-weight: 600; background: #faf4ec; font-size: 9pt; white-space: nowrap; }
.cell-work { background: #eaf4e8; text-align: center; }
.cell-holiday { background: #ffe0e0; color: #900; text-align: center; font-size: 8pt; }
.cell-ferien { background: #fff8dc; color: #7a5c00; text-align: center; font-size: 8pt; }
.cell-urlaub { background: #e8f4fd; color: #0a4c9c; text-align: center; font-size: 8pt; }
.cell-empty  { text-align: center; color: #ccc; }
tfoot td { background: #f0e8d8; font-weight: 700; text-align: center; font-size: 9pt; }
tfoot td:first-child { text-align: left; }
.time-range { font-size: 8pt; color: #2a6a2a; font-weight: 600; }
.hours      { font-size: 9.5pt; font-weight: 700; }
.pause-note { font-size: 7pt; color: #888; }
.shift-badge { display: inline-block; border-radius: 3px; padding: 0 4px; font-size: 7.5pt; font-weight: 700; color: #fff; margin: 0 1px; }
.print-btn {
    display: block; margin: 0 auto 16px; padding: 10px 28px; background: #5C3317;
    color: #DEB887; border: none; border-radius: 6px; font-size: 12pt; cursor: pointer;
}
.print-btn:hover { background: #A0522D; }
.pdf-footer { margin-top: 10px; font-size: 7.5pt; color: #aaa; text-align: right; border-top: 1px solid #e0d0be; padding-top: 6px; }
.legend { margin-top: 10px; font-size: 7.5pt; color: #666; display: flex; gap: 12px; flex-wrap: wrap; }
.legend span { padding: 2px 6px; border-radius: 3px; }

/* ── Print styles ── */
@media print {
    @page { size: A4 landscape; margin: 8mm 10mm; }
    body { background: #fff; padding: 0; font-size: 8.5pt; }
    .pdf-wrapper { box-shadow: none; padding: 0; max-width: none; border-radius: 0; }
    .print-btn, .no-print { display: none !important; }
    table { page-break-inside: avoid; }
    thead { display: table-header-group; }
    .pdf-header h1 { font-size: 13pt; }
    th, td { padding: 2.5px 3px; }
    .time-range { font-size: 7pt; }
    .hours { font-size: 8.5pt; }
    .pause-note { font-size: 6.5pt; }
    .shift-badge { font-size: 6.5pt; padding: 0 3px; }
}
</style>
</head>
<body>
<button class="print-btn no-print" onclick="window.print()">
    🖨 Als PDF speichern / Drucken
</button>

<div class="pdf-wrapper">
    <!-- Header -->
    <div class="pdf-header">
        <div class="org">🐻 <?= h($einrichtung) ?></div>
        <h1><?= h($pdf_title) ?></h1>
        <div class="sub">
            <?= h($pdf_sub) ?> &mdash;
            <?= h($schedule['name']) ?> / <?= h($schedule['year_name']) ?>,
            Revision <?= (int)$rev['revision_number'] ?>
            (<?= h(format_date_de($rev['created_at'])) ?>) &mdash;
            Erstellt: <?= h(date('d.m.Y H:i')) ?>
        </div>
    </div>

    <?php if ($type === 'woche'): ?>
    <!-- ════════════ WEEKLY TABLE ════════════ -->
    <table>
        <thead>
            <tr>
                <th class="emp-col" style="min-width:120px;">Mitarbeiter</th>
                <?php foreach ($week_dates as $dow => $wd): ?>
                <th style="width:<?= round(100/count($week_dates), 1) ?>%;">
                    <?= $de_days_abbr[$dow] ?><br>
                    <span style="font-size:8pt;"><?= h(format_date_de($wd)) ?></span>
                    <?php if (isset($holiday_map[$wd])): ?>
                        <br><span style="font-size:7pt;font-weight:400;"><?= h($holiday_map[$wd]) ?></span>
                    <?php elseif (isset($vacation_map[$wd])): ?>
                        <br><span style="font-size:7pt;font-weight:400;">Schulferien</span>
                    <?php endif; ?>
                </th>
                <?php endforeach; ?>
                <th style="width:55px;">∑ h</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($employees as $emp):
            $eid   = (int)$emp['id'];
            $name  = decrypt($emp['name_enc']);
            $shifts = $emp_shifts_all[$eid] ?? [];
            $row_total = 0;
        ?>
        <tr>
            <td class="emp-cell">
                <?= h($name) ?>
                <?php foreach ($shifts as $sh): ?>
                    <span class="shift-badge" style="background:<?= h($sh['color']) ?>;"><?= h($sh['short_name']) ?></span>
                <?php endforeach; ?>
            </td>
            <?php foreach ($week_dates as $dow => $wd):
                $is_h  = isset($holiday_map[$wd]);
                $is_f  = isset($vacation_map[$wd]);
                $is_u  = isset($emp_vac_map[$eid][$wd]);
                $entry = $entry_map[$eid][$wd] ?? null;
                if ($entry) $row_total += (float)$entry['hours'];
            ?>
            <?php if ($is_h): ?>
                <td class="cell-holiday">Feiertag<br><span style="font-size:7pt;"><?= h($holiday_map[$wd]) ?></span></td>
            <?php elseif ($is_u): ?>
                <td class="cell-urlaub">🌴 Urlaub</td>
            <?php elseif ($entry): ?>
                <td class="cell-work">
                    <?php if (!empty($entry['time_start']) && !empty($entry['time_end'])): ?>
                        <div class="time-range"><?= h(substr($entry['time_start'],0,5)) ?>–<?= h(substr($entry['time_end'],0,5)) ?></div>
                    <?php endif; ?>
                    <div class="hours"><?= h(number_format((float)$entry['hours'], 2, ',', '.')) ?> h</div>
                    <?php if ((int)($entry['pause_minuten'] ?? 0) > 0): ?>
                        <div class="pause-note">Pause: <?= (int)$entry['pause_minuten'] ?> min</div>
                    <?php endif; ?>
                    <?php if ($is_f): ?><div style="font-size:7pt;color:#806010;">Ferien</div><?php endif; ?>
                </td>
            <?php elseif ($is_f): ?>
                <td class="cell-ferien">Schulferien</td>
            <?php else: ?>
                <td class="cell-empty">—</td>
            <?php endif; ?>
            <?php endforeach; ?>
            <td class="text-center" style="font-weight:700;background:#f0e8d8;">
                <?= $row_total > 0 ? h(number_format($row_total, 2, ',', '.')) . ' h' : '—' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>Summe</td>
                <?php $gt = 0;
                foreach ($week_dates as $wd):
                    $ds = 0;
                    foreach ($employees as $emp) $ds += (float)($entry_map[$emp['id']][$wd]['hours'] ?? 0);
                    $gt += $ds;
                ?>
                <td><?= $ds > 0 ? h(number_format($ds, 2, ',', '.')) . ' h' : '' ?></td>
                <?php endforeach; ?>
                <td><?= $gt > 0 ? h(number_format($gt, 2, ',', '.')) . ' h' : '—' ?></td>
            </tr>
        </tfoot>
    </table>

    <?php else: /* MONAT */ ?>
    <!-- ════════════ MONTHLY TABLE ════════════ -->
    <table style="font-size:8.5pt;">
        <thead>
            <!-- KW row -->
            <tr>
                <th class="emp-col" rowspan="2" style="min-width:110px;vertical-align:middle;">Mitarbeiter</th>
                <?php
                $prev_kw = null; $kw_cs = 0; $kw_cells = [];
                foreach ($working_days as $wd_item) {
                    if ($wd_item['kw'] !== $prev_kw) {
                        if ($prev_kw !== null) $kw_cells[] = [$prev_kw, $kw_cs];
                        $prev_kw = $wd_item['kw']; $kw_cs = 1;
                    } else $kw_cs++;
                }
                if ($prev_kw !== null) $kw_cells[] = [$prev_kw, $kw_cs];
                foreach ($kw_cells as [$kw, $cs]):
                ?>
                <th colspan="<?= $cs ?>" style="font-size:8pt;padding:2px 3px;">KW&nbsp;<?= $kw ?></th>
                <?php endforeach; ?>
                <th rowspan="2" style="width:40px;vertical-align:middle;">∑ h</th>
            </tr>
            <tr>
                <?php foreach ($working_days as $wd_item):
                    $hc = isset($holiday_map[$wd_item['date']]) ? 'style="background:#c0392b;color:#fff;"' : (isset($vacation_map[$wd_item['date']]) ? 'style="background:#e0c000;"' : '');
                ?>
                <th style="padding:2px 1px;font-size:7.5pt;min-width:32px;" <?= $hc ?>>
                    <?= $de_days_abbr[$wd_item['dow']] ?><br><?= $wd_item['day'] ?>.
                </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($employees as $emp):
            $eid   = (int)$emp['id'];
            $name  = decrypt($emp['name_enc']);
            $shifts = $emp_shifts_all[$eid] ?? [];
            $m_total = 0;
        ?>
        <tr>
            <td class="emp-cell" style="font-size:8pt;">
                <?= h($name) ?>
                <?php foreach ($shifts as $sh): ?>
                    <span class="shift-badge" style="background:<?= h($sh['color']) ?>;"><?= h($sh['short_name']) ?></span>
                <?php endforeach; ?>
            </td>
            <?php foreach ($working_days as $wd_item):
                $wd    = $wd_item['date'];
                $is_h  = isset($holiday_map[$wd]);
                $is_f  = isset($vacation_map[$wd]);
                $is_u  = isset($emp_vac_map[$eid][$wd]);
                $entry = $entry_map[$eid][$wd] ?? null;
                if ($entry) $m_total += (float)$entry['hours'];
            ?>
            <?php if ($is_h): ?>
                <td class="cell-holiday" style="padding:1px;font-size:7pt;">FT</td>
            <?php elseif ($is_u): ?>
                <td class="cell-urlaub" style="padding:1px;font-size:7pt;">U</td>
            <?php elseif ($entry): ?>
                <td class="cell-work" style="padding:2px 1px;">
                    <div class="hours" style="font-size:8pt;"><?= h(number_format((float)$entry['hours'], 1, ',', '.')) ?></div>
                    <?php if ($is_f): ?><div style="font-size:6.5pt;color:#806010;">F</div><?php endif; ?>
                </td>
            <?php elseif ($is_f): ?>
                <td class="cell-ferien" style="padding:1px;font-size:7pt;">F</td>
            <?php else: ?>
                <td class="cell-empty">–</td>
            <?php endif; ?>
            <?php endforeach; ?>
            <td style="font-weight:700;text-align:center;background:#f0e8d8;">
                <?= $m_total > 0 ? h(number_format($m_total, 1, ',', '.')) : '—' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>Summe</td>
                <?php $gt = 0;
                foreach ($working_days as $wd_item):
                    $ds = 0;
                    foreach ($employees as $emp) $ds += (float)($entry_map[$emp['id']][$wd_item['date']]['hours'] ?? 0);
                    $gt += $ds;
                ?>
                <td style="font-size:7.5pt;"><?= $ds > 0 ? h(number_format($ds, 1, ',', '.')) : '' ?></td>
                <?php endforeach; ?>
                <td><?= $gt > 0 ? h(number_format($gt, 1, ',', '.')) : '—' ?></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>

    <!-- Legend + footer -->
    <div class="legend">
        <span style="background:#ffe0e0;color:#900;">FT = Feiertag</span>
        <span style="background:#fff8dc;color:#7a5c00;">F = Schulferien</span>
        <span style="background:#e8f4fd;color:#0a4c9c;">U = Urlaub (MA)</span>
        <span style="background:#eaf4e8;color:#1a5c1a;">Einsatz geplant</span>
        <span style="color:#aaa;">— = kein Einsatz</span>
    </div>
    <div class="pdf-footer">
        PlanBär v<?= APP_VERSION ?> &mdash; <?= h($einrichtung) ?> &mdash;
        <?= h($schedule['year_name']) ?>, Revision <?= (int)$rev['revision_number'] ?> &mdash;
        Gedruckt: <?= h(date('d.m.Y H:i')) ?> Uhr &mdash; <?= h($user['username']) ?>
    </div>
</div>

<script>
// Auto-trigger print when opened in new tab
window.addEventListener('load', function () {
    setTimeout(function() { window.print(); }, 600);
});
</script>
</body>
</html>
