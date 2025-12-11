<?php
require_once __DIR__ . '/../src/Repositories.php';
require_once __DIR__ . '/../src/Board.php';
require_once __DIR__ . '/../src/Scorer.php';
require_once __DIR__ . '/../src/QuackleImporter.php';

$error            = null;
$previewGame      = null;
$importedGameId   = null;
$encoded          = null;
$defaultDateTime  = date('Y-m-d\TH:i');
$upP1 = $upP2      = null;
$existsP1 = $existsP2 = false;
$duplicateGame    = null;
$recorderStats    = [];
$recorderGuess    = null;
$previewFileName  = null;

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

function formatErrorWithFilename(string $message, ?string $fileName): string
{
    $fileName = trim((string)$fileName);
    if ($fileName === '') {
        return $message;
    }
    return $message . ' (plik: ' . $fileName . ')';
}

function initialBag(): array
{
    return [
        'A' => 9,'Ą' => 1,'B' => 2,'C' => 3,'Ć' => 1,'D' => 3,'E' => 7,'Ę' => 1,
        'F' => 1,'G' => 2,'H' => 2,'I' => 8,'J' => 2,'K' => 3,'L' => 3,'Ł' => 2,
        'M' => 3,'N' => 5,'Ń' => 1,'O' => 6,'Ó' => 1,'P' => 3,'R' => 4,'S' => 4,
        'Ś' => 1,'T' => 3,'U' => 2,'W' => 4,'Y' => 4,'Z' => 5,'Ź' => 1,'Ż' => 1,'?' => 2,
    ];
}

function normalizeRackStringImport(?string $rack): ?string
{
    $rackClean = str_replace(' ', '', (string)$rack);
    if ($rackClean === '') {
        return null;
    }
    if (!preg_match('/^[A-Za-zĄąĆćĘęŁłŃńÓóŚśŹźŻż\?]+$/u', $rackClean)) {
        throw new RuntimeException('Stojak zawiera niedozwolone znaki (ruch z zapisu Quackle).');
    }
    return mb_strtoupper($rackClean, 'UTF-8');
}

function boardTileCount(Board $board): int
{
    $count = 0;
    for ($r = 0; $r < 15; $r++) {
        for ($c = 0; $c < 15; $c++) {
            if ($board->cells[$r][$c]['letter'] !== null) {
                $count++;
            }
        }
    }
    return $count;
}

function enforceRecorderRack(?string $rack, Board $board, int $totalTiles, string $playerName, int $moveNo): void
{
    if ($rack === null || $rack === '') {
        throw new RuntimeException(
            sprintf('Ruch #%d (%s) musi zawierać stojak gracza zapisującego.', $moveNo, $playerName)
        );
    }
    $rackLen = mb_strlen($rack, 'UTF-8');
    if ($rackLen > 7) {
        throw new RuntimeException(
            sprintf('Ruch #%d (%s) zawiera zbyt długi stojak (max 7).', $moveNo, $playerName)
        );
    }
    if (substr_count($rack, '?') > 2) {
        throw new RuntimeException(
            sprintf('Ruch #%d (%s) zawiera zbyt wiele blanków (max 2).', $moveNo, $playerName)
        );
    }
    $boardTiles = boardTileCount($board);
    $unseen = $totalTiles - $boardTiles - $rackLen;
    if ($unseen < 0) {
        throw new RuntimeException(
            sprintf('Ruch #%d (%s) ma niespójny stojak względem zestawu startowego.', $moveNo, $playerName)
        );
    }
    if ($unseen > 7 && $rackLen !== 7) {
        throw new RuntimeException(
            sprintf('Ruch #%d (%s): stojak musi zawierać 7 płytek.', $moveNo, $playerName)
        );
    }
}

function totalTilesInSet(array $bag): int
{
    $sum = 0;
    foreach ($bag as $cnt) {
        $sum += $cnt;
    }
    return $sum;
}

