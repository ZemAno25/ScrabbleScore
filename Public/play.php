<?php
require_once __DIR__.'/../src/Repositories.php';
require_once __DIR__.'/../src/Board.php';
require_once __DIR__.'/../src/Scorer.php';
require_once __DIR__.'/../src/MoveParser.php';
require_once __DIR__.'/../src/PolishLetters.php';

$game_id = (int)($_GET['game_id'] ?? 0);
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

$moves    = MoveRepo::byGame($game_id);
$lastMove = count($moves) ? $moves[count($moves) - 1] : null;
$board    = new Board();
$scorer   = new Scorer($board);

$scores = [
    $game['player1_id'] => 0,
    $game['player2_id'] => 0,
];
$err = null;

// Wyznacz gracza, który jest "następny w kolejce"
$nextPlayer = $game['player1_id'];

// Odtworzenie planszy i wyników z historii ruchów
if (count($moves) > 0) {
    foreach ($moves as $m) {
        if ($m['type'] === 'PLAY' && $m['position'] && $m['word']) {
            try {
                $scorer->placeAndScore($m['position'], $m['word']);
            } catch (Throwable $e) {
                $err = 'Błąd historycznego ruchu #'.$m['move_no'].': '.$e->getMessage();
                break;
            }
        }

        // Zaktualizuj wynik danego gracza
        $scores[$m['player_id']] = $m['cum_score'];

        // Naprzemienność graczy (wyjątki jak ENDGAME możesz dodać później)
        $nextPlayer = ($m['player_id'] == $game['player1_id'])
            ? $game['player2_id']
            : $game['player1_id'];
    }
}

// WYLICZANIE ZAWARTOŚCI "WORKA" NA PODSTAWIE PLANSZY
$bagCounts = PolishLetters::bagCounts();
$boardSize = 15; // klasyczna plansza 15x15

for ($r = 0; $r < $boardSize; $r++) {
    for ($c = 0; $c < $boardSize; $c++) {
        $cell = $board->cells[$r][$c] ?? null;
        if (!$cell || $cell['letter'] === null) {
            continue;
        }

        // Jeżeli to blank, odejmujemy z worka "?", a nie literę, którą zastępuje
        if (!empty($cell['isBlank'])) {
            if (isset($bagCounts['?']) && $bagCounts['?'] > 0) {
                $bagCounts['?']--;
            }
        } else {
            $ch = $cell['letter'];
            if (isset($bagCounts[$ch]) && $bagCounts[$ch] > 0) {
                $bagCounts[$ch]--;
            }
        }
    }
}

// Tekstowa reprezentacja worka: każda litera tyle razy, ile zostało w worku.
$bagStringParts = [];
$bagTotal = 0;
foreach ($bagCounts as $ch => $cnt) {
    if ($cnt > 0) {
        $bagTotal += $cnt;
        $bagStringParts[] = str_repeat($ch, $cnt);
    }
}
$bagString = implode(' ', $bagStringParts);

// Obsługa nowego ruchu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['raw'])) {
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
            // Jeżeli masz specjalne zasady dla ENDGAME,
            // można je tu dopisać. Na razie 0 punktów "z automatu".
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

        header('Location: play.php?game_id='.$game_id);
        exit;
    } catch (Throwable $e) {
        $err = 'Błąd: '.$e->getMessage();
    }
}

// Helpers for rendering
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

