<?php
// src/MoveParser.php
require_once __DIR__ . '/Board.php';

class ParsedMove {
    public string $type;   // PLAY|PASS|EXCHANGE|ENDGAME
    public ?string $rack = null;
    public ?string $pos  = null;
    public ?string $word = null;
}

class MoveParser {
    public static function parse(string $input): ParsedMove {
        $input = trim($input);
        $pm    = new ParsedMove();

        $upper = mb_strtoupper($input, 'UTF-8');

        if ($upper === 'PASS') {
            $pm->type = 'PASS';
            return $pm;
        }
        if ($upper === 'EXCHANGE') {
            $pm->type = 'EXCHANGE';
            return $pm;
        }
        if ($upper === 'ENDGAME') {
            $pm->type = 'ENDGAME';
            return $pm;
        }

        // format:
        //  - "<rack> <pos> <word>"  (np. "LK?RWSS EXCHANGE (RWSS)" lub "GAZ?G?O 10D GAŹŻY")
        //  - "<pos> <word>"        (np. "8F ZOWIE")
        $tokens = preg_split('/\s+/', $input);
        if (count($tokens) < 2) {
            throw new InvalidArgumentException('Niepoprawny zapis ruchu.');
        }

        if (self::looksLikePosition($tokens[0])) {
            $pm->type = 'PLAY';
            $pm->pos  = $tokens[0];
            $pm->word = implode(' ', array_slice($tokens, 1));
        } else {
            if (count($tokens) < 3) {
                throw new InvalidArgumentException('Brak pozycji lub słowa.');
            }
            $pm->type = 'PLAY';
            $pm->rack = $tokens[0];
            $pm->pos  = $tokens[1];
            $pm->word = implode(' ', array_slice($tokens, 2));
        }

        // Walidacja pozycji
        Board::coordToXY($pm->pos); // rzuci wyjątek przy błędzie

        // Słowo: litery, ewentualnie nawiasy; małe litery = blanki
        $wordNoSpaces = str_replace(' ', '', $pm->word);
        if (!preg_match('/^[()A-Za-zĄąĆćĘęŁłŃńÓóŚśŹźŻż\?]+$/u', $wordNoSpaces)) {
            throw new InvalidArgumentException('Niepoprawne znaki w słowie.');
        }

        return $pm;
    }

    private static function looksLikePosition(string $t): bool {
        // 8F / F8 itp. (1–15 + A–O lub odwrotnie)
        $t = strtoupper($t);
        return (bool)preg_match('/^(([1-9]|1[0-5])[A-O]|[A-O]([1-9]|1[0-5]))$/u', $t);
    }
}
