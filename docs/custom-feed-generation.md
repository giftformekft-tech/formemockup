# Egyedi feed generálás (2.37.2)

A létrehozás és a Generálás gomb háttérfeladatot indít, majd azonnal visszatér az adminoldalra. A feldolgozás kérésenként legfeljebb 10 terméket vesz elő, és 5 másodperc elteltével, az aktuális termék befejezése után átadja a munkát a következő adagnak.

- Az adminoldalon látszik a feldolgozott termékek száma és a kész/hiba állapot. Ez a szám a megvizsgált termékek száma, nem az XML tételeinek száma.
- Nyitott adminoldalon a jogosultsággal és nonce-szal védett állapotlekérés is feldolgoz egy adagot. Bezárt adminoldal mellett WP-Cron folytatja a feladatot; letiltott WP-Cron esetén rendszeres külső cron szükséges.
- Frissítés közben a feed URL a korábbi teljes fájlt szolgálja ki. Ha még nincs kész fájl, HTTP 503 és `Retry-After: 60` választ ad; a következő lekérés később újrapróbálható.
- A 24 óránál régebbi fájl lekérése frissítést ütemez. A Google Ads teljesítménycímkék meglévő frissítési hookja szintén elindítja a feldolgozást.
- A munkafolyamat termékazonosítóval és fájlpozícióval menti a folytatási pontot. Megszakításkor a következő adag eltávolítja a nem mentett fájlvéget. Egy feedet egyszerre egy folyamat írhat.
- Három egymást követő megszakítás, illetve írási vagy feldolgozási hiba esetén a feladat hibára áll. Javítás után a Generálás gomb újraindítja. Sikertelen automatikus próbálkozás után 5 percig nem indul új munka a crawler lekéréseire.
- A meglévő `uploads/mg_feeds` mappának írhatónak kell lennie. A `.lock` fájlokat használat közben nem szabad törölni.

## Helyi ellenőrzés

```text
php tests/custom-feed-batch-test.php
node tests/custom-feed-progress-test.js
php tests/google-ads-product-performance-test.php
php tests/google-ads-product-performance-state-test.php
```

A regressziós tesztek valódi fájlműveleteket és helyettesített WordPress/WooCommerce függvényeket használnak. Az éles szerver időkorlátját, a valódi katalógus sebességét és a cron konfigurációját külön kell ellenőrizni.
