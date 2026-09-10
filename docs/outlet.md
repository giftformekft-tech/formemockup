# Outlet készáru

A 2.38.0 verzió önálló, egyszerű WooCommerce-terméket hoz létre minden felvett készáru-kombinációhoz. Az eredeti termék árát és készletét nem módosítja.

## Használat

1. WooCommerce → Beállítások → Termékek → Készlet: legyen engedélyezve a készletkezelés.
2. Nyisd meg az eredeti egyszerű terméket szerkesztésre. Az **Outlet darab létrehozása** dobozban válassz típust, színt és méretet; add meg az árat és a darabszámot (alapérték: 1).
3. Az állapot és a már meglévő felirat a megjegyzésben rögzíthető, a vásárló is látja. A saját fotó opcionális, ha a pontos típus/szín első nézeti mockupja már létezik a `mg_mockups` könyvtárban. Ha nincs ilyen mockup, saját fotót kell választani vagy előbb legenerálni a képet.
4. Kattints a **Létrehozás és megjelenítés az Outletben** gombra. A sikeres létrehozás szerkesztési linket ad. További darabhoz frissítsd az eredeti termék szerkesztőoldalát. A létrehozás a legutóbb mentett eredeti termékadatokat használja.
5. **Termékek → Outlet**: másold ki az oldal linkjét, és tedd a navigációs menübe Sale vagy Outlet néven. Az **Outlet darabok kezelése** a WooCommerce terméklistáját outletre szűrve nyitja meg. Itt normál módon szerkeszthető az ár, a készlet, a fotó és a rövid állapotleírás; vázlatba állítással levehető a termék.

Az oldal az első jogosult adminmegnyitáskor automatikusan létrejön `[mg_outlet]` tartalommal. Előnyben részesített slug: `/outlet/`. Ha ezt már más tartalmú oldal használja, WordPress szabad slugot választ; mindig az adminban kiírt tényleges linket használd. A menüpontot a modul nem illeszti be automatikusan.

## Működés

- Fix típus/szín/méret, saját ár és készlet; nincs változatválasztó, személyre szabás vagy termékfelár. A bolt normál kupon- és kosárkedvezmény-szabályai megmaradnak.
- WooCommerce kezeli a készletellenőrzést, rendelési foglalást, levonást és visszaállítást. A modul kötelezően engedélyezi a termék saját készletkezelését és tiltja az utánrendelést.
- A termék katalógusláthatósága rejtett. A saját oldalán vásárolható, az outlet shortcode listázza. Nulla készletnél a listából eltűnik, újrakészletezéskor visszakerül. Az outlet oldal nem kér teljesoldalas gyorsítótárazást, hogy a darabszám friss maradjon.
- A Google Merchant, Facebook Catalog és a plugin egyedi feedjei kizárják az outlet-jelölt termékeket. Más szolgáltató feedjeihez külön integráció szükséges, ha ilyet is használtok.
- Az outlet rendeléstétel tartós jelölést és kombinációpillanatképet kap. Kimarad az UTT nagykerexportból, a helyi alapanyagkészlet levonásából és a rendelési nyomat-ZIP-ből. Vegyes rendelés normál tételei továbbra is feldolgozódnak; csak outletet tartalmazó rendelést a nagykerexport nem tesz gyártásba.
- Az újraküldött azonos létrehozási kérés nem hoz létre duplikált terméket. Részleges szerverhiba után a már létrejött darabot kell ellenőrizni a szerkesztőben.

## Ellenőrzés

- `php tests/outlet-test.php`: létrehozás, jogosultság, érvénytelen kombináció, duplikáció, készletbeállítások, ár, kosáradatok, feedek és nagykerexport; WordPress/WooCommerce tesztdublőrökkel.
- `php -d extension=zip tests/ai-print-export-test.php`: outletet is tartalmazó vegyes rendelés nyomat-exportja; valódi ZIP/PNG fájlokkal, helyettesített HTTP/scheduler/Imagick környezettel.
- `node tests/outlet-browser-test.js`: a tényleges PHP sablonok helyi böngészőtesztje; mobil/asztali elrendezés, függő választók, hibakezelés és ismételt kérés.
- A böngészőteszthez a `PHP_BIN`, `PLAYWRIGHT_MODULE`, `CHROME_PATH` környezeti változókban megadhatók a futtatók. A natív PHP ideiglenes könyvtára szükség esetén `-d sys_temp_dir=...` kapcsolóval állítható.

Éles WordPressben külön ellenőrizendő: a sablon megjelenése, fotófeltöltés/mockup-másolás, egy darab két párhuzamos pénztárban, rendeléstörlés és kézi készlet-visszaállítás, valamint az alkalmazott gyorsítótár viselkedése. A helyi tesztek nem helyettesítik a valódi WooCommerce-adatbázison végzett párhuzamos vásárlási próbát.
