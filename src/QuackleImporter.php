<?php
// src/QuackleImporter.php

require_once __DIR__ . '/Board.php';

class QuackleMove {
    public string $playerName;
    public string $type;          // PLAY, EXCHANGE, PASS, ENDGAME
    public ?string $rack = null;  // stojak przed ruchem
    public ?string $position = null;
    public ?string $word = null;
    public ?string $exchanged = null; // litery oddane przy wymianie
    public int $score;            // punkty za ruch
    public int $total;            // wynik łączny po ruchu
    public ?string $endRack = null;   // litery w nawiasie przy ENDGAME
    public string $rawLine;       // oryginalna linia z pliku
}

class QuackleGame {
    public ?string $player1Name = null;
    public ?string $player2Name = null;
    /** @var QuackleMove[] */
    public array $moves = [];
}

class QuackleImporter
{
    public static function parseGcg(string $contents): QuackleGame
    {
        $game = new QuackleGame();
        $lines = preg_split('/\r\n|\r|\n/', $contents);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '#')) {
                self::parseHeaderLine($game, $line);
            } elseif (str_starts_with($line, '>')) {
                $move = self::parseMoveLine($line);
                if ($move !== null) {
                    $game->moves[] = $move;
                }
            }
        }

        return $game;
    }

    private static function parseHeaderLine(QuackleGame $game, string $line): void
    {
        if (preg_match('/^#player1\s+(\S+)\s+(\S+)/u', $line, $m)) {
            $game->player1Name = $m[1];
        } elseif (preg_match('/^#player2\s+(\S+)\s+(\S+)/u', $line, $m)) {
            $game->player2Name = $m[1];
        }
    }

    private static function parseMoveLine(string $line): ?QuackleMove
    {
        // Przykłady:
        // >Quackle: AABIKTZ 8B ZABITKA +64 64
        // >IRENEUSZ: DNRSWZZ -SNWZ +0 154
        // >Marzena: Ź -  +0 298
        // >Quackle:  (ACĘHOR) +26 464

        $move = new QuackleMove();
        $move->rawLine = $line;

        $inner = substr($line, 1); // bez '>'
        $posColon = strpos($inner, ':');
        if ($posColon === false) {
            return null;
        }

        $player = trim(substr($inner, 0, $posColon));
        $rest = trim(substr($inner, $posColon + 1));

        $move->playerName = $player;

        // ENDGAME: zaczyna się od nawiasu (LITERY)
        if (preg_match('/^(?:([A-Za-zĄąĆćĘęŁłŃńÓóŚśŹźŻż\?]+)\s+)?\(([^)]+)\)\s+\+?(-?\d+)\s+(\d+)$/u', $rest, $m)) {
            $move->type    = 'ENDGAME';
            $preRack       = $m[1] ?? null;
            $move->endRack = $m[2];
            $move->score   = (int)$m[3];
            $move->total   = (int)$m[4];
            if ($preRack !== null && $preRack !== '') {
                $move->rack = mb_strtoupper($preRack, 'UTF-8');
            }
            return $move;
        }

        $tokens = preg_split('/\s+/', $rest);
        if (count($tokens) < 3) {
            return null;
        }

        $rack = $tokens[0];

        // Wymiana lub PASS: drugi token zaczyna się od '-'
        if (str_starts_with($tokens[1], '-')) {
            $minus       = $tokens[1];
            $scoreToken  = $tokens[2] ?? '+0';
            $totalToken  = $tokens[3] ?? '0';
            $move->rack  = $rack;
            $move->score = (int)ltrim($scoreToken, '+');
            $move->total = (int)$totalToken;

            if ($minus === '-') {
                $move->type      = 'PASS';
                $move->exchanged = '';
            } else {
                $move->type      = 'EXCHANGE';
                $move->exchanged = substr($minus, 1);
            }
            return $move;
        }

        // Normalny ruch: rack, pozycja, słowo, +score, total
        if (count($tokens) >= 4) {
            $move->type     = 'PLAY';
            $move->rack     = $rack;
            $move->position = $tokens[1];
            $move->word     = $tokens[2];
            $scoreToken     = $tokens[3];
            $totalToken     = $tokens[4] ?? '0';
            $move->score    = (int)ltrim($scoreToken, '+');
            $move->total    = (int)$totalToken;
            return $move;
        }

        return null;
    }

    /**
     * Konwersja słowa z notacji Quackle (kropki jako już leżące litery)
     * do notacji wewnętrznej ScrabbleScore (litery istniejące w nawiasach).
     */
    public static function convertWordToInternal(Board $board, string $position, string $word): string
    {
        [$row, $col, $orient] = Board::coordToXY($position);
        $dr = $orient === 'H' ? 0 : 1;
        $dc = $orient === 'H' ? 1 : 0;

        $result = '';
        $len = mb_strlen($word, 'UTF-8');

        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($word, $i, 1, 'UTF-8');
            if ($ch === '.') {
                $cell = $board->cells[$row][$col] ?? null;
                $letter = $cell && $cell['letter'] !== null ? $cell['letter'] : '';
                if ($letter !== '') {
                    $result .= '(' . $letter . ')';
                } else {
                    // Awaryjnie, gdyby plansza była niespójna, zachowujemy kropkę
                    $result .= '.';
                }
            } else {
                $result .= $ch;
            }
            $row += $dr;
            $col += $dc;
        }

        return $result;
    }
}
