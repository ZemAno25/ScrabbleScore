<?php
// src/Database.php

namespace App;

use PDO;
use PDOException;

class Database
{
    private ?PDO $pdo = null;

    public function __construct()
    {
        if ($this->pdo === null) {
            // Wymaga zdefiniowania stałych w src/config.php
            $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
            try {
                $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                // Rzuć wyjątek, jeśli nie można się połączyć, aby zatrzymać aplikację
                die("Błąd połączenia z bazą danych. Sprawdź config.php i status PostgreSQL. Szczegóły: " . $e->getMessage());
            }
        }
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }
}