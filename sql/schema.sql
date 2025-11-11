-- sql/schema.sql
CREATE TABLE IF NOT EXISTS players (
    id SERIAL PRIMARY KEY,
    nick VARCHAR(40) UNIQUE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS games (
    id SERIAL PRIMARY KEY,
    player1_id INT NOT NULL REFERENCES players(id) ON DELETE RESTRICT,
    player2_id INT NOT NULL REFERENCES players(id) ON DELETE RESTRICT,
    started_at TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TYPE move_type AS ENUM ('PLAY','PASS','EXCHANGE');

CREATE TABLE IF NOT EXISTS moves (
    id SERIAL PRIMARY KEY,
    game_id INT NOT NULL REFERENCES games(id) ON DELETE CASCADE,
    move_no INT NOT NULL,
    player_id INT NOT NULL REFERENCES players(id) ON DELETE RESTRICT,
    raw_input TEXT NOT NULL,
    type move_type NOT NULL,
    position VARCHAR(4),
    word TEXT,
    rack VARCHAR(16),
    score INT NOT NULL DEFAULT 0,
    cum_score INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT now(),
    CONSTRAINT uniq_move UNIQUE(game_id, move_no)
);

CREATE INDEX IF NOT EXISTS idx_moves_game ON moves(game_id);
