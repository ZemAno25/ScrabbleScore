<?php
require_once __DIR__ . '/../src/Repositories.php';
require_once __DIR__ . '/../src/Board.php';
require_once __DIR__ . '/../src/Scorer.php';
require_once __DIR__ . '/../src/PolishLetters.php';

function viewerPremiumClass(Board $board, int $row, int $col): string
{
    $ps = $board->W[$row][$col] ?? 1;
    $pl = $board->L[$row][$col] ?? 1;
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

function viewerSnapshot(Board $board): array
{
    $snapshot = [];
    for ($r = 0; $r < $board->size; $r++) {
        $row = [];
        for ($c = 0; $c < $board->size; $c++) {
            $cell = $board->cells[$r][$c];
            $row[] = [
                'letter'  => $cell['letter'],
                'isBlank' => !empty($cell['isBlank']),
            ];
        }
        $snapshot[] = $row;
    }
    return $snapshot;
}

function viewerRenderGridHtml(array $stateRows, Board $premiumLayout, array $letterValues): string
{
    ob_start();
    for ($r = 0; $r < 15; $r++) {
        echo '<div class="coord coord-row">' . ($r + 1) . '</div>';
        for ($c = 0; $c < 15; $c++) {
            $cell      = $stateRows[$r][$c];
            $hasLetter = !empty($cell['letter']);
            $premium   = viewerPremiumClass($premiumLayout, $r, $c);
            $classes   = 'cell ' . ($hasLetter ? 'tile' : $premium);
            echo '<div class="' . $classes . '">';
            if ($hasLetter) {
                if (!empty($cell['isBlank'])) {
                    $ch = mb_strtolower($cell['letter'], 'UTF-8');
                    echo '<span class="tile-blank">' . htmlspecialchars($ch, ENT_QUOTES, 'UTF-8') . '</span>';
                } else {
                    $up  = mb_strtoupper($cell['letter'], 'UTF-8');
                    $val = $letterValues[$up] ?? 0;
                    echo '<div class="tile-inner">';
                    echo '<span class="tile-letter">' . htmlspecialchars($cell['letter'], ENT_QUOTES, 'UTF-8') . '</span>';
                    echo '<span class="tile-score">' . htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') . '</span>';
                    echo '</div>';
                }
            } else {
                echo '&nbsp;';
            }
            echo '</div>';
        }
    }
    return ob_get_clean();
}

$pdo = Database::get();
$games = $pdo->query("
    SELECT g.id,
           g.started_at,
           p1.nick AS player1,
           p2.nick AS player2,
           COALESCE(MAX(m.cum_score) FILTER (WHERE m.player_id = g.player1_id), 0) AS score1,
           COALESCE(MAX(m.cum_score) FILTER (WHERE m.player_id = g.player2_id), 0) AS score2
    FROM games g
    JOIN players p1 ON p1.id = g.player1_id
    JOIN players p2 ON p2.id = g.player2_id
    LEFT JOIN moves m ON m.game_id = g.id
    GROUP BY g.id, g.started_at, g.player1_id, g.player2_id, p1.nick, p2.nick
    ORDER BY g.id DESC
")->fetchAll();

$viewGameId        = (int)($_GET['game_id'] ?? 0);
$viewerGame        = null;
$viewerMoves       = [];
$viewerError       = null;
$boardHtmlStates   = [];
$stateMeta         = [];
$defaultStateIndex = 0;
$initialGridHtml   = '';
$viewerScores      = ['p1' => 0, 'p2' => 0];
$navDisabled       = true;
$columns           = range('A', 'O');

if ($viewGameId > 0) {
    $stmt = $pdo->prepare("
        SELECT g.*,
               p1.nick AS player1_name,
               p2.nick AS player2_name
        FROM games g
        JOIN players p1 ON p1.id = g.player1_id
        JOIN players p2 ON p2.id = g.player2_id
        WHERE g.id = ?
    ");
    $stmt->execute([$viewGameId]);
    $viewerGame = $stmt->fetch();

    if ($viewerGame) {
        $viewerMoves = MoveRepo::byGame($viewGameId);
        $scoresMap   = MoveRepo::lastCumScores($viewGameId);
        $viewerScores['p1'] = $scoresMap[$viewerGame['player1_id']] ?? 0;
        $viewerScores['p2'] = $scoresMap[$viewerGame['player2_id']] ?? 0;

        $playbackBoard = new Board();
        $premiumBoard  = new Board();
        $scorer        = new Scorer($playbackBoard);
        $letterValues  = PolishLetters::values();

        $snapshots = [viewerSnapshot($playbackBoard)];
        $stateMeta = [[
            'move_no' => null,
            'player'  => null,
            'position'=> null,
            'word'    => null,
        ]];

        foreach ($viewerMoves as $mv) {
            if ($mv['type'] === 'PLAY' && $mv['position'] && $mv['word']) {
                try {
                    $scorer->placeAndScore($mv['position'], $mv['word']);
                } catch (Throwable $e) {
                    $viewerError = 'Nie udało się odtworzyć ruchu #' . $mv['move_no'] . ': ' . $e->getMessage();
                    break;
                }
                $snapshots[] = viewerSnapshot($playbackBoard);
                $stateMeta[] = [
                    'move_no'  => $mv['move_no'],
                    'player'   => $mv['nick'],
                    'position' => $mv['position'],
                    'word'     => $mv['word'],
                ];
            }
        }

        foreach ($snapshots as $snap) {
            $boardHtmlStates[] = viewerRenderGridHtml($snap, $premiumBoard, $letterValues);
        }

        $defaultStateIndex = count($boardHtmlStates) ? count($boardHtmlStates) - 1 : 0;
        $initialGridHtml   = $boardHtmlStates[$defaultStateIndex] ?? '';
        $navDisabled       = count($boardHtmlStates) <= 1;
    } else {
        $viewerError = 'Nie znaleziono gry #' . $viewGameId . '.';
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Przegląd gier</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body class="stats-games-page">
<div class="container container-wide">
    <h1>Przegląd gier</h1>
    <div class="stats-games-layout">
        <div class="card games-list-card">
            <h2>Przegląd gier</h2>
            <table class="table-compact">
                <tr>
                    <th>ID gry</th>
                    <th>Gracze</th>
                    <th>Data</th>
                    <th>Gracz 1</th>
                    <th>Gracz 2</th>
                    <th>Przewaga</th>
                </tr>
                <?php foreach ($games as $g): ?>
                    <?php
                    $score1  = (int)($g['score1'] ?? 0);
                    $score2  = (int)($g['score2'] ?? 0);
                    $diff    = abs($score1 - $score2);
                    $dateFmt = $g['started_at'] ? date('Y-m-d H:i', strtotime($g['started_at'])) : '—';
                    $rowHref = 'stats_games.php?game_id=' . (int)$g['id'] . '#viewer';
                    ?>
                    <tr>
                        <td><a class="row-link" href="<?= $rowHref ?>"><?= $g['id'] ?></a></td>
                        <td><a class="row-link" href="<?= $rowHref ?>"><?= htmlspecialchars($g['player1']) ?> vs <?= htmlspecialchars($g['player2']) ?></a></td>
                        <td><a class="row-link" href="<?= $rowHref ?>"><?= htmlspecialchars($dateFmt) ?></a></td>
                        <td><a class="row-link" href="<?= $rowHref ?>"><?= $score1 ?></a></td>
                        <td><a class="row-link" href="<?= $rowHref ?>"><?= $score2 ?></a></td>
                        <td><a class="row-link" href="<?= $rowHref ?>"><?= $diff ?></a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="card viewer-card" id="viewer">
    <?php if ($viewerGame): ?>
        <?php
        $dateFmt = $viewerGame['started_at']
            ? date('Y-m-d H:i', strtotime($viewerGame['started_at']))
            : '—';
        $navButtonsDisabled = $navDisabled ? 'disabled' : '';
        $initialMeta = $stateMeta[$defaultStateIndex] ?? null;
        if ($initialMeta && !empty($initialMeta['move_no'])) {
            $pieces = ['Po ruchu #' . $initialMeta['move_no']];
            if (!empty($initialMeta['player'])) {
                $pieces[] = $initialMeta['player'];
            }
            if (!empty($initialMeta['position']) && !empty($initialMeta['word'])) {
                $pieces[] = $initialMeta['position'] . ' ' . $initialMeta['word'];
            }
            $initialLabel = implode(' · ', $pieces);
        } else {
            $initialLabel = 'Początek gry';
        }
        ?>
            <h2>Gra #<?= $viewerGame['id'] ?> — <?= htmlspecialchars($viewerGame['player1_name']) ?> vs <?= htmlspecialchars($viewerGame['player2_name']) ?></h2>
            <p class="small">
                Data: <?= htmlspecialchars($dateFmt) ?> · Wynik:
                <strong><?= htmlspecialchars($viewerGame['player1_name']) ?></strong> <?= $viewerScores['p1'] ?>
                vs
                <?= $viewerScores['p2'] ?> <strong><?= htmlspecialchars($viewerGame['player2_name']) ?></strong>
            </p>
            <?php if ($viewerError): ?>
                <div class="card error" style="margin-top:12px">
                    <?= htmlspecialchars($viewerError) ?>
                </div>
            <?php endif; ?>
            <div class="viewer-layout">
                <div class="viewer-board-panel">
                    <div class="board-wrapper">
                        <div class="board-header">
                            <div class="coord-corner"></div>
                            <?php foreach ($columns as $col): ?>
                                <div class="coord coord-col"><?= $col ?></div>
                            <?php endforeach; ?>
                        </div>
                        <div class="board board-grid" id="viewer-board-grid" data-default-index="<?= $defaultStateIndex ?>">
                            <?= $initialGridHtml ?>
                        </div>
                    </div>
                    <div class="viewer-nav">
                        <button type="button" class="btn btn-secondary" data-viewer-nav="start" <?= $navButtonsDisabled ?>>Początek gry</button>
                        <button type="button" class="btn btn-secondary" data-viewer-nav="prev" <?= $navButtonsDisabled ?>>Ruch do tyłu</button>
                        <button type="button" class="btn btn-secondary" data-viewer-nav="next" <?= $navButtonsDisabled ?>>Ruch do przodu</button>
                        <button type="button" class="btn btn-secondary" data-viewer-nav="end" <?= $navButtonsDisabled ?>>Koniec gry</button>
                    </div>
                    <div class="viewer-state-label" id="viewer-state-label"><?= htmlspecialchars($initialLabel, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="viewer-moves">
                    <h3>Zapis gry</h3>
                    <div class="moves-list" id="viewer-moves-list">
                        <table>
                            <tr>
                                <th>#</th>
                                <th>Gracz</th>
                                <th>Zapis</th>
                                <th>OSPS</th>
                                <th>+pkt</th>
                                <th>Σ</th>
                            </tr>
                            <?php $playRowIndex = 0; ?>
                            <?php foreach ($viewerMoves as $m): ?>
                                <?php
                                $rowId         = 'move-row-' . $m['id'];
                                $isPlay        = $m['type'] === 'PLAY' && $m['position'] && $m['word'];
                                $playIndexAttr = '';
                                $ospsFlag      = array_key_exists('osps', $m) ? (bool)$m['osps'] : true;
                                if ($isPlay) {
                                    $playRowIndex++;
                                    $playIndexAttr = ' data-play-index="' . $playRowIndex . '"';
                                }
                                ?>
                                <tr id="<?= $rowId ?>"<?= $playIndexAttr ?>>
                                    <td><?= $m['move_no'] ?></td>
                                    <td><?= htmlspecialchars($m['nick'] ?? '') ?></td>
                                    <td>
                                        <?php
                                        if ($m['type'] === 'PLAY') {
                                            $parts = [];
                                            if (!empty($m['rack'])) {
                                                $parts[] = $m['rack'];
                                            }
                                            if (!empty($m['position'])) {
                                                $parts[] = $m['position'];
                                            }
                                            if (!empty($m['word'])) {
                                                $parts[] = $m['word'];
                                            }
                                            echo htmlspecialchars(implode(' ', $parts));
                                        } elseif ($m['type'] === 'ENDGAME') {
                                            echo '<strong>ZAKOŃCZENIE GRY</strong>';
                                        } else {
                                            echo htmlspecialchars($m['type']);
                                        }
                                        ?>
                                        <?php if ($m['type'] === 'EXCHANGE' && $m['rack']): ?>
                                            <span class="small">(wymiana: <?= htmlspecialchars($m['rack']) ?>)</span>
                                        <?php endif; ?>
                                        <?php if ($m['type'] === 'ENDGAME' && $m['raw_input']): ?>
                                            <?php
                                            $rawEndgame = trim(str_ireplace('ENDGAME', '', $m['raw_input']));
                                            if ($rawEndgame) {
                                                echo '<span class="small">' . htmlspecialchars($rawEndgame) . '</span>';
                                            }
                                            ?>
                                        <?php endif; ?>
                                        <?php if ($m['type'] === 'BADWORD'): ?>
                                            <span class="small error">(BADWORD)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $ospsFlag ? 'T' : 'N' ?></td>
                                    <td>
                                        <?php
                                        if ($m['type'] === 'ENDGAME') {
                                            if ($m['score'] < 0) {
                                                echo '<span class="error">' . $m['score'] . '</span>';
                                            } elseif ($m['score'] > 0) {
                                                echo '<span class="success">' . $m['score'] . '</span>';
                                            } else {
                                                echo $m['score'];
                                            }
                                        } else {
                                            echo $m['score'];
                                        }
                                        ?>
                                    </td>
                                    <td><?= $m['cum_score'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            </div>
        <?php if ($boardHtmlStates): ?>
            <script type="application/json" id="viewer-board-states">
                <?= json_encode($boardHtmlStates, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
            </script>
            <script type="application/json" id="viewer-board-meta">
                <?= json_encode($stateMeta, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
            </script>
        <?php endif; ?>
    <?php elseif ($viewerError): ?>
            <h2>Błąd podglądu</h2>
            <p class="small error"><?= htmlspecialchars($viewerError) ?></p>
    <?php else: ?>
            <h2>Podgląd gry</h2>
            <p>Wybierz grę z listy po lewej, aby włączyć tryb przeglądu ruch po ruchu.</p>
    <?php endif; ?>
        </div>
    </div>

    <div class="page-actions">
        <a class="btn btn-secondary" href="stats.php">Inne raporty</a>
        <a class="btn" href="index.php">Strona główna</a>
    </div>
</div>
<?php if ($viewerGame && $boardHtmlStates): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var statesScript = document.getElementById('viewer-board-states');
    var metaScript = document.getElementById('viewer-board-meta');
    if (!statesScript || !metaScript) {
        return;
    }
    var states = [];
    var meta = [];
    try {
        states = JSON.parse(statesScript.textContent || '[]');
        meta = JSON.parse(metaScript.textContent || '[]');
    } catch (err) {
        console.error('Nie udało się wczytać stanu planszy', err);
        return;
    }
    if (!states.length) {
        return;
    }
    var boardGrid = document.getElementById('viewer-board-grid');
    if (!boardGrid) {
        return;
    }
    var label = document.getElementById('viewer-state-label');
    var movesList = document.getElementById('viewer-moves-list');
    var defaultIndex = parseInt(boardGrid.getAttribute('data-default-index') || (states.length - 1), 10);
    if (isNaN(defaultIndex) || defaultIndex < 0) {
        defaultIndex = 0;
    }
    if (defaultIndex >= states.length) {
        defaultIndex = states.length - 1;
    }
    var currentIndex = defaultIndex;

    function renderState(idx) {
        if (!states[idx]) {
            return;
        }
        boardGrid.innerHTML = states[idx];
        currentIndex = idx;

        if (label) {
            var info = meta[idx] || null;
            if (info && info.move_no) {
                var pieces = ['Po ruchu #' + info.move_no];
                if (info.player) {
                    pieces.push(info.player);
                }
                if (info.position && info.word) {
                    pieces.push(info.position + ' ' + info.word);
                }
                label.textContent = pieces.join(' · ');
            } else {
                label.textContent = 'Początek gry';
            }
        }

        document.querySelectorAll('[data-play-index]').forEach(function (row) {
            row.classList.remove('current-play');
        });
        var targetRow = document.querySelector('[data-play-index="' + idx + '"]');
        if (targetRow) {
            targetRow.classList.add('current-play');
            if (movesList) {
                var rowTop = targetRow.offsetTop - movesList.offsetTop;
                var viewTop = movesList.scrollTop;
                var viewBottom = viewTop + movesList.clientHeight;
                var rowBottom = rowTop + targetRow.offsetHeight;
                if (rowTop < viewTop || rowBottom > viewBottom) {
                    movesList.scrollTop = rowTop - movesList.clientHeight / 3;
                }
            }
        }
    }

    renderState(currentIndex);

    document.querySelectorAll('[data-viewer-nav]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!states.length) {
                return;
            }
            var action = btn.getAttribute('data-viewer-nav');
            var nextIndex = currentIndex;
            if (action === 'start') {
                nextIndex = 0;
            } else if (action === 'end') {
                nextIndex = states.length - 1;
            } else if (action === 'prev' && currentIndex > 0) {
                nextIndex = currentIndex - 1;
            } else if (action === 'next' && currentIndex < states.length - 1) {
                nextIndex = currentIndex + 1;
            }
            if (nextIndex !== currentIndex) {
                renderState(nextIndex);
            }
        });
    });
});
</script>
<?php endif; ?>
</body>
</html>
