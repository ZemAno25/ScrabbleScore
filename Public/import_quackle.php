<?php
require_once __DIR__ . '/../src/Repositories.php';
require_once __DIR__ . '/../src/Board.php';
require_once __DIR__ . '/../src/Scorer.php';
require_once __DIR__ . '/../src/QuackleImporter.php';

$error = null;
$previewGame = null;
$importedGameId = null;

function importQuackleGameToDatabase(QuackleGame $game, string $mode): int
{
    // Ustalenie nazw graczy
    $p1Name = $game->player1Name ?: 'GRACZ1';
    $p2Name = $game->player2Name ?: 'GRACZ2';

    $p1Id = PlayerRepo::findOrCreate($p1Name);
    $p2Id = PlayerRepo::findOrCreate($p2Name);

    // Tworzymy grę z oznaczeniem trybu punktacji
    $gameId = GameRepo::create($p1Id, $p2Id, $mode);

    $board = new Board();
    $scorer = new Scorer($board);

    $moveNo = 1;

    // Mapowanie nazwy gracza (bez względu na wielkość liter) na ID
    $map = [
        mb_strtoupper($p1Name, 'UTF-8') => $p1Id,
        mb_strtoupper($p2Name, 'UTF-8') => $p2Id,
    ];

    foreach ($game->moves as $m) {
        $playerKey = mb_strtoupper($m->playerName, 'UTF-8');
        $playerId  = $map[$playerKey] ?? $p1Id;

        $data = [
            'game_id'   => $gameId,
            'move_no'   => $moveNo++,
            'player_id' => $playerId,
            'raw_input' => $m->rawLine,
            'type'      => $m->type,
            'position'  => null,
            'word'      => null,
            'rack'      => null,
            'score'     => $m->score,
            'cum_score' => $m->total,
        ];

        if ($m->type === 'PLAY') {
            if (!$m->position || !$m->word) {
                throw new RuntimeException('Brak pozycji lub słowa w ruchu PLAY.');
            }

            $internalWord = QuackleImporter::convertWordToInternal($board, $m->position, $m->word);

            // Aktualizacja planszy – ignorujemy wynik, ufamy punktacji Quackle
            try {
                $scorer->placeAndScore($m->position, $internalWord);
            } catch (Throwable $e) {
                // Przy imporcie nie zatrzymujemy się na błędzie planszy, ale warto byłoby logować
            }

            $data['position'] = $m->position;
            $data['word']     = $internalWord;
            $data['rack']     = $m->rack;
            $data['type']     = 'PLAY';
        } elseif ($m->type === 'EXCHANGE') {
            $data['rack'] = $m->rack;
            $data['type'] = 'EXCHANGE';
        } elseif ($m->type === 'PASS') {
            $data['rack'] = $m->rack;
            $data['type'] = 'PASS';
        } elseif ($m->type === 'ENDGAME') {
            // Korekta końcowa – nie zmieniamy planszy, jedynie zapisujemy informację
            $data['word'] = $m->endRack ? '(' . $m->endRack . ')' : null;
            $data['type'] = 'ENDGAME';
        }

        MoveRepo::add($data);
    }

    return $gameId;
}

