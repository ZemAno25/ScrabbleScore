-- Table: public.games

-- Ensure sequences exist for id default values
CREATE SEQUENCE IF NOT EXISTS games_id_seq START 1;
CREATE SEQUENCE IF NOT EXISTS moves_id_seq START 1;
CREATE SEQUENCE IF NOT EXISTS players_id_seq START 1;

-- Typ wyliczeniowy dla kolumny moves.type
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'move_type') THEN
        CREATE TYPE move_type AS ENUM ('PLAY', 'PASS', 'EXCHANGE', 'ENDGAME', 'BADWORD');
    END IF;
END$$;

-- Table: public.players

-- DROP TABLE IF EXISTS public.players;

CREATE TABLE IF NOT EXISTS public.players
(
    id integer NOT NULL DEFAULT nextval('players_id_seq'::regclass),
    nick character varying(40) COLLATE pg_catalog."default" NOT NULL,
    created_at timestamp without time zone NOT NULL DEFAULT now(),
    CONSTRAINT players_pkey PRIMARY KEY (id),
    CONSTRAINT players_nick_key UNIQUE (nick)
)

TABLESPACE pg_default;

ALTER TABLE IF EXISTS public.players
    OWNER to postgres;

GRANT ALL ON TABLE public.players TO postgres;

GRANT ALL ON TABLE public.players TO scrabble_usr;

-- Table: public.games

-- DROP TABLE IF EXISTS public.games;

CREATE TABLE IF NOT EXISTS public.games
(
    id integer NOT NULL DEFAULT nextval('games_id_seq'::regclass),
    player1_id integer NOT NULL,
    player2_id integer NOT NULL,
    recorder_player_id integer,
    started_at timestamp without time zone NOT NULL DEFAULT now(),
    scoring_mode character varying(20) COLLATE pg_catalog."default" NOT NULL DEFAULT 'PFS'::character varying,
    source_hash character varying(64) COLLATE pg_catalog."default",
    CONSTRAINT games_pkey PRIMARY KEY (id),
    CONSTRAINT games_player1_id_fkey FOREIGN KEY (player1_id)
        REFERENCES public.players (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE RESTRICT,
    CONSTRAINT games_player2_id_fkey FOREIGN KEY (player2_id)
        REFERENCES public.players (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE RESTRICT,
    CONSTRAINT games_recorder_player_id_fkey FOREIGN KEY (recorder_player_id)
        REFERENCES public.players (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE RESTRICT
)

TABLESPACE pg_default;

ALTER TABLE IF EXISTS public.games
    OWNER to postgres;

GRANT ALL ON TABLE public.games TO postgres;

GRANT ALL ON TABLE public.games TO scrabble_usr;
-- Index: idx_games_source_hash

-- DROP INDEX IF EXISTS public.idx_games_source_hash;

CREATE UNIQUE INDEX IF NOT EXISTS idx_games_source_hash
    ON public.games USING btree
    (source_hash COLLATE pg_catalog."default" ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default
    WHERE source_hash IS NOT NULL;

-- Index na recorder_player_id dla szybszych zapytań filtrujących po recorderze
CREATE INDEX IF NOT EXISTS idx_games_recorder_player_id
    ON public.games USING btree (recorder_player_id ASC NULLS LAST);

-- Table: public.lexicon_words

-- DROP TABLE IF EXISTS public.lexicon_words;

CREATE TABLE IF NOT EXISTS public.lexicon_words
(
    word text COLLATE pg_catalog."default" NOT NULL,
    CONSTRAINT lexicon_words_pkey PRIMARY KEY (word)
)

TABLESPACE pg_default;

ALTER TABLE IF EXISTS public.lexicon_words
    OWNER to postgres;

GRANT ALL ON TABLE public.lexicon_words TO postgres;

GRANT ALL ON TABLE public.lexicon_words TO scrabble_usr;

-- Table: public.moves

-- DROP TABLE IF EXISTS public.moves;

CREATE TABLE IF NOT EXISTS public.moves
(
    id integer NOT NULL DEFAULT nextval('moves_id_seq'::regclass),
    game_id integer NOT NULL,
    move_no integer NOT NULL,
    player_id integer NOT NULL,
    raw_input text COLLATE pg_catalog."default" NOT NULL,
    type move_type NOT NULL,
    "position" character varying(4) COLLATE pg_catalog."default",
    word text COLLATE pg_catalog."default",
    rack character varying(32) COLLATE pg_catalog."default",
    osps boolean NOT NULL DEFAULT false,
    post_rack character varying(32) COLLATE pg_catalog."default",
    score integer NOT NULL DEFAULT 0,
    cum_score integer NOT NULL DEFAULT 0,
    created_at timestamp without time zone NOT NULL DEFAULT now(),
    CONSTRAINT moves_pkey PRIMARY KEY (id),
    CONSTRAINT uniq_move UNIQUE (game_id, move_no),
    CONSTRAINT moves_game_id_fkey FOREIGN KEY (game_id)
        REFERENCES public.games (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE,
    CONSTRAINT moves_player_id_fkey FOREIGN KEY (player_id)
        REFERENCES public.players (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE RESTRICT
)

TABLESPACE pg_default;

ALTER TABLE IF EXISTS public.moves
    OWNER to postgres;

GRANT ALL ON TABLE public.moves TO postgres;

GRANT ALL ON TABLE public.moves TO scrabble_usr;
-- Index: idx_moves_game

-- DROP INDEX IF EXISTS public.idx_moves_game;

CREATE INDEX IF NOT EXISTS idx_moves_game
    ON public.moves USING btree
    (game_id ASC NULLS LAST)
    WITH (fillfactor=100, deduplicate_items=True)
    TABLESPACE pg_default;

-- Index to quickly find moves that are/are not OSPS-valid
CREATE INDEX IF NOT EXISTS idx_moves_osps
    ON public.moves USING btree (osps DESC);


-- End of schema (no sample data included)