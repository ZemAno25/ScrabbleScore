-- Utworzenie tabel dla PostgreSQL

CREATE TABLE players (
    id SERIAL PRIMARY KEY,
    nickname VARCHAR(50) UNIQUE NOT NULL
);

CREATE TABLE games (
    id SERIAL PRIMARY KEY,
    start_time TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    end_time TIMESTAMP WITHOUT TIME ZONE NULL
);

CREATE TABLE game_players (
    id SERIAL PRIMARY KEY,
    game_id INTEGER REFERENCES games(id) ON DELETE CASCADE,
    player_id INTEGER REFERENCES players(id) ON DELETE CASCADE,
    score INTEGER DEFAULT 0,
    is_rack_registered BOOLEAN DEFAULT FALSE, -- Czy gracz ma rejestrowany stojak (tylko Adam)
    UNIQUE (game_id, player_id)
);

CREATE TABLE moves (
    id SERIAL PRIMARY KEY,
    game_id INTEGER REFERENCES games(id) ON DELETE CASCADE,
    player_id INTEGER REFERENCES players(id) ON DELETE SET NULL,
    move_number INTEGER NOT NULL,
    move_type VARCHAR(10) NOT NULL, -- 'PLAY', 'PASS', 'EXCHANGE'
    points INTEGER DEFAULT 0,
    raw_input TEXT NOT NULL,          -- Surowy tekst ruchu (np. RACK 8F SŁOWO)
    rack_before VARCHAR(7) NULL,      -- Stojak gracza przed ruchem (tylko Adam)
    is_bingo BOOLEAN DEFAULT FALSE,   -- Czy ruch był za 50 pkt premii
    played_tiles_string TEXT NULL,    -- Płytki położone na planszy (np. A8 K B8 A...)
    word_formed VARCHAR(15) NULL,
    UNIQUE (game_id, move_number)
);