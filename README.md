# formemockup
mymockup

## Dashboard: kategóriaszűrő, kijelölés, tömeges AI újratervezés (2.43.0)

- **Fő- és alkategória szűrő:** a főkategória az összes alkategóriáját is hozza; az
  alkategória-lista a kiválasztott főkategória alkategóriáit mutatja.
- **Kijelölés:** jelölőnégyzet minden minta előtt, a fejlécben az oldal összes mintájához,
  **Mind kijelölése** és **Kijelölés törlése** gomb (az aktuális oldalra vonatkozik).
- **AI újratervezés a kijelöltekre:** előbb a módok (prompt mód, eltérés mértéke, elemző,
  képminőség, stílusötlet), utána mintánként egymás alatt az **eredeti** és az **új** minta
  egymás mellett. A generálás kettesével fut a nyitott lapról (ha egy proxy megszakítja a
  kérést, az állapotot lekérdezi). Ekkor még nincs felskálázás. Kártyánként: **Jóváhagyom**,
  **Újragenerálás** (az elkészült új változat lecseréli az előzőt, az előző törlődik),
  **Törlés** (a minta kikerül a futásból, az új változata törlődik), **Prompt javítása**
  (a kártyán megnyílik a jelölt pontos promptja; egy elírt szöveg kijavítása után az
  **Újragenerálás ezzel a prompttal** elemzés nélkül, pontosan ezzel a prompttal generál újra,
  2.43.3). **Mindet jóváhagyom**, **Hátralévők leállítása**.
- **Jóváhagyottak véglegesítése:** a jóváhagyott minták egyenként 4×-es AI felskálázást
  (böngésző, Real-ESRGAN) és 255 színes palettát kapnak, majd sorba áll a mockupjaik
  újragenerálása (mint az egyedi jóváhagyásnál). Ha a felskálázás nem sikerül, a szerver
  3×-os nagyítással folytatja. A kész kártya a véglegesített fájlt mutatja („Végleges: 4× AI
  felskálázás · 255 színes paletta · méret”, 2.43.4). A nem jóváhagyott új minták a termék AI
  újratervezés ablakában megmaradnak.
- Futás közben a lap bezárása előtt figyelmeztetés jön; az ablak bezárható és újranyitható.
- Teszt: `php tests/dashboard-filter-test.php`.

## Dashboard: minta-előnézet (2.42.1)

A **Dashboard** terméklistájának első oszlopa a termék **nyomtatási mintáját** mutatja
(nem a mockupot), átlátszó részeknél sakktábla-háttéren; kattintásra a teljes minta nyílik.
A lista sosem tölti be az eredeti PNG-t (az akár több tíz MB is lehet): a médiatár
bélyegképét használja, vagy egyszer készít egy 128 px-es PNG-t az
`uploads/mg_design_thumbs/` mappába. Ha a minta lecserélődik (Minta cseréje, AI
újratervezés), új előnézet készül. Oldalbetöltésenként legfeljebb 10 új előnézet készül, a
többinél addig a termék képe látszik. Ha nincs mentett mintafájl, a termék képe marad.

## AI újratervezés (2.41.0)

Ha egy minta túlságosan hasonlít egy másik webáruházéra, a **Dashboard** terméklistájában
az **AI újratervezés** gomb új stílusban rajzolja újra, ugyanazzal a szöveggel:

1. Egy választható **elemző modell** megnézi a minta PNG-t, betűhíven kiolvassa a szöveget,
   leírja a jelenlegi stílust, és megírja az új, eltérő stílus képpromptját. Választható:
   OpenAI (alapból `gpt-5-mini`) vagy Claude Opus 5.5 / Sonnet 5.5 / Haiku 5.5 / Fable 5.1.
