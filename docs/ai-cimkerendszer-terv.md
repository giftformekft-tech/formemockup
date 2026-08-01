# AI-vezérelt címkerendszer – szótár, képelemzés, ajándékkereső, keresés

Fejlesztési brief. Önállóan végrehajtható, előzetes kontextus nélkül.

Ez a dokumentum **terv**, nem implementáció. A benne szereplő címkeszótár v0 javaslat:
a 2. szakasz első lépése a jóváhagyása.

---

## 0. Kontextus

A repo egy WooCommerce plugin (`mockup-generator.php`), amely a forme.hu magyar,
saját gyártású ajándéktárgy-webshopot szolgálja ki: egyedi mintás pólók, bögrék és
hasonlók. Egy **minta** (design) több terméktípuson és színen jelenik meg; a bolti
termék kiemelt képe a legenerált mockup.

### Ma két helyen ér AI a rendszerhez

**(1) A külső AI program – bulk feltöltésnél.** A feltöltött képfájl mellé tett,
azonos nevű `.json` sidecar fájlból tölti a sorokat: `categories.main`,
`categories.sub`, `tags[]`, `description`. Az „AI mód" mezőnként kapcsolható
(`admin/class-admin-page.php:737-758`), a beolvasás a böngészőben történik
(`assets/js/bulk-upload-advanced.js:538`), az alkalmazás soronként
(`applyAiDataToRow`, uo. 109-156).

**(2) A pluginon belüli AI SEO modul.** `MG_AI_SEO_Generator`
(`includes/class-ai-seo-generator.php`, 258 sor): GPT-5 mini, `/v1/responses`
végpont, **vision** hívás a termék mockup képére, eredménye 300–500 karakteres
magyar SEO leírás a `_mg_sample_seo` metában, amit a terméktípus-leírás sablon a
`{sample_seo}` változóval szúr be. Admin futtató felülettel, force felülírással,
hívások közti késleltetéssel (`admin/class-ai-seo-page.php`).

### Hogyan romlottak el a címkék

A címkeírás három helyen történik, **mindhárom normalizálás nélkül és
hozzáfűzéssel**:

| Hely | Sor | Mit csinál |
|---|---|---|
| `includes/class-product-creator.php` | 301 | `wp_set_object_terms( $id, $names, 'product_tag', true )` |
| `includes/class-bulk-queue.php` | 637 | ugyanez, a job payload `tags` mezőjéből |
| `admin/ajax-bulk.php` | 491 | ugyanez, vesszővel tagolt szabad szövegből |

Nincs szótár, nincs ékezet- vagy egyes/többes szám egységesítés, nincs
duplikátumszűrés, és az `$append = true` miatt semmi nem kerül soha ki egy
termékről. Eredmény: **11 836 címke**, sok duplikációval, elírással és nulla
termékes taggel (`docs/ajandekvalaszto-besorolas-2026-07-13.md:25`).

### Az ajándékkereső ma nem lát címkét

A kereső válaszai `category_id`, `category_ids` és szabad `keywords` mezőt
ismernek; a facet-metszet ezekből épül (`includes/class-gift-finder-facets.php`).
A `product_tag` taxonómia nincs bekötve – ezt három helyen ki is mondja a
dokumentáció (`docs/gift-finder-setup.md:18`,
`docs/gift-finder-transfer-schema.md:88`, `admin/class-gift-finder-page.php:104`).
Emiatt hiányzik például az **Anyák napja** és **Apák napja** alkalom: kategória
nincs rá, címke van, de a kereső opciói kategóriaazonosítóval működnek
(`docs/ajandekvalaszto-besorolas-2026-07-13.md:26`).

---

## 1. Rögzített döntések

Ezek a megrendelő döntései, nem javaslatok:

1. **A címkeszótárat előre tisztázzuk.** Semmilyen tömeges címkézés nem indul,
   amíg a szótár nincs jóváhagyva.
2. **A régi címkék mennek a kukába.** Az új rendszer a helyükre lép, nem melléjük.
3. **A képet elemezzük** – azt, hogy ténylegesen mi van a mintán –, nem a meglévő
   szövegeket.
