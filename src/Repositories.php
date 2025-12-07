<?php
// src/Repositories.php

require_once __DIR__ . '/Database.php';

class PlayerRepo
{
    private static function normalizeNick(string $nick): string
    {
        $nick = trim($nick);
        if ($nick === '') {
            return '';
        }
        return mb_strtoupper($nick, 'UTF-8');
    }

    public static function create(string $nick): int
    {
        $nick = self::normalizeNick($nick);
        if ($nick === '') {
            throw new InvalidArgumentException('Pusty nick gracza.');
        }

        $pdo = Database::get();
        $stmt = $pdo->prepare('INSERT INTO players(nick, created_at) VALUES(?, now()) RETURNING id');
        $stmt->execute([$nick]);
        return (int)$stmt->fetchColumn();
    }

    public static function all(): array
    {
        return Database::get()->query('SELECT id, nick FROM players ORDER BY nick')->fetchAll();
    }

    public static function findByNick(string $nick): ?array
    {
        $nick = self::normalizeNick($nick);
        if ($nick === '') {
            return null;
        }

        $stmt = Database::get()->prepare('SELECT * FROM players WHERE nick = ?');
        $stmt->execute([$nick]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findOrCreate(string $nick): int
    {
        $nick = self::normalizeNick($nick);
        if ($nick === '') {
            throw new InvalidArgumentException('Pusty nick gracza.');
        }

        $existing = self::findByNick($nick);
        if ($existing) {
            return (int)$existing['id'];
        }
        return self::create($nick);
    }
}

class GameRepo
{
    public static function create(
        int $p1,
        int $p2,
        string $mode = 'PFS',
        ?string $startedAt = null,
        ?string $sourceHash = null,
        ?int $recorderId = null
    ): int {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'INSERT INTO games(
                player1_id,
                player2_id,
                recorder_player_id,
                started_at,
                scoring_mode,
                source_hash
             ) VALUES(
                :p1,
                :p2,
                :recorder,
                COALESCE(:started_at, now()),
                :mode,
                :source_hash
             )
             RETURNING id'
        );
        $stmt->execute([
            'p1'          => $p1,
            'p2'          => $p2,
            'recorder'    => $recorderId,
            'started_at'  => $startedAt,
            'mode'        => $mode,
            'source_hash' => $sourceHash,
        ]);
        return (int)$stmt->fetchColumn();
    }

