<?php
// public/undo_move.php
//
// Cofnięcie ostatniego ruchu w danej grze.
// Usuwa z bazy ostatni wpis w tabeli moves dla wskazanej gry.
// Plansza i wyniki zostaną przeliczone ponownie przy kolejnym
// wyświetleniu play.php na podstawie pozostałych ruchów.

require_once __DIR__ . '/../src/Repositories.php';

$game_id = (int)($_POST['game_id'] ?? $_GET['game_id'] ?? 0);
if ($game_id <= 0) {
    header('Location: index.php');
    exit;
}

$pdo = Database::get();

$stmt = $pdo->prepare(
    'SELECT id FROM moves WHERE game_id = :g ORDER BY move_no DESC LIMIT 1'
);
$stmt->execute([':g' => $game_id]);
$last = $stmt->fetch(PDO::FETCH_ASSOC);

if ($last) {
    $del = $pdo->prepare('DELETE FROM moves WHERE id = :id');
    $del->execute([':id' => (int)$last['id']]);
}

header('Location: play.php?game_id=' . $game_id);
exit;
