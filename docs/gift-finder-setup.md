# Ajándékkereső beállítása

## Első beállítás

1. Hozz létre egy WordPress oldalt (például **Ajándékkereső** néven).
2. Az oldal tartalmába illeszd be a `[mg_gift_finder]` shortcode-ot.
3. Nyisd meg a **Mockup Generator → Ajándékkereső** adminoldalt.
4. Válaszd ki az előbb létrehozott céloldalt.
5. Állítsd be a kereső és a főoldali blokk hét közös színét.
6. Add meg az automatikus szezonális ajánlókártyákat. Kártyánként választható:
   - a WooCommerce kategória képe (mockup), vagy
   - egy kijelölt hero termék kiemelt képe.
7. A kérdésekhez add hozzá a válaszokat. Egy válaszhoz megadható egy elsődleges kategória és több további, alá tartozó WooCommerce-kategória is.
8. Mentsd a beállításokat.

Az érdeklődési kör WooCommerce-kategóriák alapján szűr. Évszak- és árkeretkérdés nincs. A szezonális kártyák automatikusan csak a kiválasztott évszak hónapjaiban jelennek meg (tél: december–február, tavasz: március–május, nyár: június–augusztus, ősz: szeptember–november).

Az ajándékválasztó logikai kategóriái nem hoznak létre új WooCommerce-kategóriát. Például az „Apák napja” válasz alá közösen bevonható az `Apának`, `Papának` és `Férjnek` kategória. A keresés ezeket egyetlen alkalomcsoportként kezeli. Termékcímkéket a találati logika nem használ.

A „Házassághoz kapcsolódó esemény” alkalom saját függő lépést nyit. Itt külön választható az esküvő/házasságkötés, évforduló, lánybúcsú, legénybúcsú és tejfakasztó. Más alkalomnál ez a lépés automatikusan kimarad.

### Függő kérdések

A második és későbbi kérdések válaszainál a **Csak ezek után jelenjen meg** mezőben választhatók ki a korábbi válaszok kategóriái. Például az „Anyák napja” alkalomhoz válaszd ki az „Anyának” kategóriát. Ekkor ez az alkalom csak akkor látható, ha a vevő előtte az „Anyának” lehetőséget választotta. Több szülő megadásakor bármelyik egyezése elegendő; üres mező esetén a válasz mindig látható.

## Főoldali blokk

A Gutenberg szerkesztőben keresd az **Ajándékkereső** blokkot. Szabadon áthelyezhető, az oldalsávban pedig beállítható a főcím, a bevezető, a gombszöveg és a megjelenő címzettek. A látogató itt a „Kinek keresel ajándékot?” kérdéssel indul; a választás után a teljes kereső az alkalomlépésen folytatódik.

Shortcode tartalmakhoz alternatívaként használható:

```text
[mg_gift_finder_teaser]
```

Csak kiválasztott címzettek megjelenítéséhez:

```text
[mg_gift_finder_teaser recipient_ids="12,34,56"]
```

## Menülink

Az adminoldal tetején a **Menübe illeszthető link** mellett található másolás gombbal másold ki a céloldal URL-jét, majd ezt add meg a saját menü appban.

## Katalógus átadása besoroláshoz

Az Ajándékkereső adminoldalán a **Katalógus JSON letöltése** gombbal készíthető elemzési fájl. Az export nem tartalmaz termékneveket, SKU-kat, képeket, árakat, attribútumokat, készlet- vagy vásárlói adatokat. A visszakapott `mg-gift-finder-config` JSON ugyanitt importálható; előtte automatikus biztonsági mentés készül.

## Találati és cross-sell logika

A kereső minden válaszhoz egy vagy több WooCommerce-kategóriát rendel. A termékek annyi pontot kapnak, ahány választott logikai kategóriacsoportnak megfelelnek; csak a legmagasabb egyezésű csoport kerül a találatok közé. Az érdeklődési lépés csak születésnapnál és az általános „csak úgy” alkalomnál jelenik meg, ezért például az `Anyának → Karácsonyra` útvonalat nem írhatja felül egy nem igazolható „vicces” vagy „horgász” szűrés. Ha a vásárló a találatból kosárba tesz egy terméket, a már meglévő Mockup Generator cross-sell szabályok továbbra is automatikusan működnek a kosárban.

