# AI nyomat export: hibák és folytatás

- Az új export saját feladatazonosítót és zárolásokat kap. Egy korábbi export API-hibája közvetlenül nem zárolja az új exportot; a közös Action Scheduler késése viszont mindkettő indítását késleltetheti.
- A felület külön jelzi az indításra váró és a ténylegesen futó AI-generálást. Öt másodperc várakozás után a böngésző külön, hitelesített AJAX-kéréssel is elindíthatja az aktuális háttérfeladatot. Ez ugyanazt az atomi zárolást használja, mint az Action Scheduler, ezért a két indítás nem duplázza meg ugyanazt az API-hívást. Az állapot lekérdezése közben is folytatódik.
- HTTP 401 esetén az export jelzi a kulcshibát. Az új kulcsot az **AI Minta SEO és tagelés** beállításainál kell menteni, majd az exportablak **Export folytatása** gombjára kattintani. Hibás vagy hiányos ZIP nem tölthető le.
- Folytatáskor a ZIP helyben újra összeáll, a még elérhető kész AI-képeket újra felhasználja. Csak a hiányzó/hibás képek kapnak új generálási kísérletet, a legutóbb mentett API-kulccsal. A tétel mennyiségi példányai továbbra is egy közös generált képet használnak. A korábbi kísérlet késői eredménye nem írhatja felül az újat.
- A képek ideiglenesek: az eredeti, egyórás takarítási idő érvényben marad. Lejárt feladatnál új export szükséges. A régi kóddal már törölt képek nem állíthatók vissza. Új export vagy hiányzó kép újrapróbálása új API-költséggel járhat; nincs automatikus ismétlés API-hiba után.
- Tíz perc alatt be nem fejezett AI-feladat hibára fut és kézzel újrapróbálható. A generáláshoz új kísérletazonosító tartozik, ezért a megszakadt régi AI-folyamat zárolása nem tartja fel. A rövid böngészős kérések 30 másodperces időkorlátot kapnak; hálózati hiba után a folytatás először a meglévő feladatot használja tovább.

## Ellenőrzés

- `tests/ai-print-export-test.php`: valódi PNG/ZIP fájlok, helyettesített WordPress/HTTP/Action Scheduler/Imagick; kulcscsere, korábbi képek újrafelhasználása, dupla indítás, idegen felhasználó, lejárt/beragadt AI-feladat és késői eredmény.
- `tests/order-export-script-test.js`: időkorlát, tartalék indítás, hibajelzés, egyszeri kézi folytatás.
- `tests/order-export-browser-test.js`: tényleges böngészőben ellenőrzi az exportablakot és a mobil hibakezelést, szimulált szerverválaszokkal. Telepített Edge használatához `MG_BROWSER_CHANNEL=msedge` adható meg.

Az ellenőrzések nem igazolják az éles webshop ütemezőjének állapotát, a szerver időkorlátait vagy a szolgáltatónál az API-kulcs érvényességét.
