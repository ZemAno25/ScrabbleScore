-- Upewnij się, że wykonujesz to w bazie 'scrabblegames'
-- i jako użytkownik 'scrabble_usr' lub superużytkownik.

-- Tabela graczy (do rejestracji)
CREATE TABLE players (
    player_id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL, -- Do przechowywania hashowanych haseł
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- Tabela gier (łączy dwóch graczy)
CREATE TABLE games (
    game_id SERIAL PRIMARY KEY,
    player1_id INT NOT NULL REFERENCES players(player_id),
    player2_id INT NOT NULL REFERENCES players(player_id),
    start_time TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    end_time TIMESTAMPTZ,
    status VARCHAR(20) NOT NULL DEFAULT 'active', -- np. 'active', 'finished'
    winner_id INT REFERENCES players(player_id),
    player1_final_score INT DEFAULT 0,
    player2_final_score INT DEFAULT 0
);

-- Tabela ruchów (logika gry)
CREATE TABLE moves (
    move_id SERIAL PRIMARY KEY,
    game_id INT NOT NULL REFERENCES games(game_id) ON DELETE CASCADE,
    player_id INT NOT NULL REFERENCES players(player_id),
    move_number INT NOT NULL,
    move_type VARCHAR(10) NOT NULL, -- 'PLAY', 'EXCHANGE', 'PASS'
    score_achieved INT DEFAULT 0,
    is_bingo BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE(game_id, move_number)
);

-- Tabela zapisująca położenie PŁYTEK w danym ruchu
CREATE TABLE move_placements (
    placement_id SERIAL PRIMARY KEY,
    move_id INT NOT NULL REFERENCES moves(move_id) ON DELETE CASCADE,
    x_coord INT NOT NULL, -- Kolumna (0-14)
    y_coord INT NOT NULL, -- Wiersz (0-14)
    tile_letter CHAR(1) NOT NULL,
    used_as_letter CHAR(1) NOT NULL
);

-- Tabela zapisująca wszystkie SŁOWA utworzone w danym ruchu
CREATE TABLE move_words (
    word_id SERIAL PRIMARY KEY,
    move_id INT NOT NULL REFERENCES moves(move_id) ON DELETE CASCADE,
    word VARCHAR(21) NOT NULL,
    score INT NOT NULL
);

-- Nadanie uprawnień użytkownikowi (ważne dla kluczy SERIAL)
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO scrabble_usr;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO scrabble_usr;
