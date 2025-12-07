<?php
require_once __DIR__ . '/../src/Repositories.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Niedozwolona metoda.';
    exit;
}

$gameId = (int)($_POST['game_id'] ?? 0);
if ($gameId <= 0) {
    http_response_code(400);
    echo 'Nieprawidłowy identyfikator gry.';
    exit;
}

try {
    MoveRepo::deleteByGame($gameId);
    GameRepo::delete($gameId);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Nie udało się usunąć gry.';
    exit;
}

header('Location: new_game.php');
exit;
