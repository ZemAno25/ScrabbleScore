<?php
/**
 * Plik konfiguracyjny i połączeniowy z bazą danych.
 * Dołączaj go na początku każdego skryptu, który potrzebuje dostępu do bazy.
 */

// Ustawienia połączenia z bazą danych PostgreSQL
define('DB_HOST', 'localhost'); // Zazwyczaj 'localhost' lub '127.0.0.1'
define('DB_PORT', '5432'); // Domyślny port PostgreSQL
define('DB_NAME', 'scrabblegames');
define('DB_USER', 'scrabble_usr');
define('DB_PASS', '0123456789'); // <-- ZMIEŃ TO HASŁO

/**
 * Zmienna $pdo będzie przechowywać obiekt połączenia z bazą danych.
 * Używamy try...catch do przechwytywania błędów połączenia.
 */
try {
    // Tworzenie "Data Source Name" (DSN) dla PostgreSQL
    $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
    
    // Utworzenie instancji PDO
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    
    // Ustawienie trybu błędów PDO na wyjątki (zalecane)
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Ustawienie domyślnego trybu pobierania wyników na tablicę asocjacyjną
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Ustawienie kodowania
    $pdo->exec("SET NAMES 'UTF8'");

} catch (PDOException $e) {
    // W przypadku błędu połączenia, zatrzymaj skrypt i wyświetl komunikat
    die("BŁĄD: Nie można połączyć się z bazą danych. " . $e->getMessage());
}

?>
