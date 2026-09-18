# Egyedi feed generálás (2.38.9)

A GPT-feedeket külön `mg_openai_feeds_daily_refresh` WP-Cron esemény frissíti
24 óránként. A meglévő feedekhez is automatikusan létrejön a frissítés utáni
első WordPress-kéréskor; az első napi futás ettől számítva 24 óra múlva esedékes.
Új feed létrehozáskor továbbra is azonnal generálódik. A napi feladat csak a GPT
feedeket ütemezi, és nem kezdi újra az éppen futó generálást. Az utolsó GPT-feed
törlése vagy a bővítmény kikapcsolása megszünteti ezt a napi ütemezést.
A közvetlen CSV-link változatlan, és a kész fájl sikeres cseréjével frissül.
A futás WP-Cront igényel; letiltott WP-Cronhoz külső cron kell, látogatásalapú
WP-Cron esetén az esedékesség utáni első kérés indíthatja a feladatot.

## OpenAI / ChatGPT CSV

A Mockup Generator → Egyedi feedek → Új Feed Létrehozása űrlapon a Formátum
mezőben választható az **OpenAI / ChatGPT (CSV)**. Ugyanaz a terméktípus- és
kategóriaszűrés, nem- és korcsoport-beállítás használható, mint az XML feedeknél.
A kész sorban a **CSV letöltése** gomb adja a fájlt. A feed URL változatlan
szerkezetű (`?mg_custom_feed=<slug>`), CSV tartalomtípussal és `.csv` fájlnévvel.

Az export UTF-8, vesszővel elválasztott, szabványosan idézőjelezett CSV.
Az OpenAI saját mezőneveit használja (`item_id`, `url`, `image_url`, `seller_name`),
keresési és hirdetési jogosultsággal; a ChatGPT-n belüli checkout nincs engedélyezve.
Az ár normál pénzegységben szerepel (`3990.00 HUF`), nem a pixel százados egységeiben.
A kötelező leírás, név, pozitív ár vagy HTTPS termék-/képlink hiányakor csak az
érintett ajánlat marad ki. Az állapot mutatja az exportált és kihagyott ajánlatok
számát, valamint legfeljebb öt tételnél a termékazonosítót, típust és hibás mezőt.
Üres vagy csak HTML-t tartalmazó rövid leírás helyett a teljes leírást használja.
Ha minden ajánlat hibás, a generálás hibára áll és megőrzi a korábbi kész feedet.

A meglévő katalógusmodell szerint mintánként és terméktípusonként egy ajánlat
készül (`SKU_típus`, SKU nélkül `ID_<termékazonosító>_típus`). Az OpenAI Pixel
ugyanezeket az azonosítókat használja a virtuális termékekhez. Szín-/méretváltozatonkénti
bontást és variánscsoportokat ez az export nem állít elő; ilyen importhoz külön
variánsexport szükséges. A meglévő előnézeti képeket használja, nem generál új képeket.

A CSV létrehozása nem tölti fel automatikusan az OpenAI-fiókba, és nem indít
hirdetést. A hozzáférés és az import/feltöltés külön beállítás.
Az OpenAI szabványos feltöltési útja jelenleg amerikai piacra alapértelmezett;
a magyar célpiac és a HUF elfogadását a fiókhoz engedélyezett integrációnál
külön ellenőrizni kell. A CSV pénzneme önmagában nem állít célországot.

Hivatalos séma: https://developers.openai.com/commerce/specs/file-upload/products

## Háttérgenerálás

A létrehozás és a Generálás gomb háttérfeladatot indít, majd azonnal visszatér az adminoldalra. A feldolgozás kérésenként legfeljebb 100 terméket vesz elő, és 5 másodperc elteltével, az aktuális termék befejezése után átadja a munkát a következő adagnak. A 100-as adagméret minden formátumra és a már futó generálások következő adagjára is érvényes; lassú termékfeldolgozás esetén az időkorlát miatt kevesebb termék férhet egy adagba.

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
