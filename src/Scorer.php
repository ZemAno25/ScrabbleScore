<?php
// src/Scorer.php
require_once __DIR__.'/Board.php';
require_once __DIR__.'/PolishLetters.php';

class PlacementResult {
    public int $score = 0;
    public int $lettersPlaced = 0;
    public array $mainWordCoords = [];   // lista [row, col]
    public array $placed = [];           // współrzędne nowo położonych płytek [row, col]

    // Szczegółowy opis słów z ruchu:
    // [
    //   'word'           => 'EH',
    //   'score'          => 12,
    //   'kind'           => 'main' | 'cross',
    //   'wordMultiplier' => 3,
    //   'letters'        => [
    //       ['base' => 1, 'multL' => 1, 'multW' => 1],
    //       ['base' => 3, 'multL' => 1, 'multW' => 3],
    //   ],
    // ]
    public array $words = [];

    // Bonus za bingo (7 liter z rąk)
    public int $bingoBonus = 0;
}

class Scorer {
    private Board $board;
    private array $values;

    public function __construct(Board $board) {
        $this->board  = $board;
        $this->values = PolishLetters::values();
    }

    private function isLetter(string $ch): bool {
        return $ch !== '(' && $ch !== ')';
    }

    // Place word according to notation with parentheses for existing tiles.
    public function placeAndScore(string $pos, string $word): PlacementResult {
        [$row, $col, $orient] = Board::coordToXY($pos);
        $w  = $this->normalize($word);
        $res = new PlacementResult();

        $dr = $orient === 'H' ? 0 : 1;
        $dc = $orient === 'H' ? 1 : 0;

        $letters = $this->tokenize($w);
        $coords  = [];
        $r = $row;
        $c = $col;

        foreach ($letters as $tok) {
            if ($tok['type'] === 'exist') {
                // istniejąca płytka musi już być na planszy
                if ($this->board->cells[$r][$c]['letter'] === null) {
                    throw new RuntimeException(
                        "Płytka '{$tok['char']}' w nawiasie wymaga, aby pole " .
                        Board::xyToCoord($r, $c) . " było już zajęte."
                    );
                }
                $coords[] = [$r, $c, false];
                $r += $dr;
                $c += $dc;
                continue;
            }

            // nowa płytka
            if ($this->board->cells[$r][$c]['letter'] !== null) {
                throw new RuntimeException(
                    "Pole " . Board::xyToCoord($r, $c) . " jest już zajęte."
                );
            }

            $coords[] = [$r, $c, true, $tok['char'], $tok['isBlank']];
            $r += $dr;
            $c += $dc;
        }

        // Położenie płytek tymczasowo (locked = false dla nowych)
        foreach ($coords as $info) {
            [$rr, $cc, $isNew] = $info;
            if ($isNew) {
                $ch      = $info[3];
                $isBlank = $info[4];
                $this->board->cells[$rr][$cc] = [
                    'letter'  => $ch,
                    'isBlank' => $isBlank,
                    'locked'  => false,
                ];
                $res->placed[] = [$rr, $cc];
                $res->lettersPlaced++;
            }
        }

        // Słowo główne
        $mainDetails = [];
        $mainScore   = $this->scoreWordLine(
            $row,
            $col,
            $dr,
            $dc,
            $res->mainWordCoords,
            $mainDetails
        );
        $res->score += $mainScore;

        $mainDetails['score'] = $mainScore;
        $mainDetails['kind']  = 'main';
        $res->words[]         = $mainDetails;

        // Krzyżówki – dla każdej nowo położonej płytki
        foreach ($res->placed as [$pr, $pc]) {
            // kierunek prostopadły
            $adr = $dc;
            $adc = $dr;

            $start = $this->findWordStart($pr, $pc, $adr, $adc);
            $len   = $this->wordLen($start[0], $start[1], $adr, $adc);
            if ($len <= 1) {
                continue;   // brak krzyżówki
            }

            $crossDetails = [];
            $dummyCoords  = null; // musi być zmienna, nie literal null
            $crossScore   = $this->scoreWordLine(
                $start[0],
                $start[1],
                $adr,
                $adc,
                $dummyCoords,
                $crossDetails
            );
            $res->score += $crossScore;

            $crossDetails['score'] = $crossScore;
            $crossDetails['kind']  = 'cross';
            $res->words[]          = $crossDetails;
        }

        // Zablokuj nowo położone płytki
        foreach ($res->placed as [$pr, $pc]) {
            $this->board->cells[$pr][$pc]['locked'] = true;
        }

        // Bingo
        if ($res->lettersPlaced === 7) {
            $res->bingoBonus = 50;
            $res->score     += 50;
        }

        return $res;
    }

