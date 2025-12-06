<?php
// src/Scorer.php
require_once __DIR__.'/Board.php';
require_once __DIR__.'/PolishLetters.php';

class PlacementResult {
    public int $score = 0;
    public int $lettersPlaced = 0;

    // lista współrzędnych głównego słowa [ [r,c], ... ]
    public array $mainWordCoords = [];

    // współrzędne nowo położonych płytek [ [r,c], ... ]
    public array $placed = [];

    /**
     * Szczegóły punktacji dla wszystkich słów utworzonych w ruchu.
     * Każdy element:
     * [
     *   'kind'          => 'main' | 'cross',
     *   'word'          => 'MIKI',
     *   'letterExpr'    => '2 + 1 + 2 + 1'   (tylko litery, z uwzględnieniem premii literowych),
     *   'sumLetters'    => 6,
     *   'wordMultiplier'=> 2,
     *   'score'         => 12
     * ]
     */
    public array $wordDetails = [];
}

class Scorer {
    private Board $board;
    private array $values;

    public function __construct(Board $board) {
        $this->board = $board;
        $this->values = PolishLetters::values();
    }

    /**
     * Zwraca liczbę płytek każdego rodzaju leżących na planszy.
     * Blanki sumujemy pod kluczem '?'.
     *
     * @return array<string,int>
     */
    public static function boardLetterCounts(Board $board): array {
        $counts = [];
        for ($r = 0; $r < $board->size; $r++) {
            for ($c = 0; $c < $board->size; $c++) {
                $cell = $board->cells[$r][$c];
                if ($cell['letter'] === null) {
                    continue;
                }
                $key = !empty($cell['isBlank'])
                    ? '?'
                    : mb_strtoupper($cell['letter'], 'UTF-8');
                if (!isset($counts[$key])) {
                    $counts[$key] = 0;
                }
                $counts[$key]++;
            }
        }
        return $counts;
    }

    /**
     * Upewnia się, że liczba płytek na planszy nie przekracza liczności z zestawu startowego.
     *
     * @throws Exception
     */
    public static function ensureWithinInitialBag(Board $board, array $initialBag): void {
        $counts = self::boardLetterCounts($board);
        foreach ($counts as $letter => $cnt) {
            $limit = $initialBag[$letter] ?? 0;
            if ($cnt > $limit) {
                throw new Exception(
                    sprintf(
                        'Na planszy znajduje się za dużo płytek "%s": %d (limit %d).',
                        $letter,
                        $cnt,
                        $limit
                    )
                );
            }
        }
    }

    /**
     * Usuwa z planszy nowo położone płytki (np. gdy walidacja nie powiedzie się).
     */
    public static function revertPlacement(Board $board, PlacementResult $placement): void {
        foreach ($placement->placed as [$r, $c]) {
            $board->cells[$r][$c] = [
                'letter'  => null,
                'isBlank' => false,
                'locked'  => false,
            ];
        }
    }
    /**
     * Validates that the given rack supplies all newly placed tiles in $placement.
     * Returns the remaining rack string (letters left after the move) or throws
     * an Exception if the rack is missing any required tile.
     *
     * @throws Exception when rack does not contain required tile
     */
    public static function computeRemainingRackAfterPlacement(Board $board, PlacementResult $placement, string $rack): string {
        $rackClean = str_replace(' ', '', $rack);
        $letters = mb_str_split($rackClean, 1, 'UTF-8');
        $rackCounts = [];
        foreach ($letters as $lt) {
            $key = ($lt === '?') ? '?' : mb_strtoupper($lt, 'UTF-8');
            if (!isset($rackCounts[$key])) {
                $rackCounts[$key] = 0;
            }
            $rackCounts[$key]++;
        }

        foreach ($placement->placed as [$pr, $pc]) {
            $cell = $board->cells[$pr][$pc] ?? null;
            if (!$cell || $cell['letter'] === null) {
                throw new Exception('Brak informacji o położonej płytce.');
            }
            if (!empty($cell['isBlank'])) {
                $need = '?';
            } else {
                $need = mb_strtoupper($cell['letter'], 'UTF-8');
            }
            if (empty($rackCounts[$need])) {
                throw new Exception('Ruch niemożliwy przy podanym stojaku — brak wymaganej płytki: ' . $need);
            }
            $rackCounts[$need]--;
        }

        $remaining = '';
        foreach ($letters as $lt) {
            $key = ($lt === '?') ? '?' : mb_strtoupper($lt, 'UTF-8');
            if (!empty($rackCounts[$key])) {
                $remaining .= $lt;
                $rackCounts[$key]--;
            }
        }
        return $remaining;
    }

    private function isLetter(string $ch): bool {
        return $ch !== '(' && $ch !== ')';
    }

