#!/usr/bin/env python3
# load_lexicon_words.py
#
# Narzędzie wczytujące słownik Scrabble (lista słów, jedno na linię)
# do tabeli lexicon_words w bazie PostgreSQL scrabblegames.
#
# Założenia:
#  1. Plik słownika jest plikiem tekstowym w UTF 8,
#     gdzie każde słowo znajduje się w osobnej linii.
#  2. Wszystkie słowa są zapisywane w tabeli jako DUŻE LITERY.
#  3. Tabela docelowa ma uproszczoną postać:
#        CREATE TABLE lexicon_words (
#            word TEXT PRIMARY KEY
#        );
#  4. Dane połączenia z bazą przechowywane są w pliku db.cfg
#     w formacie INI (sekcja [database]).
#
# Użycie:
#   python3 load_lexicon_words.py /ścieżka/do/osps.txt
#
# Zależności:
#   Python 3.x
#   pakiet psycopg2-binary
#     instalacja: pip install psycopg2-binary

import sys
import os
import configparser
from typing import List, Set

import psycopg2
from psycopg2.extras import execute_values


# Nazwa pliku z konfiguracją połączenia z bazą
DB_CONFIG_FILE = "db.cfg"

# Liczba słów w pojedynczej paczce INSERT
# Dzięki wsadom przyspieszamy import dużych słowników
BATCH_SIZE = 5000


def log(msg: str) -> None:
    """
    Prosty logger wypisujący komunikaty na stdout.

    Używane do informowania użytkownika o postępie importu.
    flush() zapewnia natychmiastowe wypisanie także przy buforowanym wyjściu.
    """
    print(msg)
    sys.stdout.flush()


def read_db_config(config_path: str) -> dict:
    """
    Czyta dane połączenia do PostgreSQL z pliku db.cfg.

    Oczekiwany format pliku:
      [database]
      host = 127.0.0.1
      port = 5432
      dbname = scrabblegames
      user = scrabble_usr
      password = tajnehaslo

    Zwraca słownik z parametrami połączenia zgodny z psycopg2.connect.
    """
    log(f"[INFO] Czytam konfigurację bazy z pliku: {config_path}")

    if not os.path.exists(config_path):
        print(f"[BŁĄD] Nie znaleziono pliku konfiguracyjnego {config_path}", file=sys.stderr)
        sys.stderr.flush()
        sys.exit(1)

    cfg = configparser.ConfigParser()
    cfg.read(config_path, encoding="utf-8")

    if "database" not in cfg:
        print("[BŁĄD] W pliku db.cfg brakuje sekcji [database]", file=sys.stderr)
        sys.stderr.flush()
        sys.exit(1)

    section = cfg["database"]
    required_keys = ["host", "port", "dbname", "user", "password"]

    # Weryfikujemy obecność wszystkich wymaganych kluczy
    for key in required_keys:
        if key not in section or not section[key].strip():
            print(f"[BŁĄD] W sekcji [database] brakuje wartości dla '{key}'", file=sys.stderr)
            sys.stderr.flush()
            sys.exit(1)

    params = {
        "host": section["host"],
        "port": int(section["port"]),
        "dbname": section["dbname"],
        "user": section["user"],
        "password": section["password"],
    }

    # Informacyjny log z podstawowymi parametrami (bez hasła)
    log(
        f"[INFO] Konfiguracja bazy: host={params['host']}, "
        f"port={params['port']}, dbname={params['dbname']}, user={params['user']}"
    )
    return params


def load_words_from_file(path: str) -> List[str]:
    """
    Wczytuje słowa z pliku tekstowego.

    Działanie:
      1. Otwiera plik w UTF 8.
      2. Czyta wszystkie linie.
      3. Obcina białe znaki z końców.
      4. Ignoruje linie puste oraz te zaczynające się od znaku '#'
         (można ich używać jako komentarzy).
      5. Normalizuje każde słowo do DUŻYCH liter.
      6. Usuwa duplikaty, korzystając z typu set.
      7. Zwraca posortowaną listę unikalnych słów.

    Dzięki temu:
      - mamy pewność, że w tabeli nie będzie "aa" i "AA" jako dwóch rekordów,
      - słownik w bazie jest spójny ze sposobem zapisywania słów w grze.
    """
    log(f"[INFO] Wczytuję słownik z pliku: {path}")

    if not os.path.exists(path):
        print(f"[BŁĄD] Nie znaleziono pliku słownika {path}", file=sys.stderr)
        sys.stderr.flush()
        sys.exit(1)

    words_set: Set[str] = set()
    total_lines = 0

    try:
        with open(path, "r", encoding="utf-8") as f:
            for line_no, line in enumerate(f, start=1):
                total_lines += 1
                word = line.strip()
                if not word:
                    # pomijamy linie puste
                    continue

                # pomijamy komentarze, np. "# jakiś opis"
                if word.startswith("#"):
                    continue

                # zgodnie z ustaleniem – wszystko zapisujemy dużymi literami
                word = word.upper()
                words_set.add(word)

                # okresowo wypisujemy postęp przy bardzo dużych plikach
                if line_no % 100000 == 0:
                    log(f"[INFO] Przetworzono {line_no} linii...")

    except UnicodeDecodeError as e:
        print(f"[BŁĄD] Problem z odczytem pliku (UTF-8): {e}", file=sys.stderr)
        sys.stderr.flush()
        sys.exit(1)

    words = sorted(words_set)
    log(f"[INFO] Odczytano {total_lines} linii z pliku.")
    log(f"[INFO] Po normalizacji i usunięciu duplikatów: {len(words)} unikalnych słów.")
    return words


def ensure_lexicon_table_exists(conn) -> None:
    """
    Tworzy tabelę lexicon_words, jeśli jeszcze nie istnieje.

    Struktura:
      word TEXT PRIMARY KEY

    PRIMARY KEY automatycznie tworzy indeks B tree na kolumnie word,
    więc nie ma potrzeby tworzenia dodatkowych indeksów do prostego
    sprawdzania "czy słowo istnieje w słowniku".
    """
    log("[INFO] Sprawdzam istnienie tabeli lexicon_words...")

    ddl = """
    CREATE TABLE IF NOT EXISTS lexicon_words (
        word TEXT PRIMARY KEY
    );
    """
    with conn.cursor() as cur:
        cur.execute(ddl)
    conn.commit()
    log("[INFO] Tabela lexicon_words jest gotowa.")


def count_existing_words(conn) -> int:
    """
    Zwraca aktualną liczbę rekordów w tabeli lexicon_words.

    Używane przed i po imporcie, aby pokazać przyrost liczby słów.
    """
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) FROM lexicon_words;")
        (count,) = cur.fetchone()
    return int(count)


def insert_words(conn, words: List[str]) -> None:
    """
    Wstawia słowa do tabeli lexicon_words w paczkach.

    Działanie:
      1. Przed importem zlicza aktualną liczbę słów.
      2. Dzieli listę słów na paczki po BATCH_SIZE.
      3. Dla każdej paczki używa execute_values z klauzulą
         ON CONFLICT (word) DO NOTHING,
         dzięki czemu:
           - import jest powtarzalny (można wykonać go ponownie),
           - duplikaty nie generują błędów.
      4. Po imporcie ponownie zlicza liczbę słów, aby pokazać przyrost.
    """
    total = len(words)
    if total == 0:
        log("[INFO] Brak słów do zaimportowania.")
        return

    log(f"[INFO] Rozpoczynam import {total} słów do tabeli lexicon_words...")

    before_count = count_existing_words(conn)
    log(f"[INFO] Aktualna liczba słów w lexicon_words: {before_count}")

    # Przygotowujemy szablon zapytania z użyciem execute_values
    sql = """
        INSERT INTO lexicon_words (word)
        VALUES %s
        ON CONFLICT (word) DO NOTHING
    """

    inserted = 0
    with conn.cursor() as cur:
        for i in range(0, total, BATCH_SIZE):
            batch = words[i : i + BATCH_SIZE]
            values = [(w,) for w in batch]

            # execute_values generuje efektywne zapytanie typu:
            # INSERT INTO lexicon_words (word) VALUES ('AA'), ('AB'), ...
            execute_values(cur, sql, values)

            inserted += len(batch)
            log(f"[INFO] Przetworzono paczkę: {inserted}/{total} (część słów mogła już istnieć).")

    conn.commit()

    after_count = count_existing_words(conn)
    log(f"[INFO] Import słownika zakończony.")
    log(f"[INFO] Liczba słów w lexicon_words przed importem: {before_count}")
    log(f"[INFO] Liczba słów w lexicon_words po imporcie:  {after_count}")
    log(f"[INFO] Przyrost (łącznie nowe wpisy): {after_count - before_count}")


def main() -> int:
    """
    Punkt wejścia programu.

    1. Sprawdza, czy podano ścieżkę do pliku słownika.
    2. Czyta konfigurację bazy z db.cfg.
    3. Wczytuje słowa z pliku.
    4. Nawiązuje połączenie z PostgreSQL.
    5. Tworzy tabelę lexicon_words, jeśli nie istnieje.
    6. Wstawia słowa do tabeli w paczkach.
    7. Zamknięcie połączenia i komunikat końcowy.
    """
    if len(sys.argv) != 2:
        print("Użycie: python3 load_lexicon_words.py /ścieżka/do/osps.txt", file=sys.stderr)
        sys.stderr.flush()
        return 1

    wordlist_path = sys.argv[1]
    log(f"[START] Import słownika z pliku: {wordlist_path}")

    # 1. Dane połączenia z pliku db.cfg
    db_params = read_db_config(DB_CONFIG_FILE)

    # 2. Wczytanie słów z pliku
    words = load_words_from_file(wordlist_path)

    # 3. Nawiązanie połączenia z bazą
    try:
        log("[INFO] Nawiązuję połączenie z bazą danych...")
        conn = psycopg2.connect(**db_params)
        log("[INFO] Połączono z bazą.")
    except Exception as e:
        print(f"[BŁĄD] Błąd połączenia z bazą danych: {e}", file=sys.stderr)
        sys.stderr.flush()
        return 1

    try:
        # 4. Upewniamy się, że tabela istnieje
        ensure_lexicon_table_exists(conn)
        # 5. Wstawiamy słowa
        insert_words(conn, words)
    except Exception as e:
        print(f"[BŁĄD] Wyjątek podczas importu słownika: {e}", file=sys.stderr)
        sys.stderr.flush()
        conn.rollback()
        return 1
    finally:
        conn.close()
        log("[INFO] Zamknięto połączenie z bazą danych.")

    log("[KONIEC] Import zakończony pomyślnie.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