2. A képet a GPT Image modell készíti (az „AI egyedi nyomat” beállításnál választott modell),
   az eredeti átlátszóságával. Utána a böngésző **AI-val 4×-esre felskálázza** (lásd lent).
   **Prompt mód (2.42.9):** az ablakban generálásonként választható, hogy a prompt **brief**
   módban (alább) vagy **szabad leírás** módban készüljön (az elemző maga ír egy rövid, kreatív,
   legfeljebb 3 mondatos promptot, mint 2.42.4–2.42.7 között). A jelölt kártyáján látszik a mód,
   így a kettő egymás mellett összevethető. A fix követelmények (szöveg, körvonal, színkorlát)
   mindkettőben ugyanazok.

   **Részletes leírás mód (2.42.11):** harmadik prompt mód. Az elemző a saját, szerkeszthető
   promptodat kapja (**AI Minta SEO és tagelés → AI újratervezés → Részletes leírás mód –
   elemző prompt**, alapból a saját elemző programodban használt szöveg), és pontosan leírja a
   mintát egy angol képpromptban (szöveg `TEXT Hungarian: "..."` formában, helyzettel; fekete
   szöveg és elem fehérként). A képgeneráló az eredeti képet ebben a módban sem kapja meg. A
   rendszer mindig hozzáteszi a JSON-formátum utasítását, a pontos szöveget és a fix
   képszabályokat (fekete vektoros körvonal, kitöltött vászon levágás nélkül, nincs logó és
   webcím, nincs fekete kitöltés, nincs színes szegély, ragyogás vagy halo). A fokozat, a
   színkorlát és a világos/sötét szabály itt nem érvényes.

   **Tervezési brief (2.42.8):** az elemző nem prózát ír, hanem rövid mezőket tölt ki: téma,
   műfaj (pl. „egymás alá szedett tipográfiai idézet nagy számmal”), motívum, hangulat, durva
   felépítés, soronként a hangsúly (legnagyobb / nagy / kicsi), világos vagy sötét jelleg, és
   2–4 **jellegzetes elem**, amelyről az eredeti felismerhető (pl. babérkoszorú, türkiz szalag,
   sárga írott betű), mindegyikhez egy ugyanolyan szerepű cserével. A képpromptot ebből a kód
   rakja össze, így a nagyvonalú hasonlóság (műfaj, téma, hangsúly) megmarad, a felismerhető
   részletek viszont nem kerülnek át:
   - **Finom átdolgozás**: műfaj, téma, hangulat, durva felépítés és hangsúly; a jellegzetes
     elemek a megadott cserékre változnak; minden részlet új.
   - **Extra finom** (2.43.1): mint a Finom, de az elrendezés pontosabban marad, a betűstílus
     jellege és a rajzstílus is átkerül (a briefben `lettering_style`, `art_style`), a színek
     minden mintánál maradnak (legfeljebb 6, figurásnál is), és a betűtípus lesz más. A két
     legfeltűnőbb jellegzetes elem is megmarad, de a képgeneráló a saját kreatív variációjaként
     rajzolja újra (ugyanaz a fajta elem és szerep; a prompt nem írja elő, milyen legyen, 2.43.2).
     A „légy kreatív” helyett „maradj közel, csak a betűtípus és a megjelölt elemek variálhatók”
     utasítás megy. Szabad leírás módban is választható.
   - **Új stílus** (ajánlott): műfaj, téma, hangulat és hangsúly; saját elrendezés; a
     jellegzetes elemek tiltva.
   - **Teljesen új**: csak a téma és a hangulat; a jellegzetes elemek tiltva.

   A képgeneráló az eredeti képet egyik fokozatnál sem kapja meg (a költség miatt), csak
   a szöveges promptot (`images/generations`); az elemző a mintát kicsinyített JPEG-ként látja.
3. A kész PNG jelöltként a médiatárba kerül (legfeljebb 6 jelölt termékenként). A nem kellő
   jelöltek a kártyán a **Törlés**, mind egyszerre az **Összes jelölt törlése** gombbal
   törölhetők, a PNG-vel együtt (2.42.7). Minden jelölt egyedi fájlnevet kap, és az
   előnézetek URL-je a fájl idejével verziózott, így törlés vagy felskálázás után sem jelenhet
   meg gyorsítótárból egy régi kép (2.42.10). A
   **Jóváhagyás** a Minta cseréje sorával újragenerálja az összes mockupot, és a jóváhagyott
   PNG lesz a nyomtatási minta. A többi jelölt törlődik, az eredeti minta a médiatárban
   marad (az előzmény a `_mg_ai_redesign_history` metában).

**AI felskálázás (2.42.0):** a kész PNG-t az admin böngészője a **Real-ESRGAN**
(`realesr-general-x4v3`, BSD-3) modellel 4×-esre nagyítja (pl. 816 → 3264 px), TensorFlow.js-szel,
WebGL-en. A modell (4,9 MB) és a könyvtár (1,4 MB) a pluginban van (`assets/models/`,
`assets/vendor/tfjs/`), külső szolgáltatás és API-költség nincs. Egy visszavetítéses
színkorrekció megtartja az eredeti színeket; átlátszó mintánál a szín és az átlátszóság külön
skálázódik, sötét szegély nélkül; az üres vagy egyszínű csempék kimaradnak. A felskálázás a
generálás után magától indul, közben az ablakot nyitva kell hagyni. Ha a böngésző nem tudja
(nincs WebGL), jóváhagyáskor a szerver a régi 3×-os Lanczos-nagyítást alkalmazza. A modell
átalakítása: `tools/convert-realesrgan.py`. Teszt: `node tests/ai-upscale-browser-test.js`.