    /**
     * Główna funkcja – kładzie słowo na planszy według notacji z nawiasami
     * i zwraca wynik punktowy oraz szczegóły.
     */
    public function placeAndScore(string $pos, string $word): PlacementResult {
        [$row, $col, $orient] = Board::coordToXY($pos);
        $w   = $this->normalize($word);
        $res = new PlacementResult();

        $dr = $orient === 'H' ? 0 : 1;
        $dc = $orient === 'H' ? 1 : 0;

        $letters = $this->tokenize($w);
        $coords  = [];
        $r = $row;
        $c = $col;

        // Ustalenie pól, na które coś kładziemy / z których korzystamy
        foreach ($letters as $tok) {
            if ($tok['type'] === 'exist') {
                // płytka musi już leżeć
                if ($this->board->cells[$r][$c]['letter'] === null) {
                    throw new RuntimeException(
                        "Płytka '{$tok['char']}' w nawiasie wymaga, aby pole "
                        . Board::xyToCoord($r, $c) . " było już zajęte."
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

        // Tymczasowo połóż nowe płytki (locked = false)
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
        $res->score += $this->scoreWordWithDetails(
            $row,
            $col,
            $dr,
            $dc,
            'main',
            $res
        );

        // Krzyżówki dla każdej nowej płytki
        foreach ($res->placed as [$pr, $pc]) {
            $adr = $dc; // prostopadły kierunek
            $adc = $dr;

            $start = $this->findWordStart($pr, $pc, $adr, $adc);
            $len   = $this->wordLen($start[0], $start[1], $adr, $adc);
            if ($len > 1) {
                $res->score += $this->scoreWordWithDetails(
                    $start[0],
                    $start[1],
                    $adr,
                    $adc,
                    'cross',
                    $res
                );
            }
        }

        // Zablokuj nowo położone płytki, żeby w przyszłych ruchach pola premii już nie działały
        foreach ($res->placed as [$pr, $pc]) {
            $this->board->cells[$pr][$pc]['locked'] = true;
        }

        // Bingo za 7 płytek
        if ($res->lettersPlaced === 7) {
            $res->score += 50;
        }

        return $res;
    }

    /**
     * Normalizacja słowa:
     *  - nawiasy zostają jak są,
     *  - wielkie litery pozostają wielkie,
     *  - małe litery (dla blanków) zostają małe.
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
                // mała litera – oznacza blank z przypisaną literą
                $out .= $ch;
            }
        }
        return $out;
    }

    /**
     * Rozbija zapis słowa na tokeny:
     *  - type = 'exist' (litera w nawiasie – już leży na planszy),
     *  - type = 'new'   (nowo kładziona litera, isBlank = true/false).
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

    /**
     * Liczy słowo zaczynając od (row,col) w kierunku (dr,dc),
     * zapisuje szczegóły do PlacementResult::$wordDetails
     * i zwraca liczbę punktów za dane słowo.
     *
     * Zgodnie z ustaleniami:
     *  - najpierw sumujemy wartości literowe (z premiami literowymi),
     *  - na końcu całość mnożymy przez premię słowną,
     *  - nawiasy w zapisie pojawiają się tylko wtedy, gdy jest premia słowna (>1).
     */
    private function scoreWordWithDetails(
        int $row,
        int $col,
        int $dr,
        int $dc,
        string $kind,
        PlacementResult $res
    ): int {
        [$sr, $sc] = $this->findWordStart($row, $col, $dr, $dc);
        $r = $sr;
        $c = $sc;

        $sumLetters = 0;
        $wordMult   = 1;
        $coords     = [];
        $letters    = [];
        $baseScores = [];
        $letterMult = [];

        while (
            $r >= 0 && $c >= 0 &&
            $r < 15 && $c < 15 &&
            $this->board->cells[$r][$c]['letter'] !== null
        ) {
            $cell = $this->board->cells[$r][$c];
            $coords[]  = [$r, $c];
            $letters[] = $cell['letter'];

            $ls = $this->letterScore($cell['letter'], $cell['isBlank']);

            $multL = 1;
            $multW = 1;
            if (!$cell['locked']) {
                $multL = $this->board->L[$r][$c];
                $multW = $this->board->W[$r][$c];
            }

            $baseScores[] = $ls;
            $letterMult[] = $multL;

            $sumLetters += $ls * $multL;
            $wordMult   *= $multW;

            $r += $dr;
            $c += $dc;
        }

        $score = $sumLetters * $wordMult;

        // Zapis wyrażenia literowego, np. "2 * 2 + 1 + 3 * 2"
        $parts = [];
        $cnt   = count($baseScores);
        for ($i = 0; $i < $cnt; $i++) {
            $bs = $baseScores[$i];
            $lm = $letterMult[$i];
            if ($lm > 1) {
                $parts[] = $bs . ' * ' . $lm;
            } else {
                $parts[] = (string)$bs;
            }
        }
        $letterExpr = implode(' + ', $parts);

        if ($kind === 'main') {
            $res->mainWordCoords = $coords;
        }

        $res->wordDetails[] = [
            'kind'          => $kind,
            'word'          => implode('', $letters),
            'letterExpr'    => $letterExpr,
            'sumLetters'    => $sumLetters,
            'wordMultiplier'=> $wordMult,
            'score'         => $score,
        ];

        return $score;
    }
}
