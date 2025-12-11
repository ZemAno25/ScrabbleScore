<?php
$reports = [
    ['href' => 'stats_games.php',       'label' => 'Przegląd gier'],
    ['href' => 'stats_highscores.php',  'label' => 'Gry o największej sumie punktów'],
    ['href' => 'stats_top_moves.php',   'label' => 'Najwyżej punktowane ruchy'],
    ['href' => 'stats_bingos.php',      'label' => 'Charakterystyki wydajnościowe graczy'],
];
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Statystyki</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="container container-narrow">
    <h1>Statystyki rozgrywek</h1>
    <div class="card">
        <h2>Raporty</h2>
        <p>Wybierz zestawienie, aby zobaczyć szczegóły.</p>
        <div class="report-links">
            <?php foreach ($reports as $report): ?>
                <a class="btn" href="<?= $report['href'] ?>">
                    <?= htmlspecialchars($report['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="page-actions">
        <a class="btn" href="index.php">Powrót do menu</a>
    </div>
</div>
</body>
</html>
