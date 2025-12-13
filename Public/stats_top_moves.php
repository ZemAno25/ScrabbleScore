<?php
require_once __DIR__ . '/../src/Repositories.php';
$pdo = Database::get();
$topMoves = $pdo->query("
    SELECT m.game_id,
           g.started_at,
           m.move_no,
           p.nick,
           m.word,
           m.position,
           m.score
    FROM moves m
    JOIN games g ON g.id = m.game_id
    JOIN players p ON p.id = m.player_id
    WHERE m.type = 'PLAY'
    ORDER BY m.score DESC
    LIMIT 20
")->fetchAll();
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Najwyżej punktowane ruchy</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="container container-narrow">
    <h1>Najwyżej punktowane ruchy</h1>
    <div class="card">
        <table>
            <tr>
                <th>Gra</th>
                <th>Data gry</th>
                <th>#</th>
                <th>Gracz</th>
                <th>Pozycja</th>
                <th>Słowo</th>
                <th>+pkt</th>
                <th></th>
            </tr>
            <?php foreach ($topMoves as $m): ?>
                <?php $dateFmt = $m['started_at'] ? date('Y-m-d H:i', strtotime($m['started_at'])) : '—'; ?>
                <tr>
                    <td><?= $m['game_id'] ?></td>
                    <td><?= htmlspecialchars($dateFmt) ?></td>
                    <td><?= $m['move_no'] ?></td>
                    <td><?= htmlspecialchars($m['nick']) ?></td>
                    <td><?= $m['position'] ?></td>
                    <td><?= htmlspecialchars($m['word']) ?></td>
                    <td><?= $m['score'] ?></td>
                    <td><a class="btn" href="stats_games.php?game_id=<?= $m['game_id'] ?>#viewer">Otwórz</a></td>
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
