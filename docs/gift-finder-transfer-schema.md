# Ajándékkereső katalógus- és konfigurációcsere

## Katalógusexport

Az adminban a **Mockup Generator → Ajándékkereső → Katalógus JSON letöltése** gomb egy `mg-gift-catalog` sémájú fájlt készít. A fájl személyes adatot és rendelésazonosítót nem tartalmaz.

Fő adatcsoportjai:

- `categories`: teljes WooCommerce kategóriahierarchia;
- `tags`: termékcímkék;
- `products`: publikált termékek neve, kategóriái, címkéi, leírásai és attribútumai;
- `mockup_product_types`: a Mockup Generator elérhető terméktípusai;
- `cross_sell_rules`: jelenlegi cross-sell szabályok;
- `current_gift_finder_settings`: jelenlegi ajándékkereső-beállítások;
- `sales_12m_quantity`: opcionális, termékenként összesített eladott darabszám.

Az export szándékosan nem tartalmaz képeket vagy árakat. Az árak a virtuális terméktípus-katalógusban közösek, ezért a kategóriabesoroláshoz nincs rájuk szükség.

## Importálható konfiguráció

Az importfájl gyökérstruktúrája:

```json
{
  "schema": "mg-gift-finder-config",
  "version": 1,
  "settings": {
    "page_id": 123,
    "cards": [],
    "questions": {
      "recipient": { "title": "Kinek keresel ajándékot?", "options": [] },
      "occasion": { "title": "Milyen alkalomra?", "options": [] },
      "season": { "title": "Melyik évszakhoz illjen?", "options": [] },
      "interest": { "title": "Milyen a személyisége, mi érdekli?", "options": [] }
    },
    "bundles": []
  }
}
```

A `budgets` mező opcionális. Ha kimarad, az import nem módosítja a webhely jelenlegi árkeret-beállításait.

Egy függő válasz formátuma:

```json
{
  "label": "Anyák napjára",
  "category_id": 45,
  "parent_category_ids": [12]
}
```

Importálás előtt a rendszer elmenti a jelenlegi beállításokat. Ez az adminoldalról egy kattintással visszaállítható.
