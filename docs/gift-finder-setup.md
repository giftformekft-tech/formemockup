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

Az adminban összeállított ajándékcsomagok a hozzájuk rendelt kategóriák egyezésekor jelennek meg. Az eredménytelen kereséseket a rendszer összesítve tárolja; ugyanattól a látogatótól ugyanazt a kombinációt 30 percen belül csak egyszer számolja.

A rangsor kiszámítása gyorsítótárba kerül (kombinációnként, egy órára). A gyorsítótárat automatikusan elavulttá teszi minden termékmentés, készletváltozás, termékkategória-módosítás és az Ajándékkereső beállításainak mentése.

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

A Meta felé ugyanezek egyedi eseményként mennek (`GiftFinderStart`, `GiftFinderStep`, …), hozzájárulás nélkül nem.
