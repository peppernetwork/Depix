<?php
// Schedule generation algorithm

/**
 * Generate schedule entries for a given revision.
 * Iterates week by week over the school year, assigning daily hours per employee.
 *
 * @param PDO $pdo
 * @param int $revision_id   The revision to populate.
 * @param int $school_year_id
 * @return int Number of entries created.
 */
function generate_schedule(PDO $pdo, int $revision_id, int $school_year_id): int {

    // --- Load school year ---
    $stmt = $pdo->prepare('SELECT start_date, end_date FROM school_years WHERE id = ?');
    $stmt->execute([$school_year_id]);
    $sy = $stmt->fetch();
    if (!$sy) {
        throw new RuntimeException('Schuljahr nicht gefunden.');
    }

    // --- Load active employees ---
    $stmt = $pdo->prepare('SELECT id, weekly_hours, vacation_hours, available_days FROM employees WHERE is_active = 1');
    $stmt->execute();
    $employees = $stmt->fetchAll();
    if (empty($employees)) {
        return 0;
    }

    // --- Load public holidays as a set of date strings ---
    $stmt = $pdo->prepare('SELECT holiday_date FROM public_holidays WHERE school_year_id = ?');
    $stmt->execute([$school_year_id]);
    $holiday_rows = $stmt->fetchAll();
    $holidays = [];
    foreach ($holiday_rows as $row) {
        $holidays[$row['holiday_date']] = true;
    }

    // --- Load vacation periods ---
    $stmt = $pdo->prepare('SELECT start_date, end_date, is_work_period FROM vacation_periods WHERE school_year_id = ?');
    $stmt->execute([$school_year_id]);
    $vacations = $stmt->fetchAll();

    // --- Iterate week by week ---
    $start  = new DateTime($sy['start_date']);
    $end    = new DateTime($sy['end_date']);

    // Move start to the Monday of the first week
    $dow = (int)$start->format('N'); // 1=Mon..7=Sun
    if ($dow > 1) {
        $start->modify('-' . ($dow - 1) . ' days');
    }

    $insert_stmt = $pdo->prepare(
        'INSERT IGNORE INTO schedule_entries (revision_id, employee_id, entry_date, hours, is_vacation_period)
         VALUES (?, ?, ?, ?, ?)'
    );

    $count = 0;
    $cursor = clone $start;

    while ($cursor <= $end) {
        // Build Mon–Fri dates for this week
        $week_dates = [];
        for ($d = 0; $d < 5; $d++) {
            $day_clone = clone $cursor;
            $day_clone->modify('+' . $d . ' days');
            $ds = $day_clone->format('Y-m-d');
            if ($day_clone <= $end) {
                $week_dates[$d] = $ds; // 0=Mon..4=Fri
            }
        }

        // Determine if this week falls in a vacation period
        $week_mon = $cursor->format('Y-m-d');
        $week_fri = (clone $cursor)->modify('+4 days')->format('Y-m-d');

        $in_vacation    = false;
        $is_work_period = false;
        foreach ($vacations as $vac) {
            // Check overlap: week overlaps vacation if mon <= vac.end and fri >= vac.start
            if ($week_mon <= $vac['end_date'] && $week_fri >= $vac['start_date']) {
                $in_vacation    = true;
                $is_work_period = (bool)$vac['is_work_period'];
                break;
            }
        }

        // For each employee, calculate hours per available working day
        foreach ($employees as $emp) {
            $emp_days = array_map('intval', explode(',', $emp['available_days'])); // [0,1,2,3,4]

            // Choose target hours
            if ($in_vacation && !$is_work_period) {
                // No work during vacation (unless is_work_period)
                // If employee has vacation_hours > 0, still schedule them
                if ((float)$emp['vacation_hours'] <= 0) {
                    continue; // Skip this employee this week
                }
                $target_hours = (float)$emp['vacation_hours'];
            } elseif ($in_vacation && $is_work_period) {
                $target_hours = (float)$emp['vacation_hours'] > 0
                    ? (float)$emp['vacation_hours']
                    : (float)$emp['weekly_hours'];
            } else {
                $target_hours = (float)$emp['weekly_hours'];
            }

            if ($target_hours <= 0) {
                continue;
            }

            // Find available working days in this week for this employee
            $working_days = [];
            foreach ($emp_days as $day_idx) {
                if (!isset($week_dates[$day_idx])) {
                    continue; // Day outside school year range
                }
                $ds = $week_dates[$day_idx];
                if (isset($holidays[$ds])) {
                    continue; // Public holiday
                }
                $working_days[] = [$day_idx, $ds];
            }

            if (empty($working_days)) {
                continue;
            }

            $hours_per_day = round($target_hours / count($working_days), 2);

            foreach ($working_days as [$day_idx, $ds]) {
                $insert_stmt->execute([
                    $revision_id,
                    $emp['id'],
                    $ds,
                    $hours_per_day,
                    $in_vacation ? 1 : 0,
                ]);
                $count++;
            }
        }

        // Advance to next Monday
        $cursor->modify('+7 days');
    }

    return $count;
}

/**
 * Create a new schedule + first revision and generate entries.
 * Returns [$schedule_id, $revision_id, $entry_count].
 */
function create_schedule_with_revision(PDO $pdo, int $school_year_id, string $name, int $user_id, string $notes = ''): array {
    $pdo->beginTransaction();
    try {
        // Insert schedule
        $stmt = $pdo->prepare('INSERT INTO schedules (school_year_id, name) VALUES (?, ?)');
        $stmt->execute([$school_year_id, $name]);
        $schedule_id = (int)$pdo->lastInsertId();

        // Insert first revision
        $stmt = $pdo->prepare('INSERT INTO schedule_revisions (schedule_id, revision_number, created_by, notes) VALUES (?, 1, ?, ?)');
        $stmt->execute([$schedule_id, $user_id, $notes]);
        $revision_id = (int)$pdo->lastInsertId();

        // Generate entries
        $count = generate_schedule($pdo, $revision_id, $school_year_id);

        $pdo->commit();
        return [$schedule_id, $revision_id, $count];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Add a new revision to an existing schedule.
 * Copies no entries — runs fresh generation.
 */
function add_revision(PDO $pdo, int $schedule_id, int $user_id, string $notes = ''): array {
    // Get school_year_id for this schedule
    $stmt = $pdo->prepare('SELECT school_year_id FROM schedules WHERE id = ?');
    $stmt->execute([$schedule_id]);
    $schedule = $stmt->fetch();
    if (!$schedule) {
        throw new RuntimeException('Plan nicht gefunden.');
    }

    // Determine next revision number
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(revision_number), 0) + 1 AS next_rev FROM schedule_revisions WHERE schedule_id = ?');
    $stmt->execute([$schedule_id]);
    $next_rev = (int)$stmt->fetchColumn();

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO schedule_revisions (schedule_id, revision_number, created_by, notes) VALUES (?, ?, ?, ?)');
        $stmt->execute([$schedule_id, $next_rev, $user_id, $notes]);
        $revision_id = (int)$pdo->lastInsertId();

        $count = generate_schedule($pdo, $revision_id, $schedule['school_year_id']);

        $pdo->commit();
        return [$revision_id, $count];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
