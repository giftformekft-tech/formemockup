# Ajándékkereső beállítása

## Első beállítás

1. Hozz létre egy WordPress oldalt (például **Ajándékkereső** néven).
2. Az oldal tartalmába illeszd be a `[mg_gift_finder]` shortcode-ot.
3. Nyisd meg a **Mockup Generator → Ajándékkereső** adminoldalt.
4. Válaszd ki az előbb létrehozott céloldalt.
5. Add meg a főoldalon megjeleníthető kategóriakártyákat. Kártyánként választható:
   - a WooCommerce kategória képe (mockup), vagy
   - egy kijelölt hero termék kiemelt képe.
6. A három kérdéshez add hozzá a válaszokat, és minden választ kapcsolj egy WooCommerce termékkategóriához.
7. Mentsd a beállításokat.

## Főoldali blokk

A Gutenberg szerkesztőben keresd az **Ajándékkereső** blokkot. Szabadon áthelyezhető, az oldalsávban pedig beállítható a főcím, a bevezető, a gombszöveg és a megjelenő kategóriák.

Shortcode tartalmakhoz alternatívaként használható:

```text
[mg_gift_finder_teaser]
```

Csak kiválasztott kategóriák megjelenítéséhez:

```text
[mg_gift_finder_teaser category_ids="12,34,56"]
```

## Menülink

Az adminoldal tetején a **Menübe illeszthető link** mellett található másolás gombbal másold ki a céloldal URL-jét, majd ezt add meg a saját menü appban.

## Találati és cross-sell logika

A kereső minden válaszhoz egy WooCommerce kategóriát rendel. Azok a termékek kerülnek előre, amelyek több kiválasztott kategóriához is tartoznak. Ha a vásárló a találatból kosárba tesz egy terméket, a már meglévő Mockup Generator cross-sell szabályok továbbra is automatikusan működnek a kosárban.
