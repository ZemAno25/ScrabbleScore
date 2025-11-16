<?php
// config.php
// Adjust for your environment.
return [
    'db' => [
        'dsn' => 'pgsql:host=localhost;port=5432;dbname=scrabblegames',
        'user' => 'scrabble_usr',
        'pass' => '', //Ustaw hasło!!!
    ],
    'app' => [
        'base_url' => '/ScrabbleScore', // e.g. '/scrabblescore' if deployed in a subdir
    ]
];
