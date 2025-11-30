<?php
require_once __DIR__.'/../src/Repositories.php';
require_once __DIR__.'/../src/Board.php';
require_once __DIR__.'/../src/Scorer.php';
require_once __DIR__.'/../src/MoveParser.php';
// Zakładam, że klasa z wartościami liter (PolishLetters) jest dostępna w zasięgu
require_once __DIR__.'/../src/PolishLetters.php'; 

$game_id = (int)($_GET['game_id'] ?? 0);
$game = GameRepo::get($game_id);
if (!$game) {
    http_response_code(404);
    echo 'Brak gry.';
    exit;
}

// mapa id -> nick
$playersById = [];
foreach (PlayerRepo::all() as $p) {
    $playersById[$p['id']] = $p['nick'];
}

$moves    = MoveRepo::byGame($game_id);
$lastMove = count($moves) ? $moves[count($moves) - 1] : null;

$board  = new Board();
$scorer = new Scorer($board);

$scores = [
    $game['player1_id'] => 0,
    $game['player2_id'] => 0,
];

$err = null;

// domyślnie następny gracz to pierwszy
$nextPlayer = $game['player1_id'];

// Odtworzenie historii ruchów
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

        $scores[$m['player_id']] = $m['cum_score'];

        $nextPlayer = ($m['player_id'] == $game['player1_id'])
            ? $game['player2_id']
            : $game['player1_id'];
    }
}

/**
 * Oblicza sumę punktów dla danego ciągu liter (Quackle/PFS).
 * Wymaga dostępu do stałych z wartościami liter (PolishLetters::values()).
 */
function calculateLetterValue(string $letters): int
{
    $total = 0;
    $values = PolishLetters::values();
    // Normalizacja do wielkich liter, ponieważ tablica wartości używa wielkich liter
    $letters = mb_strtoupper($letters, 'UTF-8'); 
    
    foreach (mb_str_split($letters, 1, 'UTF-8') as $letter) {
        // Traktujemy pustą płytkę (?) jako 0 punktów
        if ($letter === '?') {
            $total += 0; 
        } else {
            $total += $values[$letter] ?? 0;
        }
    }
    return $total;
}

