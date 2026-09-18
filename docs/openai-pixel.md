# ChatGPT Ads / OpenAI Pixel

A 2.38.5 verzió a `BjMWTWbs5kDjdhkt1jY7j2` pixelt használja. A bővítmény frissítése
és az oldalgyorsítótár ürítése után a meglévő `MG_Consent_Bridge` marketing
hozzájárulása engedélyezi a betöltést. Ismeretlen vagy tiltott hozzájárulásnál
az SDK sem töltődik be; visszavonáskor `oaiq('consent', false)` állítja le.
Ugyanezt a pixelt ne telepítsd külön GTM-ben vagy a sablon fejlécében is.

Események:

- `page_viewed`: oldalmegtekintés, oldalanként egyszer.
- `contents_viewed`: termékoldal, termékazonosítóval; a változó virtuális ár miatt induló ár nélkül.
- `items_added`: sikeres WooCommerce kosárba helyezés után, a munkamenetből,
  a tényleges mennyiséggel és az aktuális, adóval növelt egységárral. AJAX,
  blokkos és hagyományos átirányítással működő kosárba helyezés támogatott.
  Az esemény legfeljebb öt percig vár a munkamenetben.
- `checkout_started`: pénztároldal, a kosár aktuális végösszegével.
- `order_created`: a köszönőoldalon, `processing` vagy `completed` állapotú
  rendelésnél, a végösszeggel és a rendelés pénznemével. Az adatlekéréshez
  a rendelés titkos kulcsa szükséges. A sikertelen/törölt rendelés nem konverzió.

A vásárlás eseményazonosítója `mg_openai_order_<order_id>`. A böngésző a küldést
helyi tárolóban is megjegyzi, így a köszönőoldal frissítése nem ismétli meg.
Ha az SDK betöltését blokkolják, a vásárlás nem kap elküldött jelölést.
Az SDK hívása nem jelent szerveroldali átvételi visszaigazolást.
A függőben lévő fizetést a megnyitott köszönőoldal kb. egy percig újraellenőrzi.
Későbbi átutalás vagy az oldal elhagyása utáni fizetés visszatérés nélkül nem
mérhető megbízhatóan ezzel a böngészős integrációval.

Az összegek ISO 4217 szerinti legkisebb egységben mennek: **3990 HUF → 399000**,
függetlenül a WooCommerce megjelenítési tizedesjegyeitől.

A `debug` alapból kikapcsolt. Fejlesztői beállítások:

```php
add_filter('mg_openai_pixel_debug', '__return_true'); // Ideiglenes diagnosztika.
add_filter('mg_openai_pixel_id', '__return_empty_string'); // Kikapcsolás.
```

## Éles ellenőrzés

1. Frissítsd a bővítményt és ürítsd a gyorsítótárat.
2. Privát böngészőablakban elutasított marketing sütikkel ne legyen OpenAI SDK-kérés.
3. Elfogadás után ellenőrizd az eseményeket az Ads Managerben / böngésző hálózati naplójában.
4. Teszteld a termékoldali és listából történő kosárba helyezést, valamint a pénztárat.
5. Tesztrendelésnél ellenőrizd az összeget és a pénznemet; frissítéskor ne legyen új vásárlás.
6. Ellenőrizd a hozzájárulás visszavonását is. CSP esetén az OpenAI hivatalos
   útmutatójában szereplő domaineket engedélyezni kell a meglévő szabályzatban.

A szerveroldali Conversions API nincs bekötve; ahhoz külön hirdetési API-hitelesítés kell.
Az SDK saját kattintásazonosító-kezelését használjuk; kézi ügyféladat-küldést nem adunk hozzá.

Források:
- https://developers.openai.com/ads/measurement-pixel
- https://developers.openai.com/ads/supported-events

Helyi ellenőrzések: `node tests/openai-pixel-script-test.js`,
`php tests/openai-pixel-test.php`, `tools/check-tracking.ps1` és PHP lint.
Ezek nem helyettesítik az éles Ads Managerben történő ellenőrzést.
