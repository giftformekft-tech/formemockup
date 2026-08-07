# Ajándékkereső katalógus- és konfigurációcsere

## Katalógusexport

Az adminban a **Mockup Generator → Ajándékkereső → Katalógus JSON letöltése** gomb egy `mg-gift-catalog` sémájú, 2-es verziójú fájlt készít. A fájl személyes adatot és rendelésazonosítót nem tartalmaz.

Fő adatcsoportjai:

- `categories`: teljes WooCommerce kategóriahierarchia;
- `tags`: termékcímkék;
- `canonical_tag_dictionary`: a mintaelemzővel közös kanonikus taglista és csoportjai;
- `products`: anonim technikai termék-ID-k, kategóriakapcsolatok, címkék, kiemeltség és opcionális összesített eladás;
- `mockup_product_types`: a Mockup Generator elérhető terméktípusai;
- `cross_sell_rules`: jelenlegi cross-sell szabályok;
- `current_gift_finder_settings`: jelenlegi ajándékkereső-beállítások;
- `sales_12m_quantity`: opcionális, termékenként összesített eladott darabszám.

Az export szándékosan nem tartalmaz terméknevet, slugot, SKU-t, képet, árat, leírást, attribútumot, készlet- vagy katalógusláthatósági adatot. Az árak a virtuális terméktípus-katalógusban közösek, ezért a kategóriabesoroláshoz nincs rájuk szükség.

## Importálható konfiguráció

Az adminban a **Konfiguráció JSON letöltése** gomb a jelenlegi kérdés–válasz láncot és a kanonikus válasz–tag kapcsolatokat is exportálja. Az import a régi 1-es és az új 2-es verziót is elfogadja.

Az importfájl gyökérstruktúrája:

```json
{
  "schema": "mg-gift-finder-config",
  "version": 2,
  "dictionary_version": "2026-08-02",
  "settings": {
    "page_id": 123,
    "colors": {
      "accent": "#c6503e",
      "accent_dark": "#9d392b",
      "ink": "#24211d",
      "muted": "#6d675f",
      "background": "#faf6ef",
      "panel": "#f7f2e9",
      "card": "#ffffff"
    },
    "cards": [],
    "tag_mode": {
      "enabled": 0
    },
    "facets": {
      "enabled": 1,
      "threshold": 12,
      "levels": {
        "recipient": 1,
        "occasion": 2,
        "wedding_type": 2,
        "interest": 3,
        "occupation": 4
      }
    },
    "questions": {
      "recipient": { "title": "Kinek keresel ajándékot?", "options": [] },
      "occasion": { "title": "Milyen alkalomra?", "options": [] },
      "wedding_type": { "title": "Milyen házassághoz kapcsolódó eseményre?", "options": [] },
      "interest": { "title": "Milyen a személyisége, mi érdekli?", "options": [] },
      "occupation": { "title": "Mi a foglalkozása?", "options": [] }
    },
    "bundles": []
  }
}
```

A `colors` mező opcionális. Ha kimarad, az import megőrzi a webhely jelenlegi ajándékkereső-színeit. Árkeret nincs a keresőben.

`tag_mode.enabled = 0` esetén a kereső a korábbi kategória- és terméknév-kulcsszó logikát használja. `1` esetén a válaszhoz rendelt kanonikus tagek is jelöltet adnak, és az azonos válaszszámú termékek között a tag-egyezés erősebb a kategória-, az pedig a kulcsszó-egyezésnél. A rendszer csak a szótárban szereplő címkéket fogadja el, és csak a már létező `product_tag` termeket használja.

A `facets` mező a kemény (metszetes) szűrés beállítása: `enabled` a be-/kikapcsolás, `threshold` a lazítási küszöb (1–100), `levels` pedig a kérdésenkénti feloldási szint (2–9; a magasabb oldódik fel előbb). A `recipient` szintje mindig 1, tehát sosem oldódik fel – az importált érték itt figyelmen kívül marad. Ha a `facets` mező kimarad, az import megőrzi a webhely jelenlegi facet-beállításait; enélkül egy régi JSON visszatöltése csendben visszaállítaná az alapértékeket.

Egy függő válasz formátuma:

```json
{
  "label": "Anyák napjára",
  "category_id": 45,
  "tag_labels": ["Anyák napja", "Anyának"],
  "parent_category_ids": [12]
}
```

Egy több meglévő kategóriát összefogó saját alkalom formátuma:

```json
{
  "label": "Apák napjára",
  "category_id": 0,
  "category_ids": [110, 111, 116],
  "option_id": "fathers-day",
  "parent_category_ids": [110, 111, 116]
}
```

A `category_ids` mező az adott logikai válasz alá bevont WooCommerce-kategóriákat tartalmazza. A `tag_labels` mező a válaszhoz tartozó, szótárból választott címkéket tartalmazza. Az `option_id` akkor szükséges, amikor nincs egyetlen kitüntetett elsődleges kategória. A tag mód kikapcsolása esetén ezek a kapcsolatok megmaradnak, de a kereső figyelmen kívül hagyja őket.

Importálás előtt a rendszer elmenti a jelenlegi beállításokat. Ez az adminoldalról egy kattintással visszaállítható.