4. **Előbb terv, utána kód.** Ez a dokumentum a terv.

---

## 2. A címkeszótár

Ez a dokumentum legfontosabb szakasza. Ha ez rossz, minden más rá épülő munka
elveszik.

### 2.1 Alapelv: a címke merőleges a kategóriára

A boltnak **193 kategóriája** van, és ezek már lefedik a *kinek* és az *alkalom*
tengelyt (15 címzett, 11 alkalom, 5 esküvői altípus, 54 foglalkozás – lásd
`docs/forme-ajandekvalaszto-konfiguracio-2026-07-13.json`). Ha a címke ugyanezt
ismétli, két dolog történik: a `/product-tag/apanak/` és a `/termek-kategoria/apanak/`
oldal egymás ellen versenyez a találati listában (kannibalizáció), a keresőben
pedig a metszet nem szűkít semmit.

**Szabály:** a címke azt írja le, ami a **mintán látható** – motívum, téma,
stílus, forma. A kategória azt, hogy **kinek és mire** való. Egy fogalom pontosan
az egyik helyen él.

Ebből következik, hogy **a foglalkozásokra nem készül címke** (mind az 54 kategória),
és **a címzettekre sem**.

### 2.2 Dimenziók

Öt dimenzió, minden címke pontosan egybe tartozik. A dimenzió term metában él, és
ez teszi lehetővé, hogy az ajándékkereső csak a neki való dimenziót ajánlja fel.

| Kód | Dimenzió | Mit ír le | Célméret |
|---|---|---|---|
| `motivum` | Motívum / téma | Mi látható a mintán | 150–220 |
| `stilus` | Stílus / hangulat | Milyen a minta hangvétele, kivitele | 15–20 |
| `forma` | Forma | Hogyan épül fel a grafika | 6–10 |
| `alkalom` | Alkalom-kiegészítő | **Csak** az az alkalom, amire nincs kategória | 8–12 |
| `szemelyesseg` | Személyre szabhatóság | Név, dátum, fotó a mintán | 3–5 |

Induló méret ~150–200 címke, felső korlát 400. Ha ennél több kell, az azt jelenti,
hogy a szabályt nem tartottuk be.

### 2.3 Névadási szabályok

1. **Magyar, kisbetűs, ékezetes.** `horgászat`, nem `Horgaszat`.
2. **Egyes szám, alanyeset.** `kutya`, nem `kutyák`, nem `kutyás`.
3. **Legfeljebb három szó.**
4. **Terméktípus nélkül.** `horgászat`, nem `horgász póló`. A terméktípus attribútum
   és kategória, nem címke; különben minden címke ötszöröződik.
5. **Címzett és foglalkozás nélkül** (2.1 szabály).
6. **Slug:** `sanitize_title()` eredménye, ékezet nélkül – `horgaszat`. Ez lesz az URL.
7. **Szinonima nem külön címke.** A `horgász`, `horgászok`, `pecázás`, `pecás` mind
   a `horgászat` címke `_mg_tag_synonyms` mezőjébe kerül. A szinonima a bolti
   keresést és az AI leképezést szolgálja, **URL-t soha nem kap.**

### 2.4 Felvételi kritérium

Egy címke akkor kerülhet a szótárba, ha mind a három teljesül:

- **Keresnek rá.** Van olyan valós magyar keresőkifejezés, aminek ez a magja
  (`tacskós póló`, `horgászos bögre`). Ha csak belső rendszerezés, akkor nem
  címke – kategória vagy semmi.
- **Legalább 3 termék hordozza.** Ez alatt a címke létrejöhet, de nem publikus.
- **Legalább 5 termék az indexeléshez.** Ez alatt a címkearchívum `noindex` –
  vékony tartalommal nem szemeteljük tele az indexet.

### 2.5 A szótár v0 javaslata

Az alábbi lista **kiindulópont a jóváhagyáshoz**, a bolt meglévő kategóriáiból és
az ajándékkereső érdeklődési köreiből levezetve. A végleges listát a 2. szakasz
zárja le, a meglévő 11 836 címke gépi csoportosításának ismeretében.

