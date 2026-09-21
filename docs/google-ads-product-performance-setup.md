# PMax Winner / Normal / Loser besorolás – beüzemelési menet

## Fontos frissítési tudnivaló

A Google Ads Scriptet teljes egészében cseréld le az adminoldalon generált új változatra. Ez előbb befejezi a félbeszakadt időszakot akkor is, ha közben új nap kezdődött. Ha a script helyi folytatási adatai elvesztek, a szerver visszaadja a befejezendő tartományt. Egy lejárt importzárolás sem engedi félbehagyni ezt az időszakot.

A jelenlegi, `1.1.0` adatbázis-verzióról ez a javítás megőrzi a történeti importot és a besorolásokat. A korábbi protokollról (`1.1.0` előtti adatbázis-verzióról) történő frissítés továbbra is teljes újraimportot igényel. A régi script által már korábban kihagyott történeti adatokat a javítás nem tudja visszamenőleg felismerni; ilyen ismert hibánál teljes történeti újraimport szükséges.

A kattintásalapú Loser mód megszűnt. Az ezt használó beállítás a CPA-alapú módra vált, és tényleges megengedett vásárlási költség megadásáig felfüggeszti az új besorolást. A korábban beállított fix költési határ megmarad. Mindkét mód legalább 7 nap megfigyelést kér alapértelmezetten.

## 1. WordPress-beállítások

1. Telepítsd a frissített plugint, majd nyisd meg a **Mockup Generator → PMax besorolás** oldalt.
2. Első körben hagyd kikapcsolva a **Feedcímke bekapcsolása** jelölőt. Így az import és a besorolás ellenőrizhető anélkül, hogy az eredmény azonnal kikerülne a Merchant feedbe.
3. Állítsd be a címkehelyet. Elsődleges ajánlás: `custom_label_1`; a `custom_label_4` szintén szabad. A `0`, `2` vagy `3` kiválasztása az ott lévő terméktípus- vagy kategóriacímkét váltja fel.
4. Add meg a Winner küszöböt és a Loser szabályt. Mindkét Loser mód HUF pénznemű Ads-fiókot igényel.
   - **Megengedett vásárlási költség (CPA), ajánlott:** add meg, mennyi hirdetési költség fér bele egy vásárlásba a saját árrésed alapján. A kód ezt nem találja ki helyetted. A tesztkeret `3 × megengedett CPA × max(1, attribútált konverzió)`; elérésekor, megfelelő megfigyelési idő után a termék Loser lehet. Például 3 000 Ft CPA-nál 0 vagy 0,01 konverzió esetén 9 000 Ft, 1,5 konverziónál 13 500 Ft a költési határ. A 3-as szorzó tesztelési ráhagyás, nem garantált statisztikai bizonyosság.
   - **Rögzített tesztkeret:** nulla konverzió és az általad megadott költési határ elérése kell a Loserhez.
   - **Minimum megfigyelési idő:** alapból 7 nap, az első kattintás vagy költés napjától a konverziós késéssel lezárt utolsó napig, mindkét szélső napot beleszámítva. Ez eltelt megfigyelési idő, nem hét külön költési nap. Hiányzó aktivitási dátumnál nincs Loser-döntés.
   - A Winner szabály továbbra is elsőbbséget kap. A kattintásszám önmagában nem minősít Losernek.
5. Add meg a konverziós késést. A javasolt induló érték 3 nap.
6. Add meg a webshop történetének kezdőnapját.
7. Add meg a Google Ads customer ID-t kötőjelek nélkül.
8. Ha csak egy konkrét Purchase művelet számítson, írd be annak pontos Google Ads-nevét. Üresen a `metrics.conversions` összes, Conversions oszlopban szereplő konverziója számít.
9. Opcionálisan add meg a PMax kampányazonosítókat vesszővel elválasztva. Üresen minden PMax kampány bekerül.
10. Kapcsold be a heti automatizmust, majd mentsd a beállításokat.

## 2. Google Ads Script telepítése

1. A WordPress-oldalon a mentés után másold ki a teljes, frissen generált scriptet.
2. A megfelelő Google Ads ügyfélfiókban nyisd meg az **Eszközök → Tömeges műveletek → Szkriptek** részt.
3. Hozz létre egy új scriptet, vagy cseréld le a régi script teljes tartalmát. Ne fusson két példány párhuzamosan.
4. Engedélyezd a szükséges hozzáféréseket, majd indíts egy kézi futást.
5. Állíts be napi ütemezést. Nagy történeti adatmennyiségnél az első import több futást igényel: a script heti tartományokkal és folytatható batchekkel dolgozik.
6. Hibamentes futás után se indítsd még el rögtön a besorolást; előbb ellenőrizd a WordPress státuszkártyáit.

## 3. A történeti import ellenőrzése

A WordPress adminoldalon az alábbiakat ellenőrizd:

- **Teljes történeti import:** `Kész`.
- Az importált időszak kezdete megegyezik a beállított webshop-kezdődátummal.
- Az import vége a konverziós késéssel korrigált legutóbbi nap.
- **Adatok frissessége:** `Friss`. Legfeljebb 48 órája lezárt import szükséges, és az adatok vége legfeljebb 2 nappal maradhat el a konverziós késéssel korrigált naptól. Friss kérés régi dátumtartománnyal nem elegendő.
- Az utolsó import pénzneme `HUF`.
- A nem párosított offer ID-k száma elfogadható. Ha sok az eltérés, ellenőrizd, hogy a Merchant offer ID formátuma valóban `<SKU>_<type_slug>`.