### Válasz-chipek a találatok fölött

A találati lista fölött chipként megjelenik minden megadott válasz, a kérdések sorrendjében, a végén a főoldali szezonális kártyáról érkező kiindulási kategóriával. Minden chip „×” gombja egy hivatkozás, amely ugyanazt az oldalt tölti be az adott válasz nélkül. Így a találat kiindulópont marad: nem kell elölről kezdeni a keresőt egyetlen válasz módosításához.

Ha egy elhagyott válaszra függő válasz épült (például az „Anyák napja” az „Anyának” címzettre), a link a függő választ is elhagyja.

Mivel a chipek hivatkozások, a szűrt találati oldalak `noindex,follow` fejlécet és az alap keresőoldalra mutató canonicalt kapnak. Enélkül a válaszkombinációk bejárható, azonos tartalmú URL-teret nyitnának a keresőrobotok előtt.

Az adminban összeállított ajándékcsomagok a hozzájuk rendelt kategóriák egyezésekor jelennek meg. A lazítást igénylő és az eredménytelen kereséseket a rendszer összesítve tárolja (adminoldal, 8. szakasz); ugyanattól a látogatótól ugyanazt a kombinációt 30 percen belül csak egyszer számolja. A tábla a korábbi, feloldás nélküli sorokat is megjeleníti.

A rangsor kiszámítása gyorsítótárba kerül (kombinációnként, egy órára). A gyorsítótárat automatikusan elavulttá teszi minden termékmentés, készletváltozás, termékkategória-módosítás és az Ajándékkereső beállításainak mentése.

## Kemény szűrés és progresszív lazítás

A válaszok **metszetként** (ÉS-kapcsolattal) szűrnek: a találatnak mindegyik megadott válasznak meg kell felelnie. Enélkül – unióban összeadva – a látható első képernyőt gyakorlatilag az első válasz (a címzett) töltötte ki, és a 3–5. kérdés alig változtatott az eredményen.

Ha a metszet a **lazítási küszöbnél** (alapérték: 12) kevesebb terméket ad, a kereső feloldja a legmagasabb szintű feloldható szűrőt, és újra próbálkozik. Ezt addig ismétli, amíg elég találat lesz, vagy amíg csak a címzett marad.

| Szint | Kérdés | Feloldás |
|---|---|---|
| 1 | Címzett | soha |
| 2 | Alkalom + házassághoz kapcsolódó esemény + szezonális kiindulás | utolsóként, együtt |
| 3 | Érdeklődés | másodikként |
| 4 | Foglalkozás | elsőként |

A szintek az adminoldal **7. Kemény szűrés és lazítás** szakaszában módosíthatók, a küszöbbel együtt. Az azonos szintű kérdések mindig együtt oldódnak fel: az alkalom feloldása a hozzá tartozó esemény feloldása nélkül értelmetlen állapot lenne. A címzett szintje kötött.

