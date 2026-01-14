# Regeneráció – hibaelemzés és működési javaslat

## Hibaelemzés (miért romlik el vagy duplikálódik)

1. **Versenyhelyzet a queue feldolgozásnál**
   - Ha egyszerre indul több cron, ugyanazt a batch-et dolgozhatják fel. Ez duplikált regenerációt, terhelést és felesleges fájlképzést okoz.
2. **Orphan fájlok és attachmentek**
   - Termék/variáns törlésnél, illetve típus- és színváltozásnál az előző mockupok nem mindig törlődnek, így maradnak „árva” fájlok/attachmentek.
3. **Nincs központi, idempotens generálási folyamat**
   - Ha a generálás nem idempotens (ugyanarra a bemenetre mindig ugyanazt az eredményt kapjuk, és nem keletkezik új fájl), akkor a legkisebb változás is új fájlt hoz létre.
4. **Hiányos differencia-alapú frissítés**
   - Ha nincs pontos összevetés (milyen típus/szín/méret létezett korábban és mi az új állapot), akkor a rendszer nem tudja biztonságosan eldönteni, mit kell létrehozni, átnevezni, törölni.

## Elvárt viselkedéshez igazított működési javaslat

### 1) Állapotmodell és differencia (diff) alapú regeneráció
**Cél:** amikor változik a terméktípus/szín/méret, akkor:
- **Átnevezések** kövessék a változást (slug, név, érték).
- **Új elemek** (új szín/méret) megkapják a hiányzó mockupokat.
- **Törlések** eltávolítsák a variánsokat és a kapcsolódó képeket/attachmenteket.

**Javaslat:**
- Minden regeneráció előtt készül egy **előző állapot snapshot** (pl. `old_index`) és egy **aktuális állapot** (`new_index`).
- A rendszer három műveletlistát készít:
  1. **Create**: új kombinációk (típus/szín/méret).
  2. **Update/Rename**: azonos képparaméterek, de változott slug/név.
  3. **Delete**: már nem létező kombinációk.
- A queue csak a **Create + Update** listát dolgozza, a **Delete** listát pedig külön, törlés-orientált folyamattal kezeli (törlés + attachment + file).

### 2) Idempotens generálás és deduplikáció
**Cél:** ne legyenek duplikált fájlok.

**Javaslat:**
- A generált fájlokból **hash alapján deduplikáció** történjen (manifest).
- A generátor mindig ugyanazt az alap mockup- és design-készletet használja:
  - **Alap design**: a bulk feltöltésből egyszer feltöltött alap minta.
  - **Mockup template**: a már feltöltött mockup háttérfájlok.
  - Generáláskor a hiányzó variáns kép készül el, de ha identikus, akkor **manifest alapján újrahasználja** a korábbit.

### 3) Működési flow (ajánlott)
1. **Input változás érzékelése**
   - Terméktípus/szín/méret változás → új diff készítés.
2. **Queue műveleti bontás**
   - Create/Update → feldolgozó queue.
   - Delete → azonnali (vagy külön queue) törlési lépés.
3. **Generálás**
   - Mockup file generálása → manifest dedupe → index frissítés.
4. **Index & attachment karbantartás**
   - Törléskor: attachment + fájl törlés.
   - Módosításkor: régiek leváltása, új útvonal mentése.

**Javasolt implementációs leképezés a jelenlegi rendszerre:**
- **Input változás érzékelése:** `handle_product_catalog_update()` + `MG_Variant_Maintenance::handle_catalog_update()` figyeli a típust, színt, méretet.
- **Create/Update queue:** `queue_multiple_for_regeneration()` hozza létre a feldolgozandó elemeket.
- **Delete lépés:** `purge_index_entries_for_type()` végzi a törlést és attachment takarítást.
- **Generálás:** `process_queue()` → `process_single()` → `MG_Generator::generate_for_product()` → manifest dedupe.

### 4) Új terméktípus hozzáadása meglévő termékekhez
**Elvárt viselkedés:** a már generált termékekhez később is hozzáadható legyen az új típus, és készüljenek el a hiányzó képek.

**Javaslat:**
- A terméktípus beállításánál legyen egy **„Hozzáadás meglévő termékekhez”** opció.
- Ha ez aktív, akkor a diff alapú folyamat:
  - megkeresi, mely termékeknél hiányzik az új típus,
  - queue-ba teszi a hiányzó kombinációkat,
  - nem regenerálja az összes meglévőt, csak a hiányzókat.

### 5) Stabil queue és terhelés menedzsment (nagy katalógusnál)
**Cél:** kontrollált terhelés, kiszámítható futás.

**Javaslat:**
- Használj dedikált **Action Scheduler**-t (WooCommerce beépítetten használja).
- Lockolás: futás közben egyetlen worker dolgozzon egy batch-en.
- Batch méret konfigurálható legyen (már létezik).
- Retry stratégia: sikertelen elemek visszahelyezése a queue végére korlátozott újrapróbálkozással.

## Összefoglaló elvárt működés (röviden)
- **Változás → diff → célzott generálás** (nem full regen).
- **Új elem → hiányzó generálás**.
- **Törlés → variáns + fájl + attachment törlés**.
- **Átnevezés → variáns/metadata frissítés**.
- **Deduplikáció → nem keletkezik duplikált fájl**.
