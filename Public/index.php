<?php
// public/index.php
$cfg = require __DIR__ . '/../config.php';
$base = $cfg['app']['base_url'] ?? '';
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>ScrabbleScore</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="container">
    <h1>ScrabbleScore</h1>
    <div class="menu">
        <a class="btn" href="register_player.php">Zarejestruj gracza</a>
        <a class="btn" href="new_game.php">Zarejestruj przebieg gry</a>
        <a class="btn" href="stats.php">Pokaż statystyki rozgrywek</a>
        <a class="btn" href="import_quackle.php">Wczytaj grę z Quackle</a>
        <a class="btn" href="export_quackle.php">Eksportuj do Quackle</a>
    </div>
</div>
</body>
</html>