**A feloldott feltétel nem tűnik el, csak lefokozódik szűrőből rangsorjellé:** a neki megfelelő termékek továbbra is előre kerülnek a listában. A találatok fölött a feloldott válasz chipje áthúzva látszik, mellette rövid magyarázat („Két szűrőt feloldottunk (foglalkozás, érdeklődés), különben csak 1 ötletet találtunk volna."). Az áthúzott chip címkéjére kattintva a szűrő visszakapcsolható – ilyenkor az URL `mg_gift_keep` paramétere tiltja rá a lazítást, és a vevő látja a valóban szűk találati listát.

A metszetes szűrés a **7. szakaszban** kikapcsolható; ekkor a kereső a korábbi, unió szerinti viselkedésre vált, és lazítás sem történik.

### Élő találatszám a kérdéseknél

A varázsló minden lépésénél az egyes válaszok alatt megjelenik, hány ötlet vár az addigi válaszok fényében, és ugyanez kerül a „Tovább” gomb feliratába is. A szám a **szigorú**, lazítás előtti találatszám: a lazítás utáni mindig a küszöb fölött lenne, tehát semmit nem mondana. A küszöb alatt szám helyett a „kevés ötlet” jelzés jelenik meg, így a kereső sosem ígér konkrét darabszámot, amit a találati oldal nem tart be.

A számokat egy `admin-ajax.php` végpont adja (`action=mg_gift_counts`). Nem futtat rangsorolást: a válaszonként gyorsítótárazott termékhalmazok metszetét számolja, és a teljes választ egyetlen, verzióhoz kötött tranziensbe teszi – az ismételt hívás így nem indít új számítást. A végpont IP-nként 5 percenként 60 hívásig szolgál ki, hogy a bejárók forgalma ne terhelje a boltot. Kikapcsolt metszetes szűrésnél nincs élő találatszám, mert ott a szigorú szám nem a megjelenő listát írná le.

## Szűrő-diagnosztika

Az adminoldal **8. Szűrő-diagnosztika (címzett × alkalom)** táblázata minden lehetséges címzett–alkalom párra megmutatja:

- **Címzett önmagában:** hány termék felel meg a címzettnek;
- **Mai unió (OR):** a jelenlegi, OR-alapú jelöltszám a két válaszra;
- **Szigorú metszet (AND):** hány termék felel meg egyszerre mindkét válasznak;
- **Szűkítés:** az alkalom hány százalékkal csökkenti a címzett termékkörét.

A 0%-os sorok pirosan látszanak: ott az alkalom ugyanazokra a kategóriákra mutat, mint a címzett, tehát a szigorú (metszetes) szűrés nem szűkít semmit. Ezeken az útvonalakon a katalógus besorolásán kell változtatni, nem a keresőn. A táblázat külön jelzi az **átfedő kategóriafát** is: ha az alkalom és a címzett közös kategóriaágon él, az `include_children` miatt a „szigorú” szint sem lesz igazán szigorú.

A számok a találati rangsorral közös, verzióhoz kötött gyorsítótárból jönnek: válaszonként egy termékhalmaz, amiből minden pár metszete adatbázis-lekérdezés nélkül számolható.

## Mérés

A kereső minden lépése méri magát, így a lemorzsolódás végigkövethető. Az események a `dataLayer`-be (GTM/GA4), a Google taghez és – megadott marketing-hozzájárulás mellett – a Meta Pixelhez is kimennek:

| Esemény | Mikor | Paraméterek |
|---|---|---|
| `gift_finder_start` | az első válasz kiválasztásakor | `gift_question` |
| `gift_finder_step` | lépésenként egyszer, továbblépéskor vagy küldéskor | `gift_step`, `gift_question`, `gift_answer` |
| `gift_finder_results` | találati oldal betöltésekor | `gift_result_count`, `gift_choice_count`, `gift_match_max` |
| `gift_finder_no_results` | üres találat esetén | ugyanaz |
| `view_item_list` | a találatok megjelenésekor (első 20 termék) | GA4 `items` |
| `select_item` | termékkártyára kattintáskor | GA4 `items` |
| `gift_finder_load_more` | a „Mutass még ötleteket" gombra | `gift_revealed` |
| `gift_finder_restart` | az „Újrakezdem" gombra | – |
| `gift_finder_chip_removed` | egy válasz-chip elhagyásakor | `gift_question` |
| `gift_finder_relaxed` | ha a lazítás legalább egy szűrőt feloldott | `gift_relaxed_count`, `gift_relaxed_questions`, `gift_strict_count`, `gift_result_count` |

A Meta felé ugyanezek egyedi eseményként mennek (`GiftFinderStart`, `GiftFinderStep`, …), hozzájárulás nélkül nem.
