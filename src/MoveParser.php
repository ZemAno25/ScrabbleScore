<?php
// src/MoveParser.php
require_once __DIR__.'/Board.php';

class ParsedMove {
    public string $type; // PLAY|PASS|EXCHANGE|ENDGAME
    public ?string $rack = null;
    public ?string $pos = null;
    public ?string $word = null;
}

class MoveParser {
    public static function parse(string $input): ParsedMove {
        $input = trim($input);
        if ($input === '') {
            throw new InvalidArgumentException('Pusty zapis ruchu.');
        }

        $pm = new ParsedMove();
        $upper = mb_strtoupper($input, 'UTF-8');

        // Proste zapisy specjalne bez dodatkowych danych
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

        // Tokenizacja po białych znakach
        $tokens = preg_split('/\s+/', $input);
        if (!$tokens || count($tokens) < 2) {
            throw new InvalidArgumentException('Niepoprawny zapis ruchu.');
        }

        // Specjalny format wymiany ze stojakiem:
        // "RACK EXCHANGE (LITERY)" np. "LK?RWSS EXCHANGE (RWSS)"
        // Pierwszy token wygląda jak stojak (nie jest pozycją),
        // drugi token to EXCHANGE (bez względu na wielkość liter).
        if (
            count($tokens) >= 2
            && mb_strtoupper($tokens[1], 'UTF-8') === 'EXCHANGE'
            && !self::looksLikePosition($tokens[0])
        ) {
            $pm->type = 'EXCHANGE';
            $pm->rack = mb_strtoupper($tokens[0], 'UTF-8');

            // Opcjonalna lista wymienianych liter w nawiasie
            if (count($tokens) > 2) {
                $rest = implode(' ', array_slice($tokens, 2));
                $rest = trim($rest);

                // Jeżeli zapis w nawiasach, usuń je
                if (preg_match('/^\((.+)\)$/u', $rest, $m)) {
                    $letters = $m[1];
                } else {
                    $letters = $rest;
                }

                // Zachowaj litery tak jak wpisano (w razie potrzeby
                // można je później normalizować)
                $pm->word = str_replace(' ', '', $letters);

                // Prosta walidacja znaków liter wymienianych
                if (!preg_match('/^[A-Za-zĄąĆćĘęŁłŃńÓóŚśŹźŻż\?]*$/u', $pm->word)) {
                    throw new InvalidArgumentException('Niepoprawne znaki w liście wymienianych liter.');
                }
            }

            return $pm;
        }

        // Od tego miejsca obsługujemy normalne ruchy z wyłożeniem słowa.

        // Może być "<rack> <pos> <word>" lub "<pos> <word>"
        if (count($tokens) < 2) {
            throw new InvalidArgumentException('Niepoprawny zapis ruchu.');
        }

        if (self::looksLikePosition($tokens[0])) {
            // Format: "<pos> <word>"
            $pm->type = 'PLAY';
            $pm->pos  = $tokens[0];
            $pm->word = implode(' ', array_slice($tokens, 1));
        } else {
            // Format: "<rack> <pos> <word>"
            if (count($tokens) < 3) {
                throw new InvalidArgumentException('Brak pozycji lub słowa.');
            }
            $pm->type = 'PLAY';
            $pm->rack = mb_strtoupper($tokens[0], 'UTF-8');
            $pm->pos  = $tokens[1];
            $pm->word = implode(' ', array_slice($tokens, 2));
        }

        // Walidacja pozycji
        Board::coordToXY($pm->pos); // rzuci wyjątek w razie błędu

        // Walidacja słowa: litery, nawiasy, ewentualne małe litery
        $wordNoSpaces = str_replace(' ', '', $pm->word ?? '');
        if ($wordNoSpaces === '') {
            throw new InvalidArgumentException('Brak słowa w zapisie ruchu.');
        }
        if (!preg_match('/^[()A-Za-zĄąĆćĘęŁłŃńÓóŚśŹźŻż\?]+$/u', $wordNoSpaces)) {
            throw new InvalidArgumentException('Niepoprawne znaki w słowie.');
        }

        return $pm;
    }

    private static function looksLikePosition(string $t): bool {
        // Pozycja w stylu "8F" albo "F8", przy czym zakres 1–15 i kolumny A–O
        return (bool)preg_match(
            '/^(([1-9]|1[0-5])[A-O]|[A-O]([1-9]|1[0-5]))$/u',
            mb_strtoupper($t, 'UTF-8')
        );
    }
}
