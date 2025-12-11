<?php
require_once __DIR__ . '/../src/Repositories.php';
$pdo = Database::get();

$playersById = [];
foreach (PlayerRepo::all() as $p) {
    $playersById[$p['id']] = $p['nick'];
}

function ensurePlayerStats(array &$stats, int $playerId): void
{
    if (!isset($stats[$playerId])) {
        $stats[$playerId] = [
            'player_id'        => $playerId,
            'total_points'     => 0,
            'max_points_game'  => 0,
            'wins'             => 0,
            'losses'           => 0,
            'draws'            => 0,
            'bingos'           => 0,
            'move_count'       => 0,
            'move_sum'         => 0,
            'max_move_points'  => 0,
        ];
    }
}

$stats = [];

$scoreRows = $pdo->query("
    SELECT g.id,
           g.player1_id,
           g.player2_id,
           COALESCE(MAX(m.cum_score) FILTER (WHERE m.player_id = g.player1_id), 0) AS score1,
           COALESCE(MAX(m.cum_score) FILTER (WHERE m.player_id = g.player2_id), 0) AS score2
    FROM games g
    LEFT JOIN moves m ON m.game_id = g.id
    GROUP BY g.id, g.player1_id, g.player2_id
")->fetchAll();

foreach ($scoreRows as $row) {
    $p1 = (int)$row['player1_id'];
    $p2 = (int)$row['player2_id'];
    $s1 = (int)$row['score1'];
    $s2 = (int)$row['score2'];

    ensurePlayerStats($stats, $p1);
    ensurePlayerStats($stats, $p2);

    $stats[$p1]['total_points']    += $s1;
    $stats[$p2]['total_points']    += $s2;
    $stats[$p1]['max_points_game']  = max($stats[$p1]['max_points_game'], $s1);
    $stats[$p2]['max_points_game']  = max($stats[$p2]['max_points_game'], $s2);

    if ($s1 > $s2) {
        $stats[$p1]['wins']++;
        $stats[$p2]['losses']++;
    } elseif ($s2 > $s1) {
        $stats[$p2]['wins']++;
        $stats[$p1]['losses']++;
    } else {
        $stats[$p1]['draws']++;
        $stats[$p2]['draws']++;
    }
}

$bingoRows = $pdo->query("
    SELECT player_id,
           COUNT(*) AS cnt
    FROM moves
    WHERE type = 'PLAY'
      AND word IS NOT NULL
      AND length(regexp_replace(word, '[()]+', '', 'g')) >= 7
    GROUP BY player_id
")->fetchAll();

foreach ($bingoRows as $row) {
    $pid = (int)$row['player_id'];
    ensurePlayerStats($stats, $pid);
    $stats[$pid]['bingos'] = (int)$row['cnt'];
}

$moveRows = $pdo->query("
    SELECT player_id,
           COUNT(*) AS moves,
           COALESCE(SUM(score), 0) AS score_sum,
           COALESCE(MAX(score), 0) AS max_score
    FROM moves
    GROUP BY player_id
")->fetchAll();

foreach ($moveRows as $row) {
    $pid = (int)$row['player_id'];
    ensurePlayerStats($stats, $pid);
    $stats[$pid]['move_count']      = (int)$row['moves'];
    $stats[$pid]['move_sum']        = (int)$row['score_sum'];
    $stats[$pid]['max_move_points'] = (int)$row['max_score'];
}

$stats = array_filter($stats, function (array $s): bool {
    return $s['total_points'] > 0 || $s['move_count'] > 0;
});

usort($stats, function (array $a, array $b): int {
    return $b['total_points'] <=> $a['total_points'];
});
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Charakterystyki wydajnościowe graczy</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="container container-wide">
    <h1>Charakterystyki wydajnościowe graczy</h1>
    <div class="card">
        <table class="table-compact">
            <tr>
                <th>Gracz</th>
                <th>Suma punktów</th>
                <th>Maks. punktów w grze</th>
                <th>Siódemki</th>
                <th>Wygrane</th>
                <th>Przegrane</th>
                <th>Remisy</th>
                <th>Śr. pkt / ruch</th>
                <th>Maks. pkt w ruchu</th>
            </tr>
            <?php foreach ($stats as $s): ?>
                <?php
                $pid = $s['player_id'];
                $avgMove = $s['move_count'] > 0 ? round($s['move_sum'] / $s['move_count'], 2) : 0;
                $nick = $playersById[$pid] ?? ('ID ' . $pid);
                ?>
                <tr>
                    <td><?= htmlspecialchars($nick) ?></td>
                    <td><?= $s['total_points'] ?></td>
                    <td><?= $s['max_points_game'] ?></td>
                    <td><?= $s['bingos'] ?></td>
                    <td><?= $s['wins'] ?></td>
                    <td><?= $s['losses'] ?></td>
                    <td><?= $s['draws'] ?></td>
                    <td><?= $avgMove ?></td>
                    <td><?= $s['max_move_points'] ?></td>
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
