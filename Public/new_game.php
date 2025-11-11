<?php
require_once __DIR__.'/../src/Repositories.php';
require_once __DIR__.'/../src/Board.php';
require_once __DIR__.'/../src/Scorer.php';
require_once __DIR__.'/../src/MoveParser.php';

$players = PlayerRepo::all();
$game_id = null;
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['p1'],$_POST['p2'])) {
    $game_id = GameRepo::create((int)$_POST['p1'], (int)$_POST['p2']);
    header('Location: play.php?game_id='.$game_id);
    exit;
}
?>
<!doctype html><html lang="pl"><head>
<meta charset="utf-8"><title>Nowa gra</title>
<link rel="stylesheet" href="../assets/style.css">
</head><body><div class="container">
<h1>Utwórz nową grę</h1>
<div class="card">
<form method="post">
<div class="grid">
<div><label>Gracz 1</label><select name="p1"><?php foreach($players as $p): ?><option value="<?=$p['id']?>"><?=htmlspecialchars($p['nick'])?></option><?php endforeach; ?></select></div>
<div><label>Gracz 2</label><select name="p2"><?php foreach($players as $p): ?><option value="<?=$p['id']?>"><?=htmlspecialchars($p['nick'])?></option><?php endforeach; ?></select></div>
</div>
<button class="btn" style="margin-top:8px">Utwórz</button>
</form>
</div>

<div class="card">
<h2>Ostatnie gry</h2>
<table><tr><th>ID</th><th>Gracze</th><th>Data</th><th></th></tr>
<?php foreach(GameRepo::list() as $g): ?>
<tr>
<td><?=$g['id']?></td>
<td><?=htmlspecialchars($g['player1'])?> vs <?=htmlspecialchars($g['player2'])?></td>
<td><?=$g['started_at']?></td>
<td><a class="btn" href="play.php?game_id=<?=$g['id']?>">Otwórz</a></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<p><a class="btn" href="index.php">Powrót</a></p>
</div></body></html>