// Obsługa formularzy
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Import z ukrytego gcg_text (Base64) po podglądzie
    if (isset($_POST['import_mode'], $_POST['gcg_text'])) {
        $mode = $_POST['import_mode'] === 'PFS' ? 'PFS' : 'QUACKLE';
        $encoded = $_POST['gcg_text'];
        $contents = base64_decode($encoded, true);
        if ($contents === false) {
            $error = 'Nie udało się odkodować danych gry.';
        } else {
            try {
                $game = QuackleImporter::parseGcg($contents);
                $importedGameId = importQuackleGameToDatabase($game, $mode);
            } catch (Throwable $e) {
                $error = 'Błąd importu: ' . $e->getMessage();
            }
        }
    } elseif (isset($_FILES['gcgfile'])) {
        // Pierwszy etap: wczytanie pliku i podgląd
        if ($_FILES['gcgfile']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Błąd uploadu pliku.';
        } else {
            $contents = file_get_contents($_FILES['gcgfile']['tmp_name']);
            if ($contents === false) {
                $error = 'Nie udało się odczytać pliku.';
            } else {
                try {
                    $previewGame = QuackleImporter::parseGcg($contents);
                    $encoded = base64_encode($contents);
                } catch (Throwable $e) {
                    $error = 'Błąd parsowania: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Wczytaj grę z Quackle</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="container">
    <h1>Wczytaj grę z Quackle</h1>

    <?php if ($error): ?>
        <div class="card error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($importedGameId !== null): ?>
        <div class="card success">
            <p>Gra została zaimportowana. ID gry: <strong><?= $importedGameId ?></strong></p>
            <p><a class="btn" href="play.php?game_id=<?= $importedGameId ?>">Przejdź do gry</a></p>
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="post" enctype="multipart/form-data">
            <label>Plik gry (.gcg)</label>
            <input type="file" name="gcgfile" required>
            <button class="btn" style="margin-top:8px">Wczytaj do podglądu</button>
        </form>
    </div>

    <?php if ($previewGame): ?>
        <div class="card">
            <?php
            $p1 = $previewGame->player1Name ?? 'gracz1';
            $p2 = $previewGame->player2Name ?? 'gracz2';
            $finalScores = [];
            foreach ($previewGame->moves as $m) {
                $finalScores[$m->playerName] = $m->total;
            }
            ?>
            <h2>Podgląd pliku</h2>
            <p>Gracz 1: <strong><?= htmlspecialchars($p1) ?></strong></p>
            <p>Gracz 2: <strong><?= htmlspecialchars($p2) ?></strong></p>
            <p>Liczba ruchów: <?= count($previewGame->moves) ?></p>

            <h3>Ruchy z pliku</h3>
            <table>
                <tr>
                    <th>#</th>
                    <th>Gracz</th>
                    <th>Typ</th>
                    <th>Rack</th>
                    <th>Pozycja</th>
                    <th>Słowo / wymiana / premia</th>
                    <th>+pkt</th>
                    <th>Σ</th>
                </tr>
                <?php foreach ($previewGame->moves as $i => $m): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($m->playerName) ?></td>
                        <td><?= htmlspecialchars($m->type) ?></td>
                        <td><?= htmlspecialchars((string)$m->rack) ?></td>
                        <td><?= htmlspecialchars((string)$m->position) ?></td>
                        <td>
                            <?php
                            if ($m->type === 'PLAY') {
                                echo htmlspecialchars((string)$m->word);
                            } elseif ($m->type === 'EXCHANGE') {
                                echo 'wymiana: ' . htmlspecialchars((string)$m->exchanged);
                            } elseif ($m->type === 'PASS') {
                                echo 'PASS';
                            } elseif ($m->type === 'ENDGAME') {
                                echo 'premia końcowa: (' . htmlspecialchars((string)$m->endRack) . ')';
                            }
                            ?>
                        </td>
                        <td><?= $m->score ?></td>
                        <td><?= $m->total ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <h3>Wyniki końcowe wg Quackle</h3>
            <?php foreach ($finalScores as $name => $score): ?>
                <p><?= htmlspecialchars($name) ?>: <strong><?= $score ?></strong></p>
            <?php endforeach; ?>

            <p class="small">
                Ruchy typu ENDGAME to zapis korekty końcowej Quackle.
                Według zasad PFS ta korekta nie jest osobnym ruchem, ale matematycznie wyniki są takie same.
            </p>

            <h3>Import do bazy</h3>
            <form method="post">
                <input type="hidden" name="gcg_text"
                       value="<?= htmlspecialchars($encoded ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <label>Tryb zapisu gry</label>
                <select name="import_mode">
                    <option value="QUACKLE">Quackle (z ruchem ENDGAME)</option>
                    <option value="PFS">PFS (zasady federacji, oznaczone w grze)</option>
                </select>
                <button class="btn" style="margin-top:8px">Importuj do bazy</button>
            </form>
        </div>
    <?php endif; ?>

    <p><a class="btn" href="index.php">Powrót</a></p>
</div>
</body>
</html>
