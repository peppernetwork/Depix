<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';

/**
 * Calculate hours from two HH:MM time strings. Returns 0 if invalid or negative.
 */
function time_to_hours(string $start, string $end): float {
    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
        return 0.0;
    }
    [$sh, $sm] = array_map('intval', explode(':', $start));
    [$eh, $em] = array_map('intval', explode(':', $end));
    $mins = ($eh * 60 + $em) - ($sh * 60 + $sm);
    return max(0.0, round($mins / 60, 2));
}

/**
 * Generate schedule entries for a given revision.
 *
 * Logic:
 *  - Iterates week by week over the school year (Mon–Fri).
 *  - Skips public holidays.
 *  - Respects vacation periods (only schedules employees with vacation_hours > 0).
 *  - Uses each employee's time_mode to determine shift times:
 *      'full' → global Betreuungszeit (from settings table)
 *      'week' → employee.week_time_start / week_time_end (same every day)
 *      'day'  → employee_day_times per weekday
 *  - Stores time_start, time_end, and calculated hours per entry.
 *
 * @return int Number of entries created.
 */
function generate_schedule(PDO $pdo, int $revision_id, int $school_year_id): int {

    // --- School year ---
    $stmt = $pdo->prepare('SELECT start_date, end_date FROM school_years WHERE id = ?');
    $stmt->execute([$school_year_id]);
    $sy = $stmt->fetch();
    if (!$sy) {
        throw new RuntimeException('Schuljahr nicht gefunden.');
    }

    // --- Global settings ---
    $bz_start = get_setting($pdo, 'betreuungszeit_start', '12:00');
    $bz_end   = get_setting($pdo, 'betreuungszeit_end',   '15:30');

    // --- Active employees ---
    $stmt = $pdo->prepare(
        'SELECT id, vacation_hours, available_days, time_mode, week_time_start, week_time_end
         FROM employees WHERE is_active = 1 ORDER BY id'
    );
    $stmt->execute();
    $employees = $stmt->fetchAll();
    if (empty($employees)) {
        return 0;
    }

    // --- Per-day times for all employees (mode='day') ---
    $day_times = []; // [employee_id][day_of_week] = ['start'=>'HH:MM','end'=>'HH:MM']
    $stmt = $pdo->prepare('SELECT employee_id, day_of_week, time_start, time_end FROM employee_day_times');
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $day_times[(int)$row['employee_id']][(int)$row['day_of_week']] = [
            'start' => substr($row['time_start'], 0, 5),
            'end'   => substr($row['time_end'],   0, 5),
        ];
    }

    // --- Public holidays as a date-string set ---
    $stmt = $pdo->prepare('SELECT holiday_date FROM public_holidays WHERE school_year_id = ?');
    $stmt->execute([$school_year_id]);
    $holidays = [];
    foreach ($stmt->fetchAll() as $row) {
        $holidays[$row['holiday_date']] = true;
    }

    // --- Vacation periods ---
    $stmt = $pdo->prepare('SELECT start_date, end_date, is_work_period FROM vacation_periods WHERE school_year_id = ?');
    $stmt->execute([$school_year_id]);
    $vacations = $stmt->fetchAll();

    // --- Prepare insert statement ---
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO schedule_entries
             (revision_id, employee_id, entry_date, hours, time_start, time_end, is_vacation_period)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    // --- Iterate week by week ---
    $start = new DateTime($sy['start_date']);
    $end   = new DateTime($sy['end_date']);

    // Move back to Monday of the week containing start_date
    $dow = (int)$start->format('N'); // 1=Mon..7=Sun
    if ($dow > 1) {
        $start->modify('-' . ($dow - 1) . ' days');
    }

    $count  = 0;
    $cursor = clone $start;

    while ($cursor <= $end) {
        // Build Mon–Fri date map for this week: [0=>'Y-m-d', ..., 4=>'Y-m-d']
        $week_dates = [];
        for ($d = 0; $d < 5; $d++) {
            $day = clone $cursor;
            if ($d > 0) {
                $day->modify('+' . $d . ' days');
            }
            if ($day >= new DateTime($sy['start_date']) && $day <= $end) {
                $week_dates[$d] = $day->format('Y-m-d');
            }
        }

        // Check vacation overlap for this week
        $week_mon = $cursor->format('Y-m-d');
        $week_fri = (clone $cursor)->modify('+4 days')->format('Y-m-d');
        $in_vacation    = false;
        $is_work_period = false;
        foreach ($vacations as $vac) {
            if ($week_mon <= $vac['end_date'] && $week_fri >= $vac['start_date']) {
                $in_vacation    = true;
                $is_work_period = (bool)$vac['is_work_period'];
                break;
            }
        }

        // Process each employee for this week
        foreach ($employees as $emp) {
            $emp_id    = (int)$emp['id'];
            $emp_days  = array_map('intval', array_filter(explode(',', $emp['available_days']), 'strlen'));
            $time_mode = $emp['time_mode'] ?? 'full';

            // Determine target hours (vacation vs school time)
            if ($in_vacation && !$is_work_period) {
                if ((float)$emp['vacation_hours'] <= 0) {
                    continue;
                }
                // For vacation weeks: hours from vacation_hours, times same as mode
                $vacation_mode = true;
                $target_override = (float)$emp['vacation_hours']; // per week
            } elseif ($in_vacation && $is_work_period) {
                $vacation_mode = true;
                $target_override = (float)$emp['vacation_hours'] > 0
                    ? (float)$emp['vacation_hours'] : null;
            } else {
                $vacation_mode   = false;
                $target_override = null;
            }

            // Find available working days (available + not holiday + within range)
            $working_days = []; // [day_index => date_string]
            foreach ($emp_days as $day_idx) {
                if (!isset($week_dates[$day_idx])) continue;
                $ds = $week_dates[$day_idx];
                if (isset($holidays[$ds])) continue;
                $working_days[$day_idx] = $ds;
            }

            if (empty($working_days)) continue;

            // Resolve time range and hours per day
            foreach ($working_days as $day_idx => $ds) {
                switch ($time_mode) {
                    case 'week':
                        $t_start = substr($emp['week_time_start'] ?? $bz_start, 0, 5);
                        $t_end   = substr($emp['week_time_end']   ?? $bz_end,   0, 5);
                        if (!$t_start || !$t_end) {
                            $t_start = $bz_start;
                            $t_end   = $bz_end;
                        }
                        break;

                    case 'day':
                        if (isset($day_times[$emp_id][$day_idx])) {
                            $t_start = $day_times[$emp_id][$day_idx]['start'];
                            $t_end   = $day_times[$emp_id][$day_idx]['end'];
                        } else {
                            // Fallback to global if no day-time defined
                            $t_start = $bz_start;
                            $t_end   = $bz_end;
                        }
                        break;

                    default: // 'full'
                        $t_start = $bz_start;
                        $t_end   = $bz_end;
                        break;
                }

                $hours_per_day = time_to_hours($t_start, $t_end);

                // If override from vacation_hours: distribute weekly total across working days
                if ($target_override !== null) {
                    $hours_per_day = round($target_override / count($working_days), 2);
                    // Keep times from the mode, but adjust hours to the vacation target
                }

                if ($hours_per_day <= 0) continue;

                $insert->execute([
                    $revision_id,
                    $emp_id,
                    $ds,
                    $hours_per_day,
                    $t_start,
                    $t_end,
                    $in_vacation ? 1 : 0,
                ]);
                $count++;
            }
        }

        $cursor->modify('+7 days');
    }

    return $count;
}

