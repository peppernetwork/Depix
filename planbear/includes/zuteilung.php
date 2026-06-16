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