**Felskálázás előtti előkészítés (2.42.13, 2.43.5 óta közvetlenül a felskálázás előtt):** a
GPT Image képe a felskálázás előtt (és a 3×-os tartalék nagyítás előtt) a Design-flow
upscale-bemenetével azonos kezelést kap; az előnézet addig az eredeti, élsimított GPT-kép (`MG_Image_Utils::prepare_upscale_input`): peremjavítás
(a félig átlátszó szélpixelek a legközelebbi fedő belső pixel színét kapják, legfeljebb 3 px),
kétértékű átlátszóság 50%-nál, és az üres szél levágása csak az átlátszóság szerint. Így a 4×-es
AI felskálázás nem nagyítja fel a színes vagy sötét szegélyt. Átlátszatlan képen nem fut. A
peremjavítás kikapcsolható: **AI Minta SEO és tagelés → AI újratervezés → Peremjavítás a
felskálázás előtt**; a rendelések AI nyomatánál továbbra is ki van kapcsolva. **2.43.6 óta** a
kétértékű átlátszóság is kapcsolható, a küszöbe 1–99% között állítható (**Kétértékű átlátszóság
a felskálázás előtt**, alapból be, 50%): kikapcsolva a GPT-kép élsimított széle változatlanul megy
a felskálázóba (simább szél, de a félig átlátszó szegély is felnagyítódik; ezt a peremjavítás
tompítja), kisebb küszöb vastagabb, nagyobb vékonyabb szélt hagy.

**Palettás PNG (2.42.12):** a felskálázott AI minta a Design-flow app upscale-jével azonos
módon **255 színes palettás PNG** lesz (`MG_Image_Utils::to_palette_png`): a teljesen átlátszó
pixelek RGB-je 0, kvantálás dithering nélkül, az élsimított szélek megtartják az átlátszóságukat
(teljes tRNS tábla, 6 alatti alfa teljesen átlátszó), és az átlátszó margó levágódik (csak az
átlátszóság szerint, színre nem). Ez a böngészős AI felskálázás feltöltésekor fut, a 3×-os
tartalék nagyítás és a korábbi jelöltek esetén jóváhagyáskor. Az Imagick kvantál, a palettás
PNG-t a plugin írja soronként (3136×3392 px kb. 2 mp, ~60 MB). A rendelés-export ettől
függetlenül, változatlanul 8 bites RGBA-t ír a nyomdának. Teszt: `php tests/palette-png-test.php`
(valódi Imagick kell).

A **Pontos szöveg** mezőt érdemes kitölteni (soronként egy sor): így biztosan ugyanaz a
szöveg kerül az új mintára. A **Saját képprompt** mezővel egy korábbi jelölt promptja
átírható és újrafuttatható, ekkor az elemzés kimarad.

Beállítások: **AI Minta SEO és tagelés → AI újratervezés**: alapértelmezett elemző, OpenAI
elemző modell, Anthropic (Claude) API-kulcs, képminőség (alacsony / közepes / magas). A
Claude modellek a kulcs mentése után jelennek meg. Claude-nál a hibásan elutasított kérést az
API automatikusan egy másik modellel futtatja újra (server-side fallback; Haiku-nál nincs).
Imagick és WooCommerce Action Scheduler szükséges, mint az AI nyomatnál.

## SEO és AI keresők (2.40.0)

Az oldalon nincs SEO bővítmény, ezért a WordPress eddig csak „Név – www.forme.hu”
címet írt ki, meta leírás, kategória-canonical és Open Graph nélkül. A kategóriaoldalról
a téma elrejtette a H1-et. A régi Yoast/Rank Math filterek (típus a címben, OG kép)
le sem futottak. A **Marketing & Mérés → SEO és AI keresők** fül és az alábbi
működés ezt pótolja. Dedikált SEO bővítmény mellett a fejléc-kimenet magától kimarad.

A vevőknek, a botoknak és a feedeknek ugyanaz a virtuális típusrendszer látszik.
Minden típus önálló termék: saját URL (`/termek/minta-ferfi-polo/`), „Név - Típus”
név (mint a feed `g:title`-je és a H1), `SKU_típus` azonosító, a típus képe és ára.

Termékoldal:

- `<title>`: `{termek} - {tipus} | {kategoria} | {oldal}`, például
  „50-nek születik - Férfi póló | Születésnap | Forme.hu”. A kategória nem
  ismétlődik, ha a név már tartalmazza. 70 karakter fölött előbb az oldalnév, majd a
  kategória marad el. Típusváltáskor a böngészőfül címe is a típusét mutatja.
