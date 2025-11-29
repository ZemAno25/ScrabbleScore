<?php
// src/PolishLetters.php
class PolishLetters {
    /**
     * Wartości punktowe liter w polskim Scrabble.
     * Używane przy liczeniu punktów (blanki zawsze mają 0).
     */
    public static function values(): array {
        return [
            'A'=>1,'Ą'=>5,'B'=>3,'C'=>2,'Ć'=>6,'D'=>2,'E'=>1,'Ę'=>5,'F'=>5,'G'=>3,'H'=>3,'I'=>1,'J'=>3,
            'K'=>2,'L'=>2,'Ł'=>3,'M'=>2,'N'=>1,'Ń'=>7,'O'=>1,'Ó'=>5,'P'=>2,'R'=>1,'S'=>1,'Ś'=>5,'T'=>2,
            'U'=>3,'W'=>4,'Y'=>2,'Z'=>1,'Ź'=>9,'Ż'=>5
        ];
    }

    /**
     * Startowa zawartość "worka" – liczba wszystkich płytek danego typu.
     * Używane do wyliczania, co zostało w worku na podstawie tego,
     * co aktualnie leży na planszy (ignorujemy stojaki graczy).
     */
    public static function bagCounts(): array {
        return [
            // Litera => liczba płytek
            'A' => 9,
            'Ą' => 1,
            'B' => 2,
            'C' => 3,
            'Ć' => 1,
            'D' => 3,
            'E' => 7,
            'Ę' => 1,
            'F' => 1,
            'G' => 2,
            'H' => 2,
            'I' => 8,
            'J' => 2,
            'K' => 3,
            'L' => 3,
            'Ł' => 2,
            'M' => 3,
            'N' => 5,
            'Ń' => 1,
            'O' => 6,
            'Ó' => 1,
            'P' => 3,
            'R' => 4,
            'S' => 4,
            'Ś' => 1,
            'T' => 3,
            'U' => 2,
            'W' => 4,
            'Y' => 4,
            'Z' => 5,
            'Ź' => 1,
            'Ż' => 1,
            '?' => 2, // dwa blanki
        ];
    }
}
