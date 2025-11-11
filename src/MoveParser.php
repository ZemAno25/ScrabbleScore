<?php
// src/MoveParser.php
require_once __DIR__.'/Board.php';

class ParsedMove {
    public string $type; // PLAY|PASS|EXCHANGE
    public ?string $rack = null;
    public ?string $pos = null;
    public ?string $word = null;
}

class MoveParser {
    public static function parse(string $input): ParsedMove {
        $input = trim($input);
        $pm = new ParsedMove();

        $upper = strtoupper($input);
        if ($upper === 'PASS') { $pm->type = 'PASS'; return $pm; }
        if ($upper === 'EXCHANGE') { $pm->type = 'EXCHANGE'; return $pm; }

        // Accept either "<rack> <pos> <word>" or "<pos> <word>"
        // Rack may contain letters including diacritics and "?"
        // Word may contain letters and parentheses marking existing tiles
        $tokens = preg_split('/\s+/', $input);
        if (count($tokens) < 2) throw new InvalidArgumentException('Niepoprawny zapis ruchu.');

        if (self::looksLikePosition($tokens[0])) {
            $pm->type = 'PLAY';
            $pm->pos = $tokens[0];
            $pm->word = implode(' ', array_slice($tokens, 1));
        } else {
            if (count($tokens) < 3) throw new InvalidArgumentException('Brak pozycji lub słowa.');
            $pm->type = 'PLAY';
            $pm->rack = $tokens[0];
            $pm->pos = $tokens[1];
            $pm->word = implode(' ', array_slice($tokens, 2));
        }
        // Validate position
        Board::coordToXY($pm->pos); // throws on error
        // Validate word content (letters, parentheses). Lowercase allowed for blanks already assigned.
        if (!preg_match('/^[()A-Za-zĄąĆćĘęŁłŃńÓóŚśŹźŻż\?]+$/u', str_replace(' ', '', $pm->word))) {
            throw new InvalidArgumentException('Niepoprawne znaki w słowie.');
        }
        return $pm;
    }

    private static function looksLikePosition(string $t): bool {
        return (bool)preg_match('/^(([1-9]|1[0-5])[A-O]|[A-O]([1-9]|1[0-5]))$/u', strtoupper($t));
    }
}
