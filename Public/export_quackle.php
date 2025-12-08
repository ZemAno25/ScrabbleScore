<?php
require_once __DIR__ . '/../src/Repositories.php';
require_once __DIR__ . '/../src/Board.php';
require_once __DIR__ . '/../src/Scorer.php';

function toQuackleWord(string $internal): string {
    // Replace each "(existing)" fragment with dots so Quackle treats them as already on board.
    return preg_replace_callback(
        '/\(([^)]+)\)/u',
        function (array $m): string {
            $len = mb_strlen($m[1], 'UTF-8');
            return str_repeat('.', $len);
        },
        $internal
    );
}

$games = GameRepo::list();
$players = [];
foreach (PlayerRepo::all() as $p) {
    $players[$p['id']] = $p['nick'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['game_id'])) {
    $game_id = (int)$_POST['game_id'];
    $game = GameRepo::get($game_id);
    if (!$game) {
        http_response_code(404);
        echo 'Gra nie znaleziona.';
        exit;
    }

    $moves = MoveRepo::byGame($game_id);

    $board = new Board();
    $scorer = new Scorer($board);

    $lines = [];
    // Header lines (match Quackle expected format)
    $p1nickRaw = $players[$game['player1_id']] ?? 'PLAYER1';
    $p2nickRaw = $players[$game['player2_id']] ?? 'PLAYER2';
    // Title-case the nick for nicer output (IRENEUSZ -> Ireneusz)
    $p1nick = mb_convert_case(mb_strtolower($p1nickRaw, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    $p2nick = mb_convert_case(mb_strtolower($p2nickRaw, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    // character encoding header + player header lines (name repeated as Quackle examples)
    $lines[] = '#character-encoding UTF-8';
    $lines[] = '#player1 ' . $p1nick . ' ' . $p1nick;
    $lines[] = '#player2 ' . $p2nick . ' ' . $p2nick;

    foreach ($moves as $m) {
        $nick = $m['nick'] ?? ($players[$m['player_id']] ?? '');
        $formatScore = static function(int $val): string {
            return $val >= 0 ? '+' . $val : (string)$val;
        };

        if ($m['type'] === 'PLAY') {
            $rack = isset($m['rack']) ? str_replace(' ', '', $m['rack']) : '';
            $pos  = $m['position'] ?? '';
            $internal = $m['word'] ?? '';
            $quackWord = toQuackleWord($internal);
            $score = (int)$m['score'];
            $total = (int)$m['cum_score'];
            // keep empty tokens explicit so spacing matches Quackle (empty rack => leading space)
            $body = $rack . ' ' . $pos . ' ' . $quackWord;
            $lines[] = '>' . $nick . ': ' . $body . ' ' . $formatScore($score) . ' ' . $total;

            // apply to board so subsequent moves see placed letters
            try {
                $scorer->placeAndScore($pos, $internal);
            } catch (Throwable $e) {
                // ignore placement errors for export
            }
        } elseif ($m['type'] === 'EXCHANGE') {
            $rack = isset($m['rack']) ? str_replace(' ', '', $m['rack']) : '';
            $ex = $m['word'] ?? '';
            $minus = '-' . $ex;
            $score = (int)$m['score'];
            $total = (int)$m['cum_score'];
            $body = $rack . ' ' . $minus;
            $lines[] = '>' . $nick . ': ' . $body . ' ' . $formatScore($score) . ' ' . $total;
        } elseif ($m['type'] === 'PASS') {
            $rack = isset($m['rack']) ? str_replace(' ', '', $m['rack']) : '';
            $score = (int)$m['score'];
            $total = (int)$m['cum_score'];
            $body = $rack . ' -';
            $lines[] = '>' . $nick . ': ' . $body . ' ' . $formatScore($score) . ' ' . $total;
        } elseif ($m['type'] === 'ENDGAME') {
            // try to extract rack from word/raw_input
            $endRack = '';
            if (!empty($m['word'])) {
                if (preg_match('/^\(([^)]+)\)$/u', $m['word'], $mm)) {
                    $endRack = $mm[1];
                } else {
                    $endRack = trim($m['word']);
                }
            }
            if ($endRack === '' && !empty($m['rack'])) {
                $endRack = $m['rack'];
            }
            if ($endRack === '' && !empty($m['raw_input'])) {
                $s = trim(str_ireplace('ENDGAME', '', $m['raw_input']));
                $s = trim($s);
                if (preg_match('/^\(?([^)]*)\)?$/u', $s, $mm)) {
                    $endRack = $mm[1];
                } else {
                    $endRack = $s;
                }
            }
            $score = (int)$m['score'];
            $total = (int)$m['cum_score'];

            $body = '(' . $endRack . ')';

            $lines[] = '>' . $nick . ': ' . $body . ' ' . $formatScore($score) . ' ' . $total;
        } else {
            // Unknown type – fallback to raw_input if available
            if (!empty($m['raw_input'])) {
                $lines[] = '>' . $nick . ': ' . trim($m['raw_input']);
            }
        }
    }

    // prepare filename
    $started = $game['started_at'] ?? null;
    $date = $started ? date('Ymd', strtotime($started)) : date('Ymd');
    $idp = str_pad((string)$game['id'], 4, '0', STR_PAD_LEFT);
    $fn = sprintf('%s-%s_%s-%s.gcg', $date, $idp, $p1nick, $p2nick);

    $content = implode("\n", $lines) . "\n";

    // If preview requested, show text in the page; otherwise force download
    if (!empty($_POST['preview'])) {
        $previewGcg = $content;
        $previewFilename = $fn;
        $previewGameId = $game_id;
    } else {
        header('Content-Type: application/octet-stream; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fn . '"');
        echo $content;
        exit;
    }
}

$gamesList = GameRepo::list();
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Eksportuj do Quackle</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="container">
    <h1>Eksport do Quackle (.gcg)</h1>

    <div class="card">
        <form method="post">
            <label>Wybierz grę do eksportu</label>
            <select name="game_id">
                <?php foreach ($gamesList as $g): ?>
                    <option value="<?= htmlspecialchars($g['id']) ?>">#<?= htmlspecialchars($g['id']) ?> — <?= htmlspecialchars($g['player1']) ?> vs <?= htmlspecialchars($g['player2']) ?> — <?= htmlspecialchars($g['started_at']) ?></option>
                <?php endforeach; ?>
            </select>
            <div style="margin-top:12px; display:flex; gap:8px">
                <button class="btn">Eksportuj</button>
                <button type="submit" name="preview" value="1" class="btn btn-secondary">Podgląd</button>
            </div>
        </form>
    </div>

    <?php if (!empty($previewGcg)): ?>
        <div class="card">
            <h3>Podgląd wygenerowanego pliku (.gcg): <?= htmlspecialchars($previewFilename) ?></h3>
            <textarea style="width:100%;height:360px;font-family:monospace;" readonly><?= htmlspecialchars($previewGcg) ?></textarea>
            <div style="margin-top:8px">
                <form method="post" style="display:inline">
                    <input type="hidden" name="game_id" value="<?= htmlspecialchars((string)$previewGameId) ?>">
                    <button class="btn">Pobierz</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <p><a class="btn" href="index.php">Powrót</a></p>
</div>
</body>
</html>
