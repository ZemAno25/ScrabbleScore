<?php
require_once __DIR__.'/../src/Repositories.php';
require_once __DIR__.'/../src/Board.php';
require_once __DIR__.'/../src/Scorer.php';
require_once __DIR__.'/../src/MoveParser.php';

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
$msg = null;

// Wyznacz gracza, który jest "następny w kolejce"
$nextPlayer = $game['player1_id'];

/**
 * Odtworzenie planszy i wyników z historii ruchów.
 * Uwaga: ruchy typu BADWORD nie są odtwarzane na planszy,
 * ponieważ litery zostały zdjęte (skuteczne kwestionowanie).
 */
if (count($moves) > 0) {
    foreach ($moves as $m) {
        if ($m['type'] === 'PLAY') {
            try {
                $scorer->placeAndScore($m['position'], $m['word']);
            } catch (Throwable $e) {
                $err = 'Błąd historycznego ruchu #'.$m['move_no'].': '.$e->getMessage();
                break;
            }
        }

        // Zaktualizuj wynik danego gracza
        $scores[$m['player_id']] = $m['cum_score'];

        // Naprzemienność graczy
        $nextPlayer = ($m['player_id'] == $game['player1_id'])
            ? $game['player2_id']
            : $game['player1_id'];
    }
}

// Obsługa nowego ruchu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['raw'])) {
    $player_id = (int)($_POST['player_id'] ?? 0);

    try {
        $parser = new MoveParser();
        $pm     = $parser->parse(trim($_POST['raw']));

        $score = 0;
        if ($pm->type === 'PLAY') {
            $placement = $scorer->placeAndScore($pm->pos, $pm->word);
            $score     = $placement->score;
        } elseif ($pm->type === 'EXCHANGE' || $pm->type === 'PASS') {
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

        // Po dodaniu ruchu stosujemy redirect (PRG),
        // a szczegóły punktacji odtworzymy poniżej z historii.
        header('Location: play.php?game_id='.$game_id);
        exit;
    } catch (Throwable $e) {
        $err = 'Błąd: '.$e->getMessage();
    }
}

// Pomocnicza funkcja do stylów pól planszy
function letterClass(Board $b, int $r, int $c): string
{
    if ($b->cells[$r][$c]['letter']) {
        return 'tile';
    }
    $ps = $b->W[$r][$c];
    $pl = $b->L[$r][$c];
    if ($ps === 3) return 'ps3';
    if ($ps === 2) return 'ps2';
    if ($pl === 3) return 'pl3';
    if ($pl === 2) return 'pl2';
    return '';
}

// Szczegóły punktacji ostatniego ruchu (odtwarzamy jak w challenge.php)
$lastPlacement = null;
$detailError   = null;

if ($lastMove && $lastMove['type'] === 'PLAY' && $lastMove['position'] && $lastMove['word']) {
    $tmpBoard  = new Board();
    $tmpScorer = new Scorer($tmpBoard);
    $movesCount = count($moves);

    // Najpierw odtwórz wszystkie ruchy przed ostatnim
    for ($i = 0; $i < $movesCount - 1; $i++) {
        $m = $moves[$i];
        if ($m['type'] === 'PLAY' && $m['position'] && $m['word']) {
            try {
                $tmpScorer->placeAndScore($m['position'], $m['word']);
            } catch (Throwable $e) {
                // historyczny błąd – ignorujemy w kontekście szczegółów
            }
        }
    }

    // Teraz zastosuj ostatni ruch na pomocniczej planszy
    try {
        $lastPlacement = $tmpScorer->placeAndScore($lastMove['position'], $lastMove['word']);
    } catch (Throwable $e) {
        $detailError = $e->getMessage();
    }
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
                                <span class="small">(wymiana: <?=htmlspecialchars($m['rack'])?>)</span>
                            <?php endif; ?>
                            <?php if ($m['type'] === 'BADWORD'): ?>
                                <span class="small bad-move">[BADWORD]</span>
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
                <?php if ($lastPlacement): ?>
                    <p><strong>Szczegóły punktacji tego ruchu:</strong></p>
                    <ul class="move-details">
                        <?php foreach ($lastPlacement->words as $wd): ?>
                            <?php
                            $label = ($wd['kind'] === 'main') ? 'Słowo główne' : 'Krzyżówka';
                            $letterParts = [];
                            foreach ($wd['letters'] as $L) {
                                // używamy wartości po premii literowej (scoreAfterLetterMult),
                                // żeby zgadzała się suma.
                                $letterParts[] = (string)$L['scoreAfterLetterMult'];
                            }
                            $letterExpr = implode(' + ', $letterParts);
                            ?>
                            <li>
                                <?=$label?>:
                                <?=htmlspecialchars($wd['word'])?>
                                = (<?=$letterExpr?>)
                                <?php if ($wd['wordMultiplier'] > 1): ?>
                                    * <?=$wd['wordMultiplier']?>
                                <?php endif; ?>
                                = <?=$wd['score']?>
                            </li>
                        <?php endforeach; ?>

                        <?php if ($lastPlacement->bingoBonus > 0): ?>
                            <li>Premia za wyłożenie 7 liter: <?=$lastPlacement->bingoBonus?></li>
                        <?php endif; ?>

                        <li><strong>Łącznie za ruch: <?=$lastPlacement->score?></strong></li>
                    </ul>
                <?php elseif ($detailError): ?>
                    <p>Nie udało się odtworzyć szczegółów punktacji: <?=htmlspecialchars($detailError)?></p>
                <?php endif; ?>

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
                <input name="raw" required>

                <button class="btn" style="margin-top:8px">Zatwierdź</button>
            </form>
        </div>

        <div class="card">
            <h2>Plansza</h2>

            <?php $columns = range('A', 'O'); ?>

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
                            <div class="cell <?=letterClass($board, $r, $c)?>">
                                <?php if ($board->cells[$r][$c]['letter']): ?>
                                    <?=htmlspecialchars($board->cells[$r][$c]['letter'])?>
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
// Automatyczne ustawienie fokusu na polu wprowadzania ruchu
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
