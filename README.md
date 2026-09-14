# formemockup
mymockup

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
A beállított centiméteres nyomatméret határozza meg a crop előtti vászonméretet;
a 3×-os nagyítás ezt megelőző feldolgozási lépés. Az üres margók levágása miatt
a végső PNG külső mérete kisebb lehet; a megmaradó grafikát nem nagyítja újra.

A **Fekete nélkül** export a fekete ruhák nyomatain kétszer távolítja el
a feketét: először a végső méretezés előtt, majd a méretezés utáni
bináris alfa-véglegesítést követően. A második feketeeltávolítás után ismét
véglegesíti az alfát. Normál exportnál feketeeltávolítás nem fut.
Az üres margók cropja mindkét exportmódban az utolsó képfeldolgozási lépés,
az összes méretezés, feketeeltávolítás és alfa-véglegesítés után, a PNG mentése előtt.

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
