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

// recorder (who records the game) — may be null
$recorderId = $game['recorder_player_id'] ?? null;

// helper: find last recorded rack for a player (searching moves list)
function findLastRackForPlayer(array $moves, int $playerId): ?string {
    for ($i = count($moves) - 1; $i >= 0; $i--) {
        if ($moves[$i]['player_id'] == $playerId && !empty($moves[$i]['rack'])) {
            return $moves[$i]['rack'];
        }
    }
    return null;
}

// helper: find last post_rack (remaining rack after a move) for a player
function findLastPostRackForPlayer(array $moves, int $playerId): ?string {
    for ($i = count($moves) - 1; $i >= 0; $i--) {
        if ($moves[$i]['player_id'] == $playerId && !empty($moves[$i]['post_rack'])) {
            return $moves[$i]['post_rack'];
        }
    }
    return null;
}

// Compute default raw input prefill: if next player is the recorder, prefill their remaining rack
$defaultRaw = '';
$defaultRack = '';
if ($recorderId !== null && $nextPlayer == $recorderId) {
    // prefer the last known post_rack (remaining after recorder's previous move)
    $lastRack = findLastPostRackForPlayer($moves, $recorderId);
    $lastRackIsPost = true;
    if ($lastRack === null) {
        // fallback to any recorded rack value (pre-move rack)
        $lastRack = findLastRackForPlayer($moves, $recorderId);
        $lastRackIsPost = false;
    }
    if ($lastRack !== null) {
        // If we have a post_rack (remaining after previous move), use it directly.
        // Otherwise (we have only a pre-move rack), subtract board letters to compute remaining.
        $boardCounts = [];
        if (!$lastRackIsPost) {
            // build board letter counts (treat blanks as '?')
            for ($r = 0; $r < 15; $r++) {
                for ($c = 0; $c < 15; $c++) {
                    $cell = $board->cells[$r][$c];
                    if ($cell['letter'] !== null) {
                        $key = !empty($cell['isBlank']) ? '?' : mb_strtoupper($cell['letter'], 'UTF-8');
                        if (!isset($boardCounts[$key])) $boardCounts[$key] = 0;
                        $boardCounts[$key]++;
                    }
                }
            }
        }

        $remaining = '';
        $rackClean = str_replace(' ', '', $lastRack);
        $letters = mb_str_split($rackClean, 1, 'UTF-8');
        foreach ($letters as $lt) {
            $k = ($lt === '?') ? '?' : mb_strtoupper($lt, 'UTF-8');
            if (!$lastRackIsPost && !empty($boardCounts[$k])) {
                $boardCounts[$k]--;
                continue; // this letter is already on board
            }
            $remaining .= $lt;
        }

        if ($remaining !== '') {
            $defaultRaw = $remaining . ' ';
        }
        // prefill rack field with the last known remaining rack for recorder
        $defaultRack = $lastRack;
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

    try {
        $raw_input = trim($_POST['raw']);
        $pm = MoveParser::parse($raw_input);

        $parsedRackProvided = !empty($pm->rack);

        // If a separate rack field was submitted, use it only when the notation
        // itself did not already include a rack. This prevents stale prefilled
        // values from silently overriding an explicit rack coming from the raw move.
        if (isset($_POST['rack'])) {
            $rackRaw = trim((string)$_POST['rack']);
            $rackClean = str_replace(' ', '', $rackRaw);
            if ($rackClean !== '') {
                // Validate: max 7 tiles and only allowed letters (Polish letters + '?')
                if (mb_strlen($rackClean, 'UTF-8') > 7) {
                    throw new Exception('Stojak nie może mieć więcej niż 7 płytek.');
                }
                if (!preg_match('/^[A-Za-zĄąĆćĘęŁłŃńÓóŚśŹźŻż\?]+$/u', $rackClean)) {
                    throw new Exception('Stojak zawiera niedozwolone znaki. Dozwolone: litery i ? dla pustych płytek.');
                }
                $rackUpper = mb_strtoupper($rackClean, 'UTF-8');
                if (!$parsedRackProvided) {
                    $pm->rack = $rackUpper;
                    $parsedRackProvided = true;
                }
            }
        }

        $score = 0;
        if ($pm->type === 'PLAY') {
            $placement = $scorer->placeAndScore($pm->pos, $pm->word);
            $score     = $placement->score;
        } elseif ($pm->type === 'ENDGAME') {
            // W przypadku ENDGAME: policz wartość stojaka jeśli została podana
            if (!empty($pm->rack)) {
                // Jeżeli w zapisie ENDGAME podano stojak, policz jego wartość.
                $rackLetters = $pm->rack;
                $val = calculateLetterValue($rackLetters);

                // Odtwórz zawartość worka (plansza + zapisane stojaki w historii),
                // aby poprawnie wykryć, czy mamy do czynienia z "bingo-like" end.
                $initialBag = [
                    'A' => 9,'Ą' => 1,'B' => 2,'C' => 3,'Ć' => 1,'D' => 3,'E' => 7,'Ę' => 1,
                    'F' => 1,'G' => 2,'H' => 2,'I' => 8,'J' => 2,'K' => 3,'L' => 3,'Ł' => 2,
                    'M' => 3,'N' => 5,'Ń' => 1,'O' => 6,'Ó' => 1,'P' => 3,'R' => 4,'S' => 4,
                    'Ś' => 1,'T' => 3,'U' => 2,'W' => 4,'Y' => 4,'Z' => 5,'Ź' => 1,'Ż' => 1,'?' => 2,
                ];

                $remaining = $initialBag;
                for ($r = 0; $r < 15; $r++) {
                    for ($c = 0; $c < 15; $c++) {
                        $cell = $board->cells[$r][$c];
                        if ($cell['letter'] !== null) {
                            $key = !empty($cell['isBlank']) ? '?' : mb_strtoupper($cell['letter'], 'UTF-8');
                            if (isset($remaining[$key]) && $remaining[$key] > 0) {
                                $remaining[$key]--;
                            }
                        }
                    }
                }

                // Odejmij znane stojaki z historii ruchów
                foreach ($moves as $mv) {
                    if (empty($mv['rack'])) continue;
                    $rackClean = str_replace(' ', '', $mv['rack']);
                    $letters = mb_str_split($rackClean, 1, 'UTF-8');
                    foreach ($letters as $lt) {
                        $key = ($lt === '?') ? '?' : mb_strtoupper($lt, 'UTF-8');
                        if (isset($remaining[$key]) && $remaining[$key] > 0) {
                            $remaining[$key]--;
                        }
                    }
                }

                $bagEmpty = true;
                foreach ($remaining as $cnt) { if ($cnt > 0) { $bagEmpty = false; break; } }

                $isBingoLike = false;
                if ($lastMove && $lastMove['type'] === 'PLAY' && $lastMove['player_id'] == $player_id && $bagEmpty) {
                    $isBingoLike = true;
                }

                $score = $isBingoLike ? ($val * 2) : $val;
            } else {
                // Brak override i brak stojaka w zapisie -> 0
                $score = 0;
            }
        }
        // Dla PASS i EXCHANGE score jest 0 (zgodne z MoveParser)
        
           // Nie używamy już możliwości ręcznego nadpisywania punktów z formularza.


        // If the player is NOT the recorder and did not provide a rack, record the letters
        // they just placed on the board as their (partial) rack state.
        if ($pm->type === 'PLAY' && (empty($pm->rack) || $pm->rack === null) && $player_id !== $recorderId) {
            if (isset($placement) && !empty($placement->placed)) {
                $placedLetters = '';
                foreach ($placement->placed as [$pr, $pc]) {
                    $cell = $board->cells[$pr][$pc] ?? null;
                    if ($cell && $cell['letter'] !== null) {
                        $placedLetters .= $cell['letter'];
                    }
                }
                if ($placedLetters !== '') {
                    $pm->rack = $placedLetters;
                }
            }
        }

        $moveNo = count($moves) + 1;
        $cum    = $scores[$player_id] + $score;

        // Prepare list of words to check against OSPS: main word + any cross words
        $checkWords = null;
        if ($pm->type === 'PLAY' && isset($placement) && !empty($placement->wordDetails)) {
            $checkWords = [];
            foreach ($placement->wordDetails as $wd) {
                if (!empty($wd['word'])) {
                    $checkWords[] = $wd['word'];
                }
            }
            // ensure main word is present as well
            if (!in_array($pm->word, $checkWords, true) && !empty($pm->word)) {
                $checkWords[] = $pm->word;
            }
        }

        // Validate rack usage whenever we know the rack (recorder or not)
        $postRack = null;
        if ($pm->type === 'PLAY' && !empty($pm->rack) && isset($placement) && !empty($placement->placed)) {
            $postRack = Scorer::computeRemainingRackAfterPlacement($board, $placement, $pm->rack);
        }

        $dataToAdd = [
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
        ];
        if ($postRack !== null) {
            $dataToAdd['post_rack'] = $postRack;
        }
        if ($checkWords !== null) {
            $dataToAdd['check_words'] = $checkWords;
        }

        MoveRepo::add($dataToAdd);

        // --- Automatyczne zasady końcowe (Quackle-like) ---
        // Pobierz nową listę ruchów (zawiera już dodany ruch)
        $moves = MoveRepo::byGame($game_id);

        // pomocnicze: znajdź ostatni zapis stojaka dla gracza
        $findLastRack = function(array $moves, int $forPlayerId, int $beforeIndex = null) {
            // jeśli podano $beforeIndex, szukamy tylko we wcześniejszych ruchach
            $max = is_int($beforeIndex) ? $beforeIndex : count($moves) - 1;
            for ($i = $max; $i >= 0; $i--) {
                if ($moves[$i]['player_id'] == $forPlayerId && !empty($moves[$i]['rack'])) {
                    return $moves[$i]['rack'];
                }
            }
            return null;
        };

        $calcLetters = function(?string $letters) {
            if ($letters === null) return 0;
            return calculateLetterValue($letters);
        };

        // Odtwórz zawartość worka po aktualnym stanie planszy (uwzględniając płytek
        // na planszy oraz stojaki, które zostały zapisane w ruchach)
        $initialBag = [
            'A' => 9,'Ą' => 1,'B' => 2,'C' => 3,'Ć' => 1,'D' => 3,'E' => 7,'Ę' => 1,
            'F' => 1,'G' => 2,'H' => 2,'I' => 8,'J' => 2,'K' => 3,'L' => 3,'Ł' => 2,
            'M' => 3,'N' => 5,'Ń' => 1,'O' => 6,'Ó' => 1,'P' => 3,'R' => 4,'S' => 4,
            'Ś' => 1,'T' => 3,'U' => 2,'W' => 4,'Y' => 4,'Z' => 5,'Ź' => 1,'Ż' => 1,'?' => 2,
        ];

        $remaining = $initialBag;
        for ($r = 0; $r < 15; $r++) {
            for ($c = 0; $c < 15; $c++) {
                $cell = $board->cells[$r][$c];
                if ($cell['letter'] !== null) {
                    $key = !empty($cell['isBlank']) ? '?' : mb_strtoupper($cell['letter'], 'UTF-8');
                    if (isset($remaining[$key]) && $remaining[$key] > 0) {
                        $remaining[$key]--;
                    }
                }
            }
        }

        // odejmij też litery z zapisanego stojaka (jeśli któryś ruch zawierał 'rack')
        foreach ($moves as $mv) {
            if (empty($mv['rack'])) continue;
            $rackClean = str_replace(' ', '', $mv['rack']);
            $letters = mb_str_split($rackClean, 1, 'UTF-8');
            foreach ($letters as $lt) {
                $key = ($lt === '?') ? '?' : mb_strtoupper($lt, 'UTF-8');
                if (isset($remaining[$key]) && $remaining[$key] > 0) {
                    $remaining[$key]--;
                }
            }
        }

        $bagEmpty = true;
        foreach ($remaining as $cnt) { if ($cnt > 0) { $bagEmpty = false; break; } }

        // funkcja: czy ostatnie N ruchów to PASS?
        $lastNPass = function(array $moves, int $n) {
            if (count($moves) < $n) return false;
            for ($i = count($moves) - $n; $i < count($moves); $i++) {
                if ($moves[$i]['type'] !== 'PASS') return false;
            }
            return true;
        };

        // Pobierz aktualne kumulowane wyniki
        $lastCum = MoveRepo::lastCumScores($game_id);

        // 1) Bingo End (auto): ostatni ruch był PLAY i wyczyścił stojak gracza oraz worek jest pusty
        if ($pm->type === 'PLAY' && isset($placement) && $placement->lettersPlaced > 0) {
            // spróbuj ustalić długość stojaka PRZED wykonaniem ruchu
            $preRack = $pm->rack ?? null;
            if ($preRack === null) {
                // szukamy ostatniego zapisu stojaka dla tego gracza przed nowym ruchem
                $preRack = $findLastRack($moves, $player_id, count($moves) - 2);
            }
            if ($preRack !== null) {
                $rackLen = mb_strlen($preRack, 'UTF-8');
                if ($rackLen === $placement->lettersPlaced && $bagEmpty) {
                    // mamy bingo end -> przyznaj premię zwycięzcy = 2x wartość stojaka przegranego
                    $p1 = $game['player1_id'];
                    $p2 = $game['player2_id'];
                    $winner = $player_id;
                    $loser  = ($winner === $p1) ? $p2 : $p1;

                    $loserRack = $findLastRack($moves, $loser);
                    if ($loserRack !== null) {
                        $loserValue = $calcLetters($loserRack);
                        $bonus = $loserValue * 2;
                        $moveNo = count($moves) + 1;
                        $cumWinner = ($lastCum[$winner] ?? 0) + $bonus;
                        MoveRepo::add([
                            'game_id'   => $game_id,
                            'move_no'   => $moveNo,
                            'player_id' => $winner,
                            'raw_input' => 'ENDGAME (' . $loserRack . ')',
                            'type'      => 'ENDGAME',
                            'position'  => null,
                            'word'      => null,
                            'rack'      => $loserRack,
                            'score'     => $bonus,
                            'cum_score' => $cumWinner,
                        ]);
                    }
                }
            }
        }

        // 2) Pass Out End: trzy kolejne PASSy -> obaj gracze tracą wartość swoich stojaków
        if ($lastNPass($moves, 3)) {
            $p1 = $game['player1_id'];
            $p2 = $game['player2_id'];
            $r1 = $findLastRack($moves, $p1);
            $r2 = $findLastRack($moves, $p2);
            $moveNo = count($moves) + 1;
            if ($r1 !== null) {
                $v1 = $calcLetters($r1);
                $cum1 = ($lastCum[$p1] ?? 0) - $v1;
                MoveRepo::add([
                    'game_id'   => $game_id,
                    'move_no'   => $moveNo,
                    'player_id' => $p1,
                    'raw_input' => 'ENDGAME (' . $r1 . ')',
                    'type'      => 'ENDGAME',
                    'position'  => null,
                    'word'      => null,
                    'rack'      => $r1,
                    'score'     => -$v1,
                    'cum_score' => $cum1,
                ]);
                $moveNo++;
            }
            if ($r2 !== null) {
                $v2 = $calcLetters($r2);
                $cum2 = ($lastCum[$p2] ?? 0) - $v2;
                MoveRepo::add([
                    'game_id'   => $game_id,
                    'move_no'   => $moveNo,
                    'player_id' => $p2,
                    'raw_input' => 'ENDGAME (' . $r2 . ')',
                    'type'      => 'ENDGAME',
                    'position'  => null,
                    'word'      => null,
                    'rack'      => $r2,
                    'score'     => -$v2,
                    'cum_score' => $cum2,
                ]);
            }
        }

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
                // Odejmij także płytki, które pojawiają się w zapisanych polach `rack`
                // z historii ruchów — wtedy nie powinny być uznawane za obecne w worku.
                foreach ($moves as $mv) {
                    if (empty($mv['rack'])) continue;
                    $rackClean = str_replace(' ', '', $mv['rack']);
                    $letters = mb_str_split($rackClean, 1, 'UTF-8');
                    foreach ($letters as $lt) {
                        $key = ($lt === '?') ? '?' : mb_strtoupper($lt, 'UTF-8');
                        if (isset($remaining[$key]) && $remaining[$key] > 0) {
                            $remaining[$key]--;
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
    <style>
     /* Three column layout: left (controls), middle (board), right (moves) */
     /* Fix middle column to board width (1000px) and allocate remaining space to side panels
         so the board keeps its size and side panels expand into previously unused space. */
    .three-col { display: grid; grid-template-columns: 520px 1000px 760px; gap: 20px; align-items: start; }
    .three-col .card { padding: 16px; box-sizing: border-box; }
     /* Moves panel: scrollable list that shows latest moves at the bottom */
    /* Allow the moves panel to grow vertically so scrolling isn't required by default */
    .moves-list { max-height: none; overflow-y: visible; overflow-x: auto; }
     /* Ensure the moves table uses table layout so all columns (score, cum) render correctly
         and horizontal scrolling is available when needed. This targets only the moves table. */
     .moves-list table { display: table; width: 100%; table-layout: auto; }
    /* Keep board centered and bag under board */
    .board-wrapper { display:block; }
    /* Force stacking: board above, bag below */
    .board-and-bag { display: block; }
    /* Bag panel: full-width under board, centered and visually aligned with board */
    .bag-panel { margin: 12px auto 0; max-width: min(100%, 72vh); text-align: center; }
    .bag-panel .bag-title { margin-bottom: 6px; }
    /* Scoring details: remove bullets and tighten spacing */
    .scoring-details ul { list-style: none; padding: 0; margin: 0; }
    .scoring-details li { margin: 6px 0; }
    /* Ensure adding new moves doesn't scroll the whole page */
    body { min-height: 100vh; }
    @media (max-width: 1000px) {
        .three-col { grid-template-columns: 1fr; }
        .moves-list { max-height: 300px; }
    }
    </style>
</head>
<body>
<div class="container">

    <?php if ($err): ?>
        <div class="card error"><?=$err?></div>
    <?php endif; ?>

    <div class="three-col">
        <div class="card left-panel">
            <?php if ($lastMove && $lastMove['type'] === 'PLAY'): ?>
                <h3>Kwestionowanie ostatniego ruchu</h3>
                <p>
                    Ostatni ruch:
                    <strong>
                        <?php
                            $parts = [];
                            if (!empty($lastMove['rack'])) { $parts[] = $lastMove['rack']; }
                            if (!empty($lastMove['position'])) { $parts[] = $lastMove['position']; }
                            if (!empty($lastMove['word'])) { $parts[] = $lastMove['word']; }
                            echo htmlspecialchars(implode(' ', $parts));
                        ?>
                    </strong>
                    (gracz: <?=htmlspecialchars($playersById[$lastMove['player_id']] ?? '')?>)
                </p>
                <form method="post" action="challenge.php" style="display:inline">
                    <input type="hidden" name="game_id" value="<?=$game_id?>">
                    <button class="btn">Kwestionuj</button>
                </form>
                <form method="post" action="challenge.php" style="display:inline;margin-left:8px">
                    <input type="hidden" name="game_id" value="<?=$game_id?>">
                    <input type="hidden" name="dry" value="1">
                    <button class="btn btn-secondary">Sprawdź</button>
                </form>
            <?php endif; ?>

            <?php if ($lastMove && $lastMove['type'] === 'PLAY' && !empty($lastMoveDetails) && $lastMoveScore !== null): ?>
                <div class="scoring-details">
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

                        if ($main !== null) {
                            $expr = $main['letterExpr'];
                            if ($main['wordMultiplier'] > 1) {
                                $line = sprintf('%s = (%s) * %d = %d', $main['word'], $expr, $main['wordMultiplier'], $main['score']);
                            } else {
                                $line = sprintf('%s = %s = %d', $main['word'], $expr, $main['score']);
                            }
                            echo '<li>Słowo główne: ' . $line . '</li>';
                        }

                        if (!empty($crosses)) {
                            echo '<li>Krzyżówki:</li>';
                            foreach ($crosses as $c) {
                                $expr = $c['letterExpr'];
                                if ($c['wordMultiplier'] > 1) {
                                    $line = sprintf('%s = (%s) * %d = %d', $c['word'], $expr, $c['wordMultiplier'], $c['score']);
                                } else {
                                    $line = sprintf('%s = %s = %d', $c['word'], $expr, $c['score']);
                                }
                                echo '<li class="small">' . $line . '</li>';
                            }
                        }

                        $sumParts = [];
                        $totalFromDetails = 0;
                        foreach ($lastMoveDetails as $d) { $sumParts[] = (string)$d['score']; $totalFromDetails += $d['score']; }
                        $bingoBonus = null;
                        if ($lastMoveScore !== null && $lastMoveScore > $totalFromDetails) { $bingoBonus = $lastMoveScore - $totalFromDetails; }
                        if ($bingoBonus !== null) { echo '<li>Premia za wyłożenie 7 liter: ' . $bingoBonus . '</li>'; }
                        $allParts = $sumParts; if ($bingoBonus !== null) { $allParts[] = (string)$bingoBonus; }
                        $displayTotal = $lastMoveScore ?? $totalFromDetails;
                        echo '<li>Łącznie za ruch: ' . implode(' + ', $allParts) . ' = ' . $displayTotal . '</li>';
                        ?>
                    </ul>
                </div>
            <?php endif; ?>

            <h3>Dodaj ruch</h3>
            <form method="post">
                <div class="player-chooser">
                    <span class="player-chooser-label">Gracz wykonujący ruch</span>
                    <div class="player-chooser-options">
                        <label class="player-radio">
                            <input type="radio" name="player_id" value="<?=$game['player1_id']?>" <?=($nextPlayer == $game['player1_id']) ? 'checked' : ''?> >
                            <span><?=htmlspecialchars($playersById[$game['player1_id']] ?? '')?></span>
                        </label>
                        <label class="player-radio">
                            <input type="radio" name="player_id" value="<?=$game['player2_id']?>" <?=($nextPlayer == $game['player2_id']) ? 'checked' : ''?> >
                            <span><?=htmlspecialchars($playersById[$game['player2_id']] ?? '')?></span>
                        </label>
                    </div>
                </div>

                <label>Zapis ruchu (np. "WIJĘKRA 8F WIJĘ", "PASS", "ENDGAME (Ź)")</label>
                <input type="text" name="raw" required value="<?=htmlspecialchars($defaultRaw)?>">

                <label style="margin-top:8px">Stojak (oddzielne pole, opcjonalne)</label>
                <input type="text" name="rack" id="rack-input" placeholder="np. ABCDEFG lub ?ABCD" value="<?=htmlspecialchars($defaultRack)?>">
                <div id="rack-error" style="color:#a94442;margin-top:6px;display:none;font-size:0.9em"></div>

                <button class="btn" style="margin-top:8px">Zatwierdź</button>
            </form>

            <form method="post" action="undo_move.php" style="display:inline-block;margin-top:8px">
                <input type="hidden" name="game_id" value="<?=$game_id?>">
                <button type="submit" name="undo_last" class="btn btn-secondary">Cofnij ostatni ruch</button>
            </form>
        </div>

        <div class="card middle-panel">
            <h2>Gra #<?=$game_id?> — <?=htmlspecialchars($playersById[$game['player1_id']] ?? '')?> vs <?=htmlspecialchars($playersById[$game['player2_id']] ?? '')?></h2>
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
                                                <span class="tile-letter"><?=htmlspecialchars($cell['letter'])?></span>
                                                <?php $values = PolishLetters::values(); $upCh = mb_strtoupper($cell['letter'], 'UTF-8'); $val = $values[$upCh] ?? 0; ?>
                                                <span class="tile-score"><?=htmlspecialchars((string)$val)?></span>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>&nbsp;<?php endif; ?>
                                </div>
                            <?php endfor; ?>
                        <?php endfor; ?>
                    </div>

                    <p class="small">Niebieskie pola — premie literowe, czerwone — słowne.</p>
                </div>

                <div class="bag-panel">
                    <div class="bag-title">Zawartość worka</div>
                    <div class="bag-letters"><?=htmlspecialchars($bagLetters)?></div>
                </div>
            </div>
        </div>

        <div class="card right-panel">
            <h2>Ruchy</h2>
            <div class="moves-list">
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
                                if (!empty($m['rack'])) { $parts[] = $m['rack']; }
                                if (!empty($m['position'])) { $parts[] = $m['position']; }
                                if (!empty($m['word'])) { $parts[] = $m['word']; }
                                $displayWord = htmlspecialchars(implode(' ', $parts));
                                echo $displayWord;
                            } elseif ($m['type'] === 'ENDGAME') {
                                echo "<strong>ZAKOŃCZENIE GRY</strong>";
                            } else {
                                echo htmlspecialchars($m['type']);
                            }
                            ?>
                            <?php if ($m['type'] === 'EXCHANGE' && $m['rack']): ?>
                                <span class="small">(wymiana: <?=htmlspecialchars($m['rack'])?>)</span>
                            <?php endif; ?>
                            <?php if ($m['type'] === 'ENDGAME' && $m['raw_input']):
                                $rawEndgame = trim(str_ireplace('ENDGAME', '', $m['raw_input']));
                                if ($rawEndgame) { echo '<span class="small">' . htmlspecialchars($rawEndgame) . '</span>'; }
                            ?>
                            <?php endif; ?>
                            <?php if ($m['type'] === 'BADWORD'): ?><span class="small error">(BADWORD)</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($m['type'] === 'ENDGAME') {
                                if ($m['score'] < 0) { echo '<span class="error">' . $m['score'] . '</span>'; }
                                elseif ($m['score'] > 0) { echo '<span class="success">' . $m['score'] . '</span>'; }
                                else { echo $m['score']; }
                            } else { echo $m['score']; } ?>
                        </td>
                        <td><?=$m['cum_score']?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
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
    // Scroll moves list to bottom so latest moves are visible
    var moves = document.querySelector('.moves-list');
    if (moves) {
        moves.scrollTop = moves.scrollHeight;
    }

    // Client-side validation for separate rack field
    var rackInput = document.getElementById('rack-input');
    var rackError = document.getElementById('rack-error');
    var moveForm = document.querySelector('form input[name="raw"]') ? document.querySelector('form input[name="raw"]').closest('form') : null;
    var rackPattern = /^[A-Za-zĄąĆćĘęŁłŃńÓóŚśŹźŻż\?]+$/u;
    if (rackInput && moveForm) {
        rackInput.addEventListener('input', function () {
            rackError.style.display = 'none';
            rackError.textContent = '';
        });

        moveForm.addEventListener('submit', function (e) {
            var val = (rackInput.value || '').replace(/\s+/g, '');
            if (val.length > 7) {
                rackError.style.display = 'block';
                rackError.textContent = 'Stojak nie może mieć więcej niż 7 płytek.';
                e.preventDefault();
                return false;
            }
            if (val.length > 0 && !rackPattern.test(val)) {
                rackError.style.display = 'block';
                rackError.textContent = 'Stojak zawiera niedozwolone znaki.';
                e.preventDefault();
                return false;
            }
            return true;
        });
    }
});
</script>
</body>
</html>
