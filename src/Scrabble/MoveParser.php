<?php
// src/Scrabble/MoveParser.php - PRZYWRÓCONY PRIORYTET KIERUNKU H przed V z ręcznym mapowaniem koordynat

namespace App\Scrabble;

class MoveParser
{
    // Standardowe wartości punktowe (używane do identyfikacji blanków)
    public const LETTER_VALUES = [
        'A' => 1, 'B' => 3, 'C' => 2, 'Ć' => 6, 'D' => 2, 'E' => 1, 'Ę' => 5, 
        'F' => 5, 'G' => 3, 'H' => 3, 'I' => 1, 'J' => 3, 'K' => 2, 'L' => 2,
        'Ł' => 3, 'M' => 2, 'N' => 1, 'Ń' => 7, 'O' => 1, 'Ó' => 5, 'P' => 2,
        'R' => 1, 'S' => 1, 'Ś' => 5, 'T' => 2, 'U' => 3, 'W' => 1, 'Y' => 3,
        'Z' => 1, 'Ź' => 9, 'Ż' => 5, '?' => 0 // Blank
    ];

    /**
     * Główna funkcja parsująca wejście dla ruchów innych niż PLAY (PASS, EXCHANGE).
     */
    public function parse(string $rawInput, bool $hasRack = false): array
    {
        $input = \trim(\mb_strtoupper($rawInput, 'UTF-8'));

        if ($input === 'PASS') {
            return ['type' => 'PASS'];
        }

        if (\preg_match('/^EX\s+(.+)$/i', $input, $matches)) {
            $letters = $matches[1];
            if (!\preg_match('/^[A-ZĄĆĘŁŃÓŚŹŻ?]+$/u', $letters)) {
                throw new \Exception("Niepoprawne litery do wymiany. Używaj tylko wielkich liter lub '?'.");
            }
            return [
                'type' => 'EXCHANGE',
                'letters' => $letters
            ];
        }

        throw new \Exception("Niepoprawny format ruchu. Użyj PASS lub EX SŁOWA.");
    }

    /**
     * Funkcja parsująca ruch PLAY (Stojak Koordynaty Słowo).
     */
    public function parsePlay(?string $rackInput, string $coordInput, string $wordInput, bool $hasRack = false): array
    {
        // 1. Walidacja i przetwarzanie stojaka (Rack)
        $rack = null;
        if ($hasRack) {
            $rack = \trim(\mb_strtoupper($rackInput ?? '', 'UTF-8'));
            if (empty($rack) || !\preg_match('/^[A-ZĄĆĘŁŃÓŚŹŻ?]{1,7}$/u', $rack)) {
                throw new \Exception("Niepoprawny stojak. Musi zawierać 1-7 wielkich liter lub '?'.");
            }
        }
        
        // 2. Walidacja i przetwarzanie słowa (Word)
        $cleanWord = \trim(\mb_strtoupper($wordInput, 'UTF-8'));
        $wordPattern = '/^([A-ZĄĆĘŁŃÓŚŹŻąćęłńóśźż]|\([A-ZĄĆĘŁŃÓŚŹŻ]\))+$/u';

        if (empty($cleanWord) || !\preg_match($wordPattern, $cleanWord)) {
            throw new \Exception("Niepoprawne słowo. Oczekiwane litery (duże/małe) lub litery w nawiasach (np. (O)).");
        }

        // 3. Walidacja i parsowanie koordynat
        $coordInput = \trim(\mb_strtoupper($coordInput, 'UTF-8'));
        
        // Wyodrębnienie litery i numeru niezależnie od kolejności (bezpieczne parsowanie)
        $colChar = \preg_replace('/[^A-O]/', '', $coordInput); // Tylko litery A-O
        $rowNum = \preg_replace('/[^0-9]/', '', $coordInput);  // Tylko cyfry 0-9
        
        // Finalna walidacja
        if (empty($colChar) || empty($rowNum) || \strlen($colChar) > 1 || (int)$rowNum < 1 || (int)$rowNum > 15) {
            throw new \Exception("Niepoprawny format koordynat startowych: {$coordInput}. Oczekiwano jednej litery A-O i numeru 1-15.");
        }

        // Obliczanie indeksów:
        
        // Ręczne mapowanie (bezpieczne, niezależne od ord/iconv)
        $charIndexMap = [
            'A' => 0, 'B' => 1, 'C' => 2, 'D' => 3, 'E' => 4, 'F' => 5, 'G' => 6, 
            'H' => 7, 'I' => 8, 'J' => 9, 'K' => 10, 'L' => 11, 'M' => 12, 'N' => 13, 'O' => 14
        ];

        if (!isset($charIndexMap[$colChar])) {
            throw new \Exception("Błąd mapowania litery kolumny: {$colChar}.");
        }

        $startCol = $charIndexMap[$colChar]; 
        $startRow = (int)$rowNum - 1;
        
        if ($startCol < 0 || $startCol >= 15 || $startRow < 0 || $startRow >= 15) {
             throw new \Exception("Koordynaty {$coordInput} są poza planszą (1-15, A-O).");
        }
        
        // 4. Ekstrakcja położonych płytek i ustalenie kierunku 
        $elements = $this->splitWordIntoElements($cleanWord);
        $playedTiles = [];
        $direction = null; 
        
        // Generujemy dwa warianty (H i V) do sprawdzenia kolinearności
        $tempTilesH = $this->getNewTilesBasedOnDirection($elements, $startRow, $startCol, 'H');
        $tempTilesV = $this->getNewTilesBasedOnDirection($elements, $startRow, $startCol, 'V');
        
        if (count($tempTilesH) === 1) { // Ruch jednopłytkowy (domyślnie V, musi być H lub V)
            $playedTiles = $this->filterNewTiles($tempTilesH);
            $direction = 'V'; 
        } elseif (count($tempTilesH) > 1 && $this->areTilesCollinear($tempTilesH, 'H')) {
            // PRIORYTET DLA KIERUNKU POZIOMEGO (H)
            $playedTiles = $this->filterNewTiles($tempTilesH);
            $direction = 'H';
        } elseif (count($tempTilesV) > 1 && $this->areTilesCollinear($tempTilesV, 'V')) {
            $playedTiles = $this->filterNewTiles($tempTilesV);
            $direction = 'V';
        } else {
             throw new \Exception("Słowo musi być położone poziomo lub pionowo (i być ciągłe).");
        }
        
        if (empty($playedTiles)) {
             throw new \Exception("Nie wykryto nowych płytek do położenia.");
        }
        
        // 5. Walidacja stojaka (jeśli ma zastosowanie) - czy gracz miał te płytki?
        if ($hasRack) {
            $this->validateRackUsage($rack, $playedTiles);
        }

        return [
            'type' => 'PLAY',
            'rack' => $rack,
            'startCoord' => $coordInput,
            'cleanWord' => $cleanWord,
            'direction' => $direction,
            'playedTiles' => $playedTiles
        ];
    }
    
    
    // --- Metody pomocnicze ---
    
    private function splitWordIntoElements(string $word): array
    {
        $pattern = '/([A-Za-zĄĆĘŁŃÓŚŹŻąćęłńóśźż]|\([A-ZĄĆĘŁŃÓŚŹŻ]\))/u';
        \preg_match_all($pattern, $word, $matches);
        return $matches[0];
    }

    private function getNewTilesBasedOnDirection(array $elements, int $startRow, int $startCol, string $direction): array
    {
        $tiles = [];
        $currentX = $startCol;
        $currentY = $startRow;
        
        foreach ($elements as $element) {
            
            $isNew = !\preg_match('/^\([A-ZĄĆĘŁŃÓŚŹŻ]\)$/u', $element);

            $letter = \mb_strtoupper($element, 'UTF-8');
            $isBlank = (\mb_strtolower($element, 'UTF-8') !== $element) || ($element === '?');
            
            $tiles[] = [
                'y' => $currentY,
                'x' => $currentX,
                'letter' => $letter, 
                'isBlank' => $isBlank,
                'isNew' => $isNew // Flaga isNew dla walidacji i filtrowania
            ];

            if ($direction === 'H') {
                $currentX++;
            } elseif ($direction === 'V') {
                $currentY++;
            }
        }
        
        return $tiles;
    }

    private function areTilesCollinear(array $tiles, string $direction): bool
    {
        if (count($tiles) <= 1) return true;
        
        if ($direction === 'H') {
            $y = $tiles[0]['y'];
            foreach ($tiles as $tile) {
                if ($tile['y'] !== $y) return false;
            }
        } else { // V
            $x = $tiles[0]['x'];
            foreach ($tiles as $tile) {
                if ($tile['x'] !== $x) return false;
            }
        }
        return true;
    }

    private function filterNewTiles(array $allTiles): array
    {
        $newTiles = [];
        foreach ($allTiles as $tile) {
            if ($tile['isNew']) {
                $newTiles[] = $tile;
            }
        }
        return $newTiles;
    }
    
    private function validateRackUsage(?string $rack, array $playedTiles): void
    {
        if (!$rack) {
            return;
        }

        $rackLetters = \preg_split('//u', $rack, -1, PREG_SPLIT_NO_EMPTY);
        
        $usedTiles = [];

        foreach ($playedTiles as $tile) {
            $usedTiles[] = \mb_strtoupper($tile['letter'], 'UTF-8');
        }

        foreach ($usedTiles as $usedLetter) {
            $index = \array_search($usedLetter, $rackLetters);

            if ($index !== false) {
                unset($rackLetters[$index]);
            } else {
                $blankIndex = \array_search('?', $rackLetters);
                if ($blankIndex !== false) {
                    unset($rackLetters[$blankIndex]);
                } else {
                    throw new \Exception("Płytka '{$usedLetter}' nie znajduje się na stojaku. Sprawdź swój stojak i/lub użycie małych liter/blanków.");
                }
            }
        }
    }
}