<?php
declare(strict_types=1);
// Error display ON during install only
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Prevent accidental re-runs after completion
if (file_exists(__DIR__ . '/.installed')) {
    die('<h2>Installation bereits abgeschlossen. Diese Datei wurde deaktiviert.</h2>');
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/hessen_calendar.php';

// --- Connect to MySQL ---
try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die('<div style="color:red"><h2>Datenbankverbindung fehlgeschlagen:</h2><pre>' . htmlspecialchars($e->getMessage()) . '</pre></div>');
}

$messages = [];
$done     = false;
$admin_pw_error = '';

// -----------------------------------------------------------------------
// Handle form submission (create admin user)
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'install') {
    $admin_username = trim($_POST['admin_username'] ?? 'admin');
    $admin_name     = trim($_POST['admin_name']     ?? 'Administrator');
    $admin_pw       = $_POST['admin_password']  ?? '';
    $admin_pw2      = $_POST['admin_password2'] ?? '';

    if (strlen($admin_username) < 3 || !preg_match('/^[a-zA-Z0-9_.\-]+$/', $admin_username)) {
        $admin_pw_error = 'Benutzername ungültig (min. 3 Zeichen, nur a-z 0-9 _ . -)';
    } elseif (strlen($admin_pw) < 8) {
        $admin_pw_error = 'Passwort muss mindestens 8 Zeichen lang sein.';
    } elseif ($admin_pw !== $admin_pw2) {
        $admin_pw_error = 'Passwörter stimmen nicht überein.';
    } else {
        $messages[] = ['ok', 'Datenbankverbindung erfolgreich: ' . DB_NAME];

        // ----------------------------------------------------------------
        // Step 2: Create tables
        // ----------------------------------------------------------------
        $tables = <<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    name_enc TEXT NOT NULL,
    email_enc TEXT,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','editor','viewer') NOT NULL DEFAULT 'viewer',
    is_active TINYINT(1) DEFAULT 1,
    last_login DATETIME,
    failed_logins INT DEFAULT 0,
    locked_until DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name_enc TEXT NOT NULL,
    weekly_hours DECIMAL(5,2) NOT NULL DEFAULT 0,
    vacation_hours DECIMAL(5,2) NOT NULL DEFAULT 0,
    max_weekly_hours DECIMAL(5,2) DEFAULT NULL COMMENT 'NULL = kein Limit',
    available_days VARCHAR(20) NOT NULL DEFAULT '0,1,2,3,4',
    time_mode ENUM('full','week','day') NOT NULL DEFAULT 'full',
    week_time_start TIME NULL,
    week_time_end TIME NULL,
    pause_minuten SMALLINT NULL COMMENT 'NULL = Systemstandard verwenden',
    urlaub_zusatz_tage SMALLINT NOT NULL DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_day_times (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    day_of_week TINYINT NOT NULL COMMENT '0=Mo, 1=Di, 2=Mi, 3=Do, 4=Fr',
    time_start TIME NOT NULL,
    time_end TIME NOT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE KEY unique_emp_day (employee_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS school_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(20) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_active TINYINT(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS public_holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_year_id INT NOT NULL,
    holiday_date DATE NOT NULL,
    name VARCHAR(100) NOT NULL,
    FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE,
    UNIQUE KEY unique_holiday (school_year_id, holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vacation_periods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_year_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_work_period TINYINT(1) DEFAULT 0,
    FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vacation_period_weeks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vacation_period_id INT NOT NULL,
    week_number INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_work_period TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (vacation_period_id) REFERENCES vacation_periods(id) ON DELETE CASCADE,
    UNIQUE KEY unique_period_week (vacation_period_id, week_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_year_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (school_year_id) REFERENCES school_years(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_revisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    revision_number INT NOT NULL,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#6c757d',
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    revision_id INT NOT NULL,
    employee_id INT NOT NULL,
    entry_date DATE NOT NULL,
    hours DECIMAL(5,2) NOT NULL,
    pause_minuten SMALLINT NOT NULL DEFAULT 0,
    time_start TIME NULL,
    time_end TIME NULL,
    is_vacation_period TINYINT(1) DEFAULT 0,
    location_id INT DEFAULT NULL,
    FOREIGN KEY (revision_id) REFERENCES schedule_revisions(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
    UNIQUE KEY unique_entry (revision_id, employee_id, entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_vacations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    school_year_id INT NOT NULL,
    vacation_date DATE NOT NULL,
    status ENUM('geplant','genehmigt','genommen') NOT NULL DEFAULT 'geplant',
    notes VARCHAR(255),
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    request_group_id INT DEFAULT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_emp_date (employee_id, vacation_date),
    KEY idx_request_group (request_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    short_name VARCHAR(10) NOT NULL DEFAULT '',
    time_start TIME NOT NULL,
    time_end TIME NOT NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#6c757d',
    sort_order INT NOT NULL DEFAULT 0,
    slot VARCHAR(20) DEFAULT NULL,
    UNIQUE KEY unique_slot (slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_shifts (
    employee_id INT NOT NULL,
    shift_id INT NOT NULL,
    PRIMARY KEY (employee_id, shift_id),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_shift_times (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    shift_id INT NOT NULL,
    day_of_week TINYINT NULL COMMENT 'NULL = gleiche Zeit an allen Tagen (Wochen-Modus); 0-4 = Mo-Fr (Tage-Modus)',
    time_start TIME NOT NULL,
    time_end TIME NOT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE,
    KEY idx_emp_shift (employee_id, shift_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_location_preferences (
    employee_id INT NOT NULL,
    location_id INT NOT NULL,
    PRIMARY KEY (employee_id, location_id),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_entry_locations (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    ip_address VARCHAR(45),
    success TINYINT(1),
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (array_filter(array_map('trim', explode(';', $tables))) as $sql) {
            if ($sql) {
                $pdo->exec($sql);
            }
        }
        $messages[] = ['ok', 'Alle Tabellen erstellt.'];

        // ----------------------------------------------------------------
        // Step 3: Seed Hessen 2026/2027 calendar data
        // ----------------------------------------------------------------
        $seed = get_hessen_2026_2027_data();

        // School year
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO school_years (name, start_date, end_date, is_active) VALUES (?,?,?,1)'
        );
        $stmt->execute([
            $seed['school_year']['name'],
            $seed['school_year']['start_date'],
            $seed['school_year']['end_date'],
        ]);
        $sy_id = $pdo->lastInsertId() ?: (int)$pdo->query('SELECT id FROM school_years WHERE name="' . $seed['school_year']['name'] . '" LIMIT 1')->fetchColumn();
        $messages[] = ['ok', 'Schuljahr ' . htmlspecialchars($seed['school_year']['name']) . ' angelegt (ID: ' . $sy_id . ').'];

        // Public holidays
        $stmt = $pdo->prepare('INSERT IGNORE INTO public_holidays (school_year_id, holiday_date, name) VALUES (?,?,?)');
        foreach ($seed['public_holidays'] as $hol) {
            $stmt->execute([$sy_id, $hol['date'], $hol['name']]);
        }
        $messages[] = ['ok', count($seed['public_holidays']) . ' Feiertage eingetragen.'];

        // Vacation periods
        $stmt = $pdo->prepare('INSERT INTO vacation_periods (school_year_id, name, start_date, end_date, is_work_period) VALUES (?,?,?,?,?)');
        foreach ($seed['vacation_periods'] as $vac) {
            // Check if already exists
            $chk = $pdo->prepare('SELECT id FROM vacation_periods WHERE school_year_id=? AND name=?');
            $chk->execute([$sy_id, $vac['name']]);
            if (!$chk->fetch()) {
                $stmt->execute([$sy_id, $vac['name'], $vac['start_date'], $vac['end_date'], $vac['is_work_period']]);
            }
        }
        $messages[] = ['ok', count($seed['vacation_periods']) . ' Schulferienzeiten eingetragen.'];

        // ----------------------------------------------------------------
        // Step 3b: Seed default settings
        // ----------------------------------------------------------------
        require_once dirname(__DIR__) . '/includes/settings.php';
        $defaults = default_settings();
        $stmt_set = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)');
        foreach ($defaults as $k => $v) {
            $stmt_set->execute([$k, $v]);
        }
        $messages[] = ['ok', count($defaults) . ' Standard-Einstellungen gesetzt.'];

        // ----------------------------------------------------------------
        // Step 3c: Seed default shifts
        // ----------------------------------------------------------------
        $default_shifts = [
            ['Frühdienst',      'FD', '11:30', '13:00', '#198754', 1],
            ['Kernarbeitszeit', 'KA', '13:00', '15:30', '#0d6efd', 2],
            ['Spätdienst',      'SD', '15:30', '17:30', '#fd7e14', 3],
        ];
        $stmt_sh = $pdo->prepare(
            'INSERT IGNORE INTO shifts (name, short_name, time_start, time_end, color, sort_order) VALUES (?,?,?,?,?,?)'
        );
        foreach ($default_shifts as $sh) {
            $stmt_sh->execute($sh);
        }
        $messages[] = ['ok', count($default_shifts) . ' Standard-Schichten angelegt (FD / KA / SD).'];

        // ----------------------------------------------------------------
        // Step 4: Create admin user
        // ----------------------------------------------------------------
        // Load crypto after DB is available
        require_once dirname(__DIR__) . '/includes/crypto.php';

        $hash      = password_hash($admin_pw, PASSWORD_BCRYPT, ['cost' => 12]);
        $name_enc  = encrypt($admin_name);

        $stmt = $pdo->prepare('SELECT id FROM users WHERE username=?');
        $stmt->execute([$admin_username]);
        if ($stmt->fetch()) {
            $messages[] = ['warn', 'Benutzer "' . $admin_username . '" existiert bereits — Passwort wird nicht überschrieben.'];
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, name_enc, password_hash, role) VALUES (?,?,?,?)'
            );
            $stmt->execute([$admin_username, $name_enc, $hash, 'admin']);
            $messages[] = ['ok', 'Admin-Benutzer "' . $admin_username . '" angelegt.'];
        }

        // ----------------------------------------------------------------
        // Step 5: Mark as installed (create lock file)
        // ----------------------------------------------------------------
        file_put_contents(__DIR__ . '/.installed', date('Y-m-d H:i:s'));

        $done = true;
        $messages[] = ['ok', 'Installation abgeschlossen! Diese Seite ist jetzt gesperrt.'];
    }
}
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PlanBär Installation</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        body { background: linear-gradient(135deg, #5C3317, #A0522D); min-height: 100vh; }
        .install-card { max-width: 640px; margin: 40px auto; border-radius: 16px; }
    </style>
</head>
<body>
<div class="container py-5">
    <div class="install-card card shadow-lg">
        <div class="card-header text-center py-4" style="background:#5C3317;color:#DEB887;border-radius:16px 16px 0 0;">
            <div style="font-size:3rem;">🐻</div>
            <h2 class="mb-0">PlanBär — Installation</h2>
        </div>
        <div class="card-body p-4">

            <?php if ($done): ?>
                <!-- SUCCESS -->
                <div class="alert alert-success">
                    <h5><i class="bi bi-check-circle-fill"></i> Installation erfolgreich!</h5>
                    <ul class="mb-0">
                        <?php foreach ($messages as [$type, $msg]): ?>
                            <li class="<?= $type === 'warn' ? 'text-warning' : '' ?>"><?= htmlspecialchars($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="alert alert-warning">
                    <strong>Wichtig:</strong> Die Datei <code>install/install.php</code> ist nun durch eine Lock-Datei gesperrt. Löschen Sie <code>install/install.php</code> vom Server und ändern Sie den <code>CRYPTO_KEY_HEX</code> in <code>config/config.php</code> auf einen eigenen sicheren Wert!
                </div>
                <a href="../public/login.php" class="btn btn-lg w-100" style="background:#5C3317;color:#DEB887;">
                    Zum Login &rarr;
                </a>

            <?php else: ?>
                <!-- INSTALL FORM -->
                <?php if ($admin_pw_error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($admin_pw_error) ?></div>
                <?php endif; ?>

                <p class="text-muted mb-4">
                    Dieses Skript erstellt die Datenbank, alle Tabellen, seeded die Hessen-Feriendaten 2026/2027 und legt den ersten Admin-Benutzer an.
                </p>

                <form method="post">
                    <input type="hidden" name="step" value="install">

                    <h5 class="fw-bold mb-3" style="color:#5C3317;">Admin-Benutzer</h5>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Benutzername</label>
                        <input type="text" class="form-control" name="admin_username"
                               value="<?= htmlspecialchars($_POST['admin_username'] ?? 'admin') ?>"
                               required minlength="3" maxlength="50" pattern="[a-zA-Z0-9_.\-]+">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Anzeigename</label>
                        <input type="text" class="form-control" name="admin_name"
                               value="<?= htmlspecialchars($_POST['admin_name'] ?? 'Administrator') ?>"
                               required maxlength="200">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Passwort</label>
                        <input type="password" class="form-control" name="admin_password"
                               required minlength="8" placeholder="Min. 8 Zeichen">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Passwort wiederholen</label>
                        <input type="password" class="form-control" name="admin_password2"
                               required minlength="8">
                    </div>

                    <div class="alert alert-info small">
                        <strong>Datenbankverbindung:</strong> <?= htmlspecialchars(DB_HOST) ?> / <?= htmlspecialchars(DB_NAME) ?><br>
                        <strong>Benutzer:</strong> <?= htmlspecialchars(DB_USER) ?>
                    </div>

                    <button type="submit" class="btn btn-lg w-100" style="background:#5C3317;color:#DEB887;">
                        🐻 Installation starten
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</body>
</html>
