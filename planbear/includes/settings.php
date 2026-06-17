<?php
declare(strict_types=1);

function get_setting(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return ($row && $row['setting_value'] !== null) ? $row['setting_value'] : $default;
}

function set_setting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value, updated_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    );
    $stmt->execute([$key, $value]);
}

function get_all_settings(PDO $pdo): array {
    $rows = $pdo->query('SELECT setting_key, setting_value FROM settings ORDER BY setting_key')->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[$row['setting_key']] = $row['setting_value'];
    }
    return $result;
}

/** Default settings used during install and as fallback. */
function default_settings(): array {
    return [
        'einrichtung_name'        => 'Nachschulische Betreuung',
        'einrichtung_adresse'     => '',
        'einrichtung_telefon'     => '',
        'einrichtung_email'       => '',
        'betreuungszeit_start'    => '13:00',
        'betreuungszeit_end'      => '15:30',
        'fruehdienst_start'       => '11:30',
        'fruehdienst_end'         => '13:00',
        'spaetdienst_start'       => '15:30',
        'spaetdienst_end'         => '17:30',
        'planung_wochentage'      => '0,1,2,3,4',
        'planung_notiz'           => '',
        // Pausen
        'pause_dauer_minuten'     => '30',
        'pause_ab_stunden'        => '6',
        // Urlaub
        'urlaub_standard_tage'    => '20',
    ];
}
