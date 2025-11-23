<?php
// src/Scorer.php
require_once __DIR__.'/Board.php';
require_once __DIR__.'/PolishLetters.php';

class PlacementResult {
    public int $score = 0;
    public int $lettersPlaced = 0;
    public array $mainWordCoords = []; // list of [row,col]
    public array $placed = [];         // coords of newly placed tiles [row,col]

    /**
     * Szczegółowy opis wszystkich słów ułożonych w ramach ruchu.
     *
     * Struktura:
     * [
     *   [
     *     'kind'   => 'main'|'cross',
     *     'detail' => [
     *         'startRow'        => int,
     *         'startCol'        => int,
     *         'startCoord'      => string (np. "8F"),
     *         'direction'       => 'H'|'V',
     *         'word'            => string,
     *         'letters'         => [
     *             [
     *                 'row'            => int,
     *                 'col'            => int,
     *                 'coord'          => string,
     *                 'letter'         => string,
     *                 'isBlank'        => bool,
     *                 'letterScore'    => int,   // bazowa wartość litery
     *                 'letterMultiplier'=> int,  // mnożnik literowy (1,2,3)
     *                 'wordMultiplier' => int,   // mnożnik słowny (1,2,3) na tym polu
     *                 'cellScore'      => int    // punktacja litery po mnożniku literowym
     *             ],
     *             ...
     *         ],
     *         'sumLetters'      => int,   // suma cellScore przed mnożnikiem słownym
     *         'wordMultiplier'  => int,   // łączny mnożnik słowa (iloczyn pól słownych)
     *         'score'           => int    // wynik za całe słowo (sumLetters * wordMultiplier)
     *     ]
     *   ],
     *   ...
     * ]
     */
    public array $words = [];

    /**
     * Premia za siódemkę (bingo), 0 lub 50.
     */
    public int $bingoBonus = 0;
}

class Scorer {
    private Board $board;
    private array $values;

    public function __construct(Board $board) {
        $this->board = $board;
        $this->values = PolishLetters::values();
    }

    private function isLetter(string $ch): bool {
        return $ch !== '(' && $ch !== ')';
    }

    /**
     * Umieszcza słowo na planszy oraz liczy wynik.
     * Zwraca PlacementResult z:
     * - całkowitym wynikiem za ruch,
     * - liczbą dołożonych liter,
     * - współrzędnymi słowa głównego,
     * - współrzędnymi nowo położonych płytek,
     * - szczegółami słów (główne + poboczne),
     * - premią za siódemkę (bingo).
     */
    public function placeAndScore(string $pos, string $word): PlacementResult {
        [$row,$col,$orient] = Board::coordToXY($pos);
        $w = $this->normalize($word);
        $res = new PlacementResult();

        $dr = $orient === 'H' ? 0 : 1;
        $dc = $orient === 'H' ? 1 : 0;

        $letters = $this->tokenize($w);
        $coords = [];
        $r = $row; $c = $col;

        foreach ($letters as $tok) {
            if ($tok['type'] === 'exist') {
                // litera w nawiasie – płytka musi już znajdować się na planszy
                if ($this->board->cells[$r][$c]['letter'] === null) {
                    throw new RuntimeException(
                        "Płytka '{$tok['char']}' w nawiasie wymaga, aby pole " .
                        Board::xyToCoord($r,$c) . " było już zajęte."
                    );
                }
                $coords[] = [$r,$c,false];
                $r += $dr; $c += $dc;
                continue;
            }
            // układamy nową płytkę
            if ($this->board->cells[$r][$c]['letter'] !== null) {
                throw new RuntimeException(
                    "Pole " . Board::xyToCoord($r,$c) . " jest już zajęte."
                );
            }
            $coords[] = [$r,$c,true,$tok['char'],$tok['isBlank']];
            $r += $dr; $c += $dc;
        }

        // Tymczasowe położenie nowych płytek – do obliczenia wyniku
        foreach ($coords as $info) {
            [$rr,$cc,$isNew] = $info;
            if ($isNew) {
                $ch = $info[3];
                $isBlank = $info[4];
                $this->board->cells[$rr][$cc] = [
                    'letter' => $ch,
                    'isBlank'=> $isBlank,
                    'locked' => false
                ];
                $res->placed[] = [$rr,$cc];
                $res->lettersPlaced++;
            }
        }

        // Słowo główne
        $mainDetail = null;
        $mainScore = $this->scoreWordLine($row,$col,$dr,$dc,$res->mainWordCoords,$mainDetail);
        $res->score += $mainScore;
        if ($mainDetail !== null) {
            $res->words[] = [
                'kind'   => 'main',
                'detail' => $mainDetail,
            ];
        }

        // Słowa krzyżujące – dla każdej nowo położonej płytki
        foreach ($res->placed as [$pr,$pc]) {
            $adr = $dc;   // kierunek prostopadły
            $adc = $dr;
            [$sr,$sc] = $this->findWordStart($pr,$pc,$adr,$adc);
            $len = $this->wordLen($sr,$sc,$adr,$adc);
            if ($len > 1) {
                $crossDetail = null;
                $crossScore = $this->scoreWordLine($sr,$sc,$adr,$adc,null,$crossDetail);
                $res->score += $crossScore;
                if ($crossDetail !== null) {
                    $res->words[] = [
                        'kind'   => 'cross',
                        'detail' => $crossDetail,
                    ];
                }
            }
        }

        // Zablokuj położone płytki
        foreach ($res->placed as [$pr,$pc]) {
            $this->board->cells[$pr][$pc]['locked'] = true;
        }

        // Premia za siódemkę (bingo)
        if ($res->lettersPlaced === 7) {
            $res->bingoBonus = 50;
            $res->score += 50;
        }

        return $res;
    }

