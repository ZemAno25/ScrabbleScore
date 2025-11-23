<?php
// src/PolishLetters.php
class PolishLetters {
    public static function values(): array {
        return [
            'A'=>1,'Ą'=>5,'B'=>3,'C'=>2,'Ć'=>6,'D'=>2,'E'=>1,'Ę'=>5,'F'=>5,'G'=>3,'H'=>3,'I'=>1,'J'=>3,
            'K'=>2,'L'=>2,'Ł'=>3,'M'=>2,'N'=>1,'Ń'=>7,'O'=>1,'Ó'=>5,'P'=>2,'R'=>1,'S'=>1,'Ś'=>5,'T'=>2,'U'=>3,'W'=>1,'Y'=>2,'Z'=>1,'Ź'=>9,'Ż'=>5
        ];
    }
}
//Zawartość kompletu
//    • 100 płytek z literami
//Gracze mają do dyspozycji 98 płytek z literami alfabetu oraz dwie 
// płytki puste, które będziemy nazywać blankami (patrz powyżej). 
// Każdej literze odpowiada określona liczba punktów (widoczna w prawym
//  dolnym rogu płytki). Blank nie ma żadnej wartości punktowej, ale 
// może zastępować dowolną literę z zestawu. Gracz, który kładzie blanka,
//  musi powiedzieć, jaką literę blank zastępuje - ustalenie to 
// pozostanie ważne do końca gry. Ilości płytek literowych i ich 
// wartości punktowe są następujące: 
//Litera=Ilość/Punkty
//        ◦ A=9/1
//        ◦ Ą=1/5
//        ◦ B=2/3
//        ◦ C=3/2
//        ◦ Ć=1/6
//        ◦ D=3/2
//        ◦ E=7/1
//        ◦ Ę=1/5
//        ◦ F=1/5
//        ◦ G=2/3
//        ◦ H=2/3
//        ◦ I=8/1
//        ◦ J=2/3
//        ◦ K=3/2
//        ◦ L=3/2
//        ◦ Ł=2/3
//        ◦ M=3/2
//        ◦ N=5/1
//        ◦ Ń=1/7
//        ◦ O=6/1
//        ◦ Ó=1/5
//        ◦ P=3/2
//        ◦ R=4/1
//        ◦ S=4/1
//        ◦ Ś=1/5
//        ◦ T=3/2
//        ◦ U=2/3
//        ◦ W=4/1
//        ◦ Y=4/2
//        ◦ Z=5/1
//        ◦ Ź=1/9
//        ◦ Ż=1/5
//        ◦ ?=2/0
//Znak zapytania "?" oznacza specjalną płytkę tzw. "Blank", 
// który w rozgrywce może zastąpić 	dowolną literę przy układaniu wyrazu.
