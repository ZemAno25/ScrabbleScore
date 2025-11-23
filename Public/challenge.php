<?php
// public/challenge.php
//
// Obsługa kwestionowania ostatniego ruchu w grze.
// Sprawdza słowo główne i wszystkie krzyżówki
// na podstawie aktualnego stanu planszy i słownika OSPS.

require_once __DIR__ . '/../src/Repositories.php';
require_once __DIR__ . '/../src/Board.php';
require_once __DIR__ . '/../src/Scorer.php';

$game_id = (int)($_POST['game_id'] ?? $_GET['game_id'] ?? 0);
if ($game_id <= 0) {
    http_response_code(400);
    echo 'Brak identyfikatora gry.';
    exit;
}

$game = GameRepo::get($game_id);
if (!$game) {
    http_response_code(404);
    echo 'Brak gry.';
    exit;
}

$moves = MoveRepo::byGame($game_id);

// Zmienne używane w szablonie
$result = null;
$lastMove = null;
$illegalWords = [];
$allWords = [];
$errorDuringPlacement = null;

if (count($moves) === 0) {
    $result = 'empty';
} else {
    $lastMove = $moves[count($moves) - 1];

    // Kwestionujemy tylko ruchy typu PLAY
    if ($lastMove['type'] !== 'PLAY') {
        $result = 'not_play';
    } else {
        // Odtwórz planszę do momentu PRZED ostatnim ruchem
        $board = new Board();
        $scorer = new Scorer($board);

        $movesCount = count($moves);
        for ($i = 0; $i < $movesCount - 1; $i++) {
            $m = $moves[$i];
            if ($m['type'] === 'PLAY' && $m['position'] && $m['word']) {
                try {
                    $scorer->placeAndScore($m['position'], $m['word']);
                } catch (Throwable $e) {
                    // Historyczne błędy nie powinny się zdarzyć, ale nie blokujemy kwestionowania.
                }
            }
        }

        // Zastosuj ostatni ruch na tymczasowej planszy, żeby ustalić słowa
        try {
            $placement = $scorer->placeAndScore($lastMove['position'], $lastMove['word']);

            // Słowo główne
            [$row, $col, $orient] = Board::coordToXY($lastMove['position']);

            $size = $board->size;
            $dr = $orient === 'H' ? 0 : 1;
            $dc = $orient === 'H' ? 1 : 0;

            // Cofnij do początku słowa głównego
            $r = $row;
            $c = $col;
            while ($r - $dr >= 0 && $r - $dr < $size &&
                   $c - $dc >= 0 && $c - $dc < $size &&
                   $board->cells[$r - $dr][$c - $dc]['letter'] !== null) {
                $r -= $dr;
                $c -= $dc;
            }

            $mainWord = '';
            // Idź do przodu zbierając litery słowa głównego
            while ($r >= 0 && $r < $size &&
                   $c >= 0 && $c < $size &&
                   $board->cells[$r][$c]['letter'] !== null) {
                $mainWord .= $board->cells[$r][$c]['letter'];
                $r += $dr;
                $c += $dc;
            }

            if ($mainWord !== '') {
                $allWords[] = mb_strtoupper($mainWord, 'UTF-8');
            }

            // Krzyżówki – dla każdego nowo położonego kafelka
            foreach ($placement->placed as [$pr, $pc]) {
                if ($orient === 'H') {
                    $cdr = 1;
                    $cdc = 0; // słowo pionowe
                } else {
                    $cdr = 0;
                    $cdc = 1; // słowo poziome
                }

                // Cofnij do początku słowa krzyżującego się
                $r = $pr;
                $c = $pc;
                while ($r - $cdr >= 0 && $r - $cdr < $size &&
                       $c - $cdc >= 0 && $c - $cdc < $size &&
                       $board->cells[$r - $cdr][$c - $cdc]['letter'] !== null) {
                    $r -= $cdr;
                    $c -= $cdc;
                }

                $word = '';
                while ($r >= 0 && $r < $size &&
                       $c >= 0 && $c < $size &&
                       $board->cells[$r][$c]['letter'] !== null) {
                    $word .= $board->cells[$r][$c]['letter'];
                    $r += $cdr;
                    $c += $cdc;
                }

                if (mb_strlen($word, 'UTF-8') > 1) {
                    $allWords[] = mb_strtoupper($word, 'UTF-8');
                }
            }

            // Usuń duplikaty
            $allWords = array_values(array_unique($allWords));

            // Sprawdź słownik OSPS
            $pdo = Database::get();
            $stmt = $pdo->prepare('SELECT 1 FROM lexicon_words WHERE word = :w');
            foreach ($allWords as $w) {
                $stmt->execute([':w' => $w]);
                if (!$stmt->fetchColumn()) {
                    $illegalWords[] = $w;
                }
            }

            if (count($illegalWords) > 0) {
                // Skuteczne kwestionowanie: oznacz ruch jako BADWORD,
                // punkty za ruch cofnięte (w tym wierszu),
                // litery znikają z planszy (logika odtwarzania planszy).
                $result = 'success';
                $pdo->beginTransaction();
                $upd = $pdo->prepare(
                    'UPDATE moves
                     SET type = :t,
                         score = 0,
                         cum_score = cum_score - :delta
                     WHERE id = :id'
                );
                $upd->execute([
                    ':t'     => 'BADWORD',
                    ':delta' => (int)$lastMove['score'],
                    ':id'    => (int)$lastMove['id'],
                ]);
                $pdo->commit();
            } else {
                $result = 'fail';
            }
        } catch (Throwable $e) {
            $errorDuringPlacement = $e->getMessage();
            $result = 'error';
        }
    }
}