**`motivum` – állat (kb. 30)**
kutya, tacskó, francia bulldog, német juhász, golden retriever, labrador, mopsz,
csivava, husky, border collie, macska, cica-mintás, ló, lovaglás, nyúl, madár,
bagoly, papagáj, halak, méhecske, pillangó, szarvas, farkas, róka, medve, oroszlán,
elefánt, dinoszaurusz, egyszarvú, sárkány

**`motivum` – hobbi és tevékenység (kb. 30)**
horgászat, vadászat, kertészkedés, növénytartás, sütés, főzés, grillezés, kávé,
tea, sör, bor, pálinka, koktél, olvasás, festés, kézimunka, kötés, fotózás,
kempingezés, túrázás, utazás, tengerpart, hegymászás, kerékpározás, motorozás,
autózás, repülés, sakk, kártya, kutyasétáltatás

**`motivum` – sport és mozgás (kb. 12)**
foci, kosárlabda, kézilabda, röplabda, úszás, futás, konditerem, jóga, harcművészet,
tánc, síelés, extrém sport

**`motivum` – zene és kultúra (kb. 12)**
gitár, dob, zongora, hegedű, bakelit, rockzene, metálzene, popzene, karaoke,
film, sorozat, könyv

**`motivum` – gamer és tech (kb. 10)**
gamer, retro játék, konzol, számítógép, programozás, robot, űrhajó, csillagászat,
mesterséges intelligencia, mém

**`motivum` – ünnep és szezon (kb. 15)**
karácsonyfa, mikulás, rénszarvas, hópehely, adventi, húsvéti nyuszi, hímes tojás,
halloweeni tök, szellem, koponya, szív, rózsa, tűzijáték, farsang, ballagási

**`motivum` – természet és mintázat (kb. 12)**
virág, napraforgó, levendula, rózsaszál, pálmafa, hegy, tenger, hullám, hold,
nap, csillag, szivárvány

**`motivum` – egyéb téma (kb. 12)**
pizza, hamburger, csoki, fagylalt, avokádó, horoszkóp, csillagjegy, jóga-mandala,
vitorlázás, kamion, traktor, tűzoltóautó

**`stilus` (18)**
vicces, poénos felirat, szarkasztikus, mém-stílusú, cuki, romantikus, motivációs,
minimál, retro, vintage, neon, akvarell, kézzel rajzolt, geometrikus, pixeles,
fekete-fehér, grunge, tipográfiai

**`forma` (8)**
feliratos, csak szöveg, illusztráció, portré, sziluett, ismétlődő mintás,
embléma, mezmintás

**`alkalom` – csak ahol nincs kategória (10)**
anyák napja, apák napja, nőnap, névnap, évforduló, szalagavató, babaváró,
nyugdíjas búcsú, csapatépítő, osztálytalálkozó

**`szemelyesseg` (4)**
névre szóló, dátumos, fotós, monogramos

### 2.6 Hogyan születik meg a végleges szótár

1. A meglévő 11 836 címke lehúzása termékszámmal együtt (a `class-gift-finder-transfer.php`
   exportja ezt már tudja: `admin/class-gift-finder-transfer.php:111`).
2. Gépi csoportosítás: az AI a nyers címkelistát a fenti dimenziókba és
   jelöltcímkékbe rendezi, minden nyers címkéhez célcímkét vagy `törlés` jelet ad.
   Ez **egy** hívás-sorozat a listán, nem termékenkénti munka.
3. A javaslat admin táblázatban jelenik meg: nyers címke → javasolt cél →
   termékszám → döntés. Ember hagyja jóvá.
4. A jóváhagyott szótár lesz a `product_tag` taxonómia új, tiszta állománya.

**A 2. lépés kimenete önmagában is érték:** megmutatja, mely témákra van sok
termék és melyekre semmi – ez katalógusbővítési input.

---

## 3. A képelemző motor

### 3.1 Kétlépcsős felépítés – ez a terv sarokköve