// Obsługa nowego ruchu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['raw'])) {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $score_override = isset($_POST['score_override']) ? (int)$_POST['score_override'] : null;

    try {
        $raw_input = trim($_POST['raw']);
        $pm = MoveParser::parse($raw_input);

        $score = 0;
        if ($pm->type === 'PLAY') {
            $placement = $scorer->placeAndScore($pm->pos, $pm->word);
            $score     = $placement->score;
        } elseif ($pm->type === 'ENDGAME') {
            // W przypadku ENDGAME:
            // Jeśli podano override, używamy go. Umożliwia to ręczne wprowadzanie kar/premii.
            if ($score_override !== null) {
                 $score = $score_override;
            } else {
                 // Jeśli nie podano override, ruch ENDGAME domyślnie ma 0 pkt
                 $score = 0; 
            }
        }
        // Dla PASS i EXCHANGE score jest 0 (zgodne z MoveParser)
        
        // Zastosowanie nadpisanej punktacji (score_override) tylko dla ruchów specjalnych
        if ($score_override !== null && $pm->type !== 'PLAY') {
             $score = $score_override;
        }


        $moveNo = count($moves) + 1;
        $cum    = $scores[$player_id] + $score;

        MoveRepo::add([
            'game_id'   => $game_id,
            'move_no'   => $moveNo,
            'player_id' => $player_id,
            'raw_input' => $raw_input,
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

// Funkcja pomocnicza do klas CSS pól
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

// Szczegóły punktacji ostatniego ruchu
$lastMoveDetails = [];
$lastMoveScore   = null;

// ... (logika szczegółów ostatniego ruchu bez zmian) ...
if ($lastMove && $lastMove['type'] === 'PLAY' && $lastMove['position'] && $lastMove['word']) {
    try {
        $tmpBoard  = new Board();
        $tmpScorer = new Scorer($tmpBoard);

        $cnt = count($moves);
        for ($i = 0; $i < $cnt - 1; $i++) {
            $m = $moves[$i];
            if ($m['type'] === 'PLAY' && $m['position'] && $m['word']) {
                try {
                    $tmpScorer->placeAndScore($m['position'], $m['word']);
                } catch (Throwable $e) {
                    // ignorujemy błędy historyczne
                }
            }
        }

        $placementExplain = $tmpScorer->placeAndScore(
            $lastMove['position'],
            $lastMove['word']
        );
        $lastMoveDetails = $placementExplain->wordDetails;
        $lastMoveScore   = $placementExplain->score;
    } catch (Throwable $e) {
        $lastMoveDetails = [];
        $lastMoveScore   = null;
    }
}

// Zawartość worka – zestaw startowy
$initialBag = [
    'A' => 9,
    'Ą' => 1,
    'B' => 2,
    'C' => 3,
    'Ć' => 1,
    'D' => 3,
    'E' => 7,
    'Ę' => 1,
    'F' => 1,
    'G' => 2,
    'H' => 2,
    'I' => 8,
    'J' => 2,
    'K' => 3,
    'L' => 3,
    'Ł' => 2,
    'M' => 3,
    'N' => 5,
    'Ń' => 1,
    'O' => 6,
    'Ó' => 1,
    'P' => 3,
    'R' => 4,
    'S' => 4,
    'Ś' => 1,
    'T' => 3,
    'U' => 2,
    'W' => 4, // liczność 4, wartość 1 jest w PolishLetters::values()
    'Y' => 4,
    'Z' => 5,
    'Ź' => 1,
    'Ż' => 1,
    '?' => 2,
];

$remaining = $initialBag;
for ($r = 0; $r < 15; $r++) {
    for ($c = 0; $c < 15; $c++) {
        $cell = $board->cells[$r][$c];
        if ($cell['letter'] !== null) {
            if (!empty($cell['isBlank'])) {
                $key = '?';
            } else {
                $key = mb_strtoupper($cell['letter'], 'UTF-8');
            }
            if (isset($remaining[$key]) && $remaining[$key] > 0) {
                $remaining[$key]--;
            }
        }
    }
}

ksort($remaining, SORT_STRING);
$bagLetters = '';
foreach ($remaining as $ch => $cnt) {
    if ($cnt <= 0) {
        continue;
    }
    $bagLetters .= str_repeat($ch, $cnt) . ' ';
}
$bagLetters = trim($bagLetters);

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
                            <?php
                            
                            $displayWord = '';
                            if ($m['type'] === 'PLAY') {
                                $parts = [];
                                // Stojak (jeśli dostępny)
                                if (!empty($m['rack'])) {
                                    $parts[] = $m['rack'];
                                }
                                // Pozycja
                                if (!empty($m['position'])) {
                                    $parts[] = $m['position'];
                                }
                                // Słowo (kolumna 'word' w bazie ma już format z nawiasami)
                                if (!empty($m['word'])) {
                                    $parts[] = $m['word'];
                                }
                                $displayWord = htmlspecialchars(implode(' ', $parts));
                                echo $displayWord;
                            } elseif ($m['type'] === 'ENDGAME') {
                                // Specjalne wyświetlanie końca gry
                                $displayWord = 'ZAKOŃCZENIE GRY';
                                echo "<strong>{$displayWord}</strong>";
                            } else {
                                // Dla PASS, EXCHANGE wyświetlamy po prostu typ ruchu.
                                $displayWord = htmlspecialchars($m['type']);
                                echo $displayWord;
                            }
                            ?>

                            <?php if ($m['type'] === 'EXCHANGE' && $m['rack']): ?>
                                <span class="small">
                                    (wymiana: <?=htmlspecialchars($m['rack'])?>)
                                </span>
                            <?php endif; ?>
                            <?php 
                            // Oznaczenie dodatkowych szczegółów, np. liter pozostałych na stojaku, dla ENDGAME
                            if ($m['type'] === 'ENDGAME' && $m['raw_input']): 
                                // Próba wyłuskania liter z surowego zapisu, jeśli zostały podane (np. dla ENDGAME (Ź))
                                $rawEndgame = trim(str_ireplace('ENDGAME', '', $m['raw_input']));
                                if ($rawEndgame) {
                                    echo '<span class="small">';
                                    echo htmlspecialchars($rawEndgame);
                                    echo '</span>';
                                }
                            ?>
                            <?php endif; ?>
                            <?php if ($m['type'] === 'BADWORD'): ?>
                                <span class="small error">(BADWORD)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                            // Kolorowanie punktacji końcowej
                            if ($m['type'] === 'ENDGAME') {
                                if ($m['score'] < 0) {
                                    // Kara
                                    echo '<span class="error">' . $m['score'] . '</span>';
                                } elseif ($m['score'] > 0) {
                                    // Premia
                                    echo '<span class="success">' . $m['score'] . '</span>';
                                } else {
                                    echo $m['score'];
                                }
                            } else {
                                echo $m['score'];
                            }
                            ?>
                        </td>
                        <td><?=$m['cum_score']?></td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <?php if ($lastMove && $lastMove['type'] === 'PLAY'): ?>
                <h3>Kwestionowanie ostatniego ruchu</h3>
                <p>
                    Ostatni ruch:
                    <strong>
                        <?php
                            // Używamy znormalizowanego wyświetlania dla czytelności zapisu
                            $parts = [];
                            if (!empty($lastMove['rack'])) { $parts[] = $lastMove['rack']; }
                            if (!empty($lastMove['position'])) { $parts[] = $lastMove['position']; }
                            if (!empty($lastMove['word'])) { $parts[] = $lastMove['word']; }
                            echo htmlspecialchars(implode(' ', $parts));
                        ?>
                    </strong>
                    (gracz:
                    <?=htmlspecialchars($playersById[$lastMove['player_id']] ?? '')?>)
                </p>
                <form method="post" action="challenge.php" style="display:inline">
                    <input type="hidden" name="game_id" value="<?=$game_id?>">
                    <button class="btn">Kwestionuj</button>
                </form>
            <?php endif; ?>
            
            <?php // ... (Szczegóły punktacji dla ruchu PLAY) ... ?>
            <?php if ($lastMove && $lastMove['type'] === 'PLAY' && !empty($lastMoveDetails) && $lastMoveScore !== null): ?>
                <h3>Szczegóły punktacji tego ruchu</h3>
                <ul>
                    <?php
                    $crosses = [];
                    $main = null;
                    foreach ($lastMoveDetails as $d) {
                        if ($d['kind'] === 'main' && $main === null) {
                            $main = $d;
                        } elseif ($d['kind'] === 'cross') {
                            $crosses[] = $d;
                        }
                    }
                    ?>

                    <?php if ($main !== null): ?>
                        <?php
                        $expr = $main['letterExpr'];
                        if ($main['wordMultiplier'] > 1) {
                            $line = sprintf(
                                '%s = (%s) * %d = %d',
                                $main['word'],
                                $expr,
                                $main['wordMultiplier'],
                                $main['score']
                            );
                        } else {
                            $line = sprintf(
                                '%s = %s = %d',
                                $main['word'],
                                $expr,
                                $main['score']
                            );
                        }
                        ?>
                        <li>Słowo główne: <?=$line?></li>
                    <?php endif; ?>

                    <?php if (!empty($crosses)): ?>
                        <li>Krzyżówki:</li>
                        <?php foreach ($crosses as $c): ?>
                            <?php
                            $expr = $c['letterExpr'];
                            if ($c['wordMultiplier'] > 1) {
                                $line = sprintf(
                                    '%s = (%s) * %d = %d',
                                    $c['word'],
                                    $expr,
                                    $c['wordMultiplier'],
                                    $c['score']
                                );
                            } else {
                                $line = sprintf(
                                    '%s = %s = %d',
                                    $c['word'],
                                    $expr,
                                    $c['score']
                                );
                            }
                            ?>
                            <li class="small"><?=$line?></li>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php
                    // częściowe sumy z poszczególnych słów
                    $sumParts          = [];
                    $totalFromDetails  = 0;
                    foreach ($lastMoveDetails as $d) {
                        $sumParts[]      = (string)$d['score'];
                        $totalFromDetails += $d['score'];
                    }

                    // jeżeli wynik ruchu jest większy niż suma słów – to jest premia za 7 liter (bingo)
                    $bingoBonus = null;
                    if ($lastMoveScore !== null && $lastMoveScore > $totalFromDetails) {
                        $bingoBonus = $lastMoveScore - $totalFromDetails;
                    }
                    ?>

                    <?php if ($bingoBonus !== null): ?>
                        <li>Premia za wyłożenie 7 liter: <?=$bingoBonus?></li>
                    <?php endif; ?>

                    <?php
                    $allParts = $sumParts;
                    if ($bingoBonus !== null) {
                        $allParts[] = (string)$bingoBonus;
                    }
                    $displayTotal = $lastMoveScore ?? $totalFromDetails;
                    ?>
                    <li>Łącznie za ruch: <?=implode(' + ', $allParts)?> = <?=$displayTotal?></li>
                </ul>
            <?php endif; ?>

            <h3>Dodaj ruch</h3>
            <p class="small">
                Aby zastosować końcowe kary/premie (zgodnie z Quackle):
                <ol>
                    <li>Wprowadź **ostatni ruch PLAY** gracza kończącego.</li>
                    <li>Wprowadź **ENDGAME** dla gracza, który **ma pozostałe płytki**. Użyj pola *Punkty* na wartość **ujemną** (karę). np. -9. Zapis ruchu: **ENDGAME (Ź)**.</li>
                    <li>Wprowadź **ENDGAME** dla gracza, który **wyłożył wszystkie płytki**. Użyj pola *Punkty* na wartość **dodatnią** (premię). np. +18. Zapis ruchu: **ENDGAME (PREMIA)**.</li>
                </ol>
            </p>
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
                    Zapis ruchu (np. "WIJĘKRA 8F WIJĘ", "PASS", "ENDGAME (Ź)")
                </label>
                <input type="text" name="raw" required>
                
                <label>
                    Punkty (Dla ruchów PLAY są obliczane. Używaj tylko do ręcznych korekt/ENDGAME):
                </label>
                <input type="number" name="score_override" placeholder="Opcjonalne dla korekt końcowych">

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
                                            <?php $ch = mb_strtolower($cell['letter'], 'UTF-8'); ?>
                                            <span class="tile-blank"><?=htmlspecialchars($ch)?></span>
                                        <?php else: ?>
                                            <div class="tile-inner">
                                                <span class="tile-letter">
                                                    <?=htmlspecialchars($cell['letter'])?>
                                                </span>
                                                <?php
                                                $values = PolishLetters::values();
                                                $upCh   = mb_strtoupper($cell['letter'], 'UTF-8');
                                                $val    = $values[$upCh] ?? 0;
                                                ?>
                                                <span class="tile-score"><?=htmlspecialchars((string)$val)?></span>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        &nbsp;
                                    <?php endif; ?>
                                </div>
                            <?php endfor; ?>
                        <?php endfor; ?>
                    </div>

                    <p class="small">
                        Niebieskie pola — premie literowe, czerwone — słowne.
                    </p>
                </div>

                <div class="bag-panel">
                    <div class="bag-title">Zawartość worka</div>
                    <div class="bag-letters"><?=htmlspecialchars($bagLetters)?></div>
                </div>
            </div>
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