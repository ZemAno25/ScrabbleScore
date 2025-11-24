<?php
// src/Scorer.php
require_once __DIR__.'/Board.php';
require_once __DIR__.'/PolishLetters.php';

class PlacementResult {
    public int $score = 0;
    public int $lettersPlaced = 0;
    public array $mainWordCoords = []; // list of [row,col]
    public array $placed = [];         // coords of newly placed tiles
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

    // Place word according to notation with parentheses for existing tiles.
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

        foreach ($letters as $tok) {
            if ($tok['type'] === 'exist') {
                // existing tile must already be on board
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

            // placing a new tile
            if ($this->board->cells[$r][$c]['letter'] !== null) {
                throw new RuntimeException(
                    "Pole " . Board::xyToCoord($r, $c) . " jest już zajęte."
                );
            }

            $coords[] = [$r, $c, true, $tok['char'], $tok['isBlank']];
            $r += $dr;
            $c += $dc;
        }

        // Place tiles tentatively to compute score
        foreach ($coords as $info) {
            [$rr, $cc, $isNew] = $info;
            if ($isNew) {
                $ch      = $info[3];
                $isBlank = $info[4];
                $this->board->cells[$rr][$cc] = [
                    'letter'  => $ch,
                    'isBlank' => $isBlank,
                    'locked'  => false
                ];
                $res->placed[] = [$rr, $cc];
                $res->lettersPlaced++;
            }
        }

        // Score main word
        $res->score += $this->scoreWordLine($row, $col, $dr, $dc, $res->mainWordCoords);

        // Score cross words for each newly placed tile
        foreach ($res->placed as [$pr, $pc]) {
            $adr   = $dc;
            $adc   = $dr; // perpendicular
            $start = $this->findWordStart($pr, $pc, $adr, $adc);
            $len   = $this->wordLen($start[0], $start[1], $adr, $adc);
            if ($len > 1) {
                $dummy = null;
                $res->score += $this->scoreWordLine($start[0], $start[1], $adr, $adc, $dummy);
            }
        }

        // Lock tiles
        foreach ($res->placed as [$pr, $pc]) {
            $this->board->cells[$pr][$pc]['locked'] = true;
        }

        // Bingo
        if ($res->lettersPlaced === 7) {
            $res->score += 50;
        }

        return $res;
    }

    private function normalize(string $w): string {
        // Uppercase except explicit lowercase which mark blanks substitution
        // Keep parentheses as is
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
                $out .= $ch; // keep lowercase to indicate blank substitution
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
                    'char' => mb_strtoupper($ch, 'UTF-8')
                ];
            } else {
                $isBlank = (mb_strtolower($ch, 'UTF-8') === $ch);
                $tokens[] = [
                    'type'    => 'new',
                    'char'    => mb_strtoupper($ch, 'UTF-8'),
                    'isBlank' => $isBlank
                ];
            }
        }

        return $tokens;
    }

    private function findWordStart(int $r, int $c, int $dr, int $dc): array {
        while (
            $r - $dr >= 0 && $c - $dc >= 0 &&
            $r - $dr < 15 && $c - $dc < 15 &&
            $this->board->cells[$r - $dr][$c - $dc]['letter'] !== null
        ) {
            $r -= $dr;
            $c -= $dc;
        }
        return [$r, $c];
    }

    private function wordLen(int $r, int $c, int $dr, int $dc): int {
        $len = 0;
        while (
            $r >= 0 && $c >= 0 &&
            $r < 15 && $c < 15 &&
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

    // $coordsOut – opcjonalnie lista współrzędnych liter w tym słowie
    private function scoreWordLine(
        int $row,
        int $col,
        int $dr,
        int $dc,
        $coordsOut = null
    ): int {
        // find start
        [$sr, $sc] = $this->findWordStart($row, $col, $dr, $dc);
        $r = $sr;
        $c = $sc;

        $sum    = 0;
        $wm     = 1;
        $coords = [];

        while (
            $r >= 0 && $c >= 0 &&
            $r < 15 && $c < 15 &&
            $this->board->cells[$r][$c]['letter'] !== null
        ) {
            $cell    = $this->board->cells[$r][$c];
            $coords[] = [$r, $c];

            $ls = $this->letterScore($cell['letter'], $cell['isBlank']);
            $multL = 1;
            $multW = 1;

            if (!$cell['locked']) { // newly placed this turn
                $multL = $this->board->L[$r][$c];
                $multW = $this->board->W[$r][$c];
            }

            $sum += $ls * $multL;
            $wm  *= $multW;

            $r += $dr;
            $c += $dc;
        }

        if (is_array($coordsOut)) {
            $coordsOut = $coords;
        }

        return $sum * $wm;
    }
}
