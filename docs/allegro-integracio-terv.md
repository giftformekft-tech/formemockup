# Allegro-integráció – terv

Fejlesztési terv. Önállóan végrehajtható, előzetes kontextus nélkül.

**Státusz:** terv, még nincs kód. Döntést igénylő pontok a 10. fejezetben.

---

## 0. Kontextus

A forme.hu egy magyar, saját gyártású ajándéktárgy-webshop (WooCommerce +
`mockup-generator` plugin): egyedi mintás pólók, pulcsik, bögrék, saját nyomdával.
A termékkínálat **virtuális**: egy minta (design) × terméktípus × szín × méret
kombinációkból áll össze, a mockup képek generáltak.

Ma egy marketplace-re megy kivitel: **Temu**. Az `admin/class-temu-export-page.php`
+ `includes/class-temu-xlsx-writer.php` páros egy XLSX-mestersablont tölt fel
adatsorokkal, amit a felhasználó **kézzel tölt fel** a Temu felületére.
Nincs API-hívás, nincs visszacsatolás, nincs rendeléskezelés.

Az Allegro ettől **alapjaiban különbözik**: valódi REST API van, tokennel,
aszinkron műveletekkel, rendelés-eseményfolyammal és számlafeltöltéssel.
Tehát nem „még egy exportfájl" kell, hanem egy **kétirányú szinkron program**.

### Miért időszerű

