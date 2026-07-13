# Ajándékkereső katalógus- és konfigurációcsere

## Katalógusexport

Az adminban a **Mockup Generator → Ajándékkereső → Katalógus JSON letöltése** gomb egy `mg-gift-catalog` sémájú fájlt készít. A fájl személyes adatot és rendelésazonosítót nem tartalmaz.

Fő adatcsoportjai:

- `categories`: teljes WooCommerce kategóriahierarchia;
- `tags`: termékcímkék;
- `products`: anonim technikai termék-ID-k, kategóriakapcsolatok, címkék, kiemeltség és opcionális összesített eladás;
- `mockup_product_types`: a Mockup Generator elérhető terméktípusai;
- `cross_sell_rules`: jelenlegi cross-sell szabályok;
- `current_gift_finder_settings`: jelenlegi ajándékkereső-beállítások;
- `sales_12m_quantity`: opcionális, termékenként összesített eladott darabszám.

Az export szándékosan nem tartalmaz terméknevet, slugot, SKU-t, képet, árat, leírást, attribútumot, készlet- vagy katalógusláthatósági adatot. Az árak a virtuális terméktípus-katalógusban közösek, ezért a kategóriabesoroláshoz nincs rájuk szükség.

## Importálható konfiguráció

Az importfájl gyökérstruktúrája:

```json
{
  "schema": "mg-gift-finder-config",
  "version": 1,
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
    "questions": {
      "recipient": { "title": "Kinek keresel ajándékot?", "options": [] },
      "occasion": { "title": "Milyen alkalomra?", "options": [] },
      "interest": { "title": "Milyen a személyisége, mi érdekli?", "options": [] },
      "occupation": { "title": "Mi a foglalkozása?", "options": [] }
    },
    "bundles": []
  }
}
```

A `colors` mező opcionális. Ha kimarad, az import megőrzi a webhely jelenlegi ajándékkereső-színeit. Árkeret nincs a keresőben.

Egy függő válasz formátuma:

```json
{
  "label": "Anyák napjára",
  "category_id": 45,
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

A `category_ids` mező az adott logikai válasz alá bevont WooCommerce-kategóriákat tartalmazza. Az `option_id` akkor szükséges, amikor nincs egyetlen kitüntetett elsődleges kategória. A kereső termékcímkéket nem használ a rangsoroláshoz.

Importálás előtt a rendszer elmenti a jelenlegi beállításokat. Ez az adminoldalról egy kattintással visszaállítható.
