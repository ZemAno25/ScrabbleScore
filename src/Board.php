<?php
// src/Board.php
class Board {
    public int $size = 15;
    /** @var array<int,array<int,array{letter:?string,isBlank:bool,locked:bool}>> */
    public array $cells;
    /** @var array<int,array<int,int>> letter multipliers (1,2,3) */
    public array $L;
    /** @var array<int,array<int,int>> word multipliers (1,2,3) */
    public array $W;

    public function __construct() {
        $this->cells = array_fill(0, $this->size, array_fill(0, $this->size, ['letter'=>null,'isBlank'=>false,'locked'=>false]));
        $this->initPremiums();
    }

    private function initPremiums(): void {
        $n = $this->size;
        $this->L = array_fill(0,$n,array_fill(0,$n,1));
        $this->W = array_fill(0,$n,array_fill(0,$n,1));

        // Standard Scrabble premiums in Quackle orientation (rows 1..15, cols A..O)
        // Word x3 (PS3)
        $ps3 = [[0,0],[0,7],[0,14],[7,0],[7,14],[14,0],[14,7],[14,14]];
        // Word x2 (PS2)
        $ps2 = [[1,1],[2,2],[3,3],[4,4],[13,13],[12,12],[11,11],[10,10],[1,13],[2,12],[3,11],[4,10],[10,4],[11,3],[12,2],[13,1]];
        // Letter x3 (PL3)
        $pl3 = [[1,5],[1,9],[5,1],[5,5],[5,9],[5,13],[9,1],[9,5],[9,9],[9,13],[13,5],[13,9]];
        // Letter x2 (PL2)
        $pl2 = [[0,3],[0,11],[2,6],[2,8],[3,0],[3,7],[3,14],[6,2],[6,6],[6,8],[6,12],
                [7,3],[7,11],[8,2],[8,6],[8,8],[8,12],[11,0],[11,7],[11,14],[12,6],[12,8],[14,3],[14,11]];

        foreach ($ps3 as [$r,$c]) $this->W[$r][$c] = 3;
        foreach ($ps2 as [$r,$c]) { $this->W[$r][$c] = 2; $this->W[$c][$r] = 2; } // symmetric already covered
        foreach ($pl3 as [$r,$c]) { $this->L[$r][$c] = 3; $this->L[$c][$r] = 3; }
        foreach ($pl2 as [$r,$c]) { $this->L[$r][$c] = 2; $this->L[$c][$r] = 2; }
    }

    public static function coordToXY(string $pos): array {
        // If starts with digit -> horizontal: row then column letter(s)
        if (preg_match('/^([1-9]|1[0-5])([A-O])$/u', strtoupper($pos), $m)) {
            $row = intval($m[1]) - 1;
            $col = ord($m[2]) - ord('A');
            return [$row,$col,'H'];
        }
        // If starts with letter -> vertical
        if (preg_match('/^([A-O])([1-9]|1[0-5])$/u', strtoupper($pos), $m)) {
            $col = ord($m[1]) - ord('A');
            $row = intval($m[2]) - 1;
            return [$row,$col,'V'];
        }
        throw new InvalidArgumentException("Niepoprawna pozycja: $pos");
    }

    public static function xyToCoord(int $row, int $col): string {
        return sprintf('%d%s', $row+1, chr(ord('A')+$col));
    }
}
