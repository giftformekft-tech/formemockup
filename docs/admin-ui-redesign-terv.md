# Mockup Generator – Admin UI egységesítési és modernizálási terv

Dátum: 2026-07-19 · Státusz: TERV (kódolás még nem történt)
Alapelv: **a funkciók nem változnak** – csak a navigáció, az elrendezés és a vizuális réteg.

---

## 1. Jelenlegi állapot (feltérképezés eredménye)

Ma **két párhuzamos navigációs rendszer** él egymás mellett, eltérő tartalommal:

### A) A plugin felső „shell" felülete (admin.php?page=mockup-generator)

SPA-szerű, modern tabos felület (`admin/class-admin-page.php` + `assets/css/admin-ui.css` + `assets/js/admin-ui.js`), 10 tabbal:

| Tab | Típus |
|---|---|
| Dashboard | beágyazott legacy oldal |
| Mockupok | natív shell nézet |
| Bulk feltöltés | natív shell nézet |
| Temu Export | natív shell nézet |
| Variánsok | beágyazott legacy oldal |
| Felárak | beágyazott legacy oldal |
| Mennyiségi kedvezmény | beágyazott legacy oldal |
| Egyedi mezők | beágyazott legacy oldal |
| Beállítások | beágyazott legacy oldal |
| Logok | **üres placeholder** |

### B) Bal oldali WP admin menü („Mockup Generator" alatt) – 17 almenüpont

