<?php
// src/Scrabble/ScoreCalculator.php - FINALNA KOREKTA LOGIKI PUNKTACJI I DEBUGOWANIE KOORDYNAT

namespace App\Scrabble;

class ScoreCalculator
{
    private Board $board;

    // Standardowe wartości punktowe dla liter w polskim Scrabble
    private const LETTER_VALUES = [
        'A' => 1, 'B' => 3, 'C' => 2, 'Ć' => 6, 'D' => 2, 'E' => 1, 'Ę' => 5, 
        'F' => 5, 'G' => 3, 'H' => 3, 'I' => 1, 'J' => 3, 'K' => 2, 'L' => 2,
        'Ł' => 3, 'M' => 2, 'N' => 1, 'Ń' => 7, 'O' => 1, 'Ó' => 5, 'P' => 2,
        'R' => 1, 'S' => 1, 'Ś' => 5, 'T' => 2, 'U' => 3, 'W' => 1, 'Y' => 3,
        'Z' => 1, 'Ź' => 9, 'Ż' => 5, '?' => 0 // Blank
    ];

    public function __construct(Board $board)
    {
        $this->board = $board;
    }

    /**
     * Główna funkcja obliczająca punkty za ruch PLAY.
     */
    public function calculate(array $parsedMove): array
    {
        if ($parsedMove['type'] !== 'PLAY') {
            return ['points' => 0, 'is_bingo' => false];
        }

        $result = $this->locateWordAndGetPlayedTiles($parsedMove);
        $fullPlacement = $result['fullPlacement'];
        $newlyPlacedTiles = $result['newlyPlacedTiles'];
        
        if (empty($newlyPlacedTiles)) {
             throw new \Exception("Nie położono żadnej nowej płytki. Zadbaj o prawidłowe użycie małych liter i nawiasów.");
        }

        // 1. Umieść płytki na tymczasowej planszy do obliczeń
        foreach ($newlyPlacedTiles as $tile) {
            $this->board->placeTile($tile['y'], $tile['x'], $tile['letter'], $tile['isBlank']);
        }

        $score = $this->calculateScoreForPlacedTiles($newlyPlacedTiles, $fullPlacement);

        // 2. Dodaj bonus za BINGO
        $isBingo = count($newlyPlacedTiles) === 7;
        if ($isBingo) {
            $score += 50;
        }

        return [
            'points' => $score,
            'is_bingo' => $isBingo
        ];
    }