- Kategória: a kategória **„Kifejezés a termékoldalak címében”** mezője (pl.
  „Születésnapi ajándék”), egyébként a neve. Ha a terméknek több kategóriája van, a
  kitöltött mezőjű kategória kerül a címbe, ennek híján a feedhez hasonlóan az első
  alkategória.
- Meta leírás: név, típus, a minta AI SEO szövegéből (`_mg_sample_seo`) vagy a
  kategória leírásából annyi mondat, amennyi 160 karakterbe fér, és az előnyök.
- Kanonikus URL: az alap `/termek/minta/` URL kanonikusa és sitemap-bejegyzése az
  alapértelmezett típus URL-je. A lista, a feedek és a termékséma eddig is ezt
  használták. A típusos URL-ek önmagukra kanonizálnak, a GMC landing page-ek nem változnak.
- Javítva: a típusra végződő slugú termékek (pl. `…-polo-pulcsi`) alap URL-je
  eddig 404-et adott, mert a virtuális szabály „…-polo” termékként és „pulcsi”
  típusként olvasta. A típusválasztó ilyen oldalon sem ír már hibás URL-t a címsorba.
- Open Graph / Twitter: a típus képe és ára, `product:retailer_item_id` = feed ID.
- Séma: a termékséma `category` mezője a feed `product_type`-ja. A márka a feedeké,
  és valódi értékeléseknél `aggregateRating` is kerül bele. Mellette BreadcrumbList.

Kategóriaoldal (**Termékek → Kategóriák → szerkesztés → SEO és AI keresők**):

- SEO cím, H1, meta leírás (karakterszámlálóval), termékcím-kifejezés.
- **Alsó SEO szöveg** és **GYIK**: csak az első oldalon, a terméklista alatt
  jelenik meg. A GYIK FAQPage sémát is kap. Formátum: első sor a kérdés, alatta a
  válasz, a kérdések között üres sor. A `K:`/`V:` és a `Kérdés? | Válasz` forma is jó.
- noindex: a kategória kimarad a keresőkből, a sitemapből és az llms.txt-ből.
- Üres mezőknél sablon érvényes, például „Születésnap – vicces, egyedi pólók és
  ajándékok | Forme.hu”. Lapozott oldalaknál „N. oldal” kerül a címbe, és saját
  canonical készül. CollectionPage/ItemList séma is van.
- **H1 sablon** (2.40.1): `{kategoria} – vicces, egyedi pólók, pulóverek és ajándékok`,
  helyőrzők: `{kategoria}`, `{szulo}`, `{db}`. Üresen a kategória neve; kategóriánként
  a H1 mező felülírja. A sablon felsorolásából kimarad a kategórianévben már szereplő
  szó: a „Vicces” kategória H1-e „Vicces – egyedi pólók, pulóverek és ajándékok”, a címe
  „Vicces – egyedi pólók és ajándékok | Forme.hu”. A slug-szerű nevek („minecraft-polok”)
  olvasható alakban kerülnek a címbe, az előnézet és a kategóriaszerkesztő jelzi őket.
- A rövid (120 karakternél rövidebb) kategórialeírásból készült meta leírás végére az
  előnyök is bekerülnek, ha elférnek.
- Az alapszövegek a valós katalógust írják le (férfi, női, gyerek póló, pulóver, párna,
  sapka, táska; bögre nincs). A régi, bögrét említő alapértéket, ha változatlanul volt
  elmentve, az új váltja (2.40.4).

Közös márka: a beállítás **Márkanév** mezője a sémát, az Open Graph-ot és mindhárom
termékfeedet (Google, Facebook, egyedi/ChatGPT) egyszerre állítja. Üresen a webhely
neve, ahogy a feedek eddig is küldték.

AI-keresők:

- `/llms.txt`: a bolt összefoglalója kategóriafával, fontos oldalakkal és kapcsolattal.
  Minden kategória a minták számával és a saját leírásával (egyedi meta leírás vagy a
  kategórialeírás eleje) szerepel. Az `init` 20-as prioritásán készül, mert a WooCommerce
  az 5-ösön regisztrálja a `product_cat` taxonómiát; a gyorsítótár verziónként külön van.
  A **Vásárlási információk** mezőbe soronként írt tények (szállítási díjak, ingyenes
  szállítás határa, szállítási idő, fizetés, elállás) a kategóriák elé kerülnek. Az
  oldalak rövid kivonattal szerepelnek; a főoldal, a bolt, a kosár, a pénztár és a fiók
  kimarad, a többit pipával lehet ki-be tenni (2.40.2).
