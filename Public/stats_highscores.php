<?php
require_once __DIR__ . '/../src/Repositories.php';
$pdo = Database::get();
$gamesTop = $pdo->query("
    SELECT g.id,
           g.started_at,
           p1.nick AS p1,
           p2.nick AS p2,
           COALESCE(MAX(m.cum_score) FILTER (WHERE m.player_id = g.player1_id), 0)
             + COALESCE(MAX(m.cum_score) FILTER (WHERE m.player_id = g.player2_id), 0) AS total
    FROM games g
    LEFT JOIN moves m ON m.game_id = g.id
    JOIN players p1 ON p1.id = g.player1_id
    JOIN players p2 ON p2.id = g.player2_id
    GROUP BY g.id, g.started_at, p1.nick, p2.nick
    ORDER BY total DESC NULLS LAST
    LIMIT 20
")->fetchAll();
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Najwyższe wyniki gier</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="container container-wide">
    <h1>Gry o największej sumie punktów</h1>
    <div class="card">
        <table class="table-compact">
            <tr>
                <th>Gra</th>
                <th>Data</th>
                <th>Gracze</th>
                <th>Suma</th>
                <th></th>
            </tr>
            <?php foreach ($gamesTop as $g): ?>
                <?php $dateFmt = $g['started_at'] ? date('Y-m-d H:i', strtotime($g['started_at'])) : '—'; ?>
                <tr>
                    <td><?= $g['id'] ?></td>
                    <td><?= htmlspecialchars($dateFmt) ?></td>
                    <td><?= htmlspecialchars($g['p1']) ?> vs <?= htmlspecialchars($g['p2']) ?></td>
                    <td><?= $g['total'] ?></td>
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