    /**
     * Wyszukuje położenie słowa i zwraca listę wszystkich płytek (nowych i istniejących)
     * w tym słowie.
     */
    public function locateWordAndGetPlayedTiles(array $parsedMove): array
    {
        // STARTOWE KOORDYNATY SĄ POBIERANE Z PIERWSZEJ NOWEJ PŁYTKI
        $startRow = $parsedMove['playedTiles'][0]['y'];
        $startCol = $parsedMove['playedTiles'][0]['x'];
        $direction = $parsedMove['direction'];
        $word = $parsedMove['cleanWord'];
        $elements = $this->splitWordIntoElements($word);
        
        $fullPlacement = [];
        $newlyPlacedTiles = [];
        $currentX = $startCol;
        $currentY = $startRow;

        error_log("Scrabble Debug: START PARSOWANIA SŁOWA: {$word}");
        // Wprowadzamy konwersję na char, by mieć pewność, że w logach jest poprawny znak
        $coordName = chr(ord('A') + $startCol) . ($startRow + 1);
        error_log("Scrabble Debug: Koordynaty startowe: {$coordName} (Y:{$startRow}, X:{$startCol}). Kierunek: {$direction}");

        foreach ($elements as $element) {
            $y = $currentY;
            $x = $currentX;
            
            // 1. Sprawdzenie, czy płytka jest oznaczona w wejściu jako ISTNIEJĄCA (nawias)
            $isExistingInInput = \preg_match('/^\(([A-ZĄĆĘŁŃÓŚŹŻ])\)$/u', $element, $m);
            $expectedLetter = $isExistingInInput ? $m[1] : null;

            $tileOnBoard = $this->board->getTile($y, $x);
            
            if ($isExistingInInput) {
                // JEŚLI JEST W NAWIASIE: 
                
                if ($tileOnBoard === null) {
                    // UWAGA: Ta linia generuje błąd. Weryfikujemy, czy koordynaty są poprawne.
                    throw new \Exception("Płytka '{$element}' wymaga, aby pole " . chr(ord('A') + $x) . ($y + 1) . " było już zajęte.");
                }
                
                // Dodajemy płytkę z planszy do pełnego umiejscowienia
                $fullPlacement[] = array_merge($tileOnBoard, [
                    'y' => $y, 
                    'x' => $x, 
                    'isNew' => false // Oznaczamy jako nie-nową
                ]);

            } else {
                // JEŚLI NIE JEST W NAWIASIE (nowa płytka)
                
                // Znajdź nowo położoną płytkę z MoveParsera
                $newTile = null;
                foreach ($parsedMove['playedTiles'] as $tile) {
                    if ($tile['x'] === $x && $tile['y'] === $y) {
                        $newTile = $tile;
                        break;
                    }
                }
                
                if (!$newTile) {
                     // Ta sytuacja powinna nastąpić tylko, jeśli płytka jest nowa, 
                     // ale poza zakresem słowa, co jest niemożliwe.
                     throw new \Exception("Błąd logiczny: Nowa płytka w słowie nie została odnaleziona dla pola " . chr(ord('A') + $x) . ($y + 1) . ".");
                }
                
                // Sprawdzamy, czy pole jest już zajęte (błąd, jeśli próbujemy położyć na zajętym)
                if ($tileOnBoard !== null) {
                    throw new \Exception("Płytka '{$element}' (oznaczona jako nowa) położona na już zajętym polu " . chr(ord('A') + $x) . ($y + 1) . ".");
                }

                // Dodajemy nową płytkę do pełnego umiejscowienia i nowo położonych
                $fullPlacement[] = $newTile;
                $newlyPlacedTiles[] = $newTile;
            }

            // Przejście do następnego pola
            if ($direction === 'H') {
                $currentX++;
            } else { 
                $currentY++; // Zakładamy V, jeśli nie H.
            }
        }

        return [
            'fullPlacement' => $fullPlacement,
            'newlyPlacedTiles' => $newlyPlacedTiles
        ];
    }

    /**
     * Oblicza punktację za wszystkie słowa utworzone przez nowo położone płytki.
     */
    private function calculateScoreForPlacedTiles(array $newlyPlacedTiles, array $mainWordFullPlacement): int
    {
        // 1. Obliczanie wyniku dla głównego słowa (tego, które zostało wpisane)
        $totalScore = $this->getWordScore($mainWordFullPlacement, $newlyPlacedTiles);

        // 2. Obliczanie wyniku dla słów pobocznych
        $processedCoords = [];

        foreach ($newlyPlacedTiles as $newTile) {
            $y = $newTile['y'];
            $x = $newTile['x'];
            
            // Ustalenie prostopadłego kierunku na podstawie położenia głównego słowa
            $mainDirection = $this->getMainDirection($mainWordFullPlacement);
            $perpendicularDirection = $this->getPerpendicularDirection($mainDirection);
            $coordKey = "{$y}-{$x}-{$perpendicularDirection}";

            if (!isset($processedCoords[$coordKey]) && $this->formsPerpendicularWord($y, $x, $perpendicularDirection)) {
                $perpendicularWordPlacement = $this->getWordPlacement($y, $x, $perpendicularDirection);
                
                if (count($perpendicularWordPlacement) > 1) {
                    $totalScore += $this->getWordScore($perpendicularWordPlacement, $newlyPlacedTiles);
                }
                // Zapobiegaj ponownemu obliczeniu
                foreach ($perpendicularWordPlacement as $tile) {
                     $processedCoords["{$tile['y']}-{$tile['x']}-{$perpendicularDirection}"] = true;
                }
            }
        }

        return $totalScore;
    }
    
    // --- NOWA METODA POMOCNICZA ---
    private function getMainDirection(array $mainWordFullPlacement): string
    {
        if (count($mainWordFullPlacement) <= 1) return 'H'; // Domyślna
        if ($mainWordFullPlacement[0]['y'] === $mainWordFullPlacement[1]['y']) return 'H';
        return 'V';
    }


    private function getPerpendicularDirection(string $mainDirection): string
    {
        return $mainDirection === 'H' ? 'V' : 'H';
    }