Az Allegro 2026 januárjában nyitotta meg a platformot magyar kereskedőknek:
egy fiókkal PL + CZ + SK + HU (~21 M vásárló), nincs havidíj, csak sikeres
eladás után jutalék, és **180 napos 0% jutalékos welcome program**.
([hvg.hu](https://hvg.hu/kkv/20260121_allegro-magyar-kereskedok-beenged),
[Allegro sajtóközlemény](https://en.media.allegro.pl/355515-allegro-the-largest-online-marketplace-of-european-origin-arrives-in-hungary-and-invites-merchants-to-join))

---

## 1. Amit meg kell építeni

| # | Funkció | Prioritás |
|---|---|---|
| 1 | OAuth2 bejelentkezés + token-frissítés (sandbox és éles) | P0 |
| 2 | forme.hu CSV/XLSX beolvasás | P0 |
| 3 | Kategória- és paraméter-feltérképezés | P0 |
| 4 | Képfeltöltés (mockup → Allegro CDN) | P0 |
| 5 | Ajánlat (offer) létrehozás + aszinkron művelet követés | P0 |
| 6 | Állapottár: SKU ↔ offerId, mit töltöttünk már fel | P0 |
| 7 | Ár- és készletszinkron (tömeges) | P1 |
| 8 | Rendelés-lehúzás eseményfolyamból | P1 |
| 9 | szamlazz.hu számlázás + számla visszatöltés Allegróra | P1 |
| 10 | Szállítási adat / csomagszám visszaírás | P2 |
| 11 | Cross-border megosztás HU/CZ/SK piacokra | P2 |
| 12 | GPSR-adatok (gyártó, EU-felelős személy) | P0 – jogszabályi |

---

## 2. Előbb tisztázandó üzleti keretek

Ezek **nem fejlesztési** kérdések, de a fejlesztés irányát eldöntik. Érdemes
előbb rendezni, mert utólag drága átépíteni.

### 2.1 Melyik piacra listázunk elsőként?

Az Allegro logikája: **„list once, sell everywhere"**. Egy ajánlatot listázol
(jellemzően allegro.pl-en, PLN-ben), majd *megosztásra beküldöd* a többi piacra.
Az Allegro **automatikusan fordít** és **automatikusan árat konvertál** (EKB-árfolyam
alapú árkonverter szabály), ha nem adsz meg saját HUF-árat.

Következmény: a programnak elég **egy** ajánlathalmazt kezelnie, a HU/CZ/SK
megjelenés az Allegro oldalán dől el. Ez nagy egyszerűsítés.

Kockázat: a gépi fordítás egyedi mintás termékeknél (szójátékos feliratok!)
könnyen félremegy. Erre külön ellenőrzési kör kell.

### 2.2 ÁFA / OSS

PL/CZ/SK fogyasztóknak történő értékesítés távértékesítés → **OSS-regisztráció**
kell a 10 000 EUR-s uniós küszöb felett, és a célország ÁFA-kulcsával kell
számlázni. A szamlazz.hu tud OSS-számlát és devizás számlát.
Ez a számlázó modul konfigurációját közvetlenül érinti (ÁFA-kulcs a
vevő országa szerint, nem fixen 27%).

**Ezt könyvelővel kell egyeztetni a fejlesztés előtt.**

### 2.3 Kifizetés és deviza

Az Allegro PLN-ben számol el. A HUF-ra váltás és annak árfolyamvesztesége
az árazásba be kell épüljön.

### 2.4 Egyedi/személyre szabott termék

A POD-termékek „személyre szabottak", ezért a 14 napos elállási jog rájuk
jellemzően nem vonatkozik – de ezt az ajánlatban **deklarálni kell**.
Ha viszont a minta előre gyártott (nem a vevő küldi), akkor **vonatkozik**.
Tisztázandó, melyik esetbe esik a kínálat.

---

## 3. Architektúra – hol lakjon a kód

Három reális opció:

| Opció | Előny | Hátrány |
|---|---|---|
| **A.** Plugin admin oldal (Temu-minta) | Ismerős UI, közvetlen WooCommerce-adat, nem kell CSV | WP-cron megbízhatatlan a rendszeres szinkronhoz; a plugin már így is hatalmas; OAuth-token tárolás/refresh WP-ben kényelmetlen |
| **B.** Önálló PHP CLI program | Composer, tesztelhető, valódi cronon fut, hosszú futású szinkron OK | Külön telepítés, külön hely |
| **C.** Hibrid *(javaslat)* | A plugin csak **exportál** egy CSV-t (mint a Temunál); az önálló program végzi az API-munkát | Két komponens |

**Javaslat: C.** Indoklás:

- A rendelés- és számlaszinkron **folyamatosan** kell fusson (5–15 percenként).
  Ezt WP-cronra tenni hiba lenne – a WP-cron csak akkor fut, ha van látogató.
- Az OAuth refresh-token 3 hónapig él, a hozzáférési token 12 óráig. Ennek
  kezelése egy állandó állapottárral (SQLite) sokkal tisztább, mint `wp_options`.
- A CSV-határ pontosan az, amit kértél, és egyben **jó szeparáció**: az Allegro-oldal
  nem függ a WooCommerce belső szerkezetétől.
- A plugin exportőr gyakorlatilag a meglévő Temu CSV-export másolata,
  kibővítve. Kis munka.

### 3.1 Javasolt könyvtárszerkezet (önálló program)

```
allegro-sync/
  bin/allegro                 # CLI belépési pont
  src/
    Auth/TokenStore.php       # token mentés + refresh
    Auth/DeviceFlow.php       # bejelentkezés böngésző nélkül
    Api/Client.php            # HTTP, rate limit, 429 backoff, retry
    Api/Operations.php        # aszinkron művelet-pollozás
    Import/CsvReader.php      # forme.hu CSV/XLSX
    Import/RowValidator.php
    Map/CategoryMap.php       # forme típus -> Allegro kategória + paraméterek
    Map/OfferBuilder.php      # sor -> offer payload
    Map/ColorMap.php          # magyar szín -> Allegro paraméterérték
    Map/SizeMap.php
    Sync/ImageSync.php
    Sync/OfferSync.php
    Sync/PriceStockSync.php
    Sync/OrderSync.php
    Invoice/SzamlazzClient.php
    Invoice/InvoiceUploader.php
    State/Db.php              # SQLite
  config/
    categories.php            # kézzel karbantartott kategória-térkép
    .env                      # client id/secret, szamlazz kulcs
  var/state.sqlite
```

---

## 4. Adatfolyam

```
forme.hu (WooCommerce plugin)
    │  „Allegro export" gomb
    ▼
allegro-export.csv  ── termékvariáns-soronként
    │
    ▼
allegro-sync import           (validálás, dry-run)
    │
    ├─ ImageSync   ─► POST /sale/images
    ├─ OfferSync   ─► POST /sale/product-offers ─► operation polling
    │                    └─► offerId elmentve SQLite-ba (SKU szerint)
    ▼
Allegro élő ajánlatok
    │
    ├─ PriceStockSync  ◄── napi CSV újraolvasás
    │
    └─ OrderSync   ◄── GET /order/events
             │
             ├─► szamlazz.hu Számla Agent  ─► PDF
             └─► POST /order/checkout-forms/{id}/invoices
                 PUT  /order/checkout-forms/{id}/invoices/{invId}/file
```

---

## 5. A forme.hu export CSV sémája

A mai Temu CSV (`admin/class-temu-export-page.php:1059`) oszlopai:

```
Termék neve; SKU; Sub SKU; Szín; Méret; Leírás; Kép URL
```

Ez az Allegróhoz **kevés**. Hiányzik belőle a legfontosabb kettő: **ár** és **készlet**.
Javasolt bővített séma:

| Oszlop | Forrás | Kötelező | Megjegyzés |
|---|---|---|---|
| `sku` | jelenlegi `SKU` + variáns | ✔ | **Ez lesz az `external.id`** – idempotencia-kulcs |
| `parent_sku` | alap SKU | ✔ | variánscsoportosításhoz |
| `name` | terméknév + típus | ✔ | Allegro cím **max. 75 karakter** – csonkolni kell! |
| `description` | kategória-SEO szöveg | ✔ | HTML, Allegro-specifikus szekciókba tördelve |
| `type` | terméktípus slug (polo, pulcsi, bogre) | ✔ | kategória-térkép kulcsa |
| `color` | szín címke | ✔ | Allegro paraméterértékre kell képezni |
| `size` | méret | ✔ | ua. |
| `price_huf` | **új** | ✔ | nettó/bruttó – tisztázandó |
| `stock` | **új** | ✔ | POD-nál jellemzően fix nagy szám |
| `image_url` | mockup URL | ✔ | több kép is: `image_url_2..n` |
| `weight_g` | **új** | ✔ | szállítási díjszabáshoz |
| `brand` | **új** | ✔ | GPSR + Allegro paraméter |
| `material` | **új** | – | pl. „100% pamut", kategóriaparaméter |
| `ean` | **új** | – | ha van; lásd 7. fejezet |

A `Sub SKU` random generálása (`str_shuffle`, `class-temu-export-page.php:1181`)
az Allegrónál **kifejezetten káros**: minden export új azonosítót adna,
és duplikált ajánlatokat hoznánk létre. Az Allegro-exportban az SKU-nak
**determinisztikusnak és stabilnak** kell lennie:
`{alap_sku}-{tipus}-{szin}-{meret}`.

XLSX-beolvasás is kell (kérted): a `MG_Temu_Xlsx_Writer` csak *ír*.
Olvasáshoz vagy egy minimál XLSX-olvasót írunk (a ZIP+sharedStrings
megközelítés a repóban már ismerős), vagy a CSV-t tesszük kötelezővé.
**Javaslat: CSV a kötelező formátum, XLSX opcionális kényelmi funkció.**

---

## 6. Allegro API – amit használni fogunk

Alap: `https://api.allegro.pl` · Sandbox: `https://api.allegro.pl.allegrosandbox.pl`
Fejléc: `Accept: application/vnd.allegro.public.v1+json`

### 6.1 Hitelesítés

- OAuth2 **Authorization Code** (felhasználói kontextus – ajánlatokhoz/rendelésekhez kell)
- **Device Flow** – CLI-hez ez a kényelmes: kiír egy kódot, böngészőben jóváhagyod
- Access token ~12 óra, refresh token ~3 hónap → automatikus refresh kell
- Sandbox auth host: `https://allegro.pl.allegrosandbox.pl/auth/oauth/…`

**Rate limit: 9000 kérés/perc Client ID-nként**, ezen felül erőforrás-specifikus
alacsonyabb limitek, felhasználónkénti leaky-bucket, túllépésnél `429`.
A kliensbe kell egy központi ütemező + exponenciális backoff.

### 6.2 Listázás

| Végpont | Szerep |
|---|---|
| `GET /sale/categories`, `GET /sale/categories/{id}/parameters` | kategória- és paraméterfa – ebből épül a `config/categories.php` |
| `GET /sale/products?phrase=…` | termékkatalógus keresés |
| `POST /sale/product-proposals` | **új termék javaslása** a katalógusba |
| `POST /sale/images` | képfeltöltés (URL-ből is) |
| `POST /sale/product-offers` | **ajánlat létrehozás** – `201` vagy `202` (aszinkron) |
| `GET /sale/product-offers/{offerId}/operations/{operationId}` | aszinkron művelet státusz |
| `GET /sale/offers` | saját ajánlatok listája |
| `GET /sale/shipping-rates` | szállítási díjszabások (max. 250 lista) |
| `POST /sale/offer-bulk-modification-commands` | **tömeges ár/készlet módosítás, kérésenként max. 25** |
| `/sale/responsible-persons`, `/sale/responsible-producers` | GPSR-adatok |

### 6.3 Rendelés és számla

| Végpont | Szerep |
|---|---|
| `GET /order/events` | rendelés-eseményfolyam – **inkrementális lehúzáshoz ez a helyes** |
| `GET /order/checkout-forms/{id}` | rendelés részletei |
| `POST /order/checkout-forms/{id}/shipments` | csomagszám visszaírás |
| `POST /order/checkout-forms/{id}/invoices` | számlaobjektum létrehozása |
| `PUT /order/checkout-forms/{id}/invoices/{invoiceId}/file` | **PDF feltöltés** |

A feltöltött számla ellenőrzési státuszt kap: `WAITING` → `ACCEPTED` / `REJECTED`.
A `REJECTED` esetet kezelni kell (értesítés).

---

## 7. A legnagyobb kockázat: termékkatalógus, GTIN és variánsok

Ezt érdemes külön kiemelni, mert **ez döntheti el a projekt megvalósíthatóságát**.

### 7.1 A variáns-API megszűnt

**2026. április 14-én az Allegro kivezette a többvariánsos ajánlat-erőforrásokat.**
A `POST/GET/PUT/DELETE /sale/offer-variants` végpontok azóta `404`-et adnak.
Helyette a variánsokat az Allegro **automatikusan képzi a Termékkatalógus alapján**.
([allegro-api#12899](https://github.com/allegro/allegro-api/issues/12899))

Következmény a mi modellünkre: **nem tudunk „egy ajánlat, benne színek és méretek"
szerkezetet feltölteni.** Minden szín×méret kombináció **önálló ajánlat**, amit
egy katalógustermékhez kötünk, és az Allegro fűzi őket variánscsoportba.

Ez a darabszámot megsokszorozza: 1 minta × 3 típus × 5 szín × 6 méret = **90 ajánlat**.
100 mintánál 9000 ajánlat. Ezért kritikus a tömeges műveletek és a
rate limit korrekt kezelése, és ezért kell stabil `external.id`.

### 7.2 GTIN/EAN és a katalógusba illesztés

Az Allegro a legtöbb kategóriában a GTIN-t alapparaméterként kezeli, és
egyre több kategóriában **kötelezi az ajánlat katalógushoz kötését**.
Egyedi mintás POD-terméknek viszont **nincs EAN-ja** és nincs benne a katalógusban.

A gyakorlatban ismert hibák: `MatchingProductNotFoundException`, illetve
„ebben a kategóriában az ajánlatot a Katalógushoz kell kötni"
([#8450](https://github.com/allegro/allegro-api/issues/8450),
[#10101](https://github.com/allegro/allegro-api/issues/10101)).

Lehetséges utak:

1. **`POST /sale/product-proposals`** – minden variánsra új katalógusterméket
   javaslunk. Ez a „hivatalos" út, de moderálásfüggő és lassú lehet 9000 tételnél.
2. **Saját GTIN-készlet vásárlása** (GS1 Magyarország) és minden variánsra
   valódi EAN kiosztása. Költséges, de ez a legrobusztusabb, és a Temu/
   egyéb piacterekre is jó. Hosszú távon ezt javaslom.
3. **Katalóguskötést nem igénylő kategória választása** – ellenőrizni kell,
   hogy a ruházati/ajándék kategóriák közül melyik ilyen még.

**Ezt a három utat a sandboxban, éles kategóriaazonosítókkal kell letesztelni,
mielőtt bármi mást fejlesztünk.** Ez a terv 1. fázisa (lásd 9.).

### 7.3 Cím- és leíráskorlát

Az ajánlat címe **max. 75 karakter**. A mai Temu-név (`terméknév + típus + toldalék`)
ezt simán túllépi. Külön címképző logika kell, prioritási sorrenddel
(minta neve → típus → szín → méret), és csonkolással.

---

## 8. Számlázás (szamlazz.hu Számla Agent)

A szamlazz.hu Számla Agent HTTP POST-tal, XML-lel működik: a rendszer egy
XML fájlt küld multipart POST-ban, ami tartalmazza a vezérlő paramétereket
és a számla adatait; számlánként egy XML.
([docs.szamlazz.hu/agent](https://docs.szamlazz.hu/agent))

Tervezett folyamat:

1. `OrderSync` lehúzza az új, **kifizetett** rendelést (`GET /order/events`).
2. Leképezzük a szamlazz.hu számla-XML-re: vevő adatai, tételek,
   **a vevő országa szerinti ÁFA-kulcs** (OSS!), deviza.
3. Számla Agent hívás → válaszban számlaszám + PDF.
4. PDF elmentése, majd feltöltés az Allegróra
   (`POST …/invoices`, majd `PUT …/invoices/{id}/file`).
5. Számlaszám + Allegro `invoiceId` rögzítése az állapottárban.

Idempotencia: rendelésazonosítónként **pontosan egy** számla. Az állapottár
`orders` táblája őrzi, hogy kiállítottuk-e már – újrafuttatásnál nem duplázunk.
Az Allegro rendelésenként **egy** PDF-et fogad.

> **Megjegyzés a pontosságról:** a `docs.szamlazz.hu` és a `developer.allegro.pl`
> domaineket a fejlesztői környezet proxyja blokkolja (403), így a pontos
> mezőneveket (`szamlaagentkulcs`, `action-xmlagentxmlfile` stb.) és a
> `product-offers` payload teljes sémáját **a hivatalos dokumentációval kell
> egyeztetni az implementáció előtt**. A fenti végpontlista és a viselkedés
> a fejlesztői portál, a GitHub issue-k és a súgó alapján megbízható,
> de a mezőszintű részletek ellenőrzendők.

---

## 9. Fázisok

| Fázis | Tartalom | Eredmény |
|---|---|---|
| **0. Előkészítés** | Allegro üzleti fiók, sandbox fiók, alkalmazás regisztrálása (client id/secret). Könyvelői egyeztetés (OSS). | Hozzáférés megvan |
| **1. Megvalósíthatósági teszt** ⚠️ | Sandboxban **kézzel/scripttel egyetlen** POD-ajánlat feltöltése: kategória kiválasztás, GTIN-kérdés eldöntése (7.2), variánsképzés ellenőrzése. | **Go/No-go döntés** |
| **2. Alapkliens** | OAuth device flow, token store, HTTP kliens rate limittel, aszinkron művelet-pollozás. | `allegro auth`, `allegro whoami` |
| **3. Export + import** | Plugin Allegro-CSV export; CLI beolvasás, validálás, `--dry-run` payload-nyomtatás. | Ellenőrizhető payloadok |
| **4. Feltöltés** | Képfeltöltés, ajánlat-létrehozás, SQLite állapottár, újrafuttatás-biztos idempotencia. | Élő ajánlatok sandboxban |
| **5. Ár/készlet** | Tömeges módosítás (`offer-bulk-modification-commands`, 25-ös kötegek). | Napi szinkron |
| **6. Rendelés + számla** | `GET /order/events`, szamlazz.hu, számla-visszatöltés. | Zárt kör |
| **7. Éles indulás** | Éles kulcsok, kis kínálattal indulás, monitorozás, cross-border megosztás. | Élesben |

Az **1. fázis kritikus**: ha kiderül, hogy a POD-termék katalóguskötése nem
megoldható ésszerű erőfeszítéssel, az egész projekt iránya változik
(pl. GTIN-vásárlás előfeltétellé válik). Ezért ez néhány napos, olcsó
kísérlet legyen, még mielőtt bármilyen keretrendszer épül.

---

## 10. Nyitott kérdések – ezekre válasz kell

1. **Hol legyen a kód?** Ebben a repóban egy `allegro-sync/` mappában,
   vagy külön repóban? (Javaslat: külön repó, mert nem WordPress-kód.)
2. **Van már Allegro eladói fiók** és regisztrált API-alkalmazás?
3. **Melyik terméktípusokkal indulunk?** A kategória-térképet típusonként
   kézzel kell felvenni – 2-3 típussal indulni sokkal gyorsabb.
4. **Van-e EAN-készlet**, vagy tervezed GS1-től venni? (7.2 miatt kritikus)
5. **Szállítás:** ki szállít PL/CZ/SK-ba, milyen díjszabással?
   Allegro Shipping / DPD, vagy saját?
6. **Árazás:** a HUF-ár honnan jön – a WooCommerce ár + szorzó, vagy külön
   Allegro-árlista? Jutalék + PLN-váltás + szállítás beépítése.
7. **szamlazz.hu:** a forme.hu ma is ezt használja? Van Agent kulcs?
8. **OSS-regisztráció** megvan-e már?

---

## 11. Amit szándékosan kihagytam az első körből

- Allegro Ads / kampánykezelés
- Vevői üzenetek (`/messaging`) kezelése
- Visszaküldés és reklamáció (`/order/refund-claims`) automatizálása
- One Fulfillment (Allegro raktár) – POD-nál nem értelmes
- Automatikus árverseny-követés

Ezek mind API-ból elérhetők, de csak akkor éri meg, ha az alapfolyamat
már stabilan megy.
