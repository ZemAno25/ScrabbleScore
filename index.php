<?php
// index.php
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>ScrabbleScore - Menu</title>
    <link rel="stylesheet" href="public/style.css">
    <style>
        .menu-container { text-align: center; background: white; padding: 40px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); max-width: 400px; margin: 50px auto; }
        .button { display: block; padding: 15px 30px; margin: 15px 0; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; transition: background-color 0.3s; }
        .button:hover { background-color: #0056b3; }
        .disabled { opacity: 0.6; cursor: not-allowed; }
    </style>
</head>
<body>
    <div class="menu-container">
        <h1>ScrabbleScore</h1>
        <nav>
            <a href="register_player.php" class="button">1. Zarejestruj gracza</a>
            <a href="start_game.php" class="button">2. Zarejestruj przebieg gry</a>
            <a href="stats.php" class="button disabled" onclick="alert('Statystyki będą dostępne w kolejnym kroku.')">3. Pokaż statystyki rozgrywek</a>
        </nav>
    </div>
</body>
</html>