    private function formsPerpendicularWord(int $y, int $x, string $direction): bool
    {
        if ($direction === 'H') {
            return ($this->board->isOccupied($y, $x - 1) || $this->board->isOccupied($y, $x + 1));
        } else { // V
            return ($this->board->isOccupied($y - 1, $x) || $this->board->isOccupied($y + 1, $x));
        }
    }

    private function getWordPlacement(int $y, int $x, string $direction): array
    {
        $placement = [];
        
        // Znajdź początek słowa (cofamy się)
        $startOffset = 0;
        while (true) {
            $prevY = $direction === 'V' ? $y - $startOffset - 1 : $y;
            $prevX = $direction === 'H' ? $x - $startOffset - 1 : $x;

            if ($this->board->isOccupied($prevY, $prevX)) {
                $startOffset++;
            } else {
                break;
            }
        }
        
        // Zbuduj słowo (idziemy do przodu)
        $currentOffset = 0;
        while (true) {
            $currY = $direction === 'V' ? $y - $startOffset + $currentOffset : $y;
            $currX = $direction === 'H' ? $x - $startOffset + $currentOffset : $x;

            $tile = $this->board->getTile($currY, $currX);

            if ($tile) {
                // Konieczne jest dodanie flagi isNew na podstawie listy nowo położonych
                $isNew = false;
                // Uproszczone sprawdzenie (zakładamy, że ta płytka jest nowa, jeśli była w liście)
                // W rzeczywistości, dla słów pobocznych, należy porównać z $newlyPlacedTiles
                // Dla uproszczenia (ponieważ ScoreCalculator pracuje na planszy po położeniu):
                // każda płytka, która nie jest na planszy przed ruchem, jest nowa.
                
                $placement[] = array_merge($tile, ['y' => $currY, 'x' => $currX]);
                $currentOffset++;
            } else {
                break;
            }
        }

        return $placement;
    }


    /**
     * Oblicza wynik dla pojedynczego słowa.
     */
    private function getWordScore(array $wordPlacement, array $newlyPlacedTiles): int
    {
        $wordMultiplier = 1;
        $score = 0;

        foreach ($wordPlacement as $tile) {
            $y = $tile['y'];
            $x = $tile['x'];
            $letter = $tile['letter'];
            $isBlank = $tile['isBlank'];

            $isNewlyPlaced = $this->isTileInList($tile, $newlyPlacedTiles);
            $letterValue = $this->getLetterValue($letter, $isBlank);
            
            // Premie są pobierane z PLANSZY!
            $premium = $this->board->getPremium($y, $x);
            
            // Płytki już na planszy (nie mają premii) - W TYM MIEJSCU SĄ NOWE I STARE PŁYTKI
            if (!$isNewlyPlaced) {
                 $score += $letterValue;
                 continue;
            }

            // Płytki nowo położone (biorą premię)
            $letterMultiplier = 1;
            switch ($premium) {
                case 'DL':
                    $letterMultiplier = 2;
                    break;
                case 'TL':
                    $letterMultiplier = 3;
                    break;
                case 'DW':
                    $wordMultiplier *= 2;
                    break;
                case 'TW':
                    $wordMultiplier *= 3;
                    break;
            }
            
            $score += $letterValue * $letterMultiplier;

            // Wyłącz premię po użyciu
            if ($premium) {
                 $this->board->removePremium($y, $x);
            }
        }

        return $score * $wordMultiplier;
    }

    // Metoda pomocnicza do sprawdzania, czy płytka jest nowo położona
    private function isTileInList(array $tile, array $tileList): bool
    {
        foreach ($tileList as $listTile) {
            if ($tile['x'] === $listTile['x'] && $tile['y'] === $listTile['y']) {
                return true;
            }
        }
        return false;
    }


    private function getLetterValue(string $letter, bool $isBlank): int
    {
        if ($isBlank) {
            return 0;
        }
        $letter = \mb_strtoupper($letter, 'UTF-8'); 
        return self::LETTER_VALUES[$letter] ?? 0;
    }

    private function splitWordIntoElements(string $word): array
    {
        $pattern = '/([A-Za-zĄĆĘŁŃÓŚŹŻąćęłńóśźż]|\([A-ZĄĆĘŁŃÓŚŹŻ]\))/u';
        \preg_match_all($pattern, $word, $matches);
        return $matches[0];
    }
}