| Almenü | Helyzet |
|---|---|
| Dashboard | duplikátum (shellben is ott van) |
| Beállítások | duplikátum |
| Termék: szerkesztés | duplikátum (shell „Mockupok" tabja fedi) |
| Egyedi mezők | duplikátum |
| Variáns megjelenítés | duplikátum (a shellben „Variánsok" néven!) |
| Feláras opciók | duplikátum (a shellben „Felárak" néven!) |
| Mennyiségi kedvezmény | duplikátum |
| Cross-sell | **csak itt érhető el** |
| Ajándékkereső | **csak itt érhető el** |
| Duplikált termékek | **csak itt érhető el** |
| AI Minta SEO | **csak itt érhető el** |
| Google Ads mérés | **csak itt érhető el** |
| Meta mérés | **csak itt érhető el** |
| Egyedi Feeder | **csak itt érhető el** |
| Maintenance | **csak itt érhető el** |
| Design Path Migration | **csak itt érhető el** (egyszeri migrációs eszköz) |

Fordítva is igaz: a **Bulk feltöltés** és a **Temu Export** *csak* a shellben létezik, a bal oldali menüből nem érhető el.

### C) Teljesen máshol lévő oldal

- **MG Migration** – az Eszközök (Tools) menü alatt (`admin/class-migration-page.php`, `add_management_page`), egyszeri „mg_products → global config" migrációhoz.

### Feltárt inkonzisztenciák

1. **Két navigáció, két halmaz** – 9 funkció kimarad a shellből, 7 duplán szerepel, 2 csak a shellben van. Ez a felhasználói panasz gyökere.
2. **Kétféle megjelenés ugyanarra az oldalra** – a shellbe ágyazva modern `mg-panel` keret, a sidebarból nyitva klasszikus WP `.wrap` markup.
3. **A 9 kimaradó oldal mind klasszikus `.wrap`** felületű, egyenként más-más CSS-sel (`mockup-maintenance.css`, `mg-crosssell.css`, inline stílusok, vagy semmi).
4. **Következetlen címkék** – „Variáns megjelenítés" vs. „Variánsok", „Feláras opciók" vs. „Felárak".
5. **Vegyes capability-k** – `edit_products` / `manage_options` / `manage_woocommerce` látható rendszer nélkül keveredik.
6. **Vegyes text domainek** – `mockup-generator`, `mgvd`, `mgcf`, illetve fordítatlan hardkódolt stringek.
7. **Kaotikus menüsorrend** – a sorrendet a fájlbetöltési sorrend adja, nem tudatos rendezés.
8. **Fejlesztői/egyszeri eszközök a normál menüben** – Design Path Migration, MG Migration, Maintenance ugyanolyan súllyal jelenik meg, mint a napi munkához használt oldalak.
9. **Halott elem** – a „Logok" tab üres placeholder.

---

## 2. Célkép: egyetlen egységes felület

### 2.1 Navigációs architektúra

**Egy belépési pont marad: a „Mockup Generator" felső menü + shell.** Minden funkció a shellbe kerül, a bal oldali almenü pedig a shell fő csoportjaira mutató, kézzel rendezett deep-link lista lesz (a WP-konvenció megtartása miatt nem szüntetjük meg teljesen a sidebart).

Mivel 19 tab nem fér el egy sorban, a navigáció **kétszintű** lesz: elsődleges csoportok (sidebar + shell fő nav) és másodlagos al-tabok a csoporton belül.

```
Mockup Generator
├── 📊 Dashboard
├── 🎨 Mockupok            → Mockupok | Termék szerkesztés | Bulk feltöltés | Duplikált termékek
├── 🛒 Értékesítés          → Variánsok | Felárak | Mennyiségi kedvezmény | Cross-sell | Ajándékkereső | Egyedi mezők
├── 📣 Marketing & Mérés    → Google Ads mérés | Meta mérés | AI Minta SEO
├── 📦 Export & Feedek      → Temu Export | Egyedi Feeder
├── 🛠 Eszközök             → Maintenance | Design Path Migration | Global Config migráció | (Logok*)
└── ⚙️ Beállítások
```

\* A Logok tab döntést igényel: vagy tényleges log-nézet készül az Eszközök csoportban, vagy töröljük, amíg nincs mögötte funkció. **Javaslat: törlés most, külön feladatként visszahozható.**

- A bal oldali almenü tételei `admin.php?page=mockup-generator&mg_tab=<csoport>` linkek – így a WP menü kiemelése és a shell állapota mindig szinkronban van.
- A **MG Migration** átköltözik az Eszközök menüből az „Eszközök" csoportba (a régi Tools-oldal redirectet kap).
- Az egyszeri migrációs eszközök (Design Path Migration, Global Config migráció) csak akkor jelennek meg, ha van még migrálandó adatuk; egyébként rejtve maradnak vagy „elvégezve" státusszal jelennek meg.

### 2.2 Visszafelé kompatibilitás (funkcióvédelem)

- **Minden régi slug megmarad**: a régi almenü-slugokra érkező kérés (`mockup-generator-variant-display`, `mg-gads-settings`, `tools.php?page=mg-migration` stb.) szerver oldali redirecttel a megfelelő shell-nézetre kerül. Könyvjelzők, e-mailben küldött linkek nem törnek el. (A mechanizmus fele már létezik: `get_legacy_slug_map()` + `rewriteLegacyLinks()`.)
- **Form actionök, AJAX endpointok, option nevek, nonce-ok változatlanok** – a render-metódusok tartalmát nem írjuk át, csak új wrapperbe kerülnek.
- **Capability-k oldalanként változatlanok** (aki ma lát egy oldalt, ezután is pontosan azt látja; aki nem, továbbra sem). A csoport-almenü a benne lévő legmegengedőbb capability-vel jelenik meg, a tabok egyenként a saját joguk szerint szűrődnek.

### 2.3 A 9 kimaradó oldal beemelése a shellbe

A már bevált „legacy panel" mintával (`get_legacy_callbacks()`): a Cross-sell, Ajándékkereső, Duplikált termékek, AI Minta SEO, Google Ads mérés, Meta mérés, Egyedi Feeder, Maintenance, Design Path Migration és MG Migration render-függvényei bekerülnek a tab-registrybe. A `.wrap` külső keretük lecserélődik a `mg-panel-body` + `mg-panel-section` szerkezetre – a belső űrlapok érintetlenek.

---

## 3. Vizuális modernizálás (design rendszer)

Az `admin-ui.css` már tartalmaz token-alapokat (`--mg-bg`, `--mg-radius`, `--mg-shadow`…). Ezt fejlesztjük teljes rendszerré:

### 3.1 Tokenek
- Színskála: alap / felület / szegély / szöveg / halvány szöveg + szemantikus színek (siker, figyelmeztetés, hiba, info) – a WP admin kék (`#2271b1`) akcentussal, hogy ne üssön el a wp-admintól.
- Tipográfia: egységes címsor-skála (page title 24px, section 16–18px, label 13px), egységes sortávok.
- Spacing-skála (4/8/12/16/20/24/32), egységes `--mg-radius` (12px) és árnyékok.

### 3.2 Komponens-készlet (minden oldal ezekből épül)
- **Page header**: csoportnév + rövid leírás + elsődleges akciógomb(ok) – a mostani shell-fejléc mintájára.
- **Section card**: `mg-panel-section` fejléc (cím + magyarázó sor) + törzs; ez váltja a `.wrap` + `<h1>` + nyers `<table class="form-table">` kombókat.
- **Save bar**: a meglévő ragadós mentősáv kiterjesztése minden űrlapos nézetre (dirty-state figyelés már van a JS-ben).
- **Táblázatok**: egységes lista-stílus (zebra, hover, jobbra igazított akciók) a Temu Export / Dedup / Feeder táblákhoz.
- **Státusz badge-ek**: pl. „exportálva", „aktív", „migráció kész".
- **Üres állapot** komponens: ikon + egy mondat + CTA (pl. Cross-sell szabály még nincs).
- **Notice-ok**: a WP `notice` osztályok megtartása (kompatibilitás), de a shellen belül egységes elhelyezéssel a page header alatt.

### 3.3 Reszponzivitás és akadálymentesség
- A csoport-nav mobilon lenyílóvá/görgethetővé válik; a panelek 1 hasábra törnek.
- A meglévő `role="tab"` / `aria-labelledby` minta kiterjesztése a másodlagos tabsorra; fókuszkezelés a tabváltásnál; billentyűs navigáció (nyilak a tabsoron).

### 3.4 CSS-konszolidáció
- Cél: **egy** admin stíluslap (`admin-ui.css`) tokenekkel; az oldalspecifikus CSS-ek (`mockup-maintenance.css`, `variant-display-admin.css`, `mg-crosssell.css` admin-részei, `admin.css`) fokozatosan a tokenekre állnak át, a duplikált szabályok kikerülnek.
- Verziózás egységesen `MG_VERSION`-nel (most keveredik: filemtime, '1.2.55', '1.0.0').

### 3.5 Szöveg-egységesítés
- Egy funkció = egy név mindenhol (javaslat: **Variánsok**, **Felárak**, a sidebar-címkék igazodnak a shell-hez).
- Text domain egységesen `mockup-generator` (`mgvd`, `mgcf` lecserélése – a megjelenő szöveg jelentése nem változik).
- A félig angol címek magyarítása: „Maintenance" → „Karbantartás", „Design Path Migration" → „Design útvonal migráció".

---

## 4. Megvalósítási fázisok

| Fázis | Tartalom | Kockázat |
|---|---|---|
| **F1 – Menükonszolidáció** | Csoportosított sidebar (7 tétel a 17 helyett), duplikátumok megszüntetése, régi slugok → redirect, címke-egységesítés, MG Migration átköltöztetése, Logok tab eltávolítása | alacsony – csak regisztráció és redirect |
| **F2 – Oldalak beemelése** | A 9+1 kimaradó oldal shellbe integrálása legacy-callback mintával, `.wrap` → `mg-panel` csere, kétszintű nav a shellben | közepes – oldalankénti smoke-test kell (mentés, AJAX) |
| **F3 – Design rendszer** | Tokenek bővítése, komponensek (section card, táblák, badge-ek, üres állapotok, save bar mindenhol), CSS-konszolidáció, reszponzív + a11y finomítás | alacsony – csak vizuális réteg |
| **F4 – Polírozás** | Dashboard modernizálás (statisztika kártyák + gyorslinkek az új csoportokra), migrációs eszközök feltételes megjelenítése, text domain rendezés | alacsony |

Minden fázis önállóan szállítható; F1 után már megszűnik az „össze-vissza menü" érzés, F2 után egyetlen egységes felület van.

### Ellenőrzési checklist fázisonként
- minden oldal betölt és **ment** (form POST célok változatlanok),
- AJAX műveletek működnek (bulk, Temu export, dedup, AI SEO),
- capability-tesztek: shop manager (`manage_woocommerce`) és admin (`manage_options`) is pontosan azt látja, amit eddig,
- régi URL-ek redirectje működik.

---

## 5. Ami garantáltan NEM változik

- üzleti logika, generálás, exportok, feedek, mérőkódok viselkedése,
- option nevek, adatstruktúrák, AJAX endpointok, nonce-ok,
- jogosultsági szintek oldalanként,
- frontend (vásárlói) felület – a terv kizárólag a wp-admin oldalt érinti.

## 6. Nyitott döntések

1. **Logok tab**: törlés most (javasolt), vagy valódi log-nézet fejlesztése az Eszközök csoportban?
2. **Csoportnevek/ikonok** véglegesítése (a fenti javaslat szabadon átnevezhető).
3. **Migrációs eszközök**: rejtsük-e automatikusan, ha nincs migrálandó adat, vagy maradjanak mindig láthatók „kész" jelöléssel?