A drága rész a képelemzés, az olcsó rész a szótárra képezés. Ezt szét kell választani:

**1. lépcső – megfigyelés (drága, egyszeri).** Vision hívás a mockup képre. Az AI
**nem címkéket ad vissza**, hanem strukturált leírást arról, hogy mit lát. Ez a
`_mg_ai_image_facts` metába kerül.

**2. lépcső – leképezés (olcsó, újrafuttatható).** A megfigyelés + a szótár →
címkejavaslat. Ez futhat szövegmodellel vagy akár tisztán PHP-ban, szinonima-illesztéssel.

**Miért így:** a szótár változni fog. Ha a címkék közvetlenül a vision hívásból
jönnének, minden szótármódosítás után újra kellene fizetni több ezer képelemzést.
Így a képelemzés egyszer fut le, a leképezés pedig annyiszor, ahányszor akarjuk.

### 3.2 Mit ad vissza a megfigyelés

```json
{
  "schema": "mg-image-facts",
  "version": 1,
  "subjects":   ["tacskó kutya", "karácsonyi sapka"],
  "text_on_design": "Boldog Karácsonyt",
  "text_language": "hu",
  "style":      ["cuki", "illusztráció"],
  "colors":     ["piros", "fehér"],
  "occasion_hint": ["karácsony"],
  "personalizable": false,
  "confidence": 0.86
}
```

- `subjects`: szabad szöveg, magyarul, amit ténylegesen lát. **Nem szótárelem** –
  ez a nyers megfigyelés.
- `text_on_design`: a mintán szereplő felirat betűhíven. Ez a bolti keresésnek is
  aranyat ér, ma sehol nincs tárolva.
- `confidence`: az AI önbevallása. A küszöb alattiak emberi átnézésre kerülnek.

### 3.3 Mit ad vissza a leképezés

```json
{
  "schema": "mg-tag-mapping",
  "version": 1,
  "tags": [
    { "slug": "taszko",     "dimension": "motivum", "score": 0.95 },
    { "slug": "karacsonyfa","dimension": "motivum", "score": 0.71 },
    { "slug": "cuki",       "dimension": "stilus",  "score": 0.80 }
  ],
  "unmatched": ["karácsonyi sapka"]
}
```

Az `unmatched` a szótárbővítés bemenete: ha ugyanaz a megfigyelés sok terméknél
lóg kint, valószínűleg hiányzik egy címke.

### 3.4 Címkeszám termékenként

**3–8 címke.** Kevesebb nem ad szűrési erőt, több felhígítja: ha minden termék 20
címkét kap, a metszet megint nem szűkít. Dimenziónként felső korlát: `motivum` max 5,
`stilus` max 2, `forma` max 1, `alkalom` max 2, `szemelyesseg` max 1.

### 3.5 Futtatás

A mostani AI SEO futtató a böngészőben pörgeti végig a listát
(`admin/class-ai-seo-page.php:177-200`): egy AJAX hívás termékenként, `delay_ms`
szünettel. Több ezer terméknél ez törékeny – egy lapfrissítés megöli a futást.

**Javaslat:** a képelemzés a meglévő `MG_Bulk_Queue` mintáját kövesse
(`includes/class-bulk-queue.php`) – perzisztens job sor, worker, folytatható
futás. A böngészős futtató maradhat a kis, javító körökre.

**Nagyságrend:** N termék × 1 vision hívás. A mai 600 ms késleltetés + hívásidő
mellett ez több ezer terméknél órákban mérhető, tehát megszakítható és
folytatható futás kell. A `_mg_ai_image_facts` megléte a szűrő: ami már megvan,
nem fut újra (ugyanaz a logika, mint a `get_candidate_product_ids()`
`meta_query`-je, `includes/class-ai-seo-generator.php:194`).

---

## 4. Mire használjuk a kész címkéket

### 4.1 Ajándékkereső

A válaszok kapnak egy `tag_ids` mezőt a `category_ids` mellé, és a facet-metszet
ezt is figyelembe veszi (`MG_Gift_Finder_Facets::choice_categories()` mellé
`choice_tags()`). Ettől:

