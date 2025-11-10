<?php
// game.php - Wersja z trzema polami wejściowymi i bezpiecznym odtwarzaniem planszy

session_start();

// KLUCZOWE POPRAWKI KODOWANIA: Konieczne dla polskich znaków
\mb_internal_encoding("UTF-8");
\mb_regex_encoding("UTF-8");

require_once 'src/config.php';
require_once 'src/Database.php';
require_once 'src/Scrabble/Board.php';
require_once 'src/Scrabble/MoveParser.php';
require_once 'src/Scrabble/ScoreCalculator.php';

use App\Scrabble\Board;
use App\Scrabble\MoveParser;
use App\Scrabble\ScoreCalculator;
use App\Database;

// Ustanowienie połączenia i walidacja ID gry
try {
    $db = new Database();
    $pdo = $db->getConnection();
} catch (\Exception $e) {
    die($e->getMessage());
}

$gameId = $_GET['game_id'] ?? null;
if (!$gameId) {
    header('Location: index.php');
    exit;
}

$error = null;

// --- 1. Pobranie i Przygotowanie Danych Gry ---

// A. Gracze w grze
$stmt = $pdo->prepare("
    SELECT gp.player_id, gp.score, gp.is_rack_registered, p.nickname 
    FROM game_players gp JOIN players p ON gp.player_id = p.id 
    WHERE gp.game_id = ? 
    ORDER BY gp.id ASC
");
$stmt->execute([$gameId]);
$gamePlayers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (count($gamePlayers) !== 2) {
    die('Błąd: Niepoprawna liczba graczy dla tej gry.');
}

$playerIds = array_column($gamePlayers, 'player_id');
$playersMap = array_combine($playerIds, $gamePlayers);


// B. Historia ruchów
$stmt = $pdo->prepare("SELECT m.*, p.nickname 
                       FROM moves m JOIN players p ON m.player_id = p.id 
                       WHERE m.game_id = ? 
                       ORDER BY m.move_number ASC");
$stmt->execute([$gameId]);
$movesHistory = $stmt->fetchAll(\PDO::FETCH_ASSOC);


// C. Ustalenie bieżącej kolejki i odtworzenie stanu planszy
$board = new Board();
$parser = new MoveParser(); 

$currentMoveNumber = count($movesHistory) + 1;
$currentPlayerIndex = (count($movesHistory) % 2); 
$currentPlayerId = $playerIds[$currentPlayerIndex];
$currentPlayerNickname = $playersMap[$currentPlayerId]['nickname'];
$currentPlayerHasRack = (bool)$playersMap[$currentPlayerId]['is_rack_registered'];


// Odtwórz stan planszy (Użycie tylko bezpiecznego played_tiles_string)
foreach ($movesHistory as $move) {
    if ($move['move_type'] === 'PLAY' && !empty($move['played_tiles_string'])) {
        try {
            // Format: "C1 L1 C2 L2 ..." (np. "8G O 8H K")
            $tilesData = explode(' ', $move['played_tiles_string']);
            
            for ($i = 0; $i < count($tilesData); $i += 2) {
                $coord = $tilesData[$i];
                $letter = $tilesData[$i+1];

                // Parsowanie koordynat (ekstrakcja)
                $colChar = strtoupper(preg_replace('/[^A-O]/', '', $coord));
                $rowNum = preg_replace('/[^0-9]/', '', $coord);
                
                $y = (int)$rowNum - 1;
                $x = ord($colChar) - ord('A');
                
                // Zakładamy, że tylko '?' może być blankiem
                $isBlank = ($letter === '?');
                
                $board->placeTile($y, $x, $letter, $isBlank);
            }

        } catch (\Exception $e) {
            error_log("Błąd odtwarzania ruchu {$move['move_number']} ({$move['raw_input']}): " . $e->getMessage());
        }
    }
}


// --- 2. Obsługa Nowego Ruchu (POST) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Dane z pól dla ruchu PLAY
    $rackInput = trim($_POST['rack_input'] ?? '');
    $coordInput = trim($_POST['coord_input'] ?? '');
    $wordInput = trim($_POST['word_input'] ?? '');

    // Dane z pola dla Innego Ruchu (PASS/EXCHANGE)
    $rawInput = trim($_POST['raw_input'] ?? ''); 

    $points = 0;
    $moveType = 'OTHER';
    $isBingo = false;
    $rackBefore = null;
    $playedTilesString = null;
    $wordFormed = null;
    $rawInputToSave = null;

    try {
        if (!empty($rawInput)) {
            // Ruch PASS lub EXCHANGE
            if ($rawInput === 'PASS' || \preg_match('/^EX\s+/i', $rawInput)) {
                $parsedMove = $parser->parse($rawInput, $currentPlayerHasRack);
                $moveType = $parsedMove['type'];
                $rawInputToSave = $rawInput; 
            } else {
                 throw new \Exception("Niepoprawny format: pole Inny Ruch służy tylko do PASS lub EX SŁOWA.");
            }

        } elseif (!empty($coordInput) && !empty($wordInput)) {
            // Ruch PLAY
            $parsedMove = $parser->parsePlay($rackInput, $coordInput, $wordInput, $currentPlayerHasRack);
            $moveType = $parsedMove['type']; // 'PLAY'
            
            $rackBefore = $parsedMove['rack'];
            $wordFormed = $parsedMove['cleanWord'];
            
            // Tworzenie pełnego ciągu wejściowego do zapisu w bazie
            $rawInputToSave = ($rackBefore ? $rackBefore . ' ' : '') . $parsedMove['startCoord'] . ' ' . $wordFormed;
            
            // Obliczanie punktów
            $tempBoard = clone $board; // Kluczowe, aby obliczać punkty na kopii aktualnej planszy
            $scoreCalculator = new ScoreCalculator($tempBoard);

            // Wywołanie calculate, które używa locateWordAndGetPlayedTiles() do walidacji i obliczeń
            $result = $scoreCalculator->calculate($parsedMove); 
            $points = $result['points'];
            $isBingo = $result['is_bingo'];

            // Przygotowanie ciągu położonych płytek do zapisu w bazie
            $playedTilesData = [];
            foreach ($parsedMove['playedTiles'] as $tile) {
                $colChar = chr(ord('A') + $tile['x']);
                $rowNum = $tile['y'] + 1;
                $playedTilesData[] = "{$colChar}{$rowNum} {$tile['letter']}";
            }
            $playedTilesString = implode(' ', $playedTilesData);
            
        } else {
            throw new \Exception("Musisz wypełnić pola Koordynaty i Słowo (dla ruchu PLAY) LUB pole Inny Ruch (dla PASS/EXCHANGE).");
        }

        // --- Zapis do Bazy Danych ---
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            INSERT INTO moves (game_id, player_id, move_number, move_type, points, 
                               raw_input, rack_before, is_bingo, played_tiles_string, word_formed)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bindValue(1, $gameId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $currentPlayerId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $currentMoveNumber, \PDO::PARAM_INT);
        $stmt->bindValue(4, $moveType, \PDO::PARAM_STR);
        $stmt->bindValue(5, $points, \PDO::PARAM_INT);
        $stmt->bindValue(6, $rawInputToSave, \PDO::PARAM_STR); 
        
        if ($rackBefore === null) {
            $stmt->bindValue(7, null, \PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(7, $rackBefore, \PDO::PARAM_STR);
        }

        $stmt->bindValue(8, (bool)$isBingo, \PDO::PARAM_BOOL); 
        $stmt->bindValue(9, $playedTilesString, $playedTilesString === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(10, $wordFormed, $wordFormed === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        
        $stmt->execute(); 

        // 2. Aktualizacja wyniku gracza
        $newTotalScore = $playersMap[$currentPlayerId]['score'] + $points;
        $stmt = $pdo->prepare("
            UPDATE game_players SET score = ? WHERE game_id = ? AND player_id = ?
        ");
        $stmt->execute([$newTotalScore, $gameId, $currentPlayerId]);

        $pdo->commit();
        
        header("Location: game.php?game_id={$gameId}");
        exit;

    } catch (\Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Błąd przetwarzania ruchu: " . htmlspecialchars($e->getMessage());
    }
}


// --- 3. Renderowanie Widoku ---
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Rozgrywka #<?php echo htmlspecialchars($gameId); ?></title>
    <link rel="stylesheet" href="public/style.css">
</head>
<body>
    <h1>Rejestracja Gry #<?php echo htmlspecialchars($gameId); ?></h1>
    <p><a href="index.php">← Powrót do menu</a></p>

    <div class="game-container">
        
        <div class="move-list">
            <h2>Wyniki i Historia</h2>
            
            <?php 
            echo "<h3>Wyniki:</h3>";
            foreach ($gamePlayers as $p) {
                echo "<p><strong>{$p['nickname']}</strong>: {$p['score']} punktów</p>";
            }
            ?>
            
            <hr>
            <h3>
                Aktualna kolej: <span class="current-player"><?php echo $currentPlayerNickname; ?></span> 
                (Ruch nr <?php echo $currentMoveNumber; ?>)
            </h3>
            
            <ol>
                <?php foreach ($movesHistory as $move): ?>
                    <li>
                        **<?php echo htmlspecialchars($move['nickname']); ?>**
                        (<?php echo $move['move_type']; ?>): 
                        `<?php echo htmlspecialchars($move['raw_input']); ?>`
                        <?php if ($move['move_type'] === 'PLAY'): ?>
                            <span style="font-weight: bold; color: green;">+<?php echo $move['points']; ?></span>
                        <?php endif; ?>
                        <?php if ($move['is_bingo']): ?>(BINGO!)<?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
            
            <?php if ($error): ?>
                <p class="error">Błąd: <?php echo $error; ?></p>
            <?php endif; ?>
            
            <form method="POST">
                <h4>Rejestracja Ruchu PLAY</h4>
                <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                    <div>
                        <label for="rack_input">Stojak (7 płytek)</label>
                        <input type="text" id="rack_input" name="rack_input" size="10" 
                               placeholder="<?php echo $currentPlayerHasRack ? '7 liter (np. WIJĘKRA)' : 'Nie wymagany'; ?>" 
                               <?php echo $currentPlayerHasRack ? 'required' : ''; ?>>
                    </div>
                    <div>
                        <label for="coord_input">Koordynaty</label>
                        <input type="text" id="coord_input" name="coord_input" size="8" 
                               placeholder="Np. 8F, F8">
                    </div>
                    <div>
                        <label for="word_input">Słowo</label>
                        <input type="text" id="word_input" name="word_input" size="20" 
                               placeholder="Np. WIJĘ, KAR(O)WA">
                    </div>
                </div>
                <button type="submit">Zatwierdź Ruch PLAY</button>
                
                <hr>
                
                <h4>Inny Ruch (PASS lub EXCHANGE)</h4>
                <label for="raw_input">Wprowadź PASS lub EX SŁOWA:</label>
                <input type="text" id="raw_input" name="raw_input" size="50"
                       placeholder="PASS lub EX AĄ?Ę (pozostaw puste, jeśli używasz pól PLAY)">
                <button type="submit">Zatwierdź Inny Ruch</button>
            </form>
        </div>
        
        <div class="board-container">
            <h2>Plansza</h2>
            <div class="scrabble-board">
                <table>
                    <tr>
                        <td></td> 
                        <?php for ($c = 0; $c < 15; $c++): ?>
                            <td style="font-size: 12px; font-weight: bold; padding: 5px;"><?php echo chr(ord('A') + $c); ?></td>
                        <?php endfor; ?>
                    </tr>
                    
                    <?php for ($y = 0; $y < 15; $y++): ?>
                        <tr>
                            <td style="font-size: 12px; font-weight: bold; padding: 5px;"><?php echo $y + 1; ?></td>
                            
                            <?php for ($x = 0; $x < 15; $x++): ?>
                                <?php
                                $tile = $board->getTile($y, $x);
                                $premium = $board->getPremium($y, $x);
                                $class = $premium ? $premium : 'puste';
                                
                                // Specjalna obsługa centrum
                                if ($board->isCenter($y, $x) && !$tile) {
                                    $class = 'center';
                                }
                                ?>
                                <td class='<?php echo $class; ?>'>
                                    <?php if ($tile): ?>
                                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);">
                                            <strong><?php echo htmlspecialchars($tile['letter']); ?></strong>
                                        </div>
                                    <?php else: ?>
                                        <?php if ($premium): ?>
                                            <span><?php echo $premium; ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    <?php endfor; ?>
                </table>
            </div>
        </div>
    </div>
</body>
</html>