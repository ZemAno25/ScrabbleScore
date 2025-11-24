<?php
// src/Scorer.php
require_once __DIR__ . '/Board.php';
require_once __DIR__ . '/PolishLetters.php';

class PlacementResult {
    public int $score = 0;
    public int $lettersPlaced = 0;

    /** współrzędne liter słowa głównego [ [r,c], ... ] */
    public array $mainWordCoords = [];

    /** współrzędne nowo położonych płytek [ [r,c], ... ] */
    public array $placed = [];

    /**
     * Szczegóły wszystkich słów powstałych w ruchu.
     * Każdy element:
     * [
     *   'text'      => 'LEWA',
     *   'isMain'    => true/false,
     *   'letters'   => [
     *       [
     *         'char'     => 'L',
     *         'isBlank'  => false,
     *         'base'     => 2,
     *         'multL'    => 1,   // premia literowa dla tego pola
     *         'multW'    => 1,   // premia słowna z tego pola (1,2,3)
     *         'score'    => 2    // wynik po zastosowaniu mnożnika literowego
     *       ],
     *       ...
     *   ],
     *   'wordMult'  => 2,        // łączna premia słowna dla słowa (iloczyn)
     *   'wordScore' => 6         // końcowa wartość słowa
     * ]
     */
    public array $words = [];

    /** bonus za wyłożenie 7 liter (bingo) */
    public int $bingoBonus = 0;
}

class Scorer {
    private Board $board;
    private array $values;

    public function __construct(Board $board) {
        $this->board  = $board;
        $this->values = PolishLetters::values();
    }

    // Główna funkcja: kładzie słowo na planszy i zwraca wynik ruchu
    public function placeAndScore(string $pos, string $word): PlacementResult {
        [$row, $col, $orient] = Board::coordToXY($pos);
        $normalized = $this->normalize($word);

        $res = new PlacementResult();

        $dr = $orient === 'H' ? 0 : 1;
        $dc = $orient === 'H' ? 1 : 0;

        // Rozbij zapis na tokeny (nowe płytki / istniejące w nawiasach)
        $letters = $this->tokenize($normalized);
        $coords  = [];

        $r = $row;
        $c = $col;

        foreach ($letters as $tok) {
            if ($tok['type'] === 'exist') {
                // W nawiasie – płytka musi już być na planszy
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

            // Nowa płytka – pole musi być puste
            if ($this->board->cells[$r][$c]['letter'] !== null) {
                throw new RuntimeException(
                    "Pole " . Board::xyToCoord($r, $c) . " jest już zajęte."
                );
            }

            $coords[] = [$r, $c, true, $tok['char'], $tok['isBlank']];
            $r += $dr;
            $c += $dc;
        }

        // Tymczasowo połóż nowe płytki, żeby policzyć wynik
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
        [$mainScore, $mainDetail] = $this->scoreWordWithDetails(
            $row,
            $col,
            $dr,
            $dc,
            $res->mainWordCoords
        );
        $mainDetail['isMain'] = true;
        $res->score          += $mainScore;
        $res->words[]         = $mainDetail;

        // Słowa krzyżujące (dla każdej nowej płytki)
        foreach ($res->placed as [$pr, $pc]) {
            // Kierunek prostopadły do słowa głównego
            $adr = $dc;
            $adc = $dr;

            [$sr, $sc] = $this->findWordStart($pr, $pc, $adr, $adc);
            $len       = $this->wordLen($sr, $sc, $adr, $adc);
            if ($len > 1) {
                [$score, $detail] = $this->scoreWordWithDetails($sr, $sc, $adr, $adc);
                $detail['isMain'] = false;
                $res->score      += $score;
                $res->words[]     = $detail;
            }
        }

        // Zablokuj położone płytki (od tej chwili premie planszy już nie działają)
        foreach ($res->placed as [$pr, $pc]) {
            $this->board->cells[$pr][$pc]['locked'] = true;
        }

        // Bingo – 7 nowych płytek
        if ($res->lettersPlaced === 7) {
            $res->bingoBonus = 50;
            $res->score     += 50;
        }

        return $res;
    }

    // Normalizacja: duże litery, zachowanie nawiasów; małe litery = blanki
    private function normalize(string $w): string {
        $out = '';
        $len = mb_strlen($w, 'UTF-8');

        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($w, $i, 1, 'UTF-8');
            if ($ch === '(' || $ch === ')') {
                $out .= $ch;
                continue;
            }
            $up = mb_strtoupper($ch, 'UTF-8');
            if ($ch === $up) {
                $out .= $up;
            } else {
                // mała litera – blank: zachowujemy małą literę
                $out .= $ch;
            }
        }
        return $out;
    }