    public static function get(int $id): array|false
    {
        $stmt = Database::get()->prepare('SELECT * FROM games WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public static function list(): array
    {
        return Database::get()->query(
            'SELECT g.id,
                    p1.nick AS player1,
                    p2.nick AS player2,
                    g.started_at,
                    g.scoring_mode
             FROM games g
             JOIN players p1 ON p1.id = g.player1_id
             JOIN players p2 ON p2.id = g.player2_id
             ORDER BY g.id DESC'
        )->fetchAll();
    }

    public static function findBySourceHash(string $hash): ?array
    {
        $hash = trim($hash);
        if ($hash === '') {
            return null;
        }
        $stmt = Database::get()->prepare('SELECT * FROM games WHERE source_hash = ?');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function delete(int $id): void
    {
        $stmt = Database::get()->prepare('DELETE FROM games WHERE id = ?');
        $stmt->execute([$id]);
    }
}

class MoveRepo
{
    public static function add(array $data): int
    {
        $pdo = Database::get();
        // compute OSPS validity flag.
        // If caller provided 'check_words' (array or JSON string), require that ALL listed words exist in lexicon_words.
        // Otherwise, fall back to checking the main 'word' field (if present). Non-word moves default to true.
        $osps = true;
        $wordsToCheck = [];
        if (isset($data['check_words'])) {
            $cw = $data['check_words'];
            if (is_string($cw)) {
                // allow JSON-encoded or comma-separated
                $decoded = json_decode($cw, true);
                if (is_array($decoded)) {
                    $wordsToCheck = $decoded;
                } else {
                    $wordsToCheck = array_filter(array_map('trim', explode(',', $cw)));
                }
            } elseif (is_array($cw)) {
                $wordsToCheck = $cw;
            }
        } elseif (!empty($data['word'])) {
            $wordsToCheck = [ (string)$data['word'] ];
        }

        if (!empty($wordsToCheck)) {
            foreach ($wordsToCheck as $w) {
                $w = trim((string)$w);
                if ($w === '') continue;
                $stmt = $pdo->prepare('SELECT 1 FROM lexicon_words WHERE lower(word) = lower(?) LIMIT 1');
                $stmt->execute([$w]);
                $found = $stmt->fetchColumn();
                if (!$found) { $osps = false; break; }
            }
        }
        $data['osps'] = $osps;
        // remove internal helper key so it doesn't get bound to SQL
        if (isset($data['check_words'])) unset($data['check_words']);
        $stmt = $pdo->prepare(
            'INSERT INTO moves(
                game_id,
                move_no,
                player_id,
                raw_input,
                type,
                position,
                word,
                rack,
                score,
                cum_score,
                osps,
                post_rack,
                created_at
             ) VALUES(
                :game_id,
                :move_no,
                :player_id,
                :raw_input,
                :type,
                :position,
                :word,
                :rack,
                :score,
                :cum_score,
                :osps,
                :post_rack,
                now()
             )
             RETURNING id'
        );

        // Explicitly bind parameters with types to avoid empty-string -> boolean issues
        $stmt->bindValue(':game_id', isset($data['game_id']) ? (int)$data['game_id'] : null, PDO::PARAM_INT);
        $stmt->bindValue(':move_no', isset($data['move_no']) ? (int)$data['move_no'] : null, PDO::PARAM_INT);
        $stmt->bindValue(':player_id', isset($data['player_id']) ? (int)$data['player_id'] : null, PDO::PARAM_INT);
        $stmt->bindValue(':raw_input', isset($data['raw_input']) ? (string)$data['raw_input'] : null, PDO::PARAM_STR);
        $stmt->bindValue(':type', isset($data['type']) ? (string)$data['type'] : null, PDO::PARAM_STR);

        if (array_key_exists('position', $data) && $data['position'] !== null) {
            $stmt->bindValue(':position', (string)$data['position'], PDO::PARAM_STR);
        } else {
            $stmt->bindValue(':position', null, PDO::PARAM_NULL);
        }

        if (array_key_exists('word', $data) && $data['word'] !== null) {
            $stmt->bindValue(':word', (string)$data['word'], PDO::PARAM_STR);
        } else {
            $stmt->bindValue(':word', null, PDO::PARAM_NULL);
        }

        if (array_key_exists('rack', $data) && $data['rack'] !== null) {
            $stmt->bindValue(':rack', (string)$data['rack'], PDO::PARAM_STR);
        } else {
            $stmt->bindValue(':rack', null, PDO::PARAM_NULL);
        }

        $stmt->bindValue(':score', isset($data['score']) ? (int)$data['score'] : 0, PDO::PARAM_INT);
        $stmt->bindValue(':cum_score', isset($data['cum_score']) ? (int)$data['cum_score'] : 0, PDO::PARAM_INT);
        $stmt->bindValue(':osps', isset($data['osps']) ? ($data['osps'] ? true : false) : false, PDO::PARAM_BOOL);
        // post_rack (remaining tiles after the move), optional
        if (array_key_exists('post_rack', $data) && $data['post_rack'] !== null) {
            $stmt->bindValue(':post_rack', (string)$data['post_rack'], PDO::PARAM_STR);
        } else {
            $stmt->bindValue(':post_rack', null, PDO::PARAM_NULL);
        }

        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public static function deleteByGame(int $gameId): void
    {
        $stmt = Database::get()->prepare('DELETE FROM moves WHERE game_id = ?');
        $stmt->execute([$gameId]);
    }

    public static function byGame(int $game_id): array
    {
        $stmt = Database::get()->prepare(
            'SELECT m.*, p.nick
             FROM moves m
             LEFT JOIN players p ON p.id = m.player_id
             WHERE game_id = ?
             ORDER BY move_no'
        );
        $stmt->execute([$game_id]);
        return $stmt->fetchAll();
    }

    public static function lastCumScores(int $game_id): array
    {
        $stmt = Database::get()->prepare(
            'SELECT player_id, COALESCE(MAX(cum_score), 0) AS s
             FROM moves
             WHERE game_id = ?
             GROUP BY player_id'
        );
        $stmt->execute([$game_id]);
        $res = [];
        foreach ($stmt as $row) {
            $res[$row['player_id']] = (int)$row['s'];
        }
        return $res;
    }
}
