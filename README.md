# ScrabbleScore (PHP/PostgreSQL)

Wymagania: PHP 8.1+, Apache 2.x, PostgreSQL 14.

## Instalacja
1. Utwórz bazę `scrabblegames` i użytkownika `scrabble_usr` z pełnymi uprawnieniami.
2. Uruchom skrypt `sql/schema.sql`.
3. Skonfiguruj `config.php` (DSN, hasło).
4. Skopiuj katalog `public/` pod DocumentRoot serwera (np. `/var/www/html/scrabblescore`) oraz `src/`, `assets/`, `config.php` obok.
5. Wejdź na `/public/index.php`.

## Użycie notacji ruchów
- `PASS` i `EXCHANGE` obsługiwane.
- Ruch: `<rack> <pozycja> <słowo>` lub `<pozycja> <słowo>`.
- Pozycja w konwencji Quackle: np. `8F` poziomo, `F8` pionowo.
- Litery wcześniej leżące na planszy ujmuj w nawiasy, np. `K4 KAR(O)`.
- Mała litera wyrazu oznacza blanka ustawionego na tę literę.

## Uwagi
- Weryfikacja słownikowa pominięta na tym etapie.
- Naliczanie premii zgodne z regułami. Bingo +50 dla 7 ułożonych płytek.
