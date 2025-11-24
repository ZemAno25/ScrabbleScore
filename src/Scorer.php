<?php
// src/Scorer.php
require_once __DIR__.'/Board.php';
require_once __DIR__.'/PolishLetters.php';

class PlacementResult {
    public int $score = 0;
    public int $lettersPlaced = 0;
    public array $mainWordCoords = []; // list of [row,col]
    public array $placed = [];         // coords of newly placed tiles

    // Nowe pola do szczegółów punktacji
    // Każdy element:
    // [
    //   'kind'          => 'main'|'cross',
    //   'word'          => string,
    //   'score'         => int,
    //   'wordMultiplier'=> int,
    //   'letters'       => [
    //       ['char'=>..., 'base'=>..., 'letterMultiplier'=>..., 'scoreAfterLetterMult'=>..., 'row'=>..., 'col'=>..., 'isNew'=>bool],
    //       ...
    //   ],
    //   'coords'        => [[row,col], ...],
    // ]
    public array $words = [];

    // Premia za wyłożenie 7 liter (bingo)
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
     * Główna funkcja licząca punkty za ruch:
     * - Umieszcza nowe płytki na planszy (tymczasowo),
     * - liczy słowo główne i wszystkie krzyżówki,
     * - zapisuje szczegóły w PlacementResult::$words,
     * - blokuje nowe płytki (locked = true),
     * - dodaje premię za bingo (7 liter).
     */
    public function placeAndScore(string $pos, string $word): PlacementResult {
        [$row, $col, $orient] = Board::coordToXY($pos);
        $w = $this->normalize($word);
        $res = new PlacementResult();

        $dr = $orient === 'H' ? 0 : 1;
        $dc = $orient === 'H' ? 1 : 0;

        $letters = $this->tokenize($w);
        $coords  = [];
        $r = $row;
        $c = $col;

        // Wyznacz pola, na które trafią litery (istniejące / nowe)
        foreach ($letters as $tok) {
            if ($tok['type'] === 'exist') {
                // istniejąca płytka musi już być na planszy
                if ($this->board->cells[$r][$c]['letter'] === null) {
                    throw new RuntimeException(
                        "Płytka '{$tok['char']}' w nawiasie wymaga, aby pole " .
                        Board::xyToCoord($r, $c) .
                        " było już zajęte."
                    );
                }
                $coords[] = [$r, $c, false];
                $r += $dr;
                $c += $dc;
                continue;
            }

            // stawiamy nową płytkę
            if ($this->board->cells[$r][$c]['letter'] !== null) {
                throw new RuntimeException(
                    "Pole " . Board::xyToCoord($r, $c) . " jest już zajęte."
                );
            }

            $coords[] = [$r, $c, true, $tok['char'], $tok['isBlank']];
            $r += $dr;
            $c += $dc;
        }

        // Umieść nowe płytki tymczasowo na planszy
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
        $mainDetail = $this->scoreWordDetailed($row, $col, $dr, $dc);
        if ($mainDetail['length'] > 0) {
            $res->score          += $mainDetail['score'];
            $res->mainWordCoords  = $mainDetail['coords'];
            $this->addWordToResult($res, 'main', $mainDetail);
        }

        // Krzyżówki dla każdej nowo położonej płytki
        foreach ($res->placed as [$pr, $pc]) {
            $adr   = $dc; // kierunek prostopadły
            $adc   = $dr;
            $start = $this->findWordStart($pr, $pc, $adr, $adc);
            $len   = $this->wordLen($start[0], $start[1], $adr, $adc);
            if ($len > 1) {
                $detail = $this->scoreWordDetailed($start[0], $start[1], $adr, $adc);
                if ($detail['length'] > 1) {
                    $res->score += $detail['score'];
                    $this->addWordToResult($res, 'cross', $detail);
                }
            }
        }

        // Zablokuj nowe płytki po przeliczeniu
        foreach ($res->placed as [$pr, $pc]) {
            $this->board->cells[$pr][$pc]['locked'] = true;
        }

        // Premia za 7 liter (bingo)
        if ($res->lettersPlaced === 7) {
            $res->bingoBonus = 50;
            $res->score     += 50;
        }

        return $res;
    }

    /**
     * Normalizacja: zamiana na wielkie litery z zachowaniem nawiasów
     * oraz małych liter (oznaczających blanka).
     */
    private function normalize(string $w): string {
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
                // mała litera pozostaje mała – oznacza blanka
                $out .= $ch;
            }
        }
        return $out;
    }

    /**
     * Rozbicie słowa na tokeny:
     * - type = 'exist' dla liter w nawiasach (istniejące na planszy),
     * - type = 'new' dla nowych liter (isBlank = true gdy litera była mała).
     */
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
            $r - $dr >= 0 && $c - $dc >= 0 &&
            $r - $dr < $size && $c - $dc < $size &&
            $this->board->cells[$r - $dr][$c - $dc]['letter'] !== null
        ) {
            $r -= $dr;
            $c -= $dc;
        }
        return [$r, $c];
    }

    private function wordLen(int $r, int $c, int $dr, int $dc): int {
        $size = $this->board->size;
        $len  = 0;
        while (
            $r >= 0 && $c >= 0 &&
            $r < $size && $c < $size &&
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
     * Szczegółowe liczenie słowa:
     * zwraca tablicę:
     * [
     *   'word'          => string,
     *   'score'         => int,
     *   'coords'        => [[row,col], ...],
     *   'letters'       => [...],
     *   'wordMultiplier'=> int,
     *   'length'        => int,
     * ]
     */
    private function scoreWordDetailed(int $row, int $col, int $dr, int $dc): array {
        [$sr, $sc] = $this->findWordStart($row, $col, $dr, $dc);
        $r = $sr;
        $c = $sc;

        $size    = $this->board->size;
        $sum     = 0;
        $wm      = 1;
        $coords  = [];
        $letters = [];
        $word    = '';

        while (
            $r >= 0 && $c >= 0 &&
            $r < $size && $c < $size &&
            $this->board->cells[$r][$c]['letter'] !== null
        ) {
            $cell = $this->board->cells[$r][$c];
            $coords[] = [$r, $c];

            $base = $this->letterScore($cell['letter'], $cell['isBlank']);

            $multL = 1;
            $multW = 1;
            if (!$cell['locked']) { // nowa płytka w tym ruchu
                $multL = $this->board->L[$r][$c];
                $multW = $this->board->W[$r][$c];
            }

            $letterScoreFinal = $base * $multL;
            $sum += $letterScoreFinal;
            $wm  *= $multW;

            $letters[] = [
                'char'                 => $cell['letter'],
                'base'                 => $base,
                'letterMultiplier'     => $multL,
                'scoreAfterLetterMult' => $letterScoreFinal,
                'row'                  => $r,
                'col'                  => $c,
                'isNew'                => !$cell['locked'],
            ];

            $word .= $cell['letter'];

            $r += $dr;
            $c += $dc;
        }

        return [
            'word'           => $word,
            'score'          => $sum * $wm,
            'coords'         => $coords,
            'letters'        => $letters,
            'wordMultiplier' => $wm,
            'length'         => count($coords),
        ];
    }

    /**
     * Dodanie wpisu o słowie do PlacementResult::$words.
     */
    private function addWordToResult(PlacementResult $res, string $kind, array $detail): void {
        $res->words[] = [
            'kind'          => $kind,
            'word'          => $detail['word'],
            'score'         => $detail['score'],
            'wordMultiplier'=> $detail['wordMultiplier'],
            'letters'       => $detail['letters'],
            'coords'        => $detail['coords'],
        ];
    }
}
