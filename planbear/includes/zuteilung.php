<?php
declare(strict_types=1);

/**
 * Lazily create the locations / employee_location_preferences tables and add
 * schedule_entries.location_id (so already-installed systems pick up the
 * Zuteilung feature without a manual migration step).
 */
function ensure_location_tables(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS locations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            color VARCHAR(7) NOT NULL DEFAULT '#6c757d',
            sort_order INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS employee_location_preferences (
            employee_id INT NOT NULL,
            location_id INT NOT NULL,
            PRIMARY KEY (employee_id, location_id),
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $col = $pdo->query("SHOW COLUMNS FROM schedule_entries LIKE 'location_id'")->fetch();
    if (!$col) {
        $pdo->exec('ALTER TABLE schedule_entries ADD COLUMN location_id INT DEFAULT NULL');
        $pdo->exec('ALTER TABLE schedule_entries ADD FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL');
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schedule_entry_locations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            revision_id INT NOT NULL,
            employee_id INT NOT NULL,
            entry_date DATE NOT NULL,
            shift_id INT DEFAULT NULL,
            location_id INT NOT NULL,
            time_start TIME NOT NULL,
            time_end TIME NOT NULL,
            FOREIGN KEY (revision_id) REFERENCES schedule_revisions(id) ON DELETE CASCADE,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE SET NULL,
            FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
            KEY idx_entry (revision_id, employee_id, entry_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** Preferred location IDs for an employee (multi-select, may be empty). */
function get_employee_location_preferences(PDO $pdo, int $employee_id): array {
    $stmt = $pdo->prepare('SELECT location_id FROM employee_location_preferences WHERE employee_id = ?');
    $stmt->execute([$employee_id]);
    return array_map('intval', array_column($stmt->fetchAll(), 'location_id'));
}

/** Replace an employee's location preferences (delete-then-insert). */
function set_employee_location_preferences(PDO $pdo, int $employee_id, array $location_ids): void {
    $pdo->prepare('DELETE FROM employee_location_preferences WHERE employee_id = ?')->execute([$employee_id]);
    if (empty($location_ids)) {
        return;
    }
    $stmt = $pdo->prepare('INSERT IGNORE INTO employee_location_preferences (employee_id, location_id) VALUES (?, ?)');
    foreach ($location_ids as $lid) {
        $stmt->execute([$employee_id, (int)$lid]);
    }
}

/**
 * Pick a random location for an employee: prefers their declared preferences,
 * falls back to any defined location if they have none.
 */
function pick_random_location(array $all_location_ids, array $preferred_ids): ?int {
    $pool = !empty($preferred_ids) ? $preferred_ids : $all_location_ids;
    if (empty($pool)) {
        return null;
    }
    return (int)$pool[array_rand($pool)];
}

/**
 * Location/time segments for all entries of an employee in a date range,
 * keyed by [employee_id][entry_date] => list of segment rows (each with
 * location_name/location_color and, if tied to a shift, shift_short_name),
 * ordered by start time.
 */
function get_location_segments_map(PDO $pdo, int $revision_id, string $date_from, string $date_to): array {
    $stmt = $pdo->prepare(
        'SELECT sel.id, sel.employee_id, sel.entry_date, sel.shift_id, sel.location_id,
                sel.time_start, sel.time_end,
                l.name AS location_name, l.color AS location_color,
                sh.short_name AS shift_short_name, sh.color AS shift_color
         FROM schedule_entry_locations sel
         JOIN locations l ON l.id = sel.location_id
         LEFT JOIN shifts sh ON sh.id = sel.shift_id
         WHERE sel.revision_id = ? AND sel.entry_date BETWEEN ? AND ?
         ORDER BY sel.time_start, sel.id'
    );
    $stmt->execute([$revision_id, $date_from, $date_to]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['employee_id']][$row['entry_date']][] = $row;
    }
    return $map;
}

/** Add a location/time segment for an employee's day. Returns the new segment id. */
function add_location_segment(
    PDO $pdo,
    int $revision_id,
    int $employee_id,
    string $entry_date,
    ?int $shift_id,
    int $location_id,
    string $time_start,
    string $time_end
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO schedule_entry_locations
         (revision_id, employee_id, entry_date, shift_id, location_id, time_start, time_end)
         VALUES (?,?,?,?,?,?,?)'
    );
    $stmt->execute([$revision_id, $employee_id, $entry_date, $shift_id, $location_id, $time_start, $time_end]);
    return (int)$pdo->lastInsertId();
}

/** Delete a single location segment by id, scoped to a revision (authorization). */
function delete_location_segment(PDO $pdo, int $segment_id, int $revision_id): void {
    $pdo->prepare('DELETE FROM schedule_entry_locations WHERE id = ? AND revision_id = ?')
        ->execute([$segment_id, $revision_id]);
}

/** Remove all location segments for an employee's day (used before re-assigning). */
function clear_location_segments(PDO $pdo, int $revision_id, int $employee_id, string $entry_date): void {
    $pdo->prepare('DELETE FROM schedule_entry_locations WHERE revision_id=? AND employee_id=? AND entry_date=?')
        ->execute([$revision_id, $employee_id, $entry_date]);
}
