# Új minta-fogalomtár – első elemzés

Forrás: `forme-ajandek-katalogus-2026-08-02.json`.

## Export összesítése

- 8 276 termék
- 193 WooCommerce-kategória
- 15 885 régi termékcímke
- 7 289 tagelt termék; 987 termék tag nélkül
- 53 061 tag-hozzárendelés
- 129 nulla használatú tag
- 11 084 tag pontosan egy terméken szerepel

A tagállomány ezért nem kanonikus szótár: hosszú farka van, és egy terméken átlagosan sok, egymással kevert típusú érték szerepel.

## Megtartott jelentési dimenziók

1. **Címzett** – apa, anya, férj, feleség, nagyszülő, testvér, barát, kolléga, főnök.
2. **Alkalom** – születésnap, karácsony, húsvét, ballagás, Valentin-nap, anyák napja, apák napja, esküvő, évforduló, búcsúztatók, nyugdíjazás, Halloween, meglepetés.
3. **Érdeklődés** – humor, gaming, sport, horgászat, állatok, autó/motor, film/sorozat, zene, kertészkedés, főzés/grill, utazás, szakma.
4. **Foglalkozás** – az ajándékkereső jelenlegi foglalkozás-ágai stabil azonosítóval.
5. **Téma vagy motívum** – például macska, kutya, ló, hal, autó, motor, kamion, traktor, foci, Forma–1, kontroller, sör, kávé, virág, szív, koponya.
6. **Évszám** – külön strukturált mezőként, nem szabad tagként.

## Szándékosan kizárt elemek

Nem kerülnek be kanonikus tagként:

- színek és háttérszínek;
- felirat, tipográfia, grafika, illusztráció, rajzolt, sziluett;
- retro, vintage, meme, minimalista és egyéb kivitel/stílus;
- konkrét idézetek és szövegrészletek;
- SEO-mondatok, például `születésnapi ajándék hegesztőnek`;
- technikai vagy kereskedelmi szavak, például `banner`, `logó`, `ajándék`;
- évszámok tagként — ezek kivételként strukturált `year` mezőben maradnak.

A `vicces` és `humoros` fogalom az `interest.humor` alatt marad, mert a jelenlegi ajándékkereső külön érdeklődési válaszként használja. A `meme` viszont formátumként kiesik.

## Fontos adatminőségi jelek

- `vicces`, `humoros` és `humor` ugyanahhoz a humoros ajándék-szándékhoz tartozik.
- `horgászat`, `HORGÁSZ`, `fishing` és `hal` nem ugyanaz: az első három érdeklődés/szinonima, a `hal` motívum.
- `apa`, `apának`, `Apák napja` és `APÁKNAPJA` címzettet, alkalmat és hibás/duplikált alakot kever.
- `gamer`, `gaming`, `videójáték` és `kontroller` érdeklődést és konkrét motívumot kever.
- Több ezres mennyiségben vannak teljes SEO-kifejezések, amelyekből több strukturált fogalom vezethető le.
- Van kódolási vagy kézi adatbeviteli zaj, például `AŐÁLNAPI AKÁNDÉK`, valamint kategóriaelírások.

## Következő használati szabály

A külső GPT-5 mini program a [tag-dictionary-v1-2026-08-02.json](tag-dictionary-v1-2026-08-02.json) `active` fogalmaiból választhat. A motívumok első v1-es listája aktív, de a tényleges termék-újrabesorolásnál a képi bizonyosságot külön mérni kell. Ismeretlen fogalom esetén az AI nem gyárthat szabad taget: külön `unmatched_concepts` javaslatot ad.
