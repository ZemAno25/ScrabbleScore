<?php
/**
 * Skrypt do przetwarzania danych z formularza rejestracji (register.php).
 * Łączy się z bazą, hashuje hasło i zapisuje nowego gracza.
 */

// 1. Dołączenie pliku konfiguracyjnego bazy danych
require_once 'db.php';

// 2. Sprawdzenie, czy formularz został wysłany metodą POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 3. Pobranie danych z formularza
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // 4. Prosta walidacja (upewnienie się, że pola nie są puste)
    if (empty($username) || empty($password)) {
        // Przekieruj z powrotem do formularza z komunikatem o błędzie
        header('Location: register.php?status=error&msg=' . urlencode('Nazwa użytkownika i hasło są wymagane.'));
        exit;
    }

    // 5. Hashowanie hasła (BEZPIECZEŃSTWO)
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // 6. Przygotowanie zapytania SQL do wstawienia danych
    $sql = "INSERT INTO players (username, password_hash) VALUES (:username, :password_hash)";

    try {
        // 7. Przygotowanie i wykonanie zapytania
        $stmt = $pdo->prepare($sql);
        
        $stmt->execute([
            ':username' => $username,
            ':password_hash' => $password_hash
        ]);

        // 8. Sukces: Przekierowanie z powrotem z komunikatem o sukcesie
        header('Location: register.php?status=success');
        exit;

    } catch (PDOException $e) {
        // 9. Obsługa błędów
        // Naruszenie ograniczenia UNIQUE (kod '23505')
        if ($e->getCode() == '23505') {
            $errorMsg = 'Ta nazwa użytkownika jest już zajęta. Wybierz inną.';
        } else {
            // Inny błąd bazy danych
            $errorMsg = 'Wystąpił błąd podczas rejestracji: ' . $e->getMessage();
        }
        
        header('Location: register.php?status=error&msg=' . urlencode($errorMsg));
        exit;
    }

} else {
    // Jeśli ktoś wszedł na ten plik bezpośrednio, a nie przez formularz
    header('Location: register.php');
    exit;
}
?>
