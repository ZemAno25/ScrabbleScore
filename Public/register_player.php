<?php
require_once __DIR__.'/../src/Repositories.php';
$msg = null; $err=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $nick = trim($_POST['nick'] ?? '');
    if ($nick === '') { $err='Podaj nick.'; }
    else {
        try {
            PlayerRepo::create($nick);
            $msg = 'Zarejestrowano gracza.';
        } catch (Throwable $e) {
            $err = 'Błąd: ' . $e->getMessage();
        }
    }
}
$players = PlayerRepo::all();
?>
<!doctype html><html lang="pl"><head>
<meta charset="utf-8"><title>Rejestracja gracza</title>
<link rel="stylesheet" href="../assets/style.css">
</head><body><div class="container">
<h1>Zarejestruj gracza</h1>
<div class="card">
<form method="post">
<label>Nick</label>
<input name="nick" maxlength="40" required>
<button class="btn" style="margin-top:8px">Zapisz</button>
</form>
<?php if($msg): ?><div class="success"><?=htmlspecialchars($msg)?></div><?php endif; ?>
<?php if($err): ?><div class="error"><?=htmlspecialchars($err)?></div><?php endif; ?>
</div>

<div class="card">
<h2>Gracze</h2>
<table><tr><th>ID</th><th>Nick</th></tr>
<?php foreach($players as $p): ?>
<tr><td><?=$p['id']?></td><td><?=htmlspecialchars($p['nick'])?></td></tr>
<?php endforeach; ?>
</table>
</div>
<p><a class="btn" href="index.php">Powrót</a></p>
</div></body></html>