- felvehető az **Anyák napja** és **Apák napja** alkalom, amire ma nincs kategória;
- az érdeklődési kör (`interest`) sokkal pontosabban szűr: a `Horgászik` válasz ma
  egy kategóriára mutat, címkével a horgászmotívumos termékeket találja meg
  akkor is, ha más kategóriába kerültek;
- a `docs/gift-finder-facet-terv.md:278` szerinti legnagyobb kockázat – hogy az
  alkalom és a címzett ugyanabban a kategóriatérben él, ezért az AND nem szűkít –
  éppen ettől oldódik: a címke **másik tengely**, tehát a metszete valóban szűkít.

**A gyorsítótár verzióját a címkeváltozásnak is léptetnie kell**
(`mg_gift_finder_cache_version`), különben a kereső órákig régi halmazokkal dolgozik.

### 4.2 Bolti keresés

A WordPress terméktkeresés a címtre és a leírásra keres, a címkékre nem. Két dolog kell:

- terméken egy `_mg_search_blob` meta: címkenevek + **szinonimáik** + a mintán
  szereplő felirat (`text_on_design`) egy szövegben, a mentéskor frissítve;
- a keresési lekérdezés kiterjesztése erre a metára (`posts_search` szűrő).

Ettől a „pecás bögre" keresés is megtalálja a `horgászat` címkéjű terméket, ma nem.

### 4.3 SEO – címkearchívum mint landing oldal

- Minden publikus címke archívuma kap **egyedi bevezető szöveget** (`_mg_tag_intro`).
  Ezt a **meglévő** `MG_AI_SEO_Generator` mintájára generáljuk – ugyanaz a
  beállításkészlet, ugyanaz a hívásforma, csak más prompt és más tárolóhely.
- **5 termék alatt `noindex`**, hogy vékony oldalak ne kerüljenek indexbe.
- A címke és a kategória közti kannibalizációt a 2.1 szabály előzi meg.

---

## 5. Fájlszerkezet

**Új fájlok**

| Fájl | Szerep |
|---|---|
| `includes/class-tag-dictionary.php` | A szótár: dimenziók, szinonimák, státusz, validálás, slug-képzés. Minden más ezt hívja. |
| `includes/class-ai-image-analyzer.php` | 1. lépcső: vision hívás, `_mg_ai_image_facts` írása. Az OpenAI hívás közös részét a meglévő `MG_AI_SEO_Generator`-ból kell kiemelni, nem másolni. |
| `includes/class-tag-mapper.php` | 2. lépcső: megfigyelés + szótár → címkejavaslat, szinonima-illesztéssel. |
| `includes/class-tag-search-index.php` | `_mg_search_blob` karbantartása és a `posts_search` kiterjesztés. |
| `admin/class-tag-dictionary-page.php` | Szótár szerkesztése, régi címkék összevonási táblázata, jóváhagyás. |
| `admin/class-tag-ai-page.php` | Futtató felület: elemzés, leképezés, alkalmazás, statisztika. |

**Módosuló fájlok**

- `includes/class-ai-seo-generator.php` – a közös OpenAI hívás kiemelése (`call_openai`,
  `extract_output_text`) megosztott ősbe vagy segédosztályba.
- `includes/class-product-creator.php:296-302`, `includes/class-bulk-queue.php:633-639`,
  `admin/ajax-bulk.php:479-492` – **írási kapu**: csak szótárbeli címke íródhat,
  és `$append = false`.
- `includes/class-gift-finder-facets.php`, `includes/class-gift-finder.php`,
  `admin/class-gift-finder-page.php` – `tag_ids` a válaszokban.
- `admin/class-gift-finder-transfer.php` – az export/import séma bővítése a
  címkekötésekkel, különben egy régi JSON visszatöltése kiüti őket
  (ugyanaz a hiba, amit a `docs/gift-finder-facet-terv.md:292` már leírt).
