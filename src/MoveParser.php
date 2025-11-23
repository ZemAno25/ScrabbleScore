<?php
// src/MoveParser.php
require_once __DIR__.'/Board.php';

class ParsedMove {
    public string $type; // PLAY|PASS|EXCHANGE|ENDGAME
    public ?string $rack = null;
    public ?string $pos = null;
    public ?string $word = null;

    /**
     * Dla ruchu EXCHANGE:
     * - pełny stojak w momencie ruchu (rack),
     * - litery faktycznie wymienione (exchanged), np. "RWSS".
     *   (na razie nie są zapisywane w bazie, ale parser je obsługuje).
     */
    public ?string $exchanged = null;
}

class MoveParser {
    public static function parse(string $input): ParsedMove {
        $input = trim($input);
        if ($input === '') {
            throw new InvalidArgumentException('Pusty zapis ruchu.');
        }

        $pm = new ParsedMove();

        $tokens = preg_split('/\s+/', $input);
        $tokens = array_values(array_filter($tokens, fn($t) => $t !== ''));
        if (count($tokens) === 0) {
            throw new InvalidArgumentException('Pusty zapis ruchu.');
        }

        $upper0 = strtoupper($tokens[0]);

        // Najprostsze przypadki: "PASS", "EXCHANGE", "ENDGAME"
        if (count($tokens) === 1) {
            if ($upper0 === 'PASS') {
                $pm->type = 'PASS';
                return $pm;
            }
            if ($upper0 === 'EXCHANGE') {
                $pm->type = 'EXCHANGE';
                return $pm;
            }
            if ($upper0 === 'ENDGAME') {
                $pm->type = 'ENDGAME';
                return $pm;
            }
        }

        // Rozszerzony EXCHANGE z podanym stojakiem i (opcjonalnie) literami wymienianymi
        //
        // Format:
        //   "<rack> EXCHANGE"
        //   "<rack> EXCHANGE (LITERY)"
        //   "<rack> EXCHANGE LITERY"
        //
        // np.:
        //   "LK?RWSS EXCHANGE (RWSS)"
        //   "LK?RWSS EXCHANGE RWSS"
        if (count($tokens) >= 2 && strtoupper($tokens[1]) === 'EXCHANGE') {
            $pm->type = 'EXCHANGE';
            $pm->rack = $tokens[0];

            if (count($tokens) >= 3) {
                // Złącz pozostałe tokeny w jedną sekwencję, usuń spacje i zewnętrzne nawiasy
                $lettersRaw = implode('', array_slice($tokens, 2));
                $lettersRaw = trim($lettersRaw);

                // Usuń pojedyncze otaczające nawiasy, jeśli są
                if (preg_match('/^\((.*)\)$/u', $lettersRaw, $m)) {
                    $lettersRaw = $m[1];
                }

                $normalized = str_replace(' ', '', $lettersRaw);

                if ($normalized !== '' &&
                    !preg_match('/^[A-Za-zĄąĆćĘęŁłŃńÓóŚśŹźŻż\?]+$/u', $normalized)
                ) {
                    throw new InvalidArgumentException(
                        'Niepoprawne znaki w literach wymienianych przy EXCHANGE.'
                    );
                }

                $pm->exchanged = $normalized === '' ? null : $normalized;
            }

            return $pm;
        }

        // Prosty PASS / EXCHANGE / ENDGAME jako pierwszy token
        if ($upper0 === 'PASS') {
            $pm->type = 'PASS';
            return $pm;
        }
        if ($upper0 === 'EXCHANGE') {
            $pm->type = 'EXCHANGE';
            return $pm;
        }
        if ($upper0 === 'ENDGAME') {
            $pm->type = 'ENDGAME';
            return $pm;
        }

        // --- Ruch PLAY ---

        // Akceptujemy:
        //   "<rack> <pos> <word>"
        //   "<pos> <word>"
        if (count($tokens) < 2) {
            throw new InvalidArgumentException('Niepoprawny zapis ruchu.');
        }

        if (self::looksLikePosition($tokens[0])) {
            // "<pos> <word>"
            $pm->type = 'PLAY';
            $pm->pos  = $tokens[0];
            $pm->word = implode(' ', array_slice($tokens, 1));
        } else {
            // "<rack> <pos> <word>"
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

        // Walidacja słowa (litery + nawiasy), małe litery dopuszczalne dla blanków
        $wordStripped = str_replace(' ', '', $pm->word);
        if (!preg_match(
            '/^[()A-Za-zĄąĆćĘęŁłŃńÓóŚśŹźŻż\?]+$/u',
            $wordStripped
        )) {
            throw new InvalidArgumentException('Niepoprawne znaki w słowie.');
        }

        return $pm;
    }

    private static function looksLikePosition(string $t): bool {
        return (bool)preg_match(
            '/^(([1-9]|1[0-5])[A-O]|[A-O]([1-9]|1[0-5]))$/u',
            strtoupper($t)
        );
    }
}