// Jeżeli masz config.php używany w innych plikach publicznych:
$cfg = require __DIR__ . '/../config.php';
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Kwestionowanie słowa — gra #<?=htmlspecialchars((string)$game_id)?></title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="container">
    <h1>Kwestionowanie słowa — gra #<?=htmlspecialchars((string)$game_id)?></h1>

    <div class="card">
        <?php if ($result === 'empty'): ?>
            <p>W tej grze nie wykonano jeszcze żadnego ruchu.</p>

        <?php elseif ($result === 'not_play'): ?>
            <p>Ostatni zapisany ruch nie jest wyłożeniem słowa, więc nie można go kwestionować.</p>

        <?php elseif ($result === 'success'): ?>
            <p>Kwestionowanie było <strong>skuteczne</strong>. Ruch
                <strong><?=htmlspecialchars($lastMove['raw_input'] ?? '')?></strong>
                został oznaczony w bazie jako <strong>BADWORD</strong>
                (nieprawidłowy ruch — <strong>BADMOVE</strong>),
                punkty za ten ruch anulowano,
                a wyłożone litery zostaną usunięte przy ponownym odtworzeniu planszy.</p>
            <?php if (!empty($illegalWords)): ?>
                <p>Następujące słowa nie zostały znalezione w słowniku OSPS:</p>
                <ul>
                    <?php foreach ($illegalWords as $w): ?>
                        <li><?=htmlspecialchars($w)?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

        <?php elseif ($result === 'fail'): ?>
            <p>Kwestionowanie było <strong>nieskuteczne</strong>. Wszystkie słowa
                utworzone w ostatnim ruchu znajdują się w słowniku OSPS.</p>
            <?php if (!empty($allWords)): ?>
                <p>Sprawdzone słowa:</p>
                <ul>
                    <?php foreach ($allWords as $w): ?>
                        <li><?=htmlspecialchars($w)?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

        <?php elseif ($result === 'error'): ?>
            <p>Wystąpił błąd podczas analizy ostatniego ruchu.</p>
            <?php if ($errorDuringPlacement): ?>
                <p>Szczegóły: <?=htmlspecialchars($errorDuringPlacement)?></p>
            <?php endif; ?>

        <?php else: ?>
            <p>Nieoczekiwany stan kwestionowania.</p>
        <?php endif; ?>
    </div>

    <p><a class="btn" href="play.php?game_id=<?=htmlspecialchars((string)$game_id)?>">Powrót do gry</a></p>
</div>
</body>
</html>