- `assets/js/bulk-upload-advanced.js` – a sidecar címkéinek validálása a szótár ellen.
- `mockup-generator.php` – új fájlok betöltése, verzió léptetése.
- `docs/gift-finder-setup.md`, `docs/gift-finder-transfer-schema.md`,
  `docs/ajandekvalaszto-besorolas-2026-07-13.md` – mindhárom azt írja ma, hogy a
  kereső nem használ címkét; ez megszűnik.

---

## 6. Szakaszok

Minden szakasz végén működő, telepíthető állapot. Szakaszonként külön commit.

### 1. szakasz – Mérés a törlés előtt

**Semmit nem törlünk, amíg nem tudjuk, mit veszítünk.** A meglévő címkeoldalak közül
néhány hozhat organikus forgalmat.

- A 11 836 címke listája termékszámmal, admin táblázatban.
- Search Console export a `/product-tag/` útvonalra: megjelenés, kattintás, pozíció.
- Ebből egy **„ne bántsd" lista**: a valóban forgalmat hozó címkék, amiket nem
  törölni, hanem a szótárba beemelni kell.

**Elfogadási kritérium:** megvan a lista, és kézzel ellenőrizhető, hogy a top 20
forgalmat hozó címke szerepel-e a szótár v0-ban.

### 2. szakasz – A szótár

- `MG_Tag_Dictionary` osztály, term meta alapon (lásd 7. szakasz).
- Admin felület: dimenzió, szinonimák, státusz szerkesztése.
- A 11 836 régi címke gépi csoportosítása → összevonási javaslat táblázat.
- Emberi jóváhagyás. **A frontenden semmi nem változik.**

**Elfogadási kritérium:** a jóváhagyott szótár 150–400 címke között van; minden
címkének van dimenziója; nincs két címke ugyanazzal a slug-gal; minden régi
címkéhez tartozik döntés (cél vagy törlés).

### 3. szakasz – Írási kapu

Ez a szakasz **a 4. előtt** kell, különben a takarítás közben a feltöltések újra
szemetelnek.

- A három írási pont csak szótárbeli címkét fogad el; ismeretlen címke nem íródik
  be, hanem javaslatsorba kerül.
- `$append = false`, hogy a termék címkekészlete valóban felülírható legyen.
- A bulk feltöltés felületén látszik, mely sidecar-címke nem ismert.

**Elfogadási kritérium:** egy szótáron kívüli címkével feltöltött minta nem hoz
létre új taxonómia-termet, és a felületen jelzi, hogy a címke javaslatsorba került.

### 4. szakasz – Képelemzés

- `MG_AI_Image_Analyzer`, sorba szervezett futással.
- A megfigyelés a `_mg_ai_image_facts` metába kerül, **címke még nem íródik**.
- Statisztika: hány terméken van megfigyelés, mennyi a bizonytalan.

**Elfogadási kritérium:** 20 véletlen terméken kézzel visszanézve a megfigyelés
egyezik azzal, ami a mockupon látszik; a futás megszakítható és folytatható; a
már elemzett termékek nem futnak újra.

### 5. szakasz – Leképezés és alkalmazás

- `MG_Tag_Mapper` javaslatot ír a `_mg_ai_tags_suggested` metába.
- Admin felület: termékenkénti javaslat átnézése, tömeges elfogadás szűrőkkel.
- **Alkalmazás:** a jóváhagyott címkék kiírása, a régi címkék törlése, a
  `_mg_tag_redirect_to` alapján 301 átirányítás a törölt címke URL-jéről.

**Elfogadási kritérium:** minden termék 3–8 címkét hord; a dimenziónkénti korlátok
tartanak; egy törölt címke URL-je 301-gyel a célcímkére megy, nem 404-re; a
„ne bántsd" listáról egyetlen címke sem tűnt el.

### 6. szakasz – Ajándékkereső

- `tag_ids` a válaszokban, a facet-metszetben és az admin szerkesztőben.
- Anyák napja / Apák napja felvétele alkalomként.
- Az export/import séma bővítése.
- A cache-verzió léptetése címkeváltozásra.

