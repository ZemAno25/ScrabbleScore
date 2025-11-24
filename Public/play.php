<?php
require_once __DIR__ . '/../src/Repositories.php';
require_once __DIR__ . '/../src/Board.php';
require_once __DIR__ . '/../src/Scorer.php';
require_once __DIR__ . '/../src/MoveParser.php';

$game_id = (int)($_GET['game_id'] ?? 0);
$game    = GameRepo::get($game_id);
if (!$game) {
    http_response_code(404);
    echo 'Brak gry.';
    exit;
}

// mapowanie id => nick
$playersById = [];
foreach (PlayerRepo::all() as $p) {
    $playersById[$p['id']] = $p['nick'];
}

$moves = MoveRepo::byGame($game_id);

// aktualny stan planszy i wyniki
$board  = new Board();
$scorer = new Scorer($board);

$scores = [
    $game['player1_id'] => 0,
    $game['player2_id'] => 0,
];

$nextPlayer    = $game['player1_id'];
$err           = null;
$lastMove      = null;
$lastPlacement = null;

// odtworzenie historii ruchów
if (count($moves) > 0) {
    $movesCount = count($moves);
    foreach ($moves as $idx => $m) {
        try {
            $placement = null;
            if ($m['type'] === 'PLAY' && $m['position'] && $m['word']) {
                $placement = $scorer->placeAndScore($m['position'], $m['word']);
            }

            // aktualizacja wyniku gracza z bazy (cum_score ma pierwszeństwo)
            $scores[$m['player_id']] = $m['cum_score'];

            // naprzemienność graczy
            $nextPlayer = ($m['player_id'] == $game['player1_id'])
                ? $game['player2_id']
                : $game['player1_id'];

            if ($idx === $movesCount - 1) {
                $lastMove = $m;
                if ($m['type'] === 'PLAY' && $placement instanceof PlacementResult) {
                    $lastPlacement = $placement;
                }
            }
        } catch (Throwable $e) {
            $err = 'Błąd historycznego ruchu #' . $m['move_no'] . ': ' . $e->getMessage();
            break;
        }
    }
}

// obsługa nowego ruchu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['raw']) && !isset($_POST['undo'])) {
    $player_id = (int)($_POST['player_id'] ?? 0);

    try {
        $pm = MoveParser::parse(trim($_POST['raw']));

        $score = 0;
        if ($pm->type === 'PLAY') {
            $placement = $scorer->placeAndScore($pm->pos, $pm->word);
            $score     = $placement->score;
        } elseif ($pm->type === 'EXCHANGE') {
            $score = 0;
        } elseif ($pm->type === 'PASS') {
            $score = 0;
        } elseif ($pm->type === 'ENDGAME') {
            // na razie ENDGAME bez dodatkowych obliczeń
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

// funkcje pomocnicze do renderowania

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

/**
 * Buduje zapis typu (2 + 1 + 1 + 1*2) dla słowa,
 * tzn. pokazuje mnożniki literowe w formie "wartość * premia".
 */
function formatWordLetterFormula(array $wordDetail): string
{
    $parts = [];

    foreach ($wordDetail['letters'] as $letter) {
        $base = $letter['base'];

        // blank ma wartość 0 – można pokazać po prostu 0
        if ($letter['isBlank']) {
            $parts[] = '0';
            continue;
        }

        $expr = (string)$base;
        if ($letter['multL'] > 1) {
            $expr = $base . '*' . $letter['multL'];
        }
        $parts[] = $expr;
    }

    return implode(' + ', $parts);
}

?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Gra #<?= $game_id ?></title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        /* wyróżnienie blanków na planszy */
        .blank-letter {
            display: inline-block;
            padding: 1px 2px;
            border: 2px solid #f1c40f;
            border-radius: 3px;
            font-size: 0.9em;
            text-transform: lowercase;
            line-height: 1;
        }
        .btn-secondary {
            background: #555;
            color: #fff;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            margin-left: 8px;
        }
        .btn-secondary:hover {
            background: #666;
        }
    </style>
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
                                <span class="small">
                                    (wymiana: <?= htmlspecialchars($m['rack']) ?>)
                                </span>
                            <?php endif; ?>
                            <?php if ($m['type'] === 'BADWORD'): ?>
                                <span class="small" style="color:#ff7676;">[BADWORD]</span>
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
                <form method="post" action="challenge.php" style="display:inline">
                    <input type="hidden" name="game_id" value="<?= $game_id ?>">
                    <button class="btn" type="submit">Kwestionuj</button>
                </form>
            <?php endif; ?>

            <?php if ($lastPlacement instanceof PlacementResult && !empty($lastPlacement->words)): ?>
                <h3>Szczegóły punktacji tego ruchu</h3>
                <ul>
                    <?php foreach ($lastPlacement->words as $w): ?>
                        <li>
                            <?= $w['isMain'] ? 'Słowo główne' : 'Słowo poboczne' ?>:
                            <strong><?= htmlspecialchars($w['text']) ?></strong>
                            =
                            (
                            <?= htmlspecialchars(formatWordLetterFormula($w)) ?>
                            )
                            <?php if ($w['wordMult'] > 1): ?>
                                * <?= $w['wordMult'] ?>
                            <?php endif; ?>
                            = <?= $w['wordScore'] ?> pkt
                        </li>
                    <?php endforeach; ?>
                    <?php if ($lastPlacement->bingoBonus > 0): ?>
                        <li>Premia za wyłożenie 7 liter (bingo): <?= $lastPlacement->bingoBonus ?> pkt</li>
                    <?php endif; ?>
                    <li><strong>Łącznie za ruch: <?= $lastPlacement->score ?> pkt</strong></li>
                </ul>
            <?php endif; ?>

            <h3>Dodaj ruch</h3>
            <form method="post">
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
                    Zapis ruchu (np. "WIJĘKRA 8F WIJĘ", "7G BAGNO",
                    "K4 KAR(O)", "PASS", "EXCHANGE",
                    "LK?RWSS EXCHANGE (RWSS)", "ENDGAME")
                </label>
                <input name="raw" required autofocus>

                <button class="btn" style="margin-top:8px" type="submit">Zatwierdź</button>
            </form>

            <form method="post" action="undo_move.php" style="display:inline;">
                <input type="hidden" name="game_id" value="<?= $game_id ?>">
                <input type="hidden" name="undo" value="1">
                <button class="btn-secondary" type="submit" style="margin-top:8px;">
                    Cofnij ostatni ruch
                </button>
            </form>
        </div>

        <div class="card">
            <h2>Plansza</h2>

            <?php $columns = range('A', 'O'); ?>

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
                            <div class="cell <?= letterClass($board, $r, $c) ?>">
                                <?php if ($board->cells[$r][$c]['letter']): ?>
                                    <?php if ($board->cells[$r][$c]['isBlank']): ?>
                                        <span class="blank-letter">
                                            <?= htmlspecialchars(mb_strtolower($board->cells[$r][$c]['letter'], 'UTF-8')) ?>
                                        </span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($board->cells[$r][$c]['letter']) ?>
                                    <?php endif; ?>
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

<script>
// po każdym załadowaniu strony ustawiamy fokus na polu zapisu ruchu
document.addEventListener('DOMContentLoaded', function () {
    var input = document.querySelector('input[name="raw"]');
    if (input) {
        input.focus();
        input.select();
    }
});
</script>
</body>
</html>