    // Tokenizuje zapis słowa na:
    //  - type=exist (litera w nawiasie – już na planszy)
    //  - type=new  (nowa płytka; isBlank określa blank)
    private function tokenize(string $w): array {
        $tokens = [];
        $inPar  = false;
        $len    = mb_strlen($w, 'UTF-8');

        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($w, $i, 1, 'UTF-8');

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

    // Znajdź początek słowa w danym kierunku
    private function findWordStart(int $r, int $c, int $dr, int $dc): array {
        while (
            $r - $dr >= 0 && $r - $dr < 15 &&
            $c - $dc >= 0 && $c - $dc < 15 &&
            $this->board->cells[$r - $dr][$c - $dc]['letter'] !== null
        ) {
            $r -= $dr;
            $c -= $dc;
        }
        return [$r, $c];
    }

    // Długość słowa od danego pola w danym kierunku
    private function wordLen(int $r, int $c, int $dr, int $dc): int {
        $len = 0;
        while (
            $r >= 0 && $r < 15 &&
            $c >= 0 && $c < 15 &&
            $this->board->cells[$r][$c]['letter'] !== null
        ) {
            $len++;
            $r += $dr;
            $c += $dc;
        }
        return $len;
    }

    // Wartość punktowa litery (bez premii); blank = 0
    private function letterScore(string $ch, bool $isBlank): int {
        if ($isBlank) {
            return 0;
        }
        return $this->values[$ch] ?? 0;
    }

    /**
     * Liczy słowo wzdłuż danej linii, zwracając:
     *  - wynik liczbowy
     *  - szczegóły do prezentacji (lista liter z mnożnikami)
     */
    private function scoreWordWithDetails(
        int $row,
        int $col,
        int $dr,
        int $dc,
        ?array &$coordsOut = null
    ): array {
        [$sr, $sc] = $this->findWordStart($row, $col, $dr, $dc);

        $r = $sr;
        $c = $sc;

        $coords    = [];
        $letters   = [];
        $sum       = 0;
        $wordMult  = 1;

        while (
            $r >= 0 && $r < 15 &&
            $c >= 0 && $c < 15 &&
            $this->board->cells[$r][$c]['letter'] !== null
        ) {
            $cell   = $this->board->cells[$r][$c];
            $coords[] = [$r, $c];

            $baseValue = $this->letterScore($cell['letter'], $cell['isBlank']);
            $multL     = 1;
            $multW     = 1;

            // premie planszy tylko dla nowo położonych płytek (locked=false)
            if (!$cell['locked']) {
                $multL = $this->board->L[$r][$c];
                $multW = $this->board->W[$r][$c];
            }

            $letterScore = $baseValue * $multL;
            $sum        += $letterScore;
            $wordMult   *= $multW;

            $letters[] = [
                'char'     => $cell['letter'],
                'isBlank'  => $cell['isBlank'],
                'base'     => $baseValue,
                'multL'    => $multL,
                'multW'    => $multW,
                'score'    => $letterScore,
            ];

            $r += $dr;
            $c += $dc;
        }

        if ($coordsOut !== null) {
            $coordsOut = $coords;
        }

        $wordText = '';
        foreach ($letters as $l) {
            $wordText .= $l['char'];
        }

        $wordScore = $sum * $wordMult;

        $detail = [
            'text'      => $wordText,
            'letters'   => $letters,
            'wordMult'  => $wordMult,
            'wordScore' => $wordScore,
            'isMain'    => false, // nadpisywane przy słowie głównym
        ];

        return [$wordScore, $detail];
    }
}