**Elfogadási kritérium:** az `Anyának → Anyák napjára` útvonal ad találatot; a
szűrő-diagnosztika táblázat (`docs/gift-finder-facet-terv.md` 2. szakasza)
kimutatja, hogy a címke-alapú szűrés ott is szűkít, ahol a kategória-alapú nem;
egy régi konfigurációs JSON importja nem üti ki a címkekötéseket.

### 7. szakasz – Keresés és SEO

- `_mg_search_blob` és a `posts_search` kiterjesztés.
- Címkearchívum bevezető szövegek generálása.
- 5 termék alatti címkék `noindex`-elése.

**Elfogadási kritérium:** a „pecás bögre" keresés megtalálja a `horgászat` címkéjű
terméket; egy 3 termékes címke oldalán ott a `noindex`; a publikus címkeoldalak
bevezető szövege egyedi.

---

## 7. Adatmodell

**Új tábla nincs.** A szótár maga a `product_tag` taxonómia, term metával kiegészítve.

| Adat | Hol | Miért ott |
|---|---|---|
| Dimenzió | `_mg_tag_dimension` term meta | A szótár és a taxonómia egy dolog legyen; így az export, a jogosultságkezelés és a WP admin ingyen működik |
| Szinonimák | `_mg_tag_synonyms` term meta (JSON) | A keresés és a leképezés használja, URL-t nem kap |
| Státusz | `_mg_tag_status` term meta (`approved` / `proposed` / `retired`) | A javaslatsor és a takarítás állapotgépe |
| Átirányítás | `_mg_tag_redirect_to` term meta (term_id) | A törölt címke URL-je ebből tudja, hova menjen 301-gyel |
| Archívum szövege | `_mg_tag_intro` term meta | Ugyanaz a minta, mint a `_mg_sample_seo` |
| Képmegfigyelés | `_mg_ai_image_facts` post meta (JSON) | A drága lépcső eredménye, egyszer születik |
| Címkejavaslat | `_mg_ai_tags_suggested` post meta (JSON) | Jóváhagyás előtti állapot, nem érinti a taxonómiát |
| Futás naplója | `_mg_ai_tags_analyzed_at`, `_mg_ai_tags_model` post meta | Ebből tudjuk, mit kell újrafuttatni modellváltáskor |
| Keresési szöveg | `_mg_search_blob` post meta | Egy mezőben, mert a `posts_search` így tud egy JOIN-nal dolgozni |
| 301 térkép | `mg_tag_redirect_map` option (autoload ki) | A törlés után a term meta már nem létezik, a térképnek túl kell élnie |

A 301 térkép a törlés **előtt** íródik ki, különben a célinformáció a termmel
együtt elvész.

---

## 8. Összhang a külső AI programmal

Ez a terv akkor ér valamit, ha a bulk feltöltés és a visszamenőleges címkézés
ugyanazt a szótárat használja. A külső programnak ezért módosulnia kell:

**A sidecar JSON új formája (v2):**

```json
{
  "schema": "mg-design-sidecar",
  "version": 2,
  "categories": { "main": "Hobbi szerint", "sub": "Horgász" },
  "tags": ["horgaszat", "vicces", "feliratos"],
  "image_facts": {
    "subjects": ["horgászbot", "ponty"],
    "text_on_design": "A hal mindig nagyobb volt",
    "text_language": "hu",
    "style": ["vicces"],
    "personalizable": false
  },
  "description": "…a mostani _mg_sample_seo szöveg…"
}
```

Két változás a mai `v1`-hez képest:

1. **A `tags` mező slug-okat tartalmaz, nem szabad szöveget.** A plugin a szótár
   ellen validál; ismeretlen slug nem íródik be, javaslatsorba kerül.
2. **Új `image_facts` blokk** – ugyanaz a séma, mint a 3.2 pontban. Ha a külső
   program úgyis megnézi a képet, ne dobjuk el a megfigyelést: ettől az újonnan
   feltöltött mintákon **nem kell** még egyszer lefuttatni a drága vision hívást.