- Ha a webgyökérben fizikai `llms.txt` van (például a Hostinger Tools bővítmény
  LLMs.txt funkciójáé), a webszerver azt adja ki a miénk helyett. A beállításoldal ezt
  jelzi, megmondja, hol kell kikapcsolni (Hostinger → Tools → LLM Optimization), és
  egy gombbal törölhető a maradék fájl. A Hostinger kapcsolójának kikapcsolása nem
  mindig törli a korábban létrehozott fájlt.
- A robots.txt ellenőrzése megmutatja, hogy az OAI-SearchBot (ChatGPT keresés), a
  PerplexityBot, a Claude-SearchBot, a Bingbot és a Googlebot elérheti-e az oldalt. A
  Cloudflare AI Crawl Control tűzfal-szinten is tilthat: ott a keresőbotokat engedd.
- IndexNow (alapból ki): az új és módosított termékek, kategóriák típusos URL-jét
  azonnal bejelenti a Bingnek, amire a ChatGPT keresés és a Copilot is támaszkodik.
- A sitemapből kimarad a szerzői archívum. A noindex kategóriák sem kerülnek bele.

Élesítés után: ellenőrizd a **Cégadatok** címét a lábléchez képest, majd ürítsd a
LiteSpeed és a Cloudflare gyorsítótárát. Utána a Search Console-ban küldd be újra a
`wp-sitemap.xml`-t, a Bing Webmaster Toolsban pedig importáld a Search Console-ból.

Ellenőrzés: `php tests/seo-meta-test.php`. Böngészős teszt a fülcímre és a
`…-pulcsi` slugokra (Playwright + Chromium szükséges):
`node tests/seo-virtual-title-browser-test.js`.

## DesignFlow-felismerés és szerkeszthető presetmegfeleltetés (2.38.11)

Az **Egyedi mezők** adminoldal **AI felismerés → preset megfeleltetés** részén
rendeld a felismerési típusokat a saját, meglévő presetjeidhez. Mindegyik sorban
a presetet és azon belül a megfelelő mezőket is válaszd ki, majd ments:

| Felismert elemek | Példa a meglévő presetre |
| --- | --- |
| Hónap | Hónap választó |
| Név | Név |
| Név + évszám | Név + évszám |
| Életkor / eltelt évek + évszám | Év + évszám |
| Évszám | Évszám |
| Évszám + hónap | évszám hónap |

A megfeleltetés az azonosítókhoz kötődik, ezért az átnevezés nem bontja meg.
Törölt vagy nem megfelelő preset/mező esetén kézi ellenőrzés szükséges.
Az összetett felismerés mindig a teljes kombinációhoz tartozó presetet választja.

A tömeges feltöltőben a PNG-k mellé válaszd ki a DesignFlow JSON-jait is,
majd kapcsold be az **AI/JSON** módot és az **Egyedi mezők és preset** opciót.
Egyértelmű, kész képről származó felismerésnél az Egyedi jelölő és a preset
automatikusan kitöltődik. A sor jelzi a felismert értéket és a választott presetet.
A kézi választás elsőbbséget kap. Bizonytalan vagy megfeleltetés nélküli találatnál
„Ellenőrizendő” jelzés jelenik meg; régi JSON nem állítja át az egyedi mezőket.
A közvetlen feltöltés és a háttérsor is ugyanezeket a szabályokat használja.

A képen látható érték csak felismerési adat, nem vásárlói alapérték. A meglévő
preset felárai, AI-nyomatbeállításai és válaszlehetőségei változatlanul érvényesek.
Korábbi JSON-okhoz a DesignFlow **Kész designok → Egyedi mezők pótlása** művelete
készíti el a szükséges adatot, fájlátnevezés és a többi termékadat módosítása nélkül.

Ellenőrzés: `php tests/personalization-import-test.php`,
`node tests/personalization-import-test.js`. A közös
`tests/fixtures/personalization-contract.json` a DesignFlow tényleges exportját
használja mind a hat profil feloldásának ellenőrzésére.

## AI egyedi nyomat a rendelés ZIP-exportjában (2.37.0)

Az **Egyedi mezők → megfelelő sablon → mező szerkesztése** alatt kapcsold be
a **Nyomat módosítása AI-val a rendelés ZIP-exportjakor** opciót, és töltsd ki
a **Képmódosítási utasítás** mezőt. A kötelező `{{ertek}}` helyőrzőbe a vevő
rendelt mezőértéke kerül. A képen látható példaévszámot vagy hónapot nem kell
előre megadni. A sablon aktuális promptja a régebbi rendelésekre is érvényes.

Az **Előre megírt AI-utasítás** listából preset- és mezőnév szerint választhatsz:

