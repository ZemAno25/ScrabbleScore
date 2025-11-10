<?php
// src/Scrabble/Board.php - Poprawiono błąd "Illegal offset type"

namespace App\Scrabble;

class Board
{
    private array $board;

    // Definicja pól premiowych (DW, TW, DL, TL) i ich współrzędne
    // KLUCZOWA POPRAWKA: Klucze są teraz ciągami znaków "Y,X"
    private const PREMIUM_SPOTS = [
        // Potrójne Słowo (TW)
        '0,0' => 'TW', '0,7' => 'TW', '0,14' => 'TW',
        '7,0' => 'TW', '7,14' => 'TW',
        '14,0' => 'TW', '14,7' => 'TW', '14,14' => 'TW',

        // Podwójne Słowo (DW)
        '1,1' => 'DW', '2,2' => 'DW', '3,3' => 'DW', '4,4' => 'DW',
        '1,13' => 'DW', '2,12' => 'DW', '3,11' => 'DW', '4,10' => 'DW',
        '13,1' => 'DW', '12,2' => 'DW', '11,3' => 'DW', '10,4' => 'DW',
        '13,13' => 'DW', '12,12' => 'DW', '11,11' => 'DW', '10,10' => 'DW',

        // Potrójna Litera (TL)
        '1,5' => 'TL', '1,9' => 'TL', '5,1' => 'TL', '5,5' => 'TL', '5,9' => 'TL', '5,13' => 'TL',
        '9,1' => 'TL', '9,5' => 'TL', '9,9' => 'TL', '9,13' => 'TL', '13,5' => 'TL', '13,9' => 'TL',

        // Podwójna Litera (DL)
        '0,3' => 'DL', '0,11' => 'DL', '2,6' => 'DL', '2,8' => 'DL', '3,0' => 'DL', '3,7' => 'DL', '3,14' => 'DL',
        '6,2' => 'DL', '6,6' => 'DL', '6,8' => 'DL', '6,12' => 'DL', '7,3' => 'DL', '7,11' => 'DL',
        '8,2' => 'DL', '8,6' => 'DL', '8,8' => 'DL', '8,12' => 'DL', '11,0' => 'DL', '11,7' => 'DL', '11,14' => 'DL',
        '12,6' => 'DL', '12,8' => 'DL', '14,3' => 'DL', '14,11' => 'DL',
    ];

    public function __construct()
    {
        $this->initializeBoard();
    }

    private function initializeBoard(): void
    {
        $this->board = [];
        for ($y = 0; $y < 15; $y++) {
            for ($x = 0; $x < 15; $x++) {
                $premiumKey = "{$y},{$x}"; // Użycie ciągów "y,x" jako klucza
                $premium = self::PREMIUM_SPOTS[$premiumKey] ?? null;

                $this->board[$y][$x] = [
                    'tile' => null,
                    'premium' => $premium
                ];
            }
        }
    }

    public function getTile(int $y, int $x): ?array
    {
        if ($y < 0 || $y >= 15 || $x < 0 || $x >= 15) {
            return null;
        }
        return $this->board[$y][$x]['tile'];
    }

    /**
     * Sprawdza, czy dane pole na planszy jest już zajęte płytką.
     */
    public function isOccupied(int $y, int $x): bool
    {
        // Sprawdzenie granic
        if ($y < 0 || $y >= 15 || $x < 0 || $x >= 15) {
            return false;
        }

        return $this->board[$y][$x]['tile'] !== null;
    }

    public function placeTile(int $y, int $x, string $letter, bool $isBlank = false): bool
    {
        if (!$this->isOccupied($y, $x)) {
            $this->board[$y][$x]['tile'] = [
                'letter' => $letter,
                'isBlank' => $isBlank
            ];
            return true;
        }
        return false;
    }

    public function getPremium(int $y, int $x): ?string
    {
        if ($y < 0 || $y >= 15 || $x < 0 || $x >= 15) {
            return null;
        }
        // Premium jest ustawione tylko raz, w initializeBoard, i nie jest pobierane z PREMIUM_SPOTS
        return $this->board[$y][$x]['premium']; 
    }

    public function removePremium(int $y, int $x): void
    {
        if ($y >= 0 && $y < 15 && $x >= 0 && $x < 15) {
            $this->board[$y][$x]['premium'] = null;
        }
    }

    public function isCenter(int $y, int $x): bool
    {
        return $y === 7 && $x === 7;
    }
}