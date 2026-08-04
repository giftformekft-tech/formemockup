# formemockup
mymockup

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
