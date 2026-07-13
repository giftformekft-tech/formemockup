# Ajándékválasztó-besorolás – 2026-07-13

## Felhasznált adatok

- 193 meglévő WooCommerce-termékkategória és azok szülő–gyermek kapcsolata.
- 7 356 anonim termék–kategória kapcsolat.
- Kategóriánként összesített, anonim 12 havi értékesítési darabszám a hero termék kiválasztásához.
- Nem használtunk terméknevet, SKU-t, attribútumot, készlet- vagy láthatósági állapotot, képet és árat.

## Besorolási elv

1. A címzett-választás a `Család` alkategóriáira és a meglévő `Szerelmes` kategóriára épül.
2. Az alkalmak csak az előző lépés releváns címzettjeinél jelennek meg. A Valentin-nap például csak feleség, férj vagy szerelmes választásakor látható.
3. Az érdeklődési körök a nagy, jól feltöltött kategóriákat használják. Ahol van értelmes, pontos alkategória, ott azt választottuk (például `Horgász`).
4. A találatok elsődleges szűrője a legutolsó, legpontosabb válasz. Az érdeklődési kör megelőzi az alkalmat, az alkalom pedig a címzettet. Ha az elsődleges kategória üres, a kereső az előző válaszra lép vissza. Így az `Apának → Születésnapra → Horgászik` útvonal kizárólag horgász kategóriás termékeket ad.
5. A főoldali kártyák hero terméke az adott kategória legnagyobb 12 havi anonim darabszámú terméke. Ha a kategóriában nem volt értékesítés, egy kategóriába tartozó érvényes termékazonosító került be.
6. Évszak- és árkeretkérdés nincs a keresőben.
7. Ajándékcsomag nem került automatikusan létrehozásra. Terméknevek és terméktípus-kapcsolatok nélkül nem állítható össze megbízható, tartalmilag összetartozó csomag. A meglévő póló → sapka/párna/vászontáska cross-sell szabály változatlan marad.
8. A főoldali Gutenberg blokk a címzettválasztással indul, majd a kiválasztott címzettel a teljes kereső alkalomlépésére visz. A blokk és a kereső hét közös színe az adminban módosítható.
9. Az alkalmak saját, több meglévő kategóriát összefogó csoportok is lehetnek. Az Apák napja, Anyák napja, évforduló/házassági alkalom és nyugdíjazás ilyen csoportként került be; a meglévő alkalmak mellett Halloween és tejfakasztó is választható. A kereső termékcímkéket nem használ.
10. A „Munkája a szenvedélye” és a „Nyugdíjazás” útvonal után külön foglalkozáslépés jelenik meg a meglévő foglalkozási alkategóriákkal.

## Adatminőségi megjegyzések

- Az exportban 11 836 címke található, köztük sok duplikáció, elírás és nulla termékes címke. Emiatt az első konfiguráció kizárólag kategóriákra épül.
- Külön `Anyák napja` és `Apák napja` kategória nincs, miközben ilyen címkék vannak. Ezeket most nem vettük fel alkalomként, mert a kereső opciói kategóriaazonosítóval működnek.
- A `Barátok` kategóriában jelenleg nincs termék, ezért nem került be címzettként; ez elkerüli az eleve gyenge vagy üres találati útvonalat.
- A `Történelem` főkategóriában jelenleg nincs termék, ezért az érdeklődési körök között sem szerepel.

## Importálás

A `forme-ajandekvalaszto-konfiguracio-2026-07-13.json` fájl a WordPress adminban a **Mockup Generator → Ajándékkereső → Konfiguráció importálása** résznél tölthető fel. Az import előtt a bővítmény automatikus biztonsági mentést készít a jelenlegi beállításokról.
