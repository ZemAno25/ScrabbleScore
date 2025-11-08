<?php
/**
 * Plik przechowujący stałe konfiguracyjne gry Scrabble.
 */

[cite_start]// Wartości punktowe liter [cite: 10-43]
define('LETTER_SCORES', [
    'A' => 1, 'Ą' => 5, 'B' => 3, 'C' => 2, 'Ć' => 6,
    'D' => 2, 'E' => 1, 'Ę' => 5, 'F' => 5, 'G' => 3,
    'H' => 3, 'I' => 1, 'J' => 3, 'K' => 2, 'L' => 2,
    'Ł' => 3, 'M' => 2, 'N' => 1, 'Ń' => 7, 'O' => 1,
    'Ó' => 5, 'P' => 2, 'R' => 1, 'S' => 1, 'Ś' => 5,
    'T' => 2, 'U' => 3, 'W' => 1, 'Y' => 2, 'Z' => 1,
    'Ź' => 9, 'Ż' => 5, '?' => 0 // Blank
]);

[cite_start]// Ilość poszczególnych płytek [cite: 10-43]
define('TILE_DISTRIBUTION', [
    'A' => 9, 'Ą' => 1, 'B' => 2, 'C' => 3, 'Ć' => 1,
    'D' => 3, 'E' => 7, 'Ę' => 1, 'F' => 1, 'G' => 2,
    'H' => 2, 'I' => 8, 'J' => 2, 'K' => 3, 'L' => 3,
    'Ł' => 2, 'M' => 3, 'N' => 5, 'Ń' => 1, 'O' => 6,
    'Ó' => 1, 'P' => 3, 'R' => 4, 'S' => 4, 'Ś' => 1,
    'T' => 3, 'U' => 2, 'W' => 4, 'Y' => 4, 'Z' => 5,
    'Ź' => 1, 'Ż' => 1, '?' => 2 // Blanki
]);

// Plansza ma 15x15
define('BOARD_SIZE', 15);
define('RACK_SIZE', 7); // 7 płytek na stojaku
define('BINGO_BONUS', 50); // Premia za 7 płytek

[cite_start]// Definicje pól premiowych [cite: 53-57]
define('BONUS_NONE', 'NONE');
define('BONUS_DL', 'PL2'); // Podwójna literowa
define('BONUS_TL', 'PL3'); // Potrójna literowa
define('BONUS_DW', 'PS2'); // Podwójna słowna
define('BONUS_TW', 'PS3'); // Potrójna słowna


/**
 * Zwraca tablicę 15x15 reprezentującą planszę i jej pola premiowe
 * [cite_start]zgodnie z zasadami [cite: 58-62] i z uwzględnieniem, że 8H nie jest premią.
 */
function getBoardBonuses() {
    $board = array_fill(0, BOARD_SIZE, array_fill(0, BOARD_SIZE, BONUS_NONE));

    // Potrójna Słowna (TW / PS3)
    // [1,A], [1,H], [1,O], [8,A], [8,O], [15,A], [15,H], [15,O]
    $tw_coords = [
        [0,0], [0,7], [0,14], 
        [7,0], [7,14], 
        [14,0], [14,7], [14,14]
    ];
    foreach ($tw_coords as $c) $board[$c[0]][$c[1]] = BONUS_TW;

    // Podwójna Słowna (DW / PS2)
    // Konwersja z Twojej listy, np. [2,B] -> [1,1], [14,N] -> [13,13]
    // Usunęliśmy [7,7] (8H) zgodnie z Twoim doprecyzowaniem.
    $dw_coords = [
        [1,1], [1,13], // 2B, 2N
        [2,2], [2,12], // 3C, 3M
        [3,3], [3,11], // 4D, 4L
        [4,4], [4,10], // 5E, 5K
        // Środek [7,7] (8H) JEST POMINIĘTY
        [10,4], [10,10], // 11E, 11K
        [11,3], [11,11], // 12D, 12L
        [12,2], [12,12], // 13C, 13M
        [13,1], [13,13]  // 14B, 14N
    ];
    foreach ($dw_coords as $c) $board[$c[0]][$c[1]] = BONUS_DW;

    // Potrójna Literowa (TL / PL3)
    // [2,F], [2,J], [6,B], [6,F], [6,J], [6,N], ...
    $tl_coords = [
        [1,5], [1,9], // 2F, 2J
        [5,1], [5,5], [5,9], [5,13], // 6B, 6F, 6J, 6N
        [9,1], [9,5], [9,9], [9,13], // 10B, 10F, 10J, 10N
        [13,5], [13,9]  // 14F, 14J
    ];
    foreach ($tl_coords as $c) $board[$c[0]][$c[1]] = BONUS_TL;

    // Podwójna Literowa (DL / PL2)
    // [1,D], [1,L], [3,G], [3,I], [4,A], [4,H], [4,O], ...
    $dl_coords = [
        [0,3], [0,11], // 1D, 1L
        [2,6], [2,8], // 3G, 3I
        [3,0], [3,7], [3,14], // 4A, 4H, 4O
        [6,2], [6,6], [6,8], [6,12], // 7C, 7G, 7I, 7M
        [7,3], [7,11], // 8D, 8L
        [8,2], [8,6], [8,8], [8,12], // 9C, 9G, 9I, 9M
        [11,0], [11,7], [11,14], // 12A, 12H, 12O
        [12,6], [12,8], // 13G, 13I
        [14,3], [14,11]  // 15D, 15L
    ];
    foreach ($dl_coords as $c) $board[$c[0]][$c[1]] = BONUS_DL;
    
    return $board;
}

// Globalna mapa bonusów, aby nie liczyć jej za każdym razem
$GLOBALS['BOARD_BONUSES'] = getBoardBonuses();

?>
