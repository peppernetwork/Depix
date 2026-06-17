<?php
// Hessen school calendar seed data for 2026/2027
// Used by install.php

function get_hessen_2026_2027_data(): array {
    return [
        'school_year' => [
            'name'       => '2026/2027',
            'start_date' => '2026-08-31',
            'end_date'   => '2027-08-22',
        ],
        'public_holidays' => [
            ['date' => '2026-10-03', 'name' => 'Tag der Deutschen Einheit'],
            ['date' => '2026-12-25', 'name' => '1. Weihnachtstag'],
            ['date' => '2026-12-26', 'name' => '2. Weihnachtstag'],
            ['date' => '2027-01-01', 'name' => 'Neujahr'],
            ['date' => '2027-03-26', 'name' => 'Karfreitag'],
            ['date' => '2027-03-29', 'name' => 'Ostermontag'],
            ['date' => '2027-05-01', 'name' => 'Tag der Arbeit'],
            ['date' => '2027-05-06', 'name' => 'Christi Himmelfahrt'],
            ['date' => '2027-05-17', 'name' => 'Pfingstmontag'],
            ['date' => '2027-05-27', 'name' => 'Fronleichnam'],
        ],
        'vacation_periods' => [
            [
                'name'           => 'Herbstferien',
                'start_date'     => '2026-10-05',
                'end_date'       => '2026-10-17',
                'is_work_period' => 0,
            ],
            [
                'name'           => 'Weihnachtsferien',
                'start_date'     => '2026-12-21',
                'end_date'       => '2027-01-08',
                'is_work_period' => 0,
            ],
            [
                'name'           => 'Osterferien',
                'start_date'     => '2027-03-17',
                'end_date'       => '2027-04-04',
                'is_work_period' => 0,
            ],
            [
                'name'           => 'Sommerferien',
                'start_date'     => '2027-07-14',
                'end_date'       => '2027-08-22',
                'is_work_period' => 0,
            ],
        ],
    ];
}
