# Ajándékkereső – facet-szűrés, progresszív lazítás, válasz-chipek

Fejlesztési brief. Önállóan végrehajtható, előzetes kontextus nélkül.

---

## 0. Kontextus

A repo egy WooCommerce plugin (`mockup-generator.php`, „Mockup Generator – FAST WebP SAFE"),
amely egy magyar, saját gyártású ajándéktárgy-webshopot (forme.hu) szolgál ki:
egyedi mintás pólók, bögrék és hasonlók, saját nyomdával.

Az **Ajándékkereső** egy vezetett kérdőív, amely a látogatót néhány kérdés után
termékajánlatokhoz vezeti.

### Érintett fájlok

| Fájl | Szerep |
|---|---|
| `includes/class-gift-finder.php` | A teljes kereső: blokk, shortcode, varázsló, találatszámítás, statisztika (~1000 sor) |
| `assets/js/gift-finder.js` | Varázsló-léptetés, mérési események, akadálymentesség |
| `assets/css/gift-finder.css` | Stílus (egysoros, tömörített formátum) |
| `admin/class-gift-finder-page.php` | Adminfelület: kérdések, válaszok, kártyák, színek |
| `admin/class-gift-finder-transfer.php` | Beállítás-export/import JSON-ban |
| `docs/gift-finder-setup.md` | Felhasználói dokumentáció |

### Hogyan működik ma

**Kérdések.** Öt kulcs, ebben a sorrendben (`MG_Gift_Finder::defaults()`):
`recipient`, `occasion`, `wedding_type`, `interest`, `occupation`.
A varázsló minden lépést kirenderel, a JS rejti el a nem aktuálisat.
Az űrlap `GET`-tel küld, a paraméterek `mg_gift_<kérdéskulcs>`, plusz
`mg_gift_submitted=1`. A `mg_gift_start` a főoldali szezonális kártyákról érkező
belépési kategória.

**Válaszok.** Egy válasz több WooCommerce-kategóriához köthető
(`category_id` + `category_ids`), lehet szabad kulcsszólistája (`keywords`),
és lehet függő (`parent_category_ids`: csak akkor jelenik meg, ha egy korábbi
válasz ezek valamelyikét adta). A `wedding_type` kérdés így függ az `occasion`-tól.

**Találatszámítás** (`compute_ranked_results()`):

1. Minden válaszhoz **külön** lekérdezés fut a hozzá tartozó kategóriákra
   (`include_children => true`), plusz egy cím szerinti kulcsszókeresés.
2. A jelöltek **unióban** adódnak össze (OR).
3. Pontozás: a termék annyi pontot kap, ahány válaszcsoportnak megfelel
   (kategória- vagy kulcsszóegyezés).
4. `compose_diverse_results()` az **első** válasz (címzett) találataiból tölti fel
   az első 10 helyet, a többiből a következő 10-et („kapcsolódó ötlet").
5. Maximum 50 találat, ebből 20 látszik, a „Mutass még" 10-esével nyit.

**Gyorsítótár.** A kész rangsor tranziensbe kerül (`mg_gift_rank_<md5>`), kulcsában
egy verziószámmal (`mg_gift_finder_cache_version` option). A verziót termékmentés,
készletváltozás, kategóriamódosítás és beállításmentés lépteti, percenként legfeljebb
egyszer. TTL: 1 óra (`MG_Gift_Finder::CACHE_TTL`).

**Mérés.** A `gift-finder.js` `track()` segédfüggvénye egyszerre küld a `dataLayer`-be,
a `gtag`-hez és – marketing-hozzájárulás esetén – a Meta Pixelhez. Meglévő események:
`gift_finder_start`, `gift_finder_step`, `gift_finder_results`, `gift_finder_no_results`,
`view_item_list`, `select_item`, `gift_finder_load_more`, `gift_finder_restart`.
**Minden új eseményt ezen a segédfüggvényen keresztül kell küldeni.**

**Statisztika.** `log_no_results()` az eredménytelen kombinációkat gyűjti a
`mg_gift_finder_no_results` optionbe (max 100 sor, látogatónként 30 perces
deduplikációval).

**Holt kód.** A `budgets` beállítást a `get_settings()` minden betöltéskor kiüríti –
az árkeret-kérdést korábban szándékosan eltávolították. Ne éleszd újra.

---

## 1. Cél

Két összefüggő probléma megoldása.

**(A) A 3–5. kérdés alig változtat az eredményen.** Mivel a válaszok unióban
adódnak, a látható első képernyőt gyakorlatilag az első válasz (címzett) tölti ki.
A vevő úgy érzi, hiába válaszolt.

**(B) A találat végállapot, nem kiindulópont.** Ha nem tetszik az eredmény, az
egyetlen lehetőség a teljes újrakezdés.

A megoldás három elemből áll:

1. **Válasz-chipek** – a válaszok láthatók és egyenként levehetők a találatok fölött.
2. **Kemény facet + progresszív lazítás** – a fontos válaszok metszetként (AND)
   szűrnek; ha így túl kevés a találat, a legkevésbé fontos feltételt feloldjuk,
   és ezt meg is mutatjuk.
3. **Élő találatszám** – a varázsló lépéseinél látszik, hány ötlet vár.

---

## 2. Nyitott döntés

**Mit mutasson az élő találatszám?** A lazítás után mindig a küszöb fölötti a
találatszám, tehát az a szám semmit nem mond. A szigorú (lazítás előtti) szám
informatív, de nem egyezik a végül megjelenő listával.

**Alapértelmezett döntés, ha nem kapsz mást:** a **szigorú** szám, de a küszöb
alatt szám helyett szöveges jelzés („kevés ötlet"). Így soha nem ígérünk konkrét
darabszámot, amit aztán nem tartunk be. Ez a döntés kizárólag a 4. szakaszt érinti;
az első hármat nem blokkolja.

**További alapértékek:** lazítási küszöb 12 (adminban állítható); a chipek
URL-alapúak, teljes oldalújratöltéssel (nem AJAX).

---

## 3. Fájlszerkezet

**Új fájl – egy darab**

- `includes/class-gift-finder-facets.php`
  Lekérdezés-építés, szigorú/lazított szintek, lazítási ciklus, találatszámlálás.
  Indoklás: a `class-gift-finder.php` már ~1000 sor, és ezt a logikát három hívó
  használja majd (frontend, admin diagnosztika, számláló végpont).
  Regisztráld a betöltendő fájlok listájába a `mockup-generator.php`-ban.

**Módosuló fájlok**

- `includes/class-gift-finder.php` – chip-sáv; a lekérdezés-építés átadása a facets
  osztálynak; lazítás-tudatos találati blokk; a no-result statisztika átalakítása
  lazítás-statisztikává; számláló AJAX végpont; `noindex` + canonical.
- `assets/js/gift-finder.js` – élő találatszám; feloldott szűrő visszakapcsolása;
  új mérési események.
- `assets/css/gift-finder.css` – chip-sáv, feloldott (áthúzott) állapot, számláló.
- `admin/class-gift-finder-page.php` – küszöb és facet-szintek beállítása;
  szűrő-diagnosztika táblázat; lazítás-statisztika.
- `admin/class-gift-finder-transfer.php` – az export/import séma bővítése az új
  beállításkulcsokkal.
- `docs/gift-finder-setup.md`, `docs/gift-finder-transfer-schema.md` – dokumentáció.
- `mockup-generator.php` – új fájl betöltése, verziószám.

---

## 4. Szakaszok

Minden szakasz végén működő, telepíthető állapot. Külön commit szakaszonként.

### 1. szakasz – Válasz-chipek (lazítás nélkül)

A találatok fölött megjelennek a megadott válaszok chipként, a kérdések
sorrendjében. Mindegyik levehető: a chip „×" gombja egy link, amely az adott
`mg_gift_<kulcs>` paramétert elhagyva tölti újra az oldalt.

Emellé: `noindex,follow` meta és canonical az alap keresőoldalra, ha
`mg_gift_submitted` jelen van. Ez azért kerül ide, mert a chipek linkek, és
nélküle a bejárható URL-tér nőne.

Új mérési esemény: `gift_finder_chip_removed` (paraméter: melyik kérdés).

**Elfogadási kritérium (kézzel ellenőrizhető):**
- végigkattintva a keresőt, a chipek a válaszaidat mutatják, helyes sorrendben;
- egy chip ×-ére kattintva az oldal újratölt, az a válasz eltűnik az URL-ből és a
  találatokból, a többi megmarad;
- a böngésző vissza gombja visszahozza az előző állapotot;
- az URL kimásolva, másik böngészőben ugyanazt adja;
- a találati oldal forrásában ott a `noindex,follow` és a canonical;
- a `dataLayer`-ben megjelenik a `gift_finder_chip_removed` esemény.

### 2. szakasz – Szűrő-diagnosztika (admin, csak olvasás)

Az admin oldalon táblázat minden **címzett × alkalom** párra:
a mai unió szerinti találatszám, a szigorú metszet szerinti találatszám, és hogy az
alkalom hány százalékkal szűkít.

Ennek célja egy konkrét kockázat kimérése: a bolt taxonómiájában az alkalmak
gyakran ugyanazokra a kategóriákra mutatnak, mint a címzettek (a
`docs/gift-finder-setup.md` példája: az „Apák napja" alá az `Apának`, `Papának`,
`Férjnek` kategória tartozik). Ilyen esetben a metszet nem szűkít semmit, és a
3. szakasz értéke ezeken az útvonalakon nulla.

A számítás a facets osztályba kerül (a 3. szakasz is ezt használja majd),
a megjelenítés az admin oldalra. A frontenden semmi nem változik.

**Elfogadási kritérium:**
- az admin oldalon megjelenik a táblázat, minden aktív címzett × alkalom párral;
- 2-3 sor kézzel visszaellenőrizhető a bolt kategórialistáján (pl. „Apának +
  Apák napja" tényleg ugyanannyi terméket ad-e, mint a sima „Apának");
- a frontend viselkedése bizonyíthatóan változatlan;
- a táblázat külön jelzi, ahol a szűkítés 0% (ott az AND hatástalan).

### 3. szakasz – Kemény facet + progresszív lazítás

**Facet-szintek** (alapértelmezés, adminban módosítható):

| Szint | Kérdés | Feloldható? |
|---|---|---|
| 1 | `recipient` | soha |
| 2 | `occasion` + `wedding_type` | utolsóként, **együtt** |
| 3 | `interest` | másodikként |
| 4 | `occupation` | **elsőként** |

A `mg_gift_start` belépési kategóriát a 2. szinttel azonosan kezeld.

**Lazítási ciklus:** futtasd a lekérdezést az összes kemény feltétellel, metszetként.
Ha a találatszám a küszöb (alap: 12) alatt van, oldd fel a legmagasabb szintű
feloldható feltételt, és futtasd újra. Ismételd, amíg elég találat lesz, vagy amíg
csak a `recipient` marad.

**A feloldott feltétel nem tűnik el, hanem lefokozódik szűrőből rangsorjellé** –
tehát a listában előre kerülnek azok a termékek, amelyek amúgy megfeleltek volna
neki. A meglévő pontozási logika (`score`) erre alkalmas, csak a kemény szintet
kell elé tenni.

**Fontos:** az `occasion` és a `wedding_type` együtt mozog. Az `occasion` feloldása
a `wedding_type` feloldása nélkül értelmetlen állapot.

**Megjelenítés:** a feloldott válaszok chipje áthúzott, mellette rövid magyarázat
(„Két szűrőt feloldottunk, különben csak 1 ötletet találtunk volna"), és a chipre
kattintva visszakapcsolható (az URL-ben egy jelzés, ami tiltja rá a lazítást).
A jelenlegi „A legközelebbi, 3/5 feltételhez illő találatokat mutatjuk" szöveg
helyére ez lép.

**Statisztika:** a `log_no_results()` helyére lazítás-statisztika kerül – mely
kombinációknál mit kellett feloldani. Ez megmondja, milyen termék hiányzik a
kínálatból. Az option kulcsa maradhat, de a tartalom szerkezete változik: kezeld
a régi formátumot is (vagy ürítsd egyszer, dokumentáltan).

Új mérési esemény: `gift_finder_relaxed` (paraméterek: hány szintet oldottunk fel,
melyik kérdéseket).

**Elfogadási kritérium:**
- a 2. szakasz táblázatából kiválasztott **szűk** kombinációnál a találati oldalon
  legalább 12 találat van, két chip áthúzott, és a szöveg megmondja, miért;
- a feloldott chipre kattintva visszakapcsol, és láthatóan kevés vagy nulla találat marad;
- **bő** kombinációnál (csak címzett) semmi nem oldódik fel, chip nem áthúzott;
- az `occasion` feloldásakor a `wedding_type` is feloldódik, sosem külön;
- az adminban megjelenik, mely kombinációknál mit kellett feloldani;
- a `dataLayer`-ben megjelenik a `gift_finder_relaxed` esemény.

### 4. szakasz – Élő találatszám

AJAX végpont (`admin-ajax.php`), amely a **következő** lépés minden válaszához
visszaadja a szigorú találatszámot az addigi válaszok fényében. A „Tovább" gomb
felirata és az opciók jelölése ebből él.

A számlálás **ne** a teljes rangsorolást futtassa, hanem olcsó `COUNT` utat
használjon, és ugyanabba a verzióhoz kötött tranziens cache-sémába kerüljön,
mint a rangsor.

**Elfogadási kritérium:**
- lépkedve a kérdéseken a számok a korábbi választásaid szerint változnak;
- szűk ágon „kevés ötlet" jelenik meg szám helyett;
- feloldás nélküli úton a találati oldal darabszáma megegyezik azzal, amit a
  gomb ígért;
- a végpont ismételt hívása nem indít új adatbázis-lekérdezést (cache-találat).

---

## 5. Adatmodell

**Új tábla nincs. Új post/user meta nincs.**

| Funkció | Tárolás | Indoklás |
|---|---|---|
| Chipek, élő számláló | Sehol – az állapot az URL-ben, a számok a meglévő, verzióhoz kötött tranziens cache-ben | Ettől marad megosztható az URL és működik a vissza gomb |
| Facet-szintek, lazítási küszöb | A meglévő `mg_gift_finder_settings` option új kulcsaiként | Konfiguráció, kicsi, és pont azokhoz a kérdésekhez tartozik, amiket az option már tárol – így az export/import egyetlen fájl marad |
| Lazítás-statisztika | A meglévő `mg_gift_finder_no_results` option átalakítva (autoload ki, 100 sorra vágva, ritkított írás) | Korlátos méret, ritka írás, a listázáson túl nincs lekérdezési igény |

---

## 6. Hatókörön kívül

| Kihagyva | Miért |
|---|---|
| Alkalom-emlékeztető (e-mail) | A megrendelő elhalasztotta. A mérés közben gyűjti, mely címzett/alkalom párok gyakoriak |
| A varázsló és a találatok AJAX-osítása | A GET-modell megosztható URL-t és működő vissza gombot ad; AJAX-szal külön állapotkezelést kapnánk |
| Kérdések törlése vagy átrendezése | Erről a bekötött mérés dönt, ne a megérzés |
| Kategóriák szétválasztása (alkalom ≠ címzett) | A 2. szakasz megméri a problémát; a megoldása katalógusmunka, nem kódfeladat |
| Ár a termékkártyán, szállítási határidő, SEO landing oldalak | Külön kör |
| Az árkeret-kérdés visszahozása | Szándékosan eltávolították, a `budgets` kulcs holt |

---

## 7. Kockázatok

1. **Az AND no-op lehet.** Ahol az alkalom és a címzett ugyanabban a
   kategóriatérben él, a metszet nem szűkít. Ez a legnagyobb kockázat – ezért van
   a 2. szakasz a 3. előtt. **Ne kezdd a 3. szakaszt a 2. eredményének ismerete nélkül.**
2. **`include_children`** miatt szülőkategóriára szűrve minden gyerek beleesik.
   Sekély vagy átfedő fánál a „szigorú" szint nem lesz szigorú – a diagnosztikának
   ezt külön kell jeleznie, különben félrevezető számokat olvasunk le.
3. **A lazítás ott futtat több lekérdezést, ahol a cache hidegen áll** – a hosszú
   farkon. Legrosszabb eset 4-5 kör egy amúgy is lassú úton. Ezért kell a
   számláláshoz olcsó `COUNT` út.
4. **A cache sosem melegszik be**, ha folyamatos termék- vagy készletszinkron fut.
   A verziót percenként legfeljebb egyszer lépteti a kód, de egy megállás nélküli
   importer így is tartósan hidegen tarthatja.
5. **A számláló végpontot robotok is hívhatják** – lépésenként N lekérdezés × bejáró
   forgalom valós terhelés. Cache és egyszerű ütemkorlát kell rá.
6. **Az import csendben visszaállíthat.** Ha a transfer sémáját nem bővíted ugyanabban
   a szakaszban, ahol a beállítás születik, egy régi JSON visszatöltése elveszi az
   új kulcsokat.
7. **Az `occasion` / `wedding_type` függés** ritkán tesztelt ág; könnyű elnézni.
8. **A 3. szakasz elmossa a mérési alapvonalat.** Élesítés előtt érdemes kimenteni a
   megelőző hét analitikai adatait.

---

## 8. Munkamódszer

- **Git:** a `CLAUDE.md` szerint minden változtatás közvetlenül a `main` branchre
  megy. Ne maradjon munka csak feature branchen.
- **Commit:** szakaszonként külön commit, magyarázó üzenettel arról, *miért*
  változik a viselkedés, nem csak arról, hogy mi.
- **Verzió:** minden CSS/JS változásnál lépesd a `mockup-generator.php` fejlécében
  a `Version:` mezőt **és** az `MG_VERSION` konstansot – ez a cache-busting alapja.
- **Nyelv:** minden felhasználónak és adminnak látszó szöveg magyar. A kódkommentek
  a szerkesztett fájl meglévő stílusát kövessék.
- **Kódstílus:** a `class-gift-finder.php` szóközös zárójelezést használ
  (`function( $x )`), a plugin többi része nem – mindig a szerkesztett fájlhoz
  igazodj.
- **Ellenőrzés commit előtt:** `php -l` minden módosított PHP fájlra, `node --check`
  minden módosított JS fájlra. Build lépés nincs, függőséget ne vegyél fel.
- **Ne** vezess be új JS keretrendszert vagy csomagkezelőt. A frontend sima
  ES5-stílusú JS jQuery-vel (a varázsló szkriptje jQuery nélküli, natív DOM).
