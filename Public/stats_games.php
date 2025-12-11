<?php
require_once __DIR__ . '/../src/Repositories.php';
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
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Przegląd gier</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="container container-wide">
    <h1>Przegląd gier</h1>
    <div class="card">
        <table class="table-compact">
            <tr>
                <th>ID gry</th>
                <th>Gracze</th>
                <th>Data</th>
                <th>Wynik gracza 1</th>
                <th>Wynik gracza 2</th>
                <th>Przewaga</th>
                <th></th>
            </tr>
            <?php foreach ($games as $g): ?>
                <?php
                $score1 = (int)($g['score1'] ?? 0);
                $score2 = (int)($g['score2'] ?? 0);
                $diff   = abs($score1 - $score2);
                $dateFmt = $g['started_at'] ? date('Y-m-d H:i', strtotime($g['started_at'])) : '—';
                ?>
                <tr>
                    <td><?= $g['id'] ?></td>
                    <td><?= htmlspecialchars($g['player1']) ?> vs <?= htmlspecialchars($g['player2']) ?></td>
                    <td><?= htmlspecialchars($dateFmt) ?></td>
                    <td><?= $score1 ?></td>
                    <td><?= $score2 ?></td>
                    <td><?= $diff ?></td>
                    <td><a class="btn" href="play.php?game_id=<?= $g['id'] ?>">Otwórz</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="stats.php">Inne raporty</a>
        <a class="btn" href="index.php">Strona główna</a>
    </div>
</div>
</body>
</html>