| Preset | Mezőhöz betöltendő utasítás |
| --- | --- |
| Hónap választó | Hónap választó — Hónap mező |
| Név | Név — Név mező |
| Név + évszám | Név + évszám — Név mező; Név + évszám — Évszám mező |
| Év + évszám | Év + évszám — Év mező (életkor / eltelt évek); Év + évszám — Évszám mező |
| Évszám | Évszám — Évszám mező |
| évszám hónap | évszám hónap — Évszám mező; évszám hónap — Hónap mező |

Az **Utasítás betöltése és AI bekapcsolása** gomb az adott mező promptját
cseréli le és bekapcsolja annak AI-nyomat opcióját. A szöveg továbbra is
szerkeszthető; a változtatást a mező mentése rögzíti. Kétmezős presetnél
mindkét mezőben külön töltsd be és mentsd a megfelelő utasítást.
A lista nem rendel automatikusan utasítást a meglévő presetekhez.

Hónap példa:

```text
A képen látható, személyre szabásra szolgáló hónapmegnevezést cseréld erre:
{{ertek}}. Kövesd az eredeti felirat kis- és nagybetűzését és nyelvtani
szerepét, az új hónaphoz helyes magyar toldalékolással.
Például MÁJUSBAN helyett SZEPTEMBERBEN.
```

Évszám példa:

```text
A képen látható születési példaévszámot cseréld erre: {{ertek}}.
Ha toldalék kapcsolódik hozzá, az új évszámhoz helyesen illeszd.
Más évszámot vagy feliratot ne módosíts.
```

Az **AI Minta SEO és tagelés** oldalon megadott OpenAI API-kulcsot használja;
a SEO-generálást ehhez nem kell bekapcsolni. Ugyanezen az oldalon az
**AI egyedi nyomat – ZIP-export → Képszerkesztő modell** választóban állítható:

- **GPT Image 2** (`gpt-image-2`) – a meglévő működés alapértelmezése.
- **GPT Image 2.5 Sunburst** (`gpt-image-2.5-sunburst`).
- **GPT Image 2.5 Flare** (`gpt-image-2.5-flare`).

A **Nyomatmodell mentése** csak az export képszerkesztőjét állítja; a SEO és
tagelés modelljét nem módosítja. A választás minden AI-mezős nyomatra érvényes.
Az export indításkor rögzíti a modellt, ezért egy menet közbeni beállításváltás
csak az új exportokra hat. A frissítés előtti várakozó exportok továbbra is
GPT Image 2-t használnak. Nincs automatikus modellváltás API-hiba esetén.

Mindhárom modellnél a minőség `low`, a kimenet egy PNG. A kimenet a forrás képarányát legfeljebb 1% eltéréssel
követő legkisebb támogatott képméret (655 360–750 000 képpont). Nincs automatikus
váltás drágább minőségre vagy felbontásra. Az átlátszó háttér megőrzését külön
kéri és ellenőrzi. A különböző modellek költsége és futási ideje eltérhet.

A **Minták letöltése (ZIP)** először a feketeeltávolítás módját kéri, majd
az AI-s egyedi tételeknél **vizuális ellenőrzőt** nyit. Az alapminta mellett
együtt láthatók a rendelésbe mentett AI-mezőértékek, a rendelés- és tételszám,
valamint a darabszám. A kép teljes méretben is megnyitható, a háttér pedig
világos és sötét között váltható.

Minden egyedi tételnél külön kell dönteni:

- **Alapminta jó – nem kell AI:** a meglévő mintát használja; nincs AI-hívás
  és nincs AI-hoz tartozó 3×-os nagyítás. A szokásos nyomatméretezés és a
  kiválasztott feketeeltávolítás továbbra is érvényes.
- **AI-módosítás kell:** a vásárló értékeivel személyre szabott kép készül.

Az ellenőrzőben vissza lehet lépni, és az összesítőből is módosítható a döntés.
Az export csak az összes tétel ellenőrzése után, az **Export indítása** gombbal
kezdődik. Az ellenőrzés alatt nincs AI-hívás vagy ZIP-feldolgozás. Ha nincs
AI-s egyedi tétel, a normál export az exportmód kiválasztása után elindul.
A döntések csak erre az exportra vonatkoznak; a preseteket nem módosítják.
Az ellenőrzés egy óráig érvényes. Megváltozott rendelés, prompt vagy alapminta
esetén új ellenőrzés szükséges. Minden döntést szerveroldalon is ellenőriz,
az ismételt indítási kérés ugyanazt az exportfeladatot adja vissza.

