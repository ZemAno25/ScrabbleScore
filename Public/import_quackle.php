<?php
require_once __DIR__ . '/../src/Repositories.php';
require_once __DIR__ . '/../src/Board.php';
require_once __DIR__ . '/../src/Scorer.php';
require_once __DIR__ . '/../src/QuackleImporter.php';

$error          = null;
$previewGame    = null;
$importedGameId = null;
$encoded        = null;
$defaultDateTime = date('Y-m-d\TH:i');
$upP1 = $upP2 = null;
$existsP1 = $existsP2 = false;
$duplicateGame = null;

function normalizeNickUpper(string $nick): string
{
    $nick = trim($nick);
    return $nick === '' ? '' : mb_strtoupper($nick, 'UTF-8');
}

function normalizeDatetimeFromInput(?string $val): ?string
{
    $val = trim((string)$val);
    if ($val === '') {
        return null;
    }
    $val = str_replace('T', ' ', $val);
    if (strlen($val) === 16) {
        $val .= ':00';
    }
    return $val;
}

function importQuackleGameToDatabase(QuackleGame $game, string $mode, ?string $startedAt, string $sourceHash): int
{
    if ($sourceHash !== '') {
        $existing = GameRepo::findBySourceHash($sourceHash);
        if ($existing) {
            throw new RuntimeException(
                'Ta partia została już zaimportowana jako gra #' . $existing['id'] . '.'
            );
        }
    }

    $p1Name = $game->player1Name ?: 'GRACZ1';
    $p2Name = $game->player2Name ?: 'GRACZ2';

    $p1Name = normalizeNickUpper($p1Name);
    $p2Name = normalizeNickUpper($p2Name);

    if ($p1Name === '' || $p2Name === '') {
        throw new RuntimeException('Nick gracza nie może być pusty.');
    }

    $p1Id = PlayerRepo::findOrCreate($p1Name);
    $p2Id = PlayerRepo::findOrCreate($p2Name);

    $gameId = GameRepo::create($p1Id, $p2Id, $mode, $startedAt, $sourceHash);

    $board  = new Board();
    $scorer = new Scorer($board);

    $moveNo = 1;

    $map = [
        $p1Name => $p1Id,
        $p2Name => $p2Id,
    ];

    foreach ($game->moves as $m) {
        $playerKey = normalizeNickUpper($m->playerName);
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

            try {
                $scorer->placeAndScore($m->position, $internalWord);
            } catch (Throwable $e) {
                // Można zalogować błąd, ale nie przerywamy importu
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
            $data['word'] = $m->endRack ? '(' . $m->endRack . ')' : null;
            $data['type'] = 'ENDGAME';
        }

        MoveRepo::add($data);
    }

    return $gameId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['import_mode'], $_POST['gcg_text'])) {
        $mode = $_POST['import_mode'] === 'PFS' ? 'PFS' : 'QUACKLE';
        $encodedText = $_POST['gcg_text'];
        $contents = base64_decode($encodedText, true);
        if ($contents === false) {
            $error = 'Nie udało się odkodować danych gry.';
        } else {
            $startedAt = normalizeDatetimeFromInput($_POST['game_datetime'] ?? null);

            try {
                $game = QuackleImporter::parseGcg($contents);

                $formP1 = normalizeNickUpper($_POST['player1'] ?? '');
                $formP2 = normalizeNickUpper($_POST['player2'] ?? '');
                if ($formP1 !== '') {
                    $game->player1Name = $formP1;
                }
                if ($formP2 !== '') {
                    $game->player2Name = $formP2;
                }

                $hash = hash('sha256', $contents);
                $importedGameId = importQuackleGameToDatabase($game, $mode, $startedAt, $hash);
            } catch (PDOException $e) {
                $error = 'Błąd bazy danych podczas importu: ' . $e->getMessage();
            } catch (Throwable $e) {
                $error = 'Błąd importu: ' . $e->getMessage();
            }
        }
    } elseif (isset($_FILES['gcgfile'])) {
        if ($_FILES['gcgfile']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Błąd uploadu pliku.';
        } else {
            $contents = file_get_contents($_FILES['gcgfile']['tmp_name']);
            if ($contents === false) {
                $error = 'Nie udało się odczytać pliku.';
            } else {
                try {
                    // Parsowanie pliku – bez bazy
                    $previewGame = QuackleImporter::parseGcg($contents);
                    $encoded     = base64_encode($contents);

                    $rawP1 = $previewGame->player1Name ?? 'GRACZ1';
                    $rawP2 = $previewGame->player2Name ?? 'GRACZ2';

                    $upP1 = normalizeNickUpper($rawP1);
                    $upP2 = normalizeNickUpper($rawP2);
                } catch (Throwable $e) {
                    $error = 'Błąd parsowania pliku: ' . $e->getMessage();
                }

                if ($previewGame && !$error) {
                    // Sprawdzenia w bazie oddzielnie, żeby mieć osobny komunikat w razie problemów z DB
                    try {
                        $existsP1 = PlayerRepo::findByNick($upP1) !== null;
                        $existsP2 = PlayerRepo::findByNick($upP2) !== null;

                        $hash = hash('sha256', $contents);
                        $duplicateGame = GameRepo::findBySourceHash($hash);
                    } catch (PDOException $e) {
                        $error = 'Błąd połączenia z bazą podczas sprawdzania graczy lub duplikatu: ' . $e->getMessage();
                    }
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
            $p1 = $previewGame->player1Name ?? 'GRACZ1';
            $p2 = $previewGame->player2Name ?? 'GRACZ2';
            $finalScores = [];
            foreach ($previewGame->moves as $m) {
                $finalScores[$m->playerName] = $m->total;
            }
            ?>
            <h2>Podgląd pliku</h2>
            <p>Gracz 1 z pliku: <strong><?= htmlspecialchars($p1) ?></strong></p>
            <p>Gracz 2 z pliku: <strong><?= htmlspecialchars($p2) ?></strong></p>
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

            <?php if ($duplicateGame): ?>
                <div class="card error">
                    <p>Ta partia została już wcześniej zaimportowana jako gra
                        <strong>#<?= htmlspecialchars($duplicateGame['id']) ?></strong>.
                        Ponowny import jest zablokowany.
                    </p>
                </div>
            <?php else: ?>
                <h3>Import do bazy</h3>
                <form method="post">
                    <input type="hidden" name="gcg_text"
                           value="<?= htmlspecialchars($encoded ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                    <label>Data i godzina gry</label>
                    <input type="datetime-local" name="game_datetime"
                           value="<?= htmlspecialchars($defaultDateTime) ?>">

                    <div class="grid">
                        <div>
                            <label>Gracz 1 (nick w bazie)</label>
                            <input name="player1" value="<?= htmlspecialchars(normalizeNickUpper($p1)) ?>">
                            <?php if ($upP1 !== null): ?>
                                <?php if ($existsP1): ?>
                                    <div class="small success">
                                        Gracz <?= htmlspecialchars($upP1) ?> jest już zarejestrowany.
                                    </div>
                                <?php else: ?>
                                    <div class="small error">
                                        Gracz <?= htmlspecialchars($upP1) ?> nie jest zarejestrowany – zostanie dodany.
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label>Gracz 2 (nick w bazie)</label>
                            <input name="player2" value="<?= htmlspecialchars(normalizeNickUpper($p2)) ?>">
                            <?php if ($upP2 !== null): ?>
                                <?php if ($existsP2): ?>
                                    <div class="small success">
                                        Gracz <?= htmlspecialchars($upP2) ?> jest już zarejestrowany.
                                    </div>
                                <?php else: ?>
                                    <div class="small error">
                                        Gracz <?= htmlspecialchars($upP2) ?> nie jest zarejestrowany – zostanie dodany.
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <label>Tryb zapisu gry</label>
                    <select name="import_mode">
                        <option value="QUACKLE">Quackle (z ruchem ENDGAME)</option>
                        <option value="PFS">PFS (zasady federacji, oznaczone w grze)</option>
                    </select>

                    <button class="btn" style="margin-top:8px">Importuj do bazy</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <p><a class="btn" href="index.php">Powrót</a></p>
</div>
</body>
</html>
