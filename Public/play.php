<?php
require_once __DIR__ . '/../src/Repositories.php';
require_once __DIR__ . '/../src/Board.php';
require_once __DIR__ . '/../src/Scorer.php';
require_once __DIR__ . '/../src/MoveParser.php';

// ---------------------------------------------------------
// Ustalenie ID gry (GET lub POST – potrzebne także dla cofnij)
// ---------------------------------------------------------
$game_id = (int)($_GET['game_id'] ?? $_POST['game_id'] ?? 0);
if ($game_id <= 0) {
    http_response_code(400);
    echo 'Brak identyfikatora gry.';
    exit;
}

// ---------------------------------------------------------
// Cofnięcie ostatniego ruchu
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['undo_last'])) {
    try {
        $pdo = Database::get();
        // Szukamy ostatniego ruchu w tej grze
        $stmt = $pdo->prepare(
            'SELECT id FROM moves WHERE game_id = :g ORDER BY move_no DESC LIMIT 1'
        );
        $stmt->execute([':g' => $game_id]);
        $lastId = $stmt->fetchColumn();

        if ($lastId) {
            $del = $pdo->prepare('DELETE FROM moves WHERE id = :id');
            $del->execute([':id' => $lastId]);
        }
    } catch (Throwable $e) {
        // Można ewentualnie zalogować błąd, ale użytkownik i tak
        // wróci do widoku gry.
    }

    header('Location: play.php?game_id=' . $game_id);
    exit;
}

// ---------------------------------------------------------
// Wczytanie gry i graczy
// ---------------------------------------------------------
$game = GameRepo::get($game_id);
if (!$game) {
    http_response_code(404);
    echo 'Brak gry.';
    exit;
}

$playersById = [];
foreach (PlayerRepo::all() as $p) {
    $playersById[$p['id']] = $p['nick'];
}

// ---------------------------------------------------------
// Ruchy i odtworzenie planszy
// ---------------------------------------------------------
$moves    = MoveRepo::byGame($game_id);
$lastMove = count($moves) ? $moves[count($moves) - 1] : null;

$board  = new Board();
$scorer = new Scorer($board);

$scores = [
    $game['player1_id'] => 0,
    $game['player2_id'] => 0,
];

$err = null;
$msg = null;

// Następny gracz (domyślnie gracz 1, potem naprzemiennie)
$nextPlayer = $game['player1_id'];

// Szczegóły punktacji ostatniego ruchu (dla sekcji "Szczegóły punktacji...")
$lastMoveBreakdown = null;

if (count($moves) > 0) {
    foreach ($moves as $m) {
        if ($m['type'] === 'PLAY' && $m['position'] && $m['word']) {
            try {
                $placement = $scorer->placeAndScore($m['position'], $m['word']);

                // Jeśli to ostatni ruch i jest PLAY – zapamiętaj breakdown
                if ($lastMove && $m['id'] == $lastMove['id']) {
                    // Scorer powinien wypełnić $placement->wordBreakdown
                    $lastMoveBreakdown = $placement->wordBreakdown ?? null;
                }
            } catch (Throwable $e) {
                $err = 'Błąd historycznego ruchu #' . $m['move_no'] . ': ' . $e->getMessage();
                break;
            }
        }

        // Odtwarzamy wyniki z bazy (cum_score)
        $scores[$m['player_id']] = $m['cum_score'];

        // Naprzemienność graczy
        $nextPlayer = ($m['player_id'] == $game['player1_id'])
            ? $game['player2_id']
            : $game['player1_id'];
    }
}