Egy tétel több AI-mezője egy hívásban módosul, a darabszám szerinti másolatok
ugyanazt a képet és döntést használják. Minden új exportban az AI-ra kijelölt
tételekhez új generálás indul. Az eredeti termékminta
érintetlen marad; a hagyományos egyedi alapminta-letöltés nem generál.

Az exportablak mutatja az AI-ra váró rendelést és tételt. A WooCommerce
Action Scheduler háttérben végzi a hosszabb hívást, ezért működő ütemezett
műveletek/loopback és Imagick szükséges. Hiányzó kötelező érték, hibás prompt,
API-hiba, hibás PNG vagy elveszett átlátszóság esetén a ZIP-export leáll, és
nem helyettesíti az egyedi nyomatot a régi alapképpel. Nincs automatikus fizetős
újrapróbálás. A mezőértéket és a nyomatot küldi el, nem a teljes rendelést.

Kizárólag az AI-val generált egyedi PNG-k automatikus **3×-os felnagyítást**
kapnak: a szélesség és a magasság is háromszoros lesz, az átlátszóság megőrzésével.
Ez helyi Imagick Lanczos átméretezés, nem újabb AI-hívás vagy AI-részletjavítás.
Tételenként egyszer fut; a darabszám szerinti másolatok ezt a képet használják.
A hagyományos mintákat nem nagyítja fel. Sikertelen felnagyítás leállítja az exportot.

A **Design-flow peremjavítás ki van kapcsolva** az AI-nyomatok exportjánál.
A korábban mentett bekapcsolt beállítást és a sorban álló feladatok ilyen
jelölését is figyelmen kívül hagyja. A 3×-os nagyítás továbbra is fut.

A felnagyított PNG a meglévő export méretezésén és a kiválasztott feketeeltávolításon
megy keresztül. A `low` eredmény szöveghűségét és nyomtatási minőségét valódi
mintán ellenőrizni kell: a prompt nem garantál pixelpontos változatlanságot.
A beállított centiméteres nyomatméret a crop utáni mintára vonatkozik.
Az üres margók eltávolítása után forgatja és méretezi a grafikát, majd
véglegesíti az alfát, további crop nélkül. A mentett PNG 300 DPI mellett
pontosan a beállított méretnek megfelelő, egész pixelre kerekített méretű.

A **Fekete nélkül** export a fekete ruhák nyomatain kétszer távolítja el
a feketét: először a forrás betöltésekor, majd a bináris alfa-véglegesítést
követően. A második feketeeltávolítás után ismét véglegesíti az alfát.
Ezután következik a crop, a forgatás, a nyomtatási méretezés és a végső
alfa-véglegesítés. Normál exportnál feketeeltávolítás nem fut.

Ellenőrzések: `php -d extension=zip tests/ai-print-export-test.php`,
`php tests/custom-fields-cart-test.php`, `node tests/order-export-script-test.js`.
Valódi böngészős varázslóteszt (Playwright + Chromium szükséges):
`node tests/order-export-browser-test.js`. Ez helyettesített WordPress-válaszokkal
ellenőrzi a döntéseket, visszalépést, mobilnézetet és a végső exportindítást.
Valódi Imagick-képfeldolgozási teszt a peremkorrekcióhoz:
`php -d extension=imagick tests/ai-print-fringe-test.php`. Áttetsző halót,
színes kontúrok javítását, az alfa és fedő részletek megőrzését, a hárompixeles
határt, az átlátszó rések védelmét, valamint a nagyítás és DTF-alpha utáni
PNG-kimenetet ellenőrzi szintetikus mintákon.
A végleges nyomatok minden esetben 8 bites RGBA PNG-k (PNG color type 6).
Az egyszínű mintákat sem menti 1 bites szürkeárnyalatos PNG-ként, mert ezt
egyes RIP-ek hibásan kezelik. A kisebb fájlméret önmagában nem hibajel.