Az **Induló besorolás futtatása** gombot csak akkor használd, amikor a teljes történeti import `Kész`, az adatok frissek, és a Loser beállításai ki vannak töltve. Hiányos, elavult vagy éppen feltöltés alatt álló adatokból a szerver nem készít új besorolást.

## 4. Besorolás és szakmai ellenőrzés

1. Kattints az **Induló besorolás futtatása** gombra.
2. Ellenőrizd a Winner / Normal / Loser darabszámokat.
3. Nézd át a terméktáblában a konverziót, kattintást, költséget, időszakot és az indoklást.
4. Külön ellenőrizd a nem párosított offer ID mintákat.
5. Ellenőrizd néhány ismert terméken, hogy:
   - a Winner elérte a beállított attribútált konverziós küszöböt;
   - a Loser elérte a kiválasztott CPA-alapú vagy rögzített tesztkeretet, és megvan a minimum megfigyelési idő; rögzített keretnél nincs konverziója;
   - minden más termék Normal.

A Winner státusz ugyanazon importbeállításokon belül végleges. Importforrás-váltáskor – például másik Ads-fiók, kampánykör vagy Purchase művelet esetén – a rendszer új, tiszta történeti importot kér.

## 5. Merchant feed bekapcsolása

1. Ha a besorolás megfelelő, kapcsold be a **Feedcímke bekapcsolása** jelölőt.
2. Ellenőrizd még egyszer a kiválasztott custom label helyet, majd mentsd a beállításokat.
3. A mentés regenerálja a fő Google Merchant feedet és az összes Google formátumú egyedi feedet.
4. Nyisd meg a generált XML-t, és keress például ilyen elemeket:
   - `<g:custom_label_1>winner</g:custom_label_1>`
   - `<g:custom_label_1>normal</g:custom_label_1>`
   - `<g:custom_label_1>loser</g:custom_label_1>`
5. Ellenőrizd, hogy egy termékben ugyanaz a custom label csak egyszer szerepel, és hogy a nem kiválasztott `custom_label_0`, `2`, `3` mezők megmaradtak.
6. Indíts feedlekérést a Merchant Centerben, majd a feldolgozás után ellenőrizd néhány termék custom label értékét.

## 6. Folyamatos működés

- A Google Ads Script fusson naponta; előbb befejezi a megkezdett időszakot, majd az aktuális utolsó 30 napot frissíti, ha maradt futási idő.
- A WordPress heti automatizmusa a teljes importált webshop-történetből újraszámolja a besorolást.
- Elavult adatnál a kézi, heti és küszöbmódosítás miatti besorolás is megáll. Az adminoldal hibaüzenetet és `Besorolás szünetel` frissességi állapotot mutat. Az utolsó sikeres besorolás, annak időpontja és a már publikált címkék megmaradnak.
- A Winner nem évül el; a Loser és Normal állapot az új adatok alapján változhat.
- Ha a script több mint 30 napig nem fut, a szerver nem enged hézagos gördülő adatot használni: biztonságosan teljes történeti újraimportot kér.

## 7. Melyik módosítás után mi szükséges?

| Módosítás | Új script kell | Teljes újraimport kell | Új besorolás kell |
|---|---:|---:|---:|
| Kezdődátum | igen | igen | igen |
| Ads customer ID | igen | igen | igen |
| Purchase művelet neve | igen | igen | igen |
| PMax kampányazonosítók | igen | igen | igen |
| Konverziós késés | igen | igen | igen |
| Winner / Loser küszöb | nem | nem | automatikusan lefut mentéskor |
| CPA / rögzített tesztkeret mód, CPA összege vagy megfigyelési idő | nem | nem | friss import esetén automatikusan lefut mentéskor |
| Feed custom label helye | nem | nem | nem; a feed regenerálódik |
| Feedcímke ki-/bekapcsolása | nem | nem | nem; a feed regenerálódik |
| Importtitok cseréje | igen | nem | nem |

## 8. Gyors hibakezelés

- **Elavult script / scope hiba:** mentsd a beállításokat, majd másold be újra az adminoldalon látható teljes scriptet.
- **Import folyamatban / busy:** ellenőrizd, hogy nem fut-e két scriptpéldány; várd meg az aktív futás végét.
- **HUF hiba:** a forintos CPA- és tesztkeret-beállításokhoz HUF Ads-fiókot használj.
- **Besorolás szünetel / elavult adat:** futtasd végig a frissített scriptet, majd ellenőrizd az utolsó teljes import időpontját és az importált időszak végét is. A félbeszakadt importot először be kell fejezni.
- **Hiányzó CPA:** add meg az egy vásárlásra megengedett hirdetési költséget, vagy válassz rögzített tesztkeretet. Az importálás addig is működik.
- **A teljes import nem kész:** hagyd futni a napi scriptet; nagy katalógusnál több végrehajtás normális.
- **Sok nem párosított offer ID:** ellenőrizd a Merchant feed ID-k és a WordPress SKU + típusslug egyezését.
- **Hibás feederedmény:** első biztonsági lépésként kapcsold ki a feedcímkét és ments. A regenerálás visszaállítja az eredeti custom label mezőket.

## 9. Fejlesztői ellenőrzések

- `php tests/google-ads-product-performance-test.php`: CPA-keretek, töredékkonverziók, minimum megfigyelési idő és Winner-elsőbbség.
- `php tests/google-ads-product-performance-state-test.php`: szerveroldali importfolytatás, lefedettség, frissesség, beállításváltás, publikált eredmények megőrzése és besorolási zárolás.
- `node tests/google-ads-product-performance-script-test.js`: a ténylegesen generált script, többek között a másnap folytatott 600 soros import és az elveszett helyi folytatási adatok helyreállítása.