// ---------------------------------------------------------
// Obsługa nowego ruchu
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['raw']) && !isset($_POST['undo_last'])) {
    $player_id = (int)($_POST['player_id'] ?? 0);

    try {
        $parser = new MoveParser();
        $pm     = $parser->parse(trim($_POST['raw']));

        $score = 0;

        if ($pm->type === 'PLAY') {
            $placement = $scorer->placeAndScore($pm->pos, $pm->word);
            $score     = $placement->score;
        } elseif ($pm->type === 'EXCHANGE') {
            $score = 0;
        } elseif ($pm->type === 'PASS') {
            $score = 0;
        } elseif ($pm->type === 'ENDGAME') {
            // Specjalny ruch końcowy – logika do ewentualnego rozwinięcia
            $score = 0;
        }

        $moveNo = count($moves) + 1;
        $cum    = $scores[$player_id] + $score;

        MoveRepo::add([
            'game_id'   => $game_id,
            'move_no'   => $moveNo,
            'player_id' => $player_id,
            'raw_input' => trim($_POST['raw']),
            'type'      => $pm->type,
            'position'  => $pm->pos ?? null,
            'word'      => $pm->word ?? null,
            'rack'      => $pm->rack ?? null,
            'score'     => $score,
            'cum_score' => $cum,
        ]);

        header('Location: play.php?game_id=' . $game_id);
        exit;
    } catch (Throwable $e) {
        $err = 'Błąd: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------
// Helper do klasy komórki planszy
// ---------------------------------------------------------
function letterClass(Board $b, int $r, int $c): string
{
    if ($b->cells[$r][$c]['letter']) {
        return 'tile';
    }
    $ps = $b->W[$r][$c];
    $pl = $b->L[$r][$c];
    if ($ps === 3) {
        return 'ps3';
    }
    if ($ps === 2) {
        return 'ps2';
    }
    if ($pl === 3) {
        return 'pl3';
    }
    if ($pl === 2) {
        return 'pl2';
    }
    return '';
}

// Kolumny A–O
$columns = range('A', 'O');
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Gra #<?= $game_id ?></title>
    <link rel="stylesheet" href="../assets/style.css">
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var input = document.querySelector('input[name="raw"]');
            if (input) {
                input.focus();
            }
        });
    </script>
