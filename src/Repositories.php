<?php
// src/Repositories.php

require_once __DIR__ . '/Database.php';

class PlayerRepo
{
    public static function create(string $nick): int
    {
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
        $stmt = Database::get()->prepare('SELECT * FROM players WHERE nick = ?');
        $stmt->execute([$nick]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findOrCreate(string $nick): int
    {
        $nick = trim($nick);
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
    public static function create(int $p1, int $p2, string $mode = 'PFS'): int
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'INSERT INTO games(player1_id, player2_id, started_at, scoring_mode)
             VALUES(?, ?, now(), ?) RETURNING id'
        );
        $stmt->execute([$p1, $p2, $mode]);
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
}

class MoveRepo
{
    public static function add(array $data): int
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'INSERT INTO moves(
                game_id, move_no, player_id,
                raw_input, type, position, word, rack,
                score, cum_score, created_at
             ) VALUES(
                :game_id, :move_no, :player_id,
                :raw_input, :type, :position, :word, :rack,
                :score, :cum_score, now()
             )
             RETURNING id'
        );
        $stmt->execute($data);
        return (int)$stmt->fetchColumn();
    }

    public static function byGame(int $game_id): array
    {
        $stmt = Database::get()->prepare(
            'SELECT m.*, p.nick
             FROM moves m
             JOIN players p ON p.id = m.player_id
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
