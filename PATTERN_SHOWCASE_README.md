# Pattern Showcase Module - Dokumentáció

## 📖 Áttekintés

A **Pattern Showcase Module** lehetővé teszi, hogy egyetlen mintát/designt automatikusan megjeleníts több termék típuson, különböző színekben, carousel vagy grid layout-ban.

## ✨ Funkciók

- ✅ **Egy design, több termék** - Automatikus mockup generálás minden kiválasztott termékre
- ✅ **Termék kategorizálás** - Férfi/Női csoportosítás
- ✅ **Több layout** - Carousel (swipeable) vagy Grid megjelenítés
- ✅ **Mobile-first design** - Teljesen reszponzív, touch gesztusokkal
- ✅ **Gutenberg block** - Drag & drop beszúrás bármely oldalra
- ✅ **Shortcode támogatás** - `[mg_pattern_showcase id="..."]`
- ✅ **Színstratégiák** - Első szín / Egyéni színek / Minden szín

## 🚀 Használat

### Admin Interface

#### 1. Pattern Showcase létrehozása

1. Menj a **Mockup Generator > Pattern Showcases** menüpontra
2. Kattints az **"Add New"** gombra
3. Töltsd ki a formot:
   - **Showcase Name**: Adj meg egy nevet (pl. "Hellfire Club Collection")
   - **Design File**: Válaszd ki a mintát a Media Library-ből
   - **Product Types**: Jelöld be, mely termékeket szeretnéd megjeleníteni
   - **Color Strategy**:
     - **First color** - minden termék első (alapértelmezett) színe
     - **Custom color** - termékenként egyedi szín választás
     - **All colors** - minden termék minden színe
   - **Layout**: Carousel vagy Grid
   - **Grid Columns**: Ha grid-et választottál, add meg az oszlopok számát (2-6)
   - **Group by Category**: Csoportosítás kategóriánként (Férfi/Női)

4. Kattints a **"Create Showcase"** gombra
5. Kattints a **"Generate Mockups Now"** gombra a mockupok generálásához

#### 2. Shortcode használata

A lista nézetben minden showcase mellett megjelenik a shortcode:

```
[mg_pattern_showcase id="showcase_abc123"]
```

Másold be ezt a shortcode-ot bármely WordPress post/page-be.

**Shortcode paraméterek:**

```
[mg_pattern_showcase id="showcase_abc123" layout="carousel"]
[mg_pattern_showcase id="showcase_abc123" layout="grid" columns="3"]
```

### Gutenberg Block használata

#### Block beszúrása

1. Nyiss meg egy WordPress oldalt vagy postot
2. Kattints a **"+"** gombra új blokk hozzáadásához
3. Keresd meg a **"Pattern Showcase"** blokkot (Media kategória)
4. Válaszd ki a kívánt showcase-t a dropdown-ból

#### Block beállítások

A jobb oldali **Settings** panelben:

- **Select Showcase**: Válaszd ki melyik showcase-t szeretnéd megjeleníteni
- **Layout Override**: Felülírhatod az alapértelmezett layoutot (Carousel/Grid)
- **Grid Columns**: Grid layout esetén az oszlopok száma

## 🎨 Megjelenítés

### Carousel Layout

- Egyszerre egy termék mockup látható
- **Desktop**: Balra/jobbra nyilak navigáláshoz
- **Mobile**: Swipe gesztusok (balra/jobbra húzás)
- **Dots**: Pontok a jelenlegi pozíció jelzésére
- **Keyboard**: Arrow keys támogatás

### Grid Layout

- Több termék egyszerre látható rácsos elrendezésben
- Reszponzív oszlopszám:
  - **Mobile (< 480px)**: 1 oszlop
  - **Mobile (480-767px)**: 2 oszlop
  - **Tablet (768px+)**: Auto-fill vagy egyedi beállítás
  - **Desktop (1024px+)**: Egyedi beállítás szerint

### Kategóriák szerinti csoportosítás

Ha a **"Group by Category"** be van kapcsolva:

- **Férfi termékek** külön szekciót kapnak
- **Női termékek** külön szekciót kapnak
- Minden szekció saját carousel/grid layout-tal rendelkezik

## 🔧 Technikai részletek

### Fájlstruktúra

```
/mockup-generator/
├── includes/
│   ├── class-pattern-showcase-manager.php      # Backend logika, CRUD
│   ├── class-pattern-showcase-frontend.php     # Frontend megjelenítés
│   └── class-pattern-showcase-api.php          # REST API Gutenberg blockhoz
├── admin/
│   └── class-pattern-showcase-page.php         # Admin interface
├── assets/
│   ├── css/
│   │   ├── pattern-showcase.css                # Frontend CSS
│   │   └── pattern-showcase-admin.css          # Admin CSS
│   └── js/
│       ├── pattern-showcase.js                 # Frontend JS (carousel/grid)
│       └── pattern-showcase-admin.js           # Admin JS
└── blocks/
    └── pattern-showcase/
        ├── block.json                           # Block definíció
        ├── index.js                             # Block editor JS
        ├── editor.css                           # Block editor CSS
        ├── style.css                            # Block frontend CSS
        └── package.json                         # NPM dependencies
```

### Adattárolás

A showcasek a WordPress `wp_options` táblában tárolódnak:

**Option name**: `mg_pattern_showcases`

**Struktúra**:

```php
[
    'showcase_abc123' => [
        'id'                => 'showcase_abc123',
        'name'              => 'Hellfire Club Collection',
        'design_file'       => 123,  // WP attachment ID
        'product_types'     => ['ferfi-polo', 'noi-polo', ...],
        'color_strategy'    => 'first',
        'custom_colors'     => ['ferfi-polo' => 'fekete', ...],
        'layout'            => 'carousel',
        'columns'           => 4,
        'group_by_category' => true,
        'mockups'           => [
            'ferfi-polo_fekete' => 456,  // WP attachment ID
            'noi-polo_feher'    => 457,
            // ...
        ],
        'created'           => '2025-11-21 12:00:00',
        'modified'          => '2025-11-21 12:00:00',
        'last_generated'    => '2025-11-21 12:30:00'
    ]
]
```

### REST API Endpoints

**Gutenberg block használja:**

- `GET /wp-json/mockup-generator/v1/pattern-showcases` - Összes showcase listázása
- `GET /wp-json/mockup-generator/v1/pattern-showcases/{id}` - Egy showcase lekérése

### AJAX Endpoints

**Admin interface használja:**

- `wp_ajax_mg_save_pattern_showcase` - Showcase mentése
- `wp_ajax_mg_delete_pattern_showcase` - Showcase törlése
- `wp_ajax_mg_generate_showcase_mockups` - Mockupok generálása
- `wp_ajax_mg_get_pattern_showcase` - Showcase lekérése

## 📱 Mobile Optimalizálás

### Touch Gestures

- **Swipe Left**: Következő slide (carousel)
- **Swipe Right**: Előző slide (carousel)
- **Tap on dots**: Ugrás az adott slide-ra
- **Drag**: Húzás az egérrel (desktop)

### Responsive Breakpoints

| Breakpoint | Grid oszlopok | Carousel padding |
|------------|---------------|------------------|
| < 480px    | 1             | 1.5rem           |
| 480-767px  | 2             | 1.5rem           |
| 768-1023px | Auto-fill     | 3rem             |
| 1024px+    | Custom        | 4rem             |

### Performance

- **Lazy loading** - Képek csak viewport-ba kerüléskor töltődnek
- **WebP formátum** - Kisebb fájlméret
- **Thumbnail variánsok** - Medium méret használata grid nézetben
- **CSS transforms** - Hardware accelerated animációk

## 🎯 Termék kategorizálás

A plugin automatikusan kategorizálja a termékeket a nevük alapján:

**Férfi kategória triggerek:**
- `ferfi`, `férfi`, `men`, `male`

**Női kategória triggerek:**
- `noi`, `női`, `women`, `female`, `woman`

**Egyéb:**
- Ha egyik sem illik, akkor **"Other"** kategóriába kerül

## 🐛 Hibakezelés

### Hibaüzenetek

- **"Showcase ID is required"** - Hiányzik a showcase ID a shortcode-ból
- **"Pattern Showcase not found"** - Nem létezik a megadott ID-jú showcase
- **"No mockups generated yet"** - Még nem lettek generálva mockupok
- **"Design file not found"** - A design fájl nem található
- **"Mockup template not found"** - A termék mockup template nem található

### Logging

A mockup generálás során fellépő hibák a `$result['errors']` tömbben visszaadásra kerülnek.

## 🔄 Verziófrissítések

### v1.3.0 - Pattern Showcase Module

**Új funkciók:**
- Pattern Showcase admin interface
- Carousel és Grid layouts
- Mobile touch gesztusok
- Gutenberg block támogatás
- Termék kategorizálás (Férfi/Női)
- Shortcode támogatás
- REST API endpoints

## 💡 Tippek és Trükkök

### 1. Optimális design fájl méret

A legjobb teljesítmény érdekében:
- **Felbontás**: 2000x2000px vagy kisebb
- **Formátum**: PNG alfa csatornával
- **Fájlméret**: < 1MB

### 2. Grid oszlopszám kiválasztása

- **2-3 oszlop**: Részletes termékek esetén
- **4-5 oszlop**: Sok termék gyors áttekintéséhez
- **6 oszlop**: Nagy képernyőkön, egyszerű mockupok esetén

### 3. Színstratégia választás

- **First color**: Gyors showcase kis termékszámmal
- **Custom color**: Ha konkrét színeket szeretnél kiválasztani
- **All colors**: Komplex showcase sok színnel (több mockup)

### 4. Performance optimalizálás

Ha sok mockup van (50+):
- Használj Grid layout-ot Carousel helyett
- Állítsd kisebbre az oszlopszámot mobilon
- Használj Lazy loading-ot (automatikus)

## 📞 Support

Ha problémád van a modullal:

1. Ellenőrizd, hogy a plugin verziója **1.3.0** vagy újabb
2. Ellenőrizd, hogy a **WooCommerce** aktív
3. Ellenőrizd, hogy a termékekhez van-e mockup template beállítva
4. Nézd meg a böngésző konzolt JavaScript hibákért
5. Nézd meg a WordPress debug log-ot PHP hibákért

---

**Készítette**: Claude
**Verzió**: 1.3.0
**Utolsó frissítés**: 2025-11-21