</head>
<body>
<div class="container">
    <h1>
        Gra #<?= $game_id ?> —
        <?= htmlspecialchars($playersById[$game['player1_id']] ?? '') ?>
        vs
        <?= htmlspecialchars($playersById[$game['player2_id']] ?? '') ?>
    </h1>

    <?php if ($err): ?>
        <div class="card error"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <div class="grid">
        <!-- LEWA KOLUMNA: RUCHY + FORMULARZ -->
        <div class="card">
            <h2>Ruchy</h2>
            <table>
                <tr>
                    <th>#</th>
                    <th>Gracz</th>
                    <th>Zapis</th>
                    <th>+pkt</th>
                    <th>Σ</th>
                </tr>
                <?php foreach ($moves as $m): ?>
                    <tr>
                        <td><?= $m['move_no'] ?></td>
                        <td><?= htmlspecialchars($m['nick']) ?></td>
                        <td>
                            <?= htmlspecialchars($m['raw_input']) ?>
                            <?php if ($m['type'] === 'EXCHANGE' && $m['rack']): ?>
                                <span class="small">(wymiana: <?= htmlspecialchars($m['rack']) ?>)</span>
                            <?php endif; ?>
                            <?php if ($m['type'] === 'BADWORD'): ?>
                                <span class="badge-badmove">BADWORD</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $m['score'] ?></td>
                        <td><?= $m['cum_score'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <?php if ($lastMove && $lastMove['type'] === 'PLAY'): ?>
                <h3>Kwestionowanie ostatniego ruchu</h3>
                <p>
                    Ostatni ruch:
                    <strong><?= htmlspecialchars($lastMove['raw_input']) ?></strong>
                    (gracz:
                    <?= htmlspecialchars($playersById[$lastMove['player_id']] ?? '') ?>)
                </p>

                <?php if ($lastMoveBreakdown): ?>
                    <h4>Szczegóły punktacji tego ruchu:</h4>
                    <ul>
                        <?php foreach ($lastMoveBreakdown as $w): ?>
                            <li>
                                <?php if ($w['kind'] === 'main'): ?>
                                    Słowo główne:
                                <?php else: ?>
                                    Słowo krzyżujące:
                                <?php endif; ?>
                                <?= htmlspecialchars($w['word']) ?> =
                                (
                                <?php
                                $parts = [];
                                foreach ($w['letters'] as $L) {
                                    $part = $L['base'];
                                    if (($L['multL'] ?? 1) !== 1) {
                                        $part .= ' * ' . ($L['multL'] ?? 1);
                                    }
                                    $parts[] = $part;
                                }
                                echo implode(' + ', $parts);
                                ?>
                                )
                                <?php if (($w['wordMultiplier'] ?? 1) !== 1): ?>
                                    * <?= $w['wordMultiplier'] ?>
                                <?php endif; ?>
                                = <?= $w['score'] ?>
                            </li>
                        <?php endforeach; ?>
                        <li><strong>Łącznie za ruch:</strong> <?= $lastMove['score'] ?> pkt</li>
                    </ul>
                <?php endif; ?>

                <form method="post" action="challenge.php" style="display:inline">
                    <input type="hidden" name="game_id" value="<?= $game_id ?>">
                    <button class="btn">Kwestionuj</button>
                </form>
            <?php endif; ?>

            <h3>Dodaj ruch</h3>
            <form method="post" id="move-form">
                <input type="hidden" name="game_id" value="<?= $game_id ?>">

                <div class="player-chooser">
                    <span class="player-chooser-label">Gracz wykonujący ruch</span>
                    <div class="player-chooser-options">
                        <label class="player-radio">
                            <input
                                type="radio"
                                name="player_id"
                                value="<?= $game['player1_id'] ?>"
                                <?= ($nextPlayer == $game['player1_id']) ? 'checked' : '' ?>
                            >
                            <span><?= htmlspecialchars($playersById[$game['player1_id']] ?? '') ?></span>
                        </label>
                        <label class="player-radio">
                            <input
                                type="radio"
                                name="player_id"
                                value="<?= $game['player2_id'] ?>"
                                <?= ($nextPlayer == $game['player2_id']) ? 'checked' : '' ?>
                            >
                            <span><?= htmlspecialchars($playersById[$game['player2_id']] ?? '') ?></span>
                        </label>
                    </div>
                </div>

                <label>
                    Zapis ruchu (np. "WIJĘKRA 8F WIJĘ", "7G BAGNO", "K4 KAR(O)", "PASS",
                    "EXCHANGE", "LK?RWSS EXCHANGE (RWSS)", "ENDGAME")
                </label>
                <input name="raw" required>

                <button class="btn" name="submit_move" value="1" style="margin-top:8px">
                    Zatwierdź
                </button>
                <button class="btn-secondary" name="undo_last" value="1" style="margin-top:8px;margin-left:8px">
                    Cofnij ostatni ruch
                </button>
            </form>
        </div>

        <!-- PRAWA KOLUMNA: PLANSZA -->
        <div class="card">
            <h2>Plansza</h2>

            <div class="board-wrapper">
                <div class="board-header">
                    <div class="coord-corner"></div>
                    <?php foreach ($columns as $col): ?>
                        <div class="coord coord-col"><?= $col ?></div>
                    <?php endforeach; ?>
                </div>

                <div class="board board-grid">
                    <?php for ($r = 0; $r < 15; $r++): ?>
                        <div class="coord coord-row"><?= $r + 1 ?></div>
                        <?php for ($c = 0; $c < 15; $c++): ?>
                            <?php $cell = $board->cells[$r][$c]; ?>
                            <div class="cell <?= letterClass($board, $r, $c) ?>">
                                <?php if ($cell['letter']): ?>
                                    <?php
                                    $isBlank = !empty($cell['isBlank']);
                                    $disp    = $isBlank
                                        ? mb_strtolower($cell['letter'], 'UTF-8')
                                        : $cell['letter'];
                                    ?>
                                    <span class="<?= $isBlank ? 'tile-blank-letter' : '' ?>">
                                        <?= htmlspecialchars($disp) ?>
                                    </span>
                                <?php else: ?>
                                    &nbsp;
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    <?php endfor; ?>
                </div>
            </div>

            <p class="small">
                Niebieskie pola — premie literowe, czerwone — słowne.
            </p>
        </div>
    </div>

    <p><a class="btn" href="new_game.php">Powrót</a></p>
</div>
</body>
</html>
