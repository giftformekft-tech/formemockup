# Rendelésforrás-statisztika

A 2.39.0 verziótól: **Mockup Generator → Marketing & Mérés → Rendelésforrás-statisztika**.
Közvetlen admin útvonal: `admin.php?page=mockup-generator&mg_tab=order_attribution`.
A megtekintéshez `manage_woocommerce` jogosultság kell, a lekérdezéshez ezen felül érvényes admin nonce.

## Használat

- Válassz időszakot (ma, tegnap, utolsó 7/30 nap, aktuális/előző hónap vagy év, egyéni dátumok), majd kattints a **Lekérdezés / frissítés** gombra.
- A **Platform**, **Kampány**, **Forgalom típusa** és **Pénznem** szűrő az összes kártyára, grafikonra és táblázatra vonatkozik.
- A platform nevére kattintva annak kampányai szűrhetők. A kampány neve is kattintható. A **Nincs UTM-kampány** külön szűrhető.
- **Napi, heti, havi, éves** bontás; a nulla rendeléses időszakok is megjelennek. A hét hétfőn kezdődik, a szélső heteket a kiválasztott időszak határolja.
- Hiba esetén a **Betöltés folytatása** az utolsó sikeres adagtól folytatja a lekérdezést. Részleges eredmény nem jelenik meg teljes kimutatásként.

## Számítás és korlátok

A jelentés kizárólag a WooCommerce által már eltárolt rendelésforrást olvassa: `_wc_order_attribution_utm_source`, `utm_medium`, `utm_campaign`, `source_type`, `referrer`. A WooCommerce egyedi mezőprefix-szűrőjét is figyelembe veszi. Új követősütit nem állít, és régi rendelésekhez nem talál ki kampányadatot.

Az `fb`, `facebook`, `l.facebook.com` ugyanahhoz a Facebook platformhoz tartozik; az Instagram külön platform. A Meta néven címkézett, platformra tovább nem bontott forrás külön Meta sor. Google, TikTok, Pinterest, Microsoft/Bing, YouTube, ChatGPT, Messenger és Audience Network is felismerhető. Ismeretlen vagy más forrásokat a jelentés nem nyeli el.

A platform és a forgalom típusa külön adat: a Facebook hivatkozás nem minősül automatikusan fizetett hirdetésnek. Ehhez például `paid_social` vagy `cpc` UTM-médium szükséges. Pixel-/kattintássütiből nem következtetünk utólag a WooCommerce szerinti forrásra. A WooCommerce munkamenethez kötött attribúciója nem feltétlen egyezik a Meta/Google riportjaival.

A `wc_get_is_paid_statuses()` által visszaadott állapotok (alapesetben processing/completed), valamint refunded számítanak. Pending/on-hold/failed/cancelled nem számít vásárlásnak. Teljesen visszatérített rendelés is egy vásárlás, jellemzően nulla megmaradó bevétellel. A bevétel kedvezmények utáni bruttó rendelésérték, szállítással és adóval, mínusz minden rögzített, nem kukázott visszatérítés. A visszatérítés az eredeti rendelés napját módosítja, akkor is, ha később történt. Nem pénzforgalmi vagy profitkimutatás. Eltérő pénznemek nem adódnak össze; hiányzó pénznem külön csoport.

Az időszakot a webshop időzónájából UTC dátumhatárokra alakítjuk, beleértve az óraátállítást. A rendelés létrehozásának napja számít, nem a fizetés napja. Egyszerre legfeljebb 3661 nap kérdezhető le. A jelentés kifizetési státusza a betöltéskori állapotot tükrözi, ezért feldolgozás közben végzett rendelésmódosítás után érdemes frissíteni.

## Működés

Olvasás az aktív HPOS vagy hagyományos rendeléstárolóból, 250 rendeléses, ID-kurzoros adagokkal. A lekérdezés elején rögzített felső rendelésazonosító kizárja a később hozzáadott rendeléseket az éppen készülő jelentésből. A metaadatok és visszatérítések csak az aktuális adaghoz töltődnek be, duplikált meta sor nem duplázza a vásárlásokat. Nem szükséges WooCommerce Analytics háttérimport vagy új adatbázistábla.

A böngészőbe csak napi, platform-, forgalomtípus-, kampány- és pénznem szerinti összesítés kerül, személyes rendelési adatok nélkül. Az összegek négy tizedes fixpontos egységekben összegződnek. A szűrők helyben működnek, új lekérdezés nélkül. A frissítés újra beolvassa a rendeléseket; nincs elavult szerveroldali riportcache.

## Ellenőrzések

- `php tests/order-attribution-test.php`: tényleges SQL-lekérdezések SQLite-on, mindkét táblasémával; státuszok, duplikált meta, több adag, pénznem, visszatérítés, időzóna, jogosultság és hibaválasz. A Node 22+ beépített SQLite modulját használja; `NODE_BIN` beállítható.
- `node tests/order-attribution-script-test.js`: időszakok, hét-/hónaphatárok, szűrés, pénznemek, visszatérítések és üres időszakok.
- `node tests/order-attribution-browser-test.js`: a tényleges PHP sablon és JavaScript böngészőtesztje szimulált AJAX-válaszokkal, asztali/mobil nézetben. Környezeti változók: `PHP_BIN`, `PLAYWRIGHT_MODULE`, `CHROME_PATH`, opcionálisan `REPORT_SCREENSHOT_DIR`.

A helyi SQL- és böngészőteszt nem éles WooCommerce-adatbázis ellenőrzése. Frissítés után néhány ismert rendelés forrását és összegét érdemes az adminban összevetni a kimutatással.