    private function normalize(string $w): string {
        // Duże litery, ale zachowujemy małe (blanki) i nawiasy
        $out = '';
        $len = mb_strlen($w);
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($w, $i, 1);
            if ($ch === '(' || $ch === ')') {
                $out .= $ch;
                continue;
            }
            $up = mb_strtoupper($ch, 'UTF-8');
            if ($ch === $up) {
                $out .= $up;
            } else {
                $out .= $ch;   // małe litery (blanki) zostają
            }
        }
        return $out;
    }

    private function tokenize(string $w): array {
        $tokens = [];
        $inPar  = false;
        $len    = mb_strlen($w);

        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($w, $i, 1);
            if ($ch === '(') {
                $inPar = true;
                continue;
            }
            if ($ch === ')') {
                $inPar = false;
                continue;
            }

            if ($inPar) {
                $tokens[] = [
                    'type' => 'exist',
                    'char' => mb_strtoupper($ch, 'UTF-8'),
                ];
            } else {
                $isBlank = (mb_strtolower($ch, 'UTF-8') === $ch);
                $tokens[] = [
                    'type'    => 'new',
                    'char'    => mb_strtoupper($ch, 'UTF-8'),
                    'isBlank' => $isBlank,
                ];
            }
        }

        return $tokens;
    }

    private function findWordStart(int $r, int $c, int $dr, int $dc): array {
        $size = $this->board->size;
        while (
            $r - $dr >= 0 && $r - $dr < $size &&
            $c - $dc >= 0 && $c - $dc < $size &&
            $this->board->cells[$r - $dr][$c - $dc]['letter'] !== null
        ) {
            $r -= $dr;
            $c -= $dc;
        }
        return [$r, $c];
    }

    private function wordLen(int $r, int $c, int $dr, int $dc): int {
        $len  = 0;
        $size = $this->board->size;
        while (
            $r >= 0 && $r < $size &&
            $c >= 0 && $c < $size &&
            $this->board->cells[$r][$c]['letter'] !== null
        ) {
            $len++;
            $r += $dr;
            $c += $dc;
        }
        return $len;
    }

    private function letterScore(string $ch, bool $isBlank): int {
        if ($isBlank) {
            return 0;
        }
        return $this->values[$ch] ?? 0;
    }

    /**
     * Liczy wartość słowa w linii (po aktualnym położeniu płytek).
     *
     * @param int        $row        – współrzędna początkowa (dowolna litera w słowie)
     * @param int        $col
     * @param int        $dr         – krok wiersza (0 lub 1)
     * @param int        $dc         – krok kolumny (0 lub 1)
     * @param array|null $coordsOut  – jeżeli podane, zostaną wpisane współrzędne wszystkich liter słowa
     * @param array|null $detailsOut – jeżeli podane, zostaną wpisane szczegóły:
     *                                 ['word' => ..., 'letters' => [...], 'wordMultiplier' => int]
     */
    private function scoreWordLine(
        int $row,
        int $col,
        int $dr,
        int $dc,
        ?array &$coordsOut = null,
        ?array &$detailsOut = null
    ): int {
        [$sr, $sc] = $this->findWordStart($row, $col, $dr, $dc);
        $r = $sr;
        $c = $sc;

        $size   = $this->board->size;
        $coords = [];
        $lettersInfo = [];
        $word = '';

        $sum = 0;
        $wm  = 1;

        while (
            $r >= 0 && $r < $size &&
            $c >= 0 && $c < $size &&
            $this->board->cells[$r][$c]['letter'] !== null
        ) {
            $cell = $this->board->cells[$r][$c];
            $coords[] = [$r, $c];

            $word .= $cell['letter'];

            $ls = $this->letterScore($cell['letter'], $cell['isBlank']);

            $multL = 1;
            $multW = 1;
            // premie tylko dla płytek położonych w tym ruchu (locked == false)
            if (!$cell['locked']) {
                $multL = $this->board->L[$r][$c];
                $multW = $this->board->W[$r][$c];
            }

            $lettersInfo[] = [
                'base'  => $ls,
                'multL' => $multL,
                'multW' => $multW,
            ];

            $sum += $ls * $multL;
            $wm  *= $multW;

            $r += $dr;
            $c += $dc;
        }

        if ($coordsOut !== null) {
            $coordsOut = $coords;
        }

        if ($detailsOut !== null) {
            $detailsOut = [
                'word'           => $word,
                'letters'        => $lettersInfo,
                'wordMultiplier' => $wm,
            ];
        }

        return $sum * $wm;
    }
}
