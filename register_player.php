<?php
// register_player.php

session_start();
require_once 'src/config.php';
require_once 'src/Database.php';

use App\Database;

$message = '';
$error = '';

$db = new Database();
$pdo = $db->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nickname = trim($_POST['nickname'] ?? '');

    if (empty($nickname)) {
        $error = 'Nick nie może być pusty.';
    } else {
        try {
            // Sprawdzenie unikalności
            $stmt = $pdo->prepare("SELECT id FROM players WHERE nickname = :nickname");
            $stmt->execute([':nickname' => $nickname]);
            
            if ($stmt->fetch()) {
                $error = "Gracz o nicku '{$nickname}' jest już zarejestrowany.";
            } else {
                // Wstawienie nowego gracza
                $stmt = $pdo->prepare("INSERT INTO players (nickname) VALUES (:nickname) RETURNING id");
                $stmt->execute([':nickname' => $nickname]);
                $message = "Gracz **{$nickname}** został pomyślnie zarejestrowany! ID: " . $stmt->fetchColumn();
            }

        } catch (\PDOException $e) {
            $error = "Błąd bazy danych: " . $e->getMessage();
        } catch (\Exception $e) {
            $error = "Wystąpił błąd: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Rejestracja Gracza</title>
    <link rel="stylesheet" href="public/style.css">
</head>
<body>
    <h1>Zarejestruj gracza</h1>
    <p><a href="index.php">← Powrót do menu</a></p>

    <?php if ($message): ?>
        <p style="color: green; font-weight: bold;"><?php echo $message; ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p style="color: red; font-weight: bold;"><?php echo $error; ?></p>
    <?php endif; ?>

    <form method="POST">
        <label for="nickname">Unikalny Nick:</label>
        <input type="text" id="nickname" name="nickname" required maxlength="50">
        <button type="submit">Zarejestruj</button>
    </form>
</body>
</html>