<?php
// config.php
// Adjust for your environment.
return [
    'db' => [
        'dsn' => 'pgsql:host=localhost;port=5432;dbname=scrabblegames',
        'user' => 'scrabble_usr',
        'pass' => 'K@rta07052025',
    ],
    'app' => [
        'base_url' => '', // e.g. '/scrabblescore' if deployed in a subdir
    ]
];