/**
 * Create a new schedule + first revision and run the generation.
 * Returns [$schedule_id, $revision_id, $entry_count].
 */
function create_schedule_with_revision(PDO $pdo, int $school_year_id, string $name, int $user_id, string $notes = ''): array {
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO schedules (school_year_id, name) VALUES (?, ?)')->execute([$school_year_id, $name]);
        $schedule_id = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO schedule_revisions (schedule_id, revision_number, created_by, notes) VALUES (?, 1, ?, ?)')
            ->execute([$schedule_id, $user_id, $notes]);
        $revision_id = (int)$pdo->lastInsertId();

        $count = generate_schedule($pdo, $revision_id, $school_year_id);

        $pdo->commit();
        return [$schedule_id, $revision_id, $count];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Add a new revision to an existing schedule (fresh generation from current employees).
 * Returns [$revision_id, $entry_count].
 */
function add_revision(PDO $pdo, int $schedule_id, int $user_id, string $notes = ''): array {
    $stmt = $pdo->prepare('SELECT school_year_id FROM schedules WHERE id = ?');
    $stmt->execute([$schedule_id]);
    $schedule = $stmt->fetch();
    if (!$schedule) {
        throw new RuntimeException('Plan nicht gefunden.');
    }

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(revision_number), 0) + 1 AS next_rev FROM schedule_revisions WHERE schedule_id = ?');
    $stmt->execute([$schedule_id]);
    $next_rev = (int)$stmt->fetchColumn();

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO schedule_revisions (schedule_id, revision_number, created_by, notes) VALUES (?, ?, ?, ?)')
            ->execute([$schedule_id, $next_rev, $user_id, $notes]);
        $revision_id = (int)$pdo->lastInsertId();

        $count = generate_schedule($pdo, $revision_id, $schedule['school_year_id']);

        $pdo->commit();
        return [$revision_id, $count];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
