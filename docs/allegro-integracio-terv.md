# Allegro-integráció – terv

Fejlesztési terv. Önállóan végrehajtható, előzetes kontextus nélkül.

**Státusz:** terv, még nincs kód. Döntést igénylő pontok a 10. fejezetben.

**Rögzített keretek** (2026-07-29):

- Allegro eladói fiók és API-alkalmazás **megvan**.
- **Nincs EAN/GTIN, és nem is lesz.** Ez a terv központi megkötése – lásd 7. fejezet.
- **Csak a magyar piac** (allegro.hu). Nincs PL/CZ/SK értékesítés.

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

Az Allegro 2026 januárjában nyitotta meg a platformot magyar kereskedőknek;
nincs havidíj, csak sikeres eladás után jutalék, és **180 napos 0% jutalékos
welcome program** fut.
([hvg.hu](https://hvg.hu/kkv/20260121_allegro-magyar-kereskedok-beenged))

---

## 1. Amit meg kell építeni

| # | Funkció | Prioritás |
|---|---|---|
| 0 | **Kategória-felderítő** – megvalósíthatóság eldöntése EAN nélkül | **P0 – ez az első** |
| 1 | OAuth2 bejelentkezés + token-frissítés (sandbox és éles) | P0 |
| 2 | forme.hu CSV/XLSX beolvasás | P0 |
| 3 | Kategória- és paraméter-térkép | P0 |
| 4 | Képfeltöltés (mockup → Allegro CDN) | P0 |
| 5 | Saját katalógustermék javaslása (`product-proposals`) | P0 |
| 6 | Ajánlat létrehozás + aszinkron művelet követés | P0 |
| 7 | Állapottár: SKU ↔ offerId ↔ productId | P0 |
| 8 | GPSR-adatok (gyártó, EU-felelős személy) | P0 – jogszabályi |
| 9 | Ár- és készletszinkron (tömeges) | P1 |
| 10 | Rendelés-lehúzás eseményfolyamból | P1 |
| 11 | szamlazz.hu számlázás + számla visszatöltés Allegróra | P1 |
| 12 | Szállítási adat / csomagszám visszaírás | P2 |

Ami **kikerült** a magyar-piac-only döntés miatt: cross-border megosztás,
OSS-számlázás, devizakezelés, gépi fordítás ellenőrzése.

---

## 2. Üzleti keretek

### 2.1 Csak magyar piac – amit ez egyszerűsít

- **ÁFA:** belföldi értékesítés, egységes 27% – **nincs OSS**, nincs célország
  szerinti kulcslogika. A számlázó modul ezzel jelentősen egyszerűbb.
- **Deviza:** HUF-ban árazunk és HUF-ban számolunk el. Nincs PLN-váltás,
  nincs árfolyamkockázat az elszámolásban.
- **Nyelv:** magyar cím és leírás. Nincs gépi fordítás, amit ellenőrizni kellene
  (ez egyedi mintás, szójátékos feliratoknál komoly kockázat lett volna).
- **Szállítás:** csak belföldi.

### 2.2 Amit viszont ellenőrizni kell: az alappiac

Az Allegro alapmodellje a „list once, sell everywhere": a hirdetést jellemzően
allegro.pl-en hozod létre, majd megosztod a többi piacra. A magyar eladók
2026-os megnyitása után viszont az allegro.hu natív piacként is működik –
az Allegro súgója szerint HUF-ban árazol és HUF-ban számolsz el, és a
magyar piac **API-ból is kezelhető**.

**Konkrét ellenőrzendő kérdés az induláskor:** a meglévő fiókban az ajánlat
alap-piaca `allegro-pl` vagy `allegro-hu`? Ez azért számít, mert ha az alappiac
PL, akkor **lengyelül kell listázni** és az Allegro fordítja magyarra – ami egy
magyar-only eladónak felesleges kerülőút és minőségi kockázat.

Ez egy 10 perces ellenőrzés a meglévő fiókon (`GET /sale/marketplaces`,
illetve egy meglévő/teszt ajánlat publikációs beállításai), de a
címképző és leírásgeneráló logikát ez dönti el.

### 2.3 Egyedi/személyre szabott termék és az elállási jog

A POD-termékek „személyre szabottak", ezért a 14 napos elállási jog rájuk
jellemzően nem vonatkozik – de ezt az ajánlatban **deklarálni kell**.
Ha viszont a minta előre gyártott (nem a vevő küldi egyedileg), akkor
**vonatkozik**. Tisztázandó, melyik esetbe esik a kínálat.

### 2.4 GPSR

A 2024 decemberétől hatályos uniós termékbiztonsági rendelet miatt az
Allegro megköveteli a gyártói és EU-felelős személy adatokat. Erre van
API-erőforrás (`/sale/responsible-persons`, `/sale/responsible-producers`):
egyszer felvesszük, utána az ajánlatokra hivatkozunk rá.

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
  kezelése állandó állapottárral (SQLite) sokkal tisztább, mint `wp_options`.
- A CSV-határ pontosan az, amit kértél, és egyben **jó szeparáció**: az Allegro-oldal
  nem függ a WooCommerce belső szerkezetétől.
- A plugin exportőr gyakorlatilag a meglévő Temu CSV-export másolata, kibővítve.

### 3.1 Javasolt könyvtárszerkezet (önálló program)

```
allegro-sync/
  bin/allegro                 # CLI belépési pont
  src/
    Auth/TokenStore.php       # token mentés + refresh
    Auth/DeviceFlow.php       # bejelentkezés böngésző nélkül
    Api/Client.php            # HTTP, rate limit, 429 backoff, retry
    Api/Operations.php        # aszinkron művelet-pollozás
    Discover/CategoryScanner.php  # <-- a 0. fázis eszköze
    Import/CsvReader.php      # forme.hu CSV/XLSX
    Import/RowValidator.php
    Map/CategoryMap.php       # forme típus -> Allegro kategória + paraméterek
    Map/OfferBuilder.php      # sor -> offer payload
    Map/ColorMap.php          # magyar szín -> Allegro paraméterérték
    Map/SizeMap.php
    Sync/ImageSync.php
    Sync/ProductProposalSync.php
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
    ├─ ImageSync            ─► POST /sale/images
    ├─ ProductProposalSync  ─► POST /sale/product-proposals
    ├─ OfferSync            ─► POST /sale/product-offers ─► operation polling
    │                            └─► offerId elmentve SQLite-ba (SKU szerint)
    ▼
Allegro élő ajánlatok (allegro.hu)
    │
    ├─ PriceStockSync  ◄── napi CSV újraolvasás
    │
    └─ OrderSync   ◄── GET /order/events
             │
             ├─► szamlazz.hu Számla Agent  ─► PDF (27% ÁFA, HUF)
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
| `price_huf` | **új** | ✔ | bruttó, HUF |
| `stock` | **új** | ✔ | POD-nál jellemzően fix nagy szám |
| `image_url` | mockup URL | ✔ | több kép is: `image_url_2..n` |
| `weight_g` | **új** | ✔ | szállítási díjszabáshoz |
| `brand` | **új** | ✔ | GPSR + Allegro paraméter |
| `material` | **új** | – | pl. „100% pamut", kategóriaparaméter |

**EAN oszlop nincs** – tudatos döntés, lásd 7. fejezet.

### 5.1 Két csapda a meglévő exportban

**A `Sub SKU` random generálása** (`str_shuffle`, `class-temu-export-page.php:1181`)
az Allegrónál kifejezetten káros: minden export új azonosítót adna, és
**duplikált ajánlatokat** hoznánk létre. Az Allegro-exportban az SKU-nak
determinisztikusnak és stabilnak kell lennie:
`{alap_sku}-{tipus}-{szin}-{meret}`.

**A kamu 14Y-os sor** (`:1244`) Temu-specifikus hack – az Allegro-exportból
ki kell maradnia.

### 5.2 XLSX

XLSX-beolvasás is kell (kérted): a `MG_Temu_Xlsx_Writer` csak *ír*.
Olvasáshoz vagy egy minimál XLSX-olvasót írunk (a ZIP + `sharedStrings`
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

### 6.2 Felderítés és listázás

| Végpont | Szerep |
|---|---|
| `GET /sale/marketplaces` | elérhető piacok, alappiac ellenőrzése (2.2) |
| `GET /sale/categories`, `GET /sale/categories/{id}` | kategóriafa + `options` (lásd 7.2) |
| `GET /sale/categories/{id}/parameters` | paraméterek, kötelezőség, GTIN-jelölés |
| `GET /sale/products?phrase=…` | termékkatalógus keresés |
| `POST /sale/product-proposals` | **saját katalógustermék javaslása** |
| `POST /sale/images` | képfeltöltés (URL-ből is) |
| `POST /sale/product-offers` | ajánlat létrehozás – `201` vagy `202` (aszinkron) |
| `GET /sale/product-offers/{offerId}/operations/{operationId}` | aszinkron művelet státusz |
| `PATCH /sale/product-offers/{offerId}` | ajánlat módosítás, termékhez kötés |
| `GET /sale/offers` | saját ajánlatok listája |
| `GET /sale/shipping-rates` | szállítási díjszabások (max. 250 lista) |
| `POST /sale/offer-bulk-modification-commands` | tömeges ár/készlet, **kérésenként max. 25** |
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

## 7. A projekt sorsa: listázás EAN nélkül

Mivel **nincs és nem is lesz EAN**, ez a fejezet dönti el, hogy a projekt
egyáltalán megvalósítható-e, és milyen terméktípusokra.

### 7.1 A variáns-API megszűnt

**2026. április 14-én az Allegro kivezette a többvariánsos ajánlat-erőforrásokat.**
A `POST/GET/PUT/DELETE /sale/offer-variants` végpontok azóta `404`-et adnak.
A variánsokat most **automatikusan képzi a Termékkatalógusból**.
([allegro-api#12899](https://github.com/allegro/allegro-api/issues/12899))

Fontos pontosítás: az Allegrón **eddig is** minden szín×méret önálló ajánlat volt –
a kivezetett erőforrás csak *összefűzte* őket variánsválasztóvá. Tehát a
darabszám nem nőtt, de **elveszítettük a csoportosítás irányítását**.

Következmény: 1 minta × 3 típus × 5 szín × 6 méret = **90 ajánlat**.
100 mintánál 9000 ajánlat. Saját javasolt katalógustermékeknél az automatikus
variánscsoportosítás nagy valószínűséggel **nem fog összeállni**, tehát a
vevő külön hirdetéseket lát, nem méretválasztót. Ezt konverziós hátrányként
be kell kalkulálni.

**Ebből következik a legfontosabb operatív javaslat: szűk kínálattal indulni.**
Néhány minta, 1–2 terméktípus, korlátozott szín- és méretskála. A gazdaságosságot
és a variánsmegjelenést élesben kell megnézni, mielőtt ezresével töltünk fel.

### 7.2 Hogyan listázunk EAN nélkül – a felderítő

A jó hír: a kategória-erőforrás visszaad egy `options` struktúrát, benne
többek között:

- `productCreationEnabled` – javasolhatunk-e **saját terméket** a katalógusba
- `offersWithProductPublicationEnabled` – publikálható-e termékhez kötött ajánlat
- `customParametersEnabled` – megadhatunk-e egyedi paramétereket

A `GET /sale/categories/{id}/parameters` pedig megadja a paraméterek
kötelezőségét és a GTIN-jelölést (`options.isGTIN`).

**Ebből épül a 0. fázis eszköze, a kategória-felderítő:**

```
allegro categories:scan --root <categoryId>
```

Minden levélkategóriára kiírja:

| Kategória | GTIN kötelező? | `productCreationEnabled` | Verdikt |
|---|---|---|---|
| … | nem | igen | ✅ listázható EAN nélkül |
| … | igen | – | ❌ számunkra zárt |

Ez a tábla a **go/no-go döntés**: ha a póló/pulcsi/bögre kategóriákban a GTIN
nem kötelező és javasolhatunk saját terméket, a projekt mehet. Ha kötelező,
az a terméktípus számunkra zárva van, és más kategóriát kell keresni.

Az ismert hibaüzenetek, amikbe bele fogunk futni, ha rossz kategóriát választunk:
`MatchingProductNotFoundException`, illetve „ebben a kategóriában az ajánlatot
a Katalógushoz kell kötni"
([#8450](https://github.com/allegro/allegro-api/issues/8450),
[#10101](https://github.com/allegro/allegro-api/issues/10101)).

### 7.3 A listázási út EAN nélkül

1. `POST /sale/product-proposals` – saját katalógustermék javaslása
   (a GTIN paramétert üresen hagyva, ahol nem kötelező).
2. A kapott `productId`-t elmentjük az állapottárba.
3. `POST /sale/product-offers` – az ajánlat a saját termékre hivatkozik.
4. Ha a javaslat moderálásra vár, az ajánlatot később kötjük hozzá
   (`PATCH /sale/product-offers/{offerId}`).

**Kockázat:** a termékjavaslat moderálásfüggő és lassú lehet nagy tételszámnál.
Ez is a szűk kínálattal való indulás mellett szól.

### 7.4 Cím- és leíráskorlát

Az ajánlat címe **max. 75 karakter**. A mai Temu-név
(`terméknév + típus + toldalék`) ezt simán túllépi. Külön címképző logika kell,
prioritási sorrenddel (minta neve → típus → szín → méret) és csonkolással.

---

## 8. Számlázás (szamlazz.hu Számla Agent)

A szamlazz.hu Számla Agent HTTP POST-tal, XML-lel működik: a rendszer egy
XML fájlt küld multipart POST-ban, ami tartalmazza a vezérlő paramétereket
és a számla adatait; számlánként egy XML.
([docs.szamlazz.hu/agent](https://docs.szamlazz.hu/agent))

Magyar-only értékesítésnél ez a modul **jelentősen egyszerűbb**: fix HUF,
fix 27% ÁFA, magyar vevő, nincs OSS-logika.

Folyamat:

1. `OrderSync` lehúzza az új, **kifizetett** rendelést (`GET /order/events`).
2. Leképezzük a szamlazz.hu számla-XML-re: vevő adatai, tételek, 27% ÁFA, HUF.
3. Számla Agent hívás → válaszban számlaszám + PDF.
4. PDF elmentése, majd feltöltés az Allegróra
   (`POST …/invoices`, majd `PUT …/invoices/{id}/file`).
5. Számlaszám + Allegro `invoiceId` rögzítése az állapottárban.

Idempotencia: rendelésazonosítónként **pontosan egy** számla. Az állapottár
`orders` táblája őrzi, hogy kiállítottuk-e már – újrafuttatásnál nem duplázunk.
Az Allegro rendelésenként **egy** PDF-et fogad.

> **Megjegyzés a pontosságról:** a `developer.allegro.pl`, a `help.allegro.com`
> és a `docs.szamlazz.hu` domaineket a fejlesztői környezet proxyja blokkolja
> (403), így a mezőszintű részleteket – a `product-offers` payload teljes
> sémáját, a kategória-`options` pontos mezőneveit, és a szamlazz.hu XML
> mezőneveit (`szamlaagentkulcs`, `action-xmlagentxmlfile` stb.) – **a hivatalos
> dokumentációval kell egyeztetni az implementáció előtt**. A végpontlista és a
> viselkedés a fejlesztői portál, a GitHub issue-k és a súgó alapján megbízható.

---

## 9. Fázisok

| Fázis | Tartalom | Eredmény |
|---|---|---|
| **0. Felderítés** ⚠️ | OAuth device flow + `categories:scan`. Alappiac ellenőrzése (2.2). A 7.2 tábla előállítása a póló/pulcsi/bögre kategóriákra. | **Go/No-go döntés** |
| **1. Egy ajánlat kézzel** ⚠️ | Sandboxban **egyetlen** POD-ajánlat végigvitele: `product-proposals` → kép → `product-offers`. | Bizonyíték, hogy EAN nélkül megy |
| **2. Alapkliens** | Token store, HTTP kliens rate limittel, aszinkron művelet-pollozás, SQLite állapottár. | `allegro auth`, `allegro whoami` |
| **3. Export + import** | Plugin Allegro-CSV export; CLI beolvasás, validálás, `--dry-run` payload-nyomtatás. | Ellenőrizhető payloadok |
| **4. Feltöltés** | Kép- és termékjavaslat-szinkron, ajánlat-létrehozás, újrafuttatás-biztos idempotencia. | Élő ajánlatok sandboxban |
| **5. Ár/készlet** | Tömeges módosítás (`offer-bulk-modification-commands`, 25-ös kötegek). | Napi szinkron |
| **6. Rendelés + számla** | `GET /order/events`, szamlazz.hu, számla-visszatöltés. | Zárt kör |
| **7. Éles indulás** | Éles kulcsok, **szűk kínálattal** indulás, monitorozás. | Élesben |

A **0. és 1. fázis kritikus**, és együtt is csak néhány nap. Amíg nem tudjuk,
hogy EAN nélkül feltölthető-e egy póló a választott kategóriába, **semmilyen
keretrendszert nem érdemes írni**. Ez a terv legfontosabb üzenete.

---

## 10. Nyitott kérdések

Megválaszolva: Allegro-fiók ✔ megvan · EAN ✘ nincs és nem lesz · piac ✔ csak HU.

Ami még nyitott:

1. **Hol legyen a kód?** Ebben a repóban egy `allegro-sync/` mappában,
   vagy külön repóban? (Javaslat: külön repó, mert nem WordPress-kód.)
2. **Melyik terméktípusokkal indulunk?** A kategória-térképet típusonként
   kézzel kell felvenni – 2-3 típussal indulni sokkal gyorsabb, és a 7.1
   darabszám-robbanás miatt amúgy is ez a helyes.
3. **Szállítás:** ki szállít belföldön, milyen díjszabással?
   Allegro Shipping / DPD, vagy saját futár?
4. **Árazás:** a HUF-ár honnan jön – a WooCommerce ár + szorzó, vagy külön
   Allegro-árlista? A jutalékot és a szállítást be kell építeni.
5. **szamlazz.hu:** a forme.hu ma is ezt használja? Van Agent kulcs?
6. **Elállási jog:** a kínálat előre gyártott mintás termék (elállás jár),
   vagy vevő által személyre szabott (nem jár)? Lásd 2.3.

---

## 11. Amit szándékosan kihagytam az első körből

- Allegro Ads / kampánykezelés
- Vevői üzenetek (`/messaging`) kezelése
- Visszaküldés és reklamáció (`/order/refund-claims`) automatizálása
- One Fulfillment (Allegro raktár) – POD-nál nem értelmes
- Automatikus árverseny-követés

Ezek mind API-ból elérhetők, de csak akkor éri meg, ha az alapfolyamat
már stabilan megy.
