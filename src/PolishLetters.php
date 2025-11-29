<?php
// src/PolishLetters.php

class PolishLetters
{
    /**
     * Zwraca mapę: LITERA => wartość punktowa w polskim Scrabble.
     *
     * Uwaga:
     *  - To są wartości punktowe, NIE liczności płytek.
     *  - Liczności płytek wykorzystujemy osobno (np. w play.php w tablicy $initialBag).
     */
    public static function values(): array
    {
        return [
            'A' => 1,
            'Ą' => 5,
            'B' => 3,
            'C' => 2,
            'Ć' => 6,
            'D' => 2,
            'E' => 1,
            'Ę' => 5,
            'F' => 5,
            'G' => 3,
            'H' => 3,
            'I' => 1,
            'J' => 3,
            'K' => 2,
            'L' => 2,
            'Ł' => 3,
            'M' => 2,
            'N' => 1,
            'Ń' => 7,
            'O' => 1,
            'Ó' => 5,
            'P' => 2,
            'R' => 1,
            'S' => 1,
            'Ś' => 5,
            'T' => 2,
            'U' => 3,
            'W' => 1, // POPRAWKA: w polskim Scrabble litera W jest za 1 punkt
            'Y' => 2,
            'Z' => 1,
            'Ź' => 9,
            'Ż' => 5,
        ];
    }
}
