<?php
// start_game.php - Finalna wersja z bindValue dla PostgreSQL

session_start();
require_once 'src/config.php';
require_once 'src/Database.php';

use App\Database;

$db = new Database();
$pdo = $db->getConnection();

$error = '';

// 1. Pobierz listę wszystkich zarejestrowanych graczy
$players = $pdo->query("SELECT id, nickname FROM players ORDER BY nickname ASC")->fetchAll(\PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $player1Id = (int)($_POST['player1'] ?? 0);
    $player2Id = (int)($_POST['player2'] ?? 0);

    // Flaga, który gracz ma rejestrowany stojak (player1 lub player2)
    $rackPlayerId = (int)($_POST['rack_player'] ?? 0);

    if ($player1Id === 0 || $player2Id === 0 || $player1Id === $player2Id) {
        $error = 'Musisz wybrać dwóch różnych graczy.';
    } elseif ($rackPlayerId !== $player1Id && $rackPlayerId !== $player2Id) {
        $error = 'Musisz wybrać, który z graczy rejestruje stojak.';
    } else {
        try {
            $pdo->beginTransaction();

            // 2. Utwórz nową grę
            $stmt = $pdo->prepare("INSERT INTO games (start_time) VALUES (NOW()) RETURNING id");
            $stmt->execute();
            $gameId = $stmt->fetchColumn();

            // 3. Przypisz graczy do gry i ustal flagę is_rack_registered
            
            $playersToInsert = [
                $player1Id,
                $player2Id
            ];
            
            $insertStmt = $pdo->prepare("INSERT INTO game_players (game_id, player_id, score, is_rack_registered) 
                                       VALUES (?, ?, 0, ?)");
            
            foreach ($playersToInsert as $pId) {
                $isRackRegistered = ($pId === $rackPlayerId);
                
                // JAWNE UŻYCIE bindValue dla PARAM_BOOL (Parametr 4 w SQL)
                $insertStmt->bindValue(1, $gameId, \PDO::PARAM_INT);
                $insertStmt->bindValue(2, $pId, \PDO::PARAM_INT);
                // Kluczowa poprawka: jawne rzutowanie na bool i ustawienie PDO::PARAM_BOOL
                $insertStmt->bindValue(3, (bool)$isRackRegistered, \PDO::PARAM_BOOL); 

                $insertStmt->execute();
            }
            
            $pdo->commit();
            
            header("Location: game.php?game_id={$gameId}");
            exit;

        } catch (\PDOException $e) {
            $pdo->rollBack();
            $error = "Błąd bazy danych podczas tworzenia gry: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Rozpocznij Nową Grę</title>
    <link rel="stylesheet" href="public/style.css">
</head>
<body>
    <h1>Rozpocznij Nową Grę</h1>
    <p><a href="index.php">← Powrót do menu</a></p>

    <?php if ($error): ?>
        <p class="error"><?php echo $error; ?></p>
    <?php endif; ?>

    <?php if (count($players) < 2): ?>
        <p style="color: orange; font-weight: bold;">Musisz zarejestrować co najmniej dwóch graczy, aby rozpocząć grę. <a href="register_player.php">Zarejestruj teraz.</a></p>
    <?php else: ?>
        <form method="POST">
            <h3>Wybierz uczestników i gracza rejestrującego stojak.</h3>
            
            <label for="player1">Gracz 1:</label>
            <select id="player1" name="player1" required onchange="updateRackSelection()">
                <option value="">Wybierz...</option>
                <?php foreach ($players as $p): ?>
                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nickname']); ?></option>
                <?php endforeach; ?>
            </select>
            
            <br><br>
            
            <label for="player2">Gracz 2:</label>
            <select id="player2" name="player2" required onchange="updateRackSelection()">
                <option value="">Wybierz...</option>
                <?php foreach ($players as $p): ?>
                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nickname']); ?></option>
                <?php endforeach; ?>
            </select>
            
            <br><br>
            
            <label for="rack_player">Kto rejestruje stojak (rack):</label>
            <select id="rack_player" name="rack_player" required>
                <option value="">Wybierz gracza...</option>
                </select>
            
            <br><br>
            <button type="submit">Rozpocznij Grę</button>
        </form>
        
        <script>
        function updateRackSelection() {
            const player1Select = document.getElementById('player1');
            const player2Select = document.getElementById('player2');
            const rackSelect = document.getElementById('rack_player');
            
            rackSelect.innerHTML = '<option value="">Wybierz gracza...</option>';
            
            // Dodawanie wybranego Gracza 1
            if (player1Select.value) {
                rackSelect.innerHTML += `<option value="${player1Select.value}">${player1Select.options[player1Select.selectedIndex].text}</option>`;
            }
            // Dodawanie wybranego Gracza 2
            if (player2Select.value) {
                rackSelect.innerHTML += `<option value="${player2Select.value}">${player2Select.options[player2Select.selectedIndex].text}</option>`;
            }
        }
        </script>
    <?php endif; ?>
</body>
</html>