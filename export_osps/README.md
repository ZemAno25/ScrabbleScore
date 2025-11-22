# Eksporter słownika Quackle DAWG do pliku tekstowego

## Opis

Ten program eksportuje słownik Quackle zapisany w formacie DAWG w wersji 1 do zwykłego pliku tekstowego. Każde słowo jest zapisane w osobnej linii w kodowaniu UTF8.

Narzędzie zostało przetestowane na polskim słowniku Scrabble `osps.dawg` i odtwarza słowa dokładnie tak jak Quackle.

Program nie korzysta z bibliotek Qt ani kodu Quackle w czasie działania. Odtwarza tylko format pliku `.dawg` używany przez Quackle.

## Wymagania

1. System zgodny z POSIX (Linux, macOS) z zainstalowanym kompilatorem C++.
2. Kompilator zgodny ze standardem C++17.
3. Plik wejściowy DAWG w wersji 1, na przykład `osps.dawg` wygenerowany przez Quackle przy użyciu narzędzia `makeminidawg`.

## Kompilacja

W katalogu z plikiem `export_osps.cpp` wykonaj:

```bash
g++ -std=c++17 -O2 -Wall -Wextra -pedantic export_osps.cpp -o export_osps
