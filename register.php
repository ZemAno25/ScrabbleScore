<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rejestracja - Scrabble</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f4; display: grid; place-items: center; min-height: 90vh; }
        form { background: #fff; border: 1px solid #ccc; padding: 25px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        div { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="password"] { width: 300px; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background-color: #0056b3; }
        .message { padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>

    <form action="handle_register.php" method="POST">
        <h2>Zarejestruj się w grze Scrabble</h2>
        
        <?php
        // Wyświetlanie komunikatów o błędach lub sukcesie, jeśli zostaną przekazane w URL
        if (isset($_GET['status'])) {
            if ($_GET['status'] == 'success') {
                echo '<div class="message success">Rejestracja zakończona sukcesem! Możesz się teraz zalogować.</div>';
            } elseif ($_GET['status'] == 'error' && isset($_GET['msg'])) {
                echo '<div class="message error">' . htmlspecialchars($_GET['msg']) . '</div>';
            }
        }
        ?>

        <div>
            <label for="username">Nazwa użytkownika:</label>
            <input type="text" id="username" name="username" required>
        </div>
        <div>
            <label for="password">Hasło:</label>
            <input type="password" id="password" name="password" required>
        </div>
        <div>
            <button type="submit">Zarejestruj</button>
        </div>
    </form>

</body>
</html>