Fekete kontúr maradványának és a PNG-kódolásnak regressziós tesztje valódi Imagick-kel:
`php -d extension=imagick tests/black-export-edge-test.php`.
Opcionálisan egy PNG útvonalát is elfogadja, amelyen memóriában ellenőrzi
a végső feketeeltávolítást; az eredeti fájlt nem módosítja.
Az AI-export teszt valódi PNG-adatot és ZIP-fájlt használ, de a WordPress,
az ütemező, az OpenAI HTTP-válasz és az Imagick helyettesített; nem éles AI-próba.
API-forrás: [OpenAI képgenerálás](https://developers.openai.com/api/docs/guides/image-generation).

## Helyi készlet (Készlet menü)

A WordPress adminban a **Készlet** csoport tartja nyilván, hány darab van
raktáron az egyes póló variánsokból, és a nagyker rendelés ebből vonja le,
amit nem kell megrendelni.

A nyilvántartás sorai nem kézzel felvitt lista: a virtuális variáns
katalógusból (`MG_Variant_Display_Manager::get_catalog_index()`) generálódnak,
így minden új terméktípus, szín és méret automatikusan megjelenik. Egy sort a
**terméktípus + szín + méret** hármas azonosít.

- **Készletmátrix** – terméktípusonként egy szín (sor) × méret (oszlop) rács.
  A `size_color_matrix` alapján nem létező variánsok cellái nem tölthetők ki.
  Minden színsor mellett ott a nagyker (UTT) cikkszám. Sor- és oszlopszintű
  tömeges kitöltés, valamint cellánkénti **biztonsági készlet**, ami alá az
  export nem nyúl.
- **Bevételezés** – a megérkezett szállítmány sorai ugyanabban a
  `cikkszám,darab` formában illeszthetők be, ahogy a nagyker CSV kiment, és a
  mennyiségek hozzáadódnak a készlethez.
- **Hiánylista** – az elfogyott vagy a biztonsági szintet elérő variánsok.
- **Mozgásnapló** – minden készletváltozás időponttal, felhasználóval,
  okkal és a hozzá tartozó rendeléssel.

### Nagyker export levonás

A rendelés listán futó **Nagyker CSV Export (UTT)** bulk action a levonás
előtt megerősítő előnézetet mutat: mi jön a helyi készletből, mit kell
megrendelni, mi nem rendelhető (hiányzó UTT cikkszám) és mely tételekből nem
sikerült kiolvasni a variánst. A készlet levonása és a rendelések „Gyártás
alatt" státuszba léptetése csak a megerősítés után történik meg. Az előnézet
és maga a levonás is kikapcsolható a Készletmátrix tetején.

- A levonás **atomi** SQL feltétellel történik, így két párhuzamosan futó
  export nem oszthatja ki ugyanazt a darabot kétszer.
- A rendelés tételére felkerül, mennyit fedezett a helyi készlet
  (`_mg_local_stock_taken`), ezért **ugyanaz a rendelés újraexportálva nem
  vonja le kétszer** a készletet, és a már fedezett darabokat nem is rendeli
  meg újra.
- Sztornó vagy visszatérítés esetén a levont mennyiség automatikusan
  visszakerül a készletbe.
- Ha egy variánshoz nincs UTT cikkszám, a helyi készlet akkor is fogy (a póló
  fizikailag lekerül a polcról), de a CSV-be nem kerül sor – ezt az előnézet
  külön kiemeli.
- A méretkulcsot minden hívó a `MG_Local_Stock::normalize_size()` metóduson
  keresztül állítja elő (`XXL` → `2xl`, `3-6 hó` → `3/6m`), így a készlet
  kulcsa és a CSV cikkszáma nem tud elcsúszni.

A modul két saját táblát használ (`{prefix}mg_local_stock`,
`{prefix}mg_local_stock_log`), amelyek első admin betöltéskor jönnek létre.
Egységteszt: `php tests/local-stock-test.php`.

## Allegro export

The WordPress admin contains an **Export & Feedek → Allegro Export** tab. It
creates a UTF-8, semicolon-separated CSV that can be imported into the
companion `allegro-sync` application.

- Every product type + colour + size combination receives a deterministic SKU.
- The original Woo colour is exported as the manufacturer colour, while the
  Allegro core colour comes from Allegro's accepted clothing dictionary.
- Standard sizes are normalized (`2XL` → `XXL`, `XXXL` → `3XL`). Unknown and
  child age-range sizes must be mapped explicitly in the admin page.
- Woo stock is used when stock management is enabled; otherwise the saved
  template stock is used.
- Each virtual product type can store laid-flat length and width values for
  every size. When present, the exact exported variant receives a Hungarian
  size-measurement block in its description and separate `length_cm` / `width_cm`
  CSV values for Allegro's additional parameters; no size-chart image is needed.
- Missing SKU, description, image, price, colour mapping or size mapping stops
  the download and produces an actionable validation list.

The first mapping screen is organized into three Allegro profiles: child,
men's and women's T-shirts. Each Allegro category is assigned to one virtual
product type, then that type's own colours and sizes are mapped from dropdowns
containing the exact Allegro dictionary values. Collared shirts are not part of
the initial profiles.

The export itself follows the same two-step selection model as the Temu
screen: first choose the exact Woo products from a paginated, category-filtered
table, then include or exclude the mapped virtual product types, colours and
sizes. Only the resulting exact combinations are written to the Allegro CSV.
