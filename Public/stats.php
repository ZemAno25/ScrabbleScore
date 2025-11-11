<?php
require_once __DIR__.'/../src/Repositories.php';
$pdo = Database::get();

$gamesTop = $pdo->query("SELECT g.id, p1.nick AS p1, p2.nick AS p2, COALESCE(MAX(m.cum_score) FILTER (WHERE m.player_id=g.player1_id),0)+COALESCE(MAX(m.cum_score) FILTER (WHERE m.player_id=g.player2_id),0) AS total
FROM games g
LEFT JOIN moves m ON m.game_id=g.id
JOIN players p1 ON p1.id=g.player1_id
JOIN players p2 ON p2.id=g.player2_id
GROUP BY g.id, p1.nick, p2.nick
ORDER BY total DESC NULLS LAST
LIMIT 20")->fetchAll();

$topMoves = $pdo->query("SELECT m.game_id, m.move_no, p.nick, m.word, m.position, m.score
FROM moves m JOIN players p ON p.id=m.player_id
WHERE m.type='PLAY'
ORDER BY m.score DESC
LIMIT 20")->fetchAll();

$bingos = $pdo->query("SELECT p.nick, COUNT(*) AS cnt
FROM moves m JOIN players p ON p.id=m.player_id
WHERE m.type='PLAY' AND m.word IS NOT NULL AND length(regexp_replace(m.word,'[()]+','','g')) >= 7
GROUP BY p.nick
ORDER BY cnt DESC")->fetchAll();

$games = GameRepo::list();
?>
<!doctype html><html lang="pl"><head>
<meta charset="utf-8"><title>Statystyki</title>
<link rel="stylesheet" href="../assets/style.css">
</head><body><div class="container">
<h1>Statystyki rozgrywek</h1>

<div class="card">
<h2>Przegląd gier</h2>
<table><tr><th>ID</th><th>Gracze</th><th>Data</th><th></th></tr>
<?php foreach($games as $g): ?>
<tr>
<td><?=$g['id']?></td>
<td><?=htmlspecialchars($g['player1'])?> vs <?=htmlspecialchars($g['player2'])?></td>
<td><?=$g['started_at']?></td>
<td><a class="btn" href="play.php?game_id=<?=$g['id']?>">Otwórz</a></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="grid">
<div class="card">
<h2>Gry o największej sumie punktów</h2>
<table><tr><th>Gra</th><th>Gracze</th><th>Suma</th></tr>
<?php foreach($gamesTop as $g): ?>
<tr><td><?=$g['id']?></td><td><?=htmlspecialchars($g['p1'])?> vs <?=htmlspecialchars($g['p2'])?></td><td><?=$g['total']?></td></tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<h2>Najwyżej punktowane ruchy</h2>
<table><tr><th>Gra</th><th>#</th><th>Gracz</th><th>Pozycja</th><th>Słowo</th><th>+pkt</th></tr>
<?php foreach($topMoves as $m): ?>
<tr><td><?=$m['game_id']?></td><td><?=$m['move_no']?></td><td><?=htmlspecialchars($m['nick'])?></td><td><?=$m['position']?></td><td><?=htmlspecialchars($m['word'])?></td><td><?=$m['score']?></td></tr>
<?php endforeach; ?>
</table>
</div>
</div>

<div class="card">
<h2>Ilość siódemek (bingo) wg graczy</h2>
<table><tr><th>Gracz</th><th>Liczba</th></tr>
<?php foreach($bingos as $b): ?>
<tr><td><?=htmlspecialchars($b['nick'])?></td><td><?=$b['cnt']?></td></tr>
<?php endforeach; ?>
</table>
</div>

<p><a class="btn" href="index.php">Powrót</a></p>
</div></body></html>
