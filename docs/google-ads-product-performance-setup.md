# PMax Winner / Normal / Loser besorolás – beüzemelési menet

## Fontos frissítési tudnivaló

Ez a kiadás új importprotokollt használ. A frissítés utáni migráció szándékosan törli a korábbi PMax napi adatokat és besorolásokat, ezért a Google Ads Scriptet teljes egészében le kell cserélni, majd újra végig kell futtatni a történeti importot.

## 1. WordPress-beállítások

1. Telepítsd a frissített plugint, majd nyisd meg a **Mockup Generator → PMax besorolás** oldalt.
2. Első körben hagyd kikapcsolva a **Feedcímke bekapcsolása** jelölőt. Így az import és a besorolás ellenőrizhető anélkül, hogy az eredmény azonnal kikerülne a Merchant feedbe.
3. Állítsd be a címkehelyet. Elsődleges ajánlás: `custom_label_1`; a `custom_label_4` szintén szabad. A `0`, `2` vagy `3` kiválasztása az ott lévő terméktípus- vagy kategóriacímkét váltja fel.
4. Add meg a Winner küszöböt és a Loser szabályt.
   - Költésalapú Loser szabály csak HUF pénznemű Ads-fiókkal használható.
   - Kattintásalapú módban a költési küszöb nem vesz részt a döntésben.
   - A **kattintásküszöböt alapértelmezésben a bolt saját konverziós rátája adja**, nem a kézi érték. Egy átlagos termék is produkál nulla eladást, ha kevés kattintást kap: a valószínűsége `(1 - CVR)^n`. A küszöb az a kattintásszám, ahol ez 5% alá esik: `n = ln(0,05) / ln(1 - CVR)`. 2%-os bolti CVR-nél ez **149 kattintás** – a korábbi kézi 30 kattintás mellett egy teljesen átlagos termék 54,6% eséllyel kapott volna Loser címkét pusztán a szórásból. Az adminfelület kiírja a boltod aktuális CVR-jét és a belőle számolt küszöböt. A kézi érték csak akkor lép életbe, ha kikapcsolod az automatikát, vagy ha még nincs elég importált kattintásadat.
5. Add meg a **Loser megfigyelési ablakot** (alapérték: 90 nap). A Loser-döntés csak ennyi nap adatát nézi; a Winner továbbra is a teljes történetből dől el és végleges marad. Ez töri meg az önbeteljesítő hurkot: ha egy terméket a Loser címke miatt kiveszel a kampányból, nem kap több kattintást, a régi adatai kifutnak az ablakból, visszakerül `normal` állapotba, és kap egy újabb esélyt. Rögzített ablak nélkül a Loser címke véglegesen kizárná a terméket.
6. Add meg a konverziós késést. A javasolt induló érték 3 nap.
7. Add meg a webshop történetének kezdőnapját.
8. Add meg a Google Ads customer ID-t kötőjelek nélkül.
9. Ha csak egy konkrét Purchase művelet számítson, írd be annak pontos Google Ads-nevét. Üresen a `metrics.conversions` összes, Conversions oszlopban szereplő konverziója számít.
10. Opcionálisan add meg a PMax kampányazonosítókat vesszővel elválasztva. Üresen minden PMax kampány bekerül.
11. Kapcsold be a heti automatizmust, majd mentsd a beállításokat.

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
- Költésalapú Loser módban az utolsó import pénzneme `HUF`.
- A nem párosított offer ID-k száma elfogadható. Ha sok az eltérés, ellenőrizd, hogy a Merchant offer ID formátuma valóban `<SKU>_<type_slug>`.

Az **Induló besorolás futtatása** gombot csak akkor használd, amikor a teljes történeti import `Kész` állapotú. A szerver hiányos lefedettségnél egyébként is letiltja a besorolást.

## 4. Besorolás és szakmai ellenőrzés

1. Kattints az **Induló besorolás futtatása** gombra.
2. Ellenőrizd a Winner / Normal / Loser darabszámokat.
3. Nézd át a terméktáblában a konverziót, kattintást, költséget, időszakot és az indoklást.
4. Külön ellenőrizd a nem párosított offer ID mintákat.
5. Ellenőrizd néhány ismert terméken, hogy:
   - a Winner elérte a beállított attribútált konverziós küszöböt a **teljes történetben**;
   - a Losernek a **megfigyelési ablakon belül** nincs konverziója, viszont ott elérte a kiválasztott költési vagy kattintási küszöböt;
   - minden más termék Normal.

A besorolás összesítője kiírja a ténylegesen alkalmazott kattintásküszöböt, a bolt mért CVR-jét és a megfigyelési ablak kezdőnapját, így az indoklás visszafejthető.

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

- A Google Ads Script fusson naponta; minden normál futás az utolsó 30 napot frissíti.
- A WordPress heti automatizmusa a teljes importált webshop-történetből újraszámolja a besorolást.
- A Winner nem évül el; a Loser és Normal állapot az új adatok alapján változhat.
- A Loser **nem végleges**: a döntés csak a megfigyelési ablakot nézi. Ha a címke miatt kiveszed a terméket a kampányból, a régi kattintásai kifutnak az ablakból, és a termék magától visszakerül `normal` állapotba egy újabb tesztre. Ez szándékos: enélkül a Loser címke önmagát tartaná fenn örökre.
- A kattintásküszöb automatikus módban együtt mozog a bolt konverziós rátájával, ezért a besorolás beállításváltás nélkül is finomodhat, ahogy nő az adat.
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
| Költéses / kattintásos Loser mód | nem | nem | automatikusan lefut mentéskor |
| CVR-alapú kattintásküszöb ki-/bekapcsolása | nem | nem | automatikusan lefut mentéskor |
| Loser megfigyelési ablak | nem | nem | automatikusan lefut mentéskor |
| Feed custom label helye | nem | nem | nem; a feed regenerálódik |
| Feedcímke ki-/bekapcsolása | nem | nem | nem; a feed regenerálódik |
| Importtitok cseréje | igen | nem | nem |

## 8. Gyors hibakezelés

- **Elavult script / scope hiba:** mentsd a beállításokat, majd másold be újra az adminoldalon látható teljes scriptet.
- **Import folyamatban / busy:** ellenőrizd, hogy nem fut-e két scriptpéldány; várd meg az aktív futás végét.
- **HUF hiba:** költésalapú Loser módhoz HUF Ads-fiókot használj, vagy válts kattintásalapú módra.
- **A teljes import nem kész:** hagyd futni a napi scriptet; nagy katalógusnál több végrehajtás normális.
- **Sok nem párosított offer ID:** ellenőrizd a Merchant feed ID-k és a WordPress SKU + típusslug egyezését.
- **Hibás feederedmény:** első biztonsági lépésként kapcsold ki a feedcímkét és ments. A regenerálás visszaállítja az eredeti custom label mezőket.
- **Nulla Winner és nulla Loser, minden termék Normal:** ez korábban az offer ID kis-nagybetű eltérése miatt fordult elő – a feed nagybetűs SKU-ból képzi az azonosítót, a Google Ads viszont kisbetűsen adja vissza. A párosítás ma normalizálva történik. Ha mégis ezt látod, a besorolás összesítőjében nézd meg a nem párosított offer ID-k számát.
- **Túl kevés Loser a vártnál:** valószínűleg a CVR-alapú kattintásküszöb lépett életbe, ami jóval magasabb a korábbi kézi értéknél. Ez szándékos: a régi küszöb az átlagos termékek felét is Losernek jelölte. Az adminfelület kiírja a számolt küszöböt és a mögötte lévő CVR-t.