A `version: 1` sidecar-t a plugin továbbra is fogadja (a `tags` mezőt akkor
szinonimaként próbálja illeszteni), hogy a két oldal külön ütemben frissülhessen.

**A szótár letölthető legyen gépi formában** (`slug`, `név`, `dimenzió`,
`szinonimák`) – így a külső program mindig a friss listával dolgozik, nem egy
beégetett másolattal.

---

## 9. Kockázatok

1. **A 11 836 címke törlése SEO-veszteség lehet.** Ez a legnagyobb kockázat, ezért
   van az 1. szakasz a többi előtt. Törölni csak mért forgalom ismeretében és
   301-es térképpel szabad. **Ne kezdd az 5. szakaszt az 1. eredménye nélkül.**
2. **A vision téved.** Egy stilizált mintán a modell mást lát, mint az ember. Ezért
   van `confidence`, ezért nem ír élesbe a 4. szakasz, és ezért kell a 20 elemes
   kézi minta az elfogadáshoz.
3. **A hozzáfűzéses írás visszacsinálja a takarítást.** Ha a 3. szakasz `$append`
   javítása kimarad, a következő bulk feltöltés újra ráteszi a régi címkéket.
4. **Kannibalizáció.** Ha a 2.1 szabály csúszik, a címkeoldal a kategóriaoldal ellen
   fog versenyezni, és a mérhető eredmény romlás lesz, nem javulás.
5. **Címkehígulás.** Termékenként 20 címkével a keresőben megint nem szűkít semmi.
   A dimenziónkénti korlát nem kozmetika.
6. **Költség és futásidő.** Több ezer vision hívás órákig fut. A kétlépcsős
   felépítés (3.1) azért kell, hogy ezt egyszer kelljen kifizetni.
7. **A gyorsítótár.** Az ajándékkereső halmazai címkeváltozásra is elavulnak; ha a
   verzióléptetés kimarad, a kereső órákig hazudik.
8. **A mérési alapvonal elmosódik.** Az 5. és 7. szakasz élesítése előtt érdemes
   kimenteni az előző hét analitikáját és a Search Console adatait.

---

## 10. Hatókörön kívül

| Kihagyva | Miért |
|---|---|
| A kategóriastruktúra átalakítása | A címke merőleges tengely; a kategóriák rendbetétele külön, katalógusmunka |
| Termékek automatikus átnevezése | A név üzleti döntés, nem AI-feladat; a mostani névképzés a fájlnévből marad |
| Szín- és méretattribútumok AI-ból | Ezek a mockup generálásból már pontosan ismertek |
| Többnyelvűség | A bolt magyar; a szótár magyar |
| A címkék megjelenítése a terméklapon | Külön UX-kör, a rendszer működéséhez nem kell |
| Az ajándékkereső kérdéseinek átrendezése | Erről a bekötött mérés dönt (`docs/gift-finder-facet-terv.md:269`) |

---

## 11. Munkamódszer

- **Git:** a `CLAUDE.md` szerint minden változtatás a `main` branchre megy; ne
  maradjon munka csak feature branchen.
- **Commit:** szakaszonként külön commit, magyarázó üzenettel arról, *miért*
  változik a viselkedés.
- **Verzió:** minden CSS/JS változásnál lépesd a `mockup-generator.php` fejlécében
  a `Version:` mezőt **és** az `MG_VERSION` konstansot.
- **Nyelv:** minden felhasználónak és adminnak látszó szöveg magyar.
- **Kódstílus:** a szerkesztett fájl meglévő stílusához igazodj – a gift finder
  fájljai szóközös zárójelezést használnak (`function( $x )`), a plugin többi része nem.
- **API-hívás:** ne szülessen második OpenAI kliens. A meglévő
  `MG_AI_SEO_Generator::call_openai()` logikáját ki kell emelni, és mindkét
  modulnak azt kell hívnia – egy API kulcs, egy végpont, egy hibakezelés.
- **Ellenőrzés commit előtt:** `php -l` minden módosított PHP fájlra,
  `node --check` minden módosított JS fájlra. Build lépés nincs, függőséget ne vegyél fel.
