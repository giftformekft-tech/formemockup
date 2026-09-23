# AI nyomat export: hibák és folytatás

- Az új export saját feladatazonosítót és zárolásokat kap. Egy korábbi export API-hibája közvetlenül nem zárolja az új exportot; a közös Action Scheduler késése viszont mindkettő indítását késleltetheti.
- A felület külön jelzi az indításra váró és a ténylegesen futó AI-generálást. Öt másodperc várakozás után a böngésző külön, hitelesített AJAX-kéréssel is elindíthatja az aktuális háttérfeladatot. Ez ugyanazt az atomi zárolást használja, mint az Action Scheduler, ezért a két indítás nem duplázza meg ugyanazt az API-hívást. Az állapot lekérdezése közben is folytatódik.
- HTTP 401 esetén az export jelzi a kulcshibát. Az új kulcsot az **AI Minta SEO és tagelés** beállításainál kell menteni, majd az exportablak **Export folytatása** gombjára kattintani. Hibás vagy hiányos ZIP nem tölthető le.
- Folytatáskor a ZIP helyben újra összeáll, a még elérhető kész AI-képeket újra felhasználja. Csak a hiányzó/hibás képek kapnak új generálási kísérletet, a legutóbb mentett API-kulccsal. A tétel mennyiségi példányai továbbra is egy közös generált képet használnak. A korábbi kísérlet késői eredménye nem írhatja felül az újat.
- A képek ideiglenesek: az eredeti, egyórás takarítási idő érvényben marad. Lejárt feladatnál új export szükséges. A régi kóddal már törölt képek nem állíthatók vissza. Új export vagy hiányzó kép újrapróbálása új API-költséggel járhat; nincs automatikus ismétlés API-hiba után.
- A sorban állás legfeljebb tíz percig, az elindult AI-feldolgozás legfeljebb öt percig maradhat eredmény nélkül. Ezután a feladat hibára fut és kézzel újrapróbálható. Az API-kérés időkorlátja változatlanul 180 mp, a beállított PHP-futásidő 240 mp. A generáláshoz új kísérletazonosító tartozik, ezért a megszakadt régi AI-folyamat zárolása nem tartja fel.
- A 2.38.10-es verziótól a felületen látható az aktuális lépés (előkészítés, OpenAI-válasz, képellenőrzés, nagyítás, mentés), az eltelt idő és az utolsó sikeres állapotválasz óta eltelt idő. A PHP végzetes hibája vagy váratlan kilépése eltárolt hibaüzenetet hagy maga után; a memória- és futásidőkorlát külön üzenetet kap. Ha a szerver úgy állítja le a folyamatot, hogy PHP shutdown sem futhat, az ötperces állapotellenőrzés jelzi az elakadást.
- A háttérindítás HTTP-, hálózati és értelmezhetetlen válaszhibái láthatók. Egy proxy-időtúllépés után a felület tovább ellenőrzi a szerver állapotát, mert a generálás még futhat. Ha a munka továbbra sem indult el, hiba és folytatásgomb jelenik meg. A nyers HTML/szerverhibaoldal sosem kerül a felületre.
- A rövid böngészős kérések 30 másodperc után önállóan hibára futnak akkor is, ha a kapcsolat megszakítása nem működik. A külön böngészős állapotfigyelő 45 mp állapotválasz-hiányt, illetve tíz perce változatlan feldolgozási lépést is jelez. Nincs automatikus fizetős újragenerálás; a folytatás először a meglévő feladatot használja tovább.
- A szerveroldali hibák WooCommerce naplóforrása **mg-ai-print**. A napló a feladatot, tételt, utolsó lépést, eltelt időt és biztonságos hibakategóriát (például `http_401`, `curl_28`, `memory_limit`) tartalmazza. API-kulcsot, ügyfélpromptot és nyers szolgáltatói választ nem naplózunk.
- Frissítés után az adminoldalt újra kell tölteni: egy korábban nyitva maradt exportablak továbbra is a régi JavaScriptet futtatja.

## Ellenőrzés

- `tests/ai-print-export-test.php`: valódi PNG/ZIP fájlok, helyettesített WordPress/HTTP/Action Scheduler/Imagick; kulcscsere, korábbi képek újrafelhasználása, dupla indítás, idegen felhasználó, lejárt/beragadt AI-feladat és késői eredmény.
- `tests/order-export-script-test.js`: időkorlát, tartalék indítás, hibajelzés, egyszeri kézi folytatás.
- `tests/ai-print-shutdown-test.php`: valódi külön PHP-folyamatban végzetes hiba, `exit` és elkapott kivétel; ellenőrzi az eltárolt hibát, zárolásfeloldást és egyszeri naplózást.
- `tests/order-export-browser-test.js`: tényleges böngészőben ellenőrzi az exportablakot és a mobil hibakezelést, szimulált szerverválaszokkal. Telepített Edge használatához `MG_BROWSER_CHANNEL=msedge` adható meg.

Az ellenőrzések nem igazolják az éles webshop ütemezőjének állapotát, a szerver időkorlátait vagy a szolgáltatónál az API-kulcs érvényességét.