?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Gra #<?=$game_id?></title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="container">
    <h1>
        Gra #<?=$game_id?> —
        <?=htmlspecialchars($playersById[$game['player1_id']] ?? '')?>
        vs
        <?=htmlspecialchars($playersById[$game['player2_id']] ?? '')?>
    </h1>

    <?php if ($err): ?>
        <div class="card error"><?=$err?></div>
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
                        <td><?=$m['move_no']?></td>
                        <td><?=htmlspecialchars($m['nick'])?></td>
                        <td>
                            <?=htmlspecialchars($m['raw_input'])?>
                            <?php if ($m['type'] === 'EXCHANGE' && $m['rack']): ?>
                                <span class="small">
                                    (wymiana: <?=htmlspecialchars($m['rack'])?>)
                                </span>
                            <?php endif; ?>
                            <?php if ($m['type'] === 'BADWORD'): ?>
                                <span class="small error">(ruch nieprawidłowy)</span>
                            <?php endif; ?>
                        </td>
                        <td><?=$m['score']?></td>
                        <td><?=$m['cum_score']?></td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <?php if ($lastMove && $lastMove['type'] === 'PLAY'): ?>
                <h3>Kwestionowanie ostatniego ruchu</h3>
                <p>
                    Ostatni ruch:
                    <strong><?=htmlspecialchars($lastMove['raw_input'])?></strong>
                    (gracz:
                    <?=htmlspecialchars($playersById[$lastMove['player_id']] ?? '')?>)
                </p>
                <form method="post" action="challenge.php" style="display:inline">
                    <input type="hidden" name="game_id" value="<?=$game_id?>">
                    <button class="btn">Kwestionuj</button>
                </form>
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
                                value="<?=$game['player1_id']?>"
                                <?=($nextPlayer == $game['player1_id']) ? 'checked' : ''?>
                            >
                            <span><?=htmlspecialchars($playersById[$game['player1_id']] ?? '')?></span>
                        </label>
                        <label class="player-radio">
                            <input
                                type="radio"
                                name="player_id"
                                value="<?=$game['player2_id']?>"
                                <?=($nextPlayer == $game['player2_id']) ? 'checked' : ''?>
                            >
                            <span><?=htmlspecialchars($playersById[$game['player2_id']] ?? '')?></span>
                        </label>
                    </div>
                </div>

                <label>
                    Zapis ruchu (np. "WIJĘKRA 8F WIJĘ", "7G BAGNO", "K4 KAR(O)",
                    "PASS", "EXCHANGE", "LK?RWSS EXCHANGE (RWSS)", "ENDGAME")
                </label>
                <input type="text" name="raw" required>

                <button class="btn" style="margin-top:8px">Zatwierdź</button>
            </form>

            <form method="post" action="undo_move.php" style="display:inline-block;margin-top:8px">
                <input type="hidden" name="game_id" value="<?=$game_id?>">
                <button type="submit" name="undo_last" class="btn btn-secondary">
                    Cofnij ostatni ruch
                </button>
            </form>
        </div>

        <div class="card">
            <h2>Plansza</h2>

            <?php $columns = range('A', 'O'); ?>

            <div class="board-and-bag">
                <div class="board-wrapper">
                    <div class="board-header">
                        <div class="coord-corner"></div>
                        <?php foreach ($columns as $col): ?>
                            <div class="coord coord-col"><?=$col?></div>
                        <?php endforeach; ?>
                    </div>

                    <div class="board board-grid">
                        <?php for ($r = 0; $r < 15; $r++): ?>
                            <div class="coord coord-row"><?=$r + 1?></div>
                            <?php for ($c = 0; $c < 15; $c++): ?>
                                <?php $cell = $board->cells[$r][$c]; ?>
                                <div class="cell <?=letterClass($board, $r, $c)?>">
                                    <?php if ($cell['letter']): ?>
                                        <?php if (!empty($cell['isBlank'])): ?>
                                            <span class="tile-blank">
                                                <?=htmlspecialchars(mb_strtolower($cell['letter'], 'UTF-8'))?>
                                            </span>
                                        <?php else: ?>
                                            <?=htmlspecialchars($cell['letter'])?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        &nbsp;
                                    <?php endif; ?>
                                </div>
                            <?php endfor; ?>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="bag-panel">
                    <div class="bag-title">Worek</div>
                    <?php if ($bagTotal > 0): ?>
                        <p class="small">Pozostało płytek: <?=$bagTotal?></p>
                        <p class="bag-letters"><?=htmlspecialchars($bagString)?></p>
                        <p class="small">
                            Każda litera powtórzona tyle razy, ile zostało jej w worku
                            (stan liczony wyłącznie z tego, co leży na planszy).
                        </p>
                    <?php else: ?>
                        <p class="small">Worek pusty – wszystkie płytki znajdują się na planszy.</p>
                    <?php endif; ?>
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
