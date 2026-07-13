# Ajándékválasztó-besorolás – 2026-07-13

## Felhasznált adatok

- 193 meglévő WooCommerce-termékkategória és azok szülő–gyermek kapcsolata.
- 7 356 anonim termék–kategória kapcsolat.
- Kategóriánként összesített, anonim 12 havi értékesítési darabszám a hero termék kiválasztásához.
- Nem használtunk terméknevet, SKU-t, attribútumot, készlet- vagy láthatósági állapotot, képet és árat.

## Besorolási elv

1. A címzett-választás a `Család` alkategóriáira és a meglévő `Szerelmes` kategóriára épül.
2. Az alkalmak csak az előző lépés releváns címzettjeinél jelennek meg. A Valentin-nap például csak feleség, férj vagy szerelmes választásakor látható.
3. Az évszakok a meglévő szezonális kategóriákra mutatnak: Húsvét, Nyaralás, Halloween és Karácsony.
4. Az érdeklődési körök a nagy, jól feltöltött kategóriákat használják. Ahol van értelmes, pontos alkategória, ott azt választottuk (például `Horgász`).
5. A főoldali kártyák hero terméke az adott kategória legnagyobb 12 havi anonim darabszámú terméke. Ha a kategóriában nem volt értékesítés, egy kategóriába tartozó érvényes termékazonosító került be.
6. Az árkeretek szándékosan nem szerepelnek az importban, ezért a webhely jelenlegi árkeret-beállítása megmarad.
7. Ajándékcsomag nem került automatikusan létrehozásra. Terméknevek és terméktípus-kapcsolatok nélkül nem állítható össze megbízható, tartalmilag összetartozó csomag. A meglévő póló → sapka/párna/vászontáska cross-sell szabály változatlan marad.

## Adatminőségi megjegyzések

- Az exportban 11 836 címke található, köztük sok duplikáció, elírás és nulla termékes címke. Emiatt az első konfiguráció kizárólag kategóriákra épül.
- Külön `Anyák napja` és `Apák napja` kategória nincs, miközben ilyen címkék vannak. Ezeket most nem vettük fel alkalomként, mert a kereső opciói kategóriaazonosítóval működnek.
- A `Barátok` kategóriában jelenleg nincs termék, ezért nem került be címzettként; ez elkerüli az eleve gyenge vagy üres találati útvonalat.
- A `Történelem` főkategóriában jelenleg nincs termék, ezért az érdeklődési körök között sem szerepel.

## Importálás

A `forme-ajandekvalaszto-konfiguracio-2026-07-13.json` fájl a WordPress adminban a **Mockup Generator → Ajándékkereső → Konfiguráció importálása** résznél tölthető fel. Az import előtt a bővítmény automatikus biztonsági mentést készít a jelenlegi beállításokról.