    private function normalize(string $w): string {
        // Zamiana na wielkie litery z zachowaniem:
        // - nawiasów
        // - małych liter oznaczających blanka
        $out = '';
        $len = mb_strlen($w);
        for ($i=0; $i<$len; $i++) {
            $ch = mb_substr($w,$i,1);
            if ($ch === '(' || $ch === ')' ) {
                $out .= $ch;
                continue;
            }
            $up = mb_strtoupper($ch,'UTF-8');
            if ($ch === $up) {
                $out .= $up;
            } else {
                // mała litera – oznaczenie blanka
                $out .= $ch;
            }
        }
        return $out;
    }

    private function tokenize(string $w): array {
        $tokens = [];
        $inPar = false;
        $len = mb_strlen($w);
        for ($i=0;$i<$len;$i++){
            $ch = mb_substr($w,$i,1);
            if ($ch === '(') { $inPar = true; continue; }
            if ($ch === ')') { $inPar = false; continue; }
            if ($inPar) {
                // litera istniejąca już na planszy
                $tokens[] = [
                    'type' => 'exist',
                    'char' => mb_strtoupper($ch,'UTF-8')
                ];
            } else {
                // nowa litera (być może blank)
                $isBlank = (mb_strtolower($ch,'UTF-8') === $ch);
                $tokens[] = [
                    'type'    => 'new',
                    'char'    => mb_strtoupper($ch,'UTF-8'),
                    'isBlank' => $isBlank
                ];
            }
        }
        return $tokens;
    }

    private function findWordStart(int $r,int $c,int $dr,int $dc): array {
        while (
            $r-$dr>=0 && $c-$dc>=0 &&
            $r-$dr<15 && $c-$dc<15 &&
            $this->board->cells[$r-$dr][$c-$dc]['letter'] !== null
        ) {
            $r -= $dr;
            $c -= $dc;
        }
        return [$r,$c];
    }

    private function wordLen(int $r,int $c,int $dr,int $dc): int {
        $len=0;
        while (
            $r>=0 && $c>=0 &&
            $r<15 && $c<15 &&
            $this->board->cells[$r][$c]['letter'] !== null
        ) {
            $len++;
            $r+=$dr;
            $c+=$dc;
        }
        return $len;
    }

    private function letterScore(string $ch, bool $isBlank): int {
        if ($isBlank) return 0;
        return $this->values[$ch] ?? 0;
    }

    /**
     * Liczy wynik pojedynczej "linii" słowa (głównego lub pobocznego),
     * opcjonalnie zwracając:
     * - listę współrzędnych (coordsOut),
     * - pełny opis słowa (detailsOut).
     */
    private function scoreWordLine(
        int $row,
        int $col,
        int $dr,
        int $dc,
        ?array &$coordsOut = null,
        ?array &$detailsOut = null
    ): int {
        // znajdź początek słowa
        [$sr,$sc] = $this->findWordStart($row,$col,$dr,$dc);
        $r = $sr;
        $c = $sc;

        $sum = 0;
        $wm  = 1;
        $coords = [];
        $lettersDetails = [];

        while (
            $r>=0 && $c>=0 &&
            $r<15 && $c<15 &&
            $this->board->cells[$r][$c]['letter'] !== null
        ) {
            $cell = $this->board->cells[$r][$c];
            $coords[] = [$r,$c];

            $ls = $this->letterScore($cell['letter'],$cell['isBlank']);
            $multL = 1;
            $multW = 1;

            // Premie obowiązują tylko dla nowo położonych płytek (locked = false)
            if (!$cell['locked']) {
                $multL = $this->board->L[$r][$c];
                $multW = $this->board->W[$r][$c];
            }

            $cellScore = $ls * $multL;
            $sum      += $cellScore;
            $wm       *= $multW;

            $lettersDetails[] = [
                'row'            => $r,
                'col'            => $c,
                'coord'          => Board::xyToCoord($r,$c),
                'letter'         => $cell['letter'],
                'isBlank'        => $cell['isBlank'],
                'letterScore'    => $ls,
                'letterMultiplier'=> $multL,
                'wordMultiplier' => $multW,
                'cellScore'      => $cellScore,
            ];

            $r += $dr;
            $c += $dc;
        }

        $total = $sum * $wm;

        if ($coordsOut !== null) {
            $coordsOut = $coords;
        }

        if ($detailsOut !== null) {
            $wordText = '';
            foreach ($lettersDetails as $ld) {
                $wordText .= $ld['letter'];
            }
            $detailsOut = [
                'startRow'       => $sr,
                'startCol'       => $sc,
                'startCoord'     => Board::xyToCoord($sr,$sc),
                'direction'      => ($dr === 0 ? 'H' : 'V'),
                'word'           => $wordText,
                'letters'        => $lettersDetails,
                'sumLetters'     => $sum,
                'wordMultiplier' => $wm,
                'score'          => $total,
            ];
        }

        return $total;
    }
}
