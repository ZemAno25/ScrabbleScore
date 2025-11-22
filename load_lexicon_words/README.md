# Import słownika OSPS do bazy ScrabbleScore

To narzędzie służy do załadowania słownika Scrabble (OSPS) z pliku tekstowego do tabeli `lexicon_words` w bazie PostgreSQL `scrabblegames`.

Słownik może być następnie wykorzystywany przez aplikację **ScrabbleScore** do sprawdzania poprawności ruchów.

---

## Wymagania

1. Python 3.x  
2. Biblioteka PostgreSQL dla Pythona:
   ```bash
   pip install psycopg2-binary

    Baza danych PostgreSQL scrabblegames oraz użytkownik mający prawo do:

        łączenia się z bazą,

        tworzenia tabeli lexicon_words,

        wykonywania poleceń INSERT.

Struktura tabeli słownika

Skrypt zakłada uproszczoną tabelę:

CREATE TABLE lexicon_words (
    word TEXT PRIMARY KEY
);

PRIMARY KEY automatycznie tworzy indeks B-tree, dlatego nie są potrzebne dodatkowe indeksy.
Plik konfiguracyjny db.cfg

W tym samym katalogu co skrypt musi znajdować się plik:

[database]
host = 127.0.0.1
port = 5432
dbname = scrabblegames
user = scrabble_usr
password = TUTAJ_WPISZ_HASLO

Uwagi:

    sekcja musi nazywać się dokładnie [database],

    wszystkie pola są wymagane,

    plik powinien mieć ograniczone uprawnienia (zawiera hasło).

Format pliku słownika

Skrypt oczekuje pliku tekstowego w UTF-8, gdzie:

    każde słowo jest w osobnej linii,

    linie puste są ignorowane,

    linie zaczynające się od # są komentarzami,

    wszystkie słowa są automatycznie konwertowane do DUŻYCH LITER.

Przykład:

aa
Aa
# komentarz
źrebię
ŻREBIĘ

Po imporcie w tabeli znajdą się:

AA
ŹREBIĘ

Uruchomienie importu

Załóżmy, że:

    skrypt load_lexicon_words.py oraz db.cfg są w tym samym katalogu,

    słownik przygotowany przez export_osps znajduje się w pliku osps.txt.

Uruchom import poleceniem:

python3 load_lexicon_words.py /pełna/ścieżka/do/osps.txt

Przykładowe komunikaty działania

Skrypt wypisze:

    wczytywanie konfiguracji,

    liczbę linii i liczbę unikalnych słów,

    postęp przetwarzania paczek:

[INFO] Przetworzono paczkę: 5000/2584337

podsumowanie:

    Liczba słów przed importem: X
    Liczba słów po imporcie: Y
    Przyrost: Z

Jeśli wszystko przebiegło poprawnie:

[KONIEC] Import zakończony pomyślnie.

Ponowne uruchamianie (idempotentność)

Skrypt używa:

ON CONFLICT (word) DO NOTHING

Dlatego:

    można uruchomić go wielokrotnie,

    duplikaty nie powodują błędów,

    nowe wersje słownika można dogrywać bez czyszczenia tabeli.

Typowe problemy
Brak pliku db.cfg

Skrypt zgłosi błąd — utwórz plik we właściwym katalogu.
Błędne dane logowania

Skrypt zwróci komunikat o błędzie połączenia.
Zły format pliku słownika

Skrypt zgłosi problem z kodowaniem lub odczytem — upewnij się, że plik ma kodowanie UTF-8.
Aktualizacja słownika

Po wygenerowaniu nowej wersji OSPS:

    Uruchom ponownie export_osps

    Wygeneruj nowy plik tekstowy

    Zaimportuj go:

python3 load_lexicon_words.py osps.txt

Nowe słowa zostaną dodane automatycznie.
Licencja

Narzędzie jest częścią projektu ScrabbleScore i może być dowolnie rozszerzane i modyfikowane.