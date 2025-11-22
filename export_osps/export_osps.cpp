/*
 * export_osps.cpp
 *
 * Narzędzie w C++ do eksportu słownika Quackle w formacie DAWG (wersja 1)
 * do zwykłego pliku tekstowego z listą słów.
 *
 * Założenia:
 *  1. Plik wejściowy to plik .dawg wygenerowany przez Quackle
 *     w formacie DAWG v1 (pierwszy bajt pliku ma wartość 1).
 *  2. Plik nagłówkowy DAWG v1 zawiera:
 *       - bajt wersji
 *       - 16 bajtów MD5
 *       - 3 bajty metadanych
 *       - bajt z długością alfabetu
 *       - alfabet w postaci tokenów UTF8 (np. "A", "Ą", "B", ...)
 *  3. Po nagłówku następuje ciąg węzłów DAWG, każdy po 7 bajtów:
 *       - bajty [0..2]  trzybajtowy indeks pierwszego dziecka
 *       - bajt [3]      litera plus flagi
 *       - bajty [4..6]  wartość playability (3 bajty)
 *
 * Wersja 1 DAWG:
 *   litera i flagi:
 *     - dolne 6 bitów (0..5) to indeks litery w alfabecie
 *     - bit 6 (wartość 64) to flaga lastChild
 *     - bit 7 to flaga insmallerdict/british (w eksporcie ignorujemy)
 *
 *   playability:
 *     - wartość różna od zera oznacza, że węzeł jest terminalny
 *       czyli kończy poprawne słowo ze słownika
 *
 * W pliku Quackle węzeł o indeksie 0 jest korzeniem.
 * Jego litera nie reprezentuje żadnego znaku w słowie.
 * Dzieci korzenia (jego poddrzewo) zawierają właściwe słowa.
 *
 * Program:
 *   - czyta nagłówek DAWG v1
 *   - odczytuje alfabet UTF8
 *   - wczytuje wszystkie węzły do pamięci (wektor bajtów)
 *   - dostarcza funkcję decode do dekodowania pojedynczego węzła
 *   - od węzła 0 pobiera childIndex czyli indeks pierwszego dziecka korzenia
 *   - wykonuje DFS po wszystkich dzieciach korzenia i wypisuje słowa,
 *     gdy napotka węzeł terminalny
 */

#include <cstdint>
#include <fstream>
#include <iostream>
#include <stdexcept>
#include <string>
#include <vector>

using std::cerr;
using std::cout;
using std::endl;
using std::ifstream;
using std::ofstream;
using std::runtime_error;
using std::size_t;
using std::string;
using std::vector;

/*
 * Struktura pomocnicza reprezentująca zdekodowany węzeł DAWG.
 *
 * childIndex  indeks pierwszego dziecka tego węzła
 *             0 oznacza brak dzieci
 *
 * letterIndex indeks litery w alfabecie (0..63)
 *             indeksuje wektor alphabet w strukturze Dawg
 *
 * terminal    informacja czy węzeł kończy poprawne słowo
 *
 * lastChild   informacja czy węzeł jest ostatnim dzieckiem
 *             w swoim zestawie rodzeństwa
 */
struct NodeInfo {
    std::uint32_t childIndex;
    std::uint8_t letterIndex;
    bool terminal;
    bool lastChild;
};

/*
 * Struktura Dawg przechowuje cały słownik w pamięci.
 *
 * bytes    surowe bajty węzłów DAWG
 *          każdy węzeł ma 7 bajtów
 *
 * alphabet alfabet UTF8 odczytany z nagłówka DAWG v1
 *
 * version  numer wersji DAWG
 *          interesuje nas głównie wersja 1
 */
struct Dawg {
    vector<std::uint8_t> bytes;
    vector<string> alphabet;
    std::uint8_t version;

    // Liczba węzłów w słowniku
    size_t nodeCount() const {
        return bytes.size() / 7;
    }

    /*
     * Dekodowanie pojedynczego węzła o indeksie index.
     *
     * Węzeł index zajmuje w buforze bytes pozycje
     * od index * 7 do index * 7 + 6.
     *
     * Format:
     *  bytes[pos + 0..2]  indeks pierwszego dziecka (3 bajty)
     *  bytes[pos + 3]     litera oraz flagi
     *  bytes[pos + 4..6]  playability (3 bajty)
     */
    NodeInfo decode(size_t index) const {
        NodeInfo info{};
        size_t pos = index * 7;

        if (pos + 7 > bytes.size()) {
            throw runtime_error("decode: indeks węzła poza zakresem (pos+7 > bytes.size)");
        }

        // Składamy indeks dziecka z 3 bajtów
        std::uint32_t p =
            (static_cast<std::uint32_t>(bytes[pos]) << 16) |
            (static_cast<std::uint32_t>(bytes[pos + 1]) << 8) |
            static_cast<std::uint32_t>(bytes[pos + 2]);

        std::uint8_t letterByte = bytes[pos + 3];

        // Wersja 1 DAWG używa playability do oznaczenia terminalności
        std::uint32_t playability =
            (static_cast<std::uint32_t>(bytes[pos + 4]) << 16) |
            (static_cast<std::uint32_t>(bytes[pos + 5]) << 8) |
            static_cast<std::uint32_t>(bytes[pos + 6]);

        info.childIndex = p;

        if (version == 0) {
            /*
             * Wersja 0 DAWG (dla porządku, nie dotyczy osps.dawg).
             *
             * letterByte:
             *  - bity 0..4  indeks litery (0..31)
             *  - bit 5      terminalność
             *  - bit 6      lastChild
             *  - bit 7      flaga british (tu ignorowana)
             */
            bool t = (letterByte & 32U) != 0;
            bool lastchild = (letterByte & 64U) != 0;

            std::uint8_t letter =
                static_cast<std::uint8_t>(letterByte & 31U);

            info.terminal = t;
            info.lastChild = lastchild;
            info.letterIndex = letter;
        } else {
            /*
             * Wersja 1 DAWG (osps.dawg).
             *
             * letterByte:
             *  - bity 0..5  indeks litery (0..63)
             *  - bit 6      lastChild
             *  - bit 7      flaga insmallerdict/british (pomijamy)
             *
             * playability:
             *  - wartość różna od zera oznacza węzeł terminalny
             */
            bool lastchild = (letterByte & 64U) != 0;

            std::uint8_t letter =
                static_cast<std::uint8_t>(letterByte & 63U);

            bool t = (playability != 0);

            info.terminal = t;
            info.lastChild = lastchild;
            info.letterIndex = letter;
        }

        return info;
    }
};

/*
 * Funkcja wczytuje plik DAWG i buduje strukturę Dawg:
 *
 *  1. Wczytuje bajt wersji.
 *
 *  2. Dla wersji 1:
 *     - wczytuje 16 bajtów MD5 (ignorujemy zawartość)
 *     - wczytuje 3 bajty metadanych (dla nas bez znaczenia)
 *     - wczytuje długość alfabetu (1 bajt)
 *     - wczytuje alfabet jako sekwencję tokenów UTF8
 *
 *  3. Wczytuje resztę pliku jako tablicę węzłów po 7 bajtów.
 */
Dawg loadDawgFromFile(const string &filename) {
    ifstream file(filename.c_str(), std::ios::binary);
    if (!file) {
        throw runtime_error("Nie mozna otworzyc pliku DAWG: " + filename);
    }

    Dawg dawg;

    // Bajt wersji
    int v = file.get();
    if (v == EOF) {
        throw runtime_error("Pusty plik DAWG");
    }
    dawg.version = static_cast<std::uint8_t>(v);
    cerr << "[INFO] Wersja DAWG (pierwszy bajt): " << (int)dawg.version << endl;

    if (dawg.version == 0) {
        /*
         * Wersja 0 DAWG nie ma nagłówka z alfabetem.
         * Cofamy wskaźnik pliku i dalej zakładamy brak alfabetu.
         * Ten wariant nie jest używany przez osps.dawg,
         * zostawiamy jednak obsługę jako fallback.
         */
        file.clear();
        file.seekg(0, std::ios::beg);
        cerr << "[INFO] Uzywam interpretacji V0 (bez naglowka alfabetu)." << endl;
    } else if (dawg.version == 1) {
        /*
         * Wersja 1 DAWG używana przez Quackle dla nowocześniejszych słowników
         * w tym dla polskiego słownika Scrabble (osps.dawg).
         */

        // 16 bajtów MD5 (ignorujemy zawartość)
        char hash[16];
        file.read(hash, sizeof(hash));
        if (!file) {
            throw runtime_error("Nieprawidlowy naglowek MD5 w pliku DAWG");
        }

        // 3 bajty metadanych
        unsigned char bytes3[3];
        file.read(reinterpret_cast<char*>(bytes3), 3);
        if (!file) {
            throw runtime_error("Nieprawidlowy naglowek (3 bajty metadanych) w pliku DAWG");
        }
        cerr << "[INFO] 3 bajty metadanych: "
             << std::hex
             << (int)bytes3[0] << " "
             << (int)bytes3[1] << " "
             << (int)bytes3[2]
             << std::dec << endl;

        // Długość alfabetu w bajcie
        int alphaLen = file.get();
        if (alphaLen <= 0) {
            throw runtime_error("Nieprawidlowa dlugosc alfabetu w pliku DAWG (alphaLen <= 0)");
        }
        dawg.alphabet.resize(alphaLen);
        cerr << "[INFO] Dlugosc alfabetu: " << alphaLen << endl;

        /*
         * Wczytanie alfabetu.
         * Każdy symbol jest czytany operatorem >> jako token
         * zakończony białym znakiem.
         * Po nim czytany jest pojedynczy znak separatora (spacja lub newline).
         */
        for (int i = 0; i < alphaLen; ++i) {
            file >> dawg.alphabet[i];
            int c = file.get();
            if (!file) {
                throw runtime_error("Blad odczytu alfabetu z pliku DAWG przy i=" + std::to_string(i));
            }
            (void)c;
        }

        cerr << "[INFO] Alfabet (kolejno): ";
        for (size_t i = 0; i < dawg.alphabet.size(); ++i) {
            cerr << dawg.alphabet[i];
            if (i + 1 != dawg.alphabet.size()) {
                cerr << ", ";
            }
        }
        cerr << endl;

    } else {
        throw runtime_error(
            "Nieznana wersja DAWG: " + std::to_string(dawg.version));
    }

    /*
     * Wczytanie części węzłowej pliku.
     * Każdy węzeł ma 7 bajtów.
     * Czytamy kolejne bloki po 7 bajtów aż do końca pliku.
     */
    vector<std::uint8_t> buf(7);
    size_t totalBytes = 0;
    while (true) {
        file.read(reinterpret_cast<char*>(buf.data()), 7);
        std::streamsize got = file.gcount();
        if (got == 0) {
            break;
        }
        if (got != 7) {
            throw runtime_error(
                "Plik DAWG ma dlugosc niepodzielna przez 7 bajtow (ostatni blok ma " +
                std::to_string(got) + " bajtow)");
        }
        dawg.bytes.insert(dawg.bytes.end(), buf.begin(), buf.end());
        totalBytes += 7;
    }

    if (dawg.bytes.size() % 7 != 0) {
        throw runtime_error(
            "Liczba bajtow DAWG nie jest wielokrotnoscia 7: " +
            std::to_string(dawg.bytes.size()));
    }

    cerr << "[INFO] Laczna liczba bajtow z wezlami: " << totalBytes << endl;
    cerr << "[INFO] Liczba wezlow (totalBytes / 7): " << dawg.nodeCount() << endl;

    return dawg;
}

/*
 * DFS po poddrzewie zaczynając od indeksu startIndex.
 *
 * startIndex powinien odpowiadać pierwszemu dziecku danego węzła rodzica.
 * W naszym przypadku rodzicem jest korzeń o indeksie 0,
 * a startIndex to jego childIndex.
 *
 * prefix:
 *   aktualnie budowane słowo (prefiks)
 *
 * out:
 *   strumień wyjściowy (np. plik z listą słów)
 *
 * visitedSteps, maxSteps:
 *   licznik bezpieczeństwa, który chroni przed ewentualną pętlą
 *   w przypadku uszkodzonego pliku DAWG.
 */
void dfsWords(const Dawg &dawg,
              std::uint32_t startIndex,
              string &prefix,
              std::ostream &out,
              size_t &visitedSteps,
              const size_t maxSteps)
{
    std::uint32_t index = startIndex;

    /*
     * Dzieci danego węzła są przechowywane w jednym ciągu węzłów.
     * Zaczynamy od indeksu startIndex, a kolejne rodzeństwo
     * to indeksy startIndex + 1, startIndex + 2 itd.
     * Ostatnie dziecko ma ustawioną flagę lastChild.
     */
    while (index < dawg.nodeCount()) {
        if (visitedSteps > maxSteps) {
            throw runtime_error("Przekroczono limit krokow DFS (" +
                                std::to_string(maxSteps) +
                                "). Prawdopodobna petla w strukturze DAWG.");
        }
        ++visitedSteps;

        NodeInfo node = dawg.decode(index);

        // Zapamiętujemy długość prefiksu, aby potem go odtworzyć
        size_t oldLen = prefix.size();

        /*
         * Dla wersji 1 mamy alfabet wczytany z nagłówka.
         * W normalnej sytuacji letterIndex jest poprawnym
         * indeksem w tym wektorze.
         */
        if (!dawg.alphabet.empty()) {
            if (node.letterIndex >= dawg.alphabet.size()) {
                /*
                 * W prawidłowym pliku DAWG to nie powinno się zdarzyć.
                 * Zamiast natychmiast przerywać, logujemy ostrzeżenie
                 * i wstawiamy znak zapasowy.
                 */
                cerr << "[WARN] Node " << index
                     << " ma letterIndex=" << (int)node.letterIndex
                     << " przy alphabet.size()=" << dawg.alphabet.size()
                     << ". Zastepuje znakiem '?'." << endl;
                prefix.push_back('?');
            } else {
                prefix += dawg.alphabet[node.letterIndex];
            }
        } else {
            // Fallback dla DAWG bez alfabetu (wersja 0)
            prefix.push_back('?');
        }

        /*
         * Jeżeli węzeł jest terminalny, aktualny prefiks
         * reprezentuje pełne słowo i zapisujemy je do wyjścia.
         */
        if (node.terminal) {
            out << prefix << '\n';
        }

        /*
         * Jeśli węzeł ma dzieci (childIndex != 0),
         * wykonywany jest rekurencyjny DFS od pierwszego dziecka.
         */
        if (node.childIndex != 0) {
            if (node.childIndex >= dawg.nodeCount()) {
                throw runtime_error("Indeks dziecka poza zakresem w DFS: node.childIndex=" +
                                    std::to_string(node.childIndex) +
                                    " przy nodeCount=" +
                                    std::to_string(dawg.nodeCount()));
            }
            dfsWords(dawg, node.childIndex, prefix, out, visitedSteps, maxSteps);
        }

        // Usuwamy aktualną literę i wracamy do poprzedniego prefiksu
        prefix.resize(oldLen);

        /*
         * Jeśli lastChild jest ustawione, to kończymy obsługę rodzeństwa
         * na tym poziomie i wracamy wyżej w rekurencji.
         */
        if (node.lastChild) {
            break;
        }

        /*
         * Przechodzimy do kolejnego rodzeństwa
         * na tym samym poziomie.
         */
        ++index;
    }
}

/*
 * Funkcja main:
 *
 *  - oczekuje dwóch argumentów:
 *      argv[1]  plik wejściowy osps.dawg
 *      argv[2]  plik wyjściowy z listą słów
 *
 *  - wczytuje DAWG
 *  - pobiera węzeł korzenia o indeksie 0
 *  - odczytuje jego childIndex
 *  - uruchamia DFS od tego dziecka
 */
int main(int argc, char** argv) {
    if (argc < 3) {
        cerr << "Uzycie: " << argv[0]
             << " osps.dawg wyjscie.txt\n";
        return 1;
    }

    string dawgFile = argv[1];
    string outFile  = argv[2];

    try {
        Dawg dawg = loadDawgFromFile(dawgFile);
        size_t N = dawg.nodeCount();
        if (N == 0) {
            throw runtime_error("Brak wezlow w pliku DAWG");
        }

        /*
         * Węzeł o indeksie 0 jest korzeniem.
         * Jego litera nie reprezentuje żadnego znaku słowa.
         * Interesują nas jego dzieci.
         */
        NodeInfo rootNode = dawg.decode(0);
        std::uint32_t rootChild = rootNode.childIndex;

        cerr << "[INFO] Wskaznik childIndex korzenia (wezel 0): "
             << rootChild << endl;

        if (rootChild == 0) {
            throw runtime_error("Korzen (wezel 0) nie ma dzieci (childIndex == 0). Plik moze byc uszkodzony.");
        }
        if (rootChild >= N) {
            throw runtime_error("childIndex korzenia poza zakresem: " +
                                std::to_string(rootChild) +
                                " przy nodeCount=" +
                                std::to_string(N));
        }

        ofstream out(outFile.c_str());
        if (!out) {
            throw runtime_error(
                "Nie mozna otworzyc pliku wyjsciowego: " + outFile);
        }

        string prefix;
        size_t visitedSteps = 0;
        /*
         * Limit kroków DFS jako zabezpieczenie przed
         * ewentualną pętlą w strukturze DAWG.
         * W prawidłowym pliku w ogóle nie powinien zostać przekroczony.
         */
        const size_t maxSteps = N * 40;

        cerr << "[INFO] Start DFS od dziecka korzenia (indeks "
             << rootChild << ")..." << endl;

        dfsWords(dawg, rootChild, prefix, out, visitedSteps, maxSteps);

        cerr << "[INFO] Zakonczono DFS. Laczna liczba odwiedzonych krokow: "
             << visitedSteps << endl;

    } catch (const std::exception &ex) {
        cerr << "Blad: " << ex.what() << endl;
        return 1;
    }

    return 0;
}