function gatherRackStatsForPlayers(QuackleGame $game): array
{
    $stats = [];
    foreach ($game->moves as $mv) {
        $player = normalizeNickUpper($mv->playerName ?? '');
        if ($player === '') {
            continue;
        }
        if (!isset($stats[$player])) {
            $stats[$player] = [
                'first_len'    => null,
                'long_count'   => 0,
                'short_count'  => 0,
                'rack_entries' => 0,
                'total_len'    => 0,
            ];
        }
        $rackClean = str_replace(' ', '', (string)$mv->rack);
        if ($rackClean === '') {
            continue;
        }
        $len = mb_strlen($rackClean, 'UTF-8');
        if ($stats[$player]['first_len'] === null) {
            $stats[$player]['first_len'] = $len;
        }
        if ($len >= 6) {
            $stats[$player]['long_count']++;
        } else {
            $stats[$player]['short_count']++;
        }
        $stats[$player]['rack_entries']++;
        $stats[$player]['total_len'] += $len;
    }
    return $stats;
}

function guessRecorderChoice(array $stats, string $player1, string $player2): array
{
    $score = [];
    foreach ([$player1, $player2] as $nick) {
        $s = $stats[$nick] ?? [
            'first_len'    => null,
            'long_count'   => 0,
            'short_count'  => 0,
            'rack_entries' => 0,
            'total_len'    => 0,
        ];
        $val = ($s['long_count'] ?? 0) * 2 - ($s['short_count'] ?? 0);
        if (($s['first_len'] ?? 0) >= 6) {
            $val += 3;
        }
        $score[$nick] = $val;
    }

    $guess = null;
    if (($score[$player1] ?? 0) >= ($score[$player2] ?? 0) + 2) {
        $guess = 'p1';
    } elseif (($score[$player2] ?? 0) >= ($score[$player1] ?? 0) + 2) {
        $guess = 'p2';
    } elseif (
        ($stats[$player1]['first_len'] ?? null) !== null
        && ($stats[$player1]['first_len'] ?? 0) >= 6
        && (($stats[$player2]['first_len'] ?? null) === null || ($stats[$player2]['first_len'] ?? 0) < 6)
    ) {
        $guess = 'p1';
    } elseif (
        ($stats[$player2]['first_len'] ?? null) !== null
        && ($stats[$player2]['first_len'] ?? 0) >= 6
        && (($stats[$player1]['first_len'] ?? null) === null || ($stats[$player1]['first_len'] ?? 0) < 6)
    ) {
        $guess = 'p2';
    }

    return [
        'guess'  => $guess,
        'scores' => $score,
    ];
}

function importQuackleGameToDatabase(
    QuackleGame $game,
    string $mode,
    ?string $startedAt,
    string $sourceHash,
    ?string $recorderChoice
): int
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

    $recorderId = null;
    if ($recorderChoice === 'p1') {
        $recorderId = $p1Id;
    } elseif ($recorderChoice === 'p2') {
        $recorderId = $p2Id;
    }

    $gameId = GameRepo::create($p1Id, $p2Id, $mode, $startedAt, $sourceHash, $recorderId);

    $board      = new Board();
    $scorer     = new Scorer($board);
    $initialBag = initialBag();
    $totalTiles = totalTilesInSet($initialBag);

    $moveNo = 1;

    $map = [
        $p1Name => $p1Id,
        $p2Name => $p2Id,
    ];

    foreach ($game->moves as $m) {
        $playerKey      = normalizeNickUpper($m->playerName);
        $playerId       = $map[$playerKey] ?? $p1Id;
        $rackNormalized = normalizeRackStringImport($m->rack);
        $isRecorder     = ($recorderId !== null && $playerId === $recorderId);

        $currentMoveNo = $moveNo++;

        $data = [
            'game_id'   => $gameId,
            'move_no'   => $currentMoveNo,
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
                $placement = $scorer->placeAndScore($m->position, $internalWord);
            } catch (Throwable $e) {
                // Można zalogować błąd, ale nie przerywamy importu
                $placement = null;
            }

            if ($isRecorder) {
                enforceRecorderRack($rackNormalized, $board, $totalTiles, $m->playerName, $currentMoveNo);
            }

            $data['position'] = $m->position;
            $data['word']     = $internalWord;
            $data['rack']     = $rackNormalized;
            $data['type']     = 'PLAY';

            if ($placement !== null) {
                try {
                    Scorer::ensureWithinInitialBag($board, $initialBag);
                } catch (Throwable $e) {
                    Scorer::revertPlacement($board, $placement);
                    throw new RuntimeException(
                        sprintf(
                            'Ruch #%d (%s %s %s) przekracza liczbę dostępnych płytek: %s',
                            $currentMoveNo,
                            $m->playerName,
                            $m->position,
                            $m->word,
                            $e->getMessage()
                        )
                    );
                }
            }

            if ($placement !== null && $isRecorder && $rackNormalized !== null && !empty($placement->placed)) {
                try {
                    $postRack = Scorer::computeRemainingRackAfterPlacement($board, $placement, $rackNormalized);
                    if ($postRack !== '') {
                        $data['post_rack'] = $postRack;
                    }
                } catch (Throwable $e) {
                    throw new RuntimeException(
                        sprintf(
                            'Ruch #%d (%s %s %s) nie zgadza się ze stojakiem "%s": %s',
                            $currentMoveNo,
                            $m->playerName,
                            $m->position,
                            $m->word,
                            $rackNormalized ?? '',
                            $e->getMessage()
                        )
                    );
                }
            }

            // prepare check_words: include main word and all cross words from placement details
            if ($placement !== null && !empty($placement->wordDetails)) {
                $check = [];
                foreach ($placement->wordDetails as $wd) {
                    if (!empty($wd['word'])) $check[] = $wd['word'];
                }
                if (!empty($check)) {
                    $data['check_words'] = $check;
                }
            }
        } elseif ($m->type === 'EXCHANGE') {
            if ($isRecorder) {
                enforceRecorderRack($rackNormalized, $board, $totalTiles, $m->playerName, $currentMoveNo);
            }
            if ($rackNormalized !== null) {
                $data['rack'] = $rackNormalized;
            }
            $data['type'] = 'EXCHANGE';
        } elseif ($m->type === 'PASS') {
            if ($rackNormalized !== null) {
                $data['rack'] = $rackNormalized;
            }
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
        $importFileName = trim((string)($_POST['gcg_filename'] ?? ''));
        $encodedText = $_POST['gcg_text'];
        $contents = base64_decode($encodedText, true);
        if ($contents === false) {
            $error = formatErrorWithFilename('Nie udało się odkodować danych gry.', $importFileName);
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

                $recorderChoice = $_POST['recorder_choice'] ?? null;
                if ($recorderChoice !== 'p1' && $recorderChoice !== 'p2') {
                    $recorderChoice = null;
                }

                $hash = hash('sha256', $contents);
                $importedGameId = importQuackleGameToDatabase($game, $mode, $startedAt, $hash, $recorderChoice);
            } catch (PDOException $e) {
                $error = formatErrorWithFilename(
                    'Błąd bazy danych podczas importu: ' . $e->getMessage(),
                    $importFileName
                );
            } catch (Throwable $e) {
                $error = formatErrorWithFilename('Błąd importu: ' . $e->getMessage(), $importFileName);
            }
        }
    } elseif (isset($_FILES['gcgfile'])) {
        $uploadedFileName = trim((string)($_FILES['gcgfile']['name'] ?? ''));
        if ($_FILES['gcgfile']['error'] !== UPLOAD_ERR_OK) {
            $error = formatErrorWithFilename('Błąd uploadu pliku.', $uploadedFileName);
        } else {
            $contents = file_get_contents($_FILES['gcgfile']['tmp_name']);
            if ($contents === false) {
                $error = formatErrorWithFilename('Nie udało się odczytać pliku.', $uploadedFileName);
            } else {
                try {
                    // Parsowanie pliku – bez bazy
                    $previewGame = QuackleImporter::parseGcg($contents);
                    $encoded     = base64_encode($contents);
                    $previewFileName = $uploadedFileName !== '' ? $uploadedFileName : null;
                    $rawP1 = $previewGame->player1Name ?? 'GRACZ1';
                    $rawP2 = $previewGame->player2Name ?? 'GRACZ2';

                    $upP1 = normalizeNickUpper($rawP1);
                    $upP2 = normalizeNickUpper($rawP2);

                    $recorderStats = gatherRackStatsForPlayers($previewGame);
                    $guessData = guessRecorderChoice($recorderStats, $upP1, $upP2);
                    $recorderGuess = $guessData['guess'] ?? null;
                } catch (Throwable $e) {
                    $error = formatErrorWithFilename(
                        'Błąd parsowania pliku: ' . $e->getMessage(),
                        $uploadedFileName
                    );
                }

                if ($previewGame && !$error) {
                    // Sprawdzenia w bazie oddzielnie, żeby mieć osobny komunikat w razie problemów z DB
                    try {
                        $existsP1 = PlayerRepo::findByNick($upP1) !== null;
                        $existsP2 = PlayerRepo::findByNick($upP2) !== null;

                        $hash = hash('sha256', $contents);
                        $duplicateGame = GameRepo::findBySourceHash($hash);
                    } catch (PDOException $e) {
                        $error = formatErrorWithFilename(
                            'Błąd połączenia z bazą podczas sprawdzania graczy lub duplikatu: ' . $e->getMessage(),
                            $uploadedFileName
                        );
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
<div class="container container-narrow">
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

    <div class="card form-card">
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
            <?php if ($previewFileName): ?>
                <p>Nazwa pliku: <strong><?= htmlspecialchars($previewFileName) ?></strong></p>
            <?php endif; ?>
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

            <h3>Analiza stojaków</h3>
            <ul>
                <?php
                $statP1 = $recorderStats[$upP1] ?? null;
                $statP2 = $recorderStats[$upP2] ?? null;
                $describeStat = function($label, ?array $stat) {
                    $escapedLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    if ($stat === null || ($stat['rack_entries'] ?? 0) === 0) {
                        return sprintf('%s: brak zapisów stojaka.', $escapedLabel);
                    }
                    $first = $stat['first_len'] ?? null;
                    $firstTxt = $first !== null ? $first . ' liter' : 'brak danych';
                    return sprintf(
                        '%s: pierwszy stojak %s, długich zapisów: %d, krótkich: %d.',
                        $escapedLabel,
                        $firstTxt,
                        $stat['long_count'] ?? 0,
                        $stat['short_count'] ?? 0
                    );
                };
                ?>
                <li><?= $describeStat($p1, $statP1) ?></li>
                <li><?= $describeStat($p2, $statP2) ?></li>
            </ul>

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
                    <input type="hidden" name="gcg_filename"
                           value="<?= htmlspecialchars($previewFileName ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                    <label>Data i godzina gry</label>
                    <input type="datetime-local" name="game_datetime"
                           value="<?= htmlspecialchars($defaultDateTime) ?>">

                    <?php
                    $recorderDefault = $recorderGuess ?? 'none';
                    ?>
                    <label style="margin-top:12px">Kto prowadził zapis?</label>
                    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:8px">
                        <label style="display:flex;align-items:center;gap:6px">
                            <input type="radio" name="recorder_choice" value="p1"
                                <?= $recorderDefault === 'p1' ? 'checked' : '' ?>>
                            <?= htmlspecialchars($p1) ?>
                        </label>
                        <label style="display:flex;align-items:center;gap:6px">
                            <input type="radio" name="recorder_choice" value="p2"
                                <?= $recorderDefault === 'p2' ? 'checked' : '' ?>>
                            <?= htmlspecialchars($p2) ?>
                        </label>
                        <label style="display:flex;align-items:center;gap:6px">
                            <input type="radio" name="recorder_choice" value="none"
                                <?= $recorderDefault === 'none' ? 'checked' : '' ?>>
                            Nie wiem / brak
                        </label>
                    </div>
                    <?php if ($recorderGuess !== null): ?>
                        <div class="small success">
                            Sugerowany zapisujący: <?= $recorderGuess === 'p1'
                                ? htmlspecialchars($p1) : htmlspecialchars($p2) ?>.
                        </div>
                    <?php else: ?>
                        <div class="small">
                            Nie udało się jednoznacznie wskazać zapisującego — proszę wybrać ręcznie.
                        </div>
                    <?php endif; ?>

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

    <div class="page-actions">
        <a class="btn" href="index.php">Powrót</a>
    </div>
</div>
</body>
</html>
