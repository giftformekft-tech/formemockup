# Meta Pixel és CAPI – pontos mérés

## Működés

- A Pixel és a Conversions API ugyanazt a WooCommerce-rendelésszámot használja `event_id`-ként.
- A Purchase csak a beállított, alapértelmezetten `processing` vagy `completed` rendeléseknél kerül mérésre.
- A böngészős Pixel Advanced Matching adatai már az első Pixel-inicializáláskor rendelkezésre állnak; a Pixel nem inicializálódik kétszer ugyanazzal az azonosítóval.
- A CAPI-küldés Action Schedulerrel, ennek hiányában WP-Cronnal fut, és legfeljebb hatszor próbálkozik.
- Sikeresnek csak olyan Meta-válasz számít, amely legalább egy fogadott eseményt jelez.
- A szerveroldali felhasználóadatok csak eltárolt `granted` marketing-consent esetén kerülnek elküldésre.

## Virtuális termékadatok

A Meta katalógus, a Pixel és a CAPI azonos formátumú termékazonosítót használ:

`alap-SKU_virtualis-tipus-slug`

A Purchase tartalmazza a rendelés teljes értékét, pénznemét, kedvezményét, rendelési azonosítóját, valamint tételenként az ID-t, mennyiséget és kedvezmény utáni egységárat.

## Attribution és egyezési adatok

A checkout a rendeléshez menti az `_fbp`, `_fbc` és `fbclid` adatokat, továbbá a hozzájárulási állapotot, az IP-címet és a user agentet. Ha az `_fbc` cookie még nem készült el, de van `fbclid`, a plugin szabványos `_fbc` értéket épít belőle. Az egyezéshez e-mail, telefon, név, település, régió, irányítószám, ország és stabil külső azonosító használható. Consent nélkül ezek nem kerülnek a CAPI-ba.

## Hozzájárulás felismerése

A Pixel minden eseménye és a CAPI-küldés is megadott marketing-hozzájáruláshoz kötött. A consent híd (`MG_Consent_Bridge`) automatikusan felismeri a WP Consent API-t, a Complianzot, a CookieYes-t, a Cookiebotot, a Borlabs Cookie-t, az Iubendát, a Moove GDPR-t, a Cookie Notice-t és a Real Cookie Bannert, továbbá bármely Consent Mode `ad_storage` frissítést a `dataLayer`-ben. Egyedi banner esetén elég `mg_gads_consent` eseményt küldeni `{granted: true}` detaillel.

Ha a diagnosztikában minden rendelésnél „ismeretlen” a consent, akkor a sütikezelő nem jut el a mérőmodulokhoz – ilyenkor gyakorlatilag egyetlen Meta-esemény sem megy ki.

## Kimaradt Purchase pótlása

- Ha a rendelés a köszönőoldal betöltésekor még nem mérhető állapotú (átutalás, késve érkező fizetési visszaigazolás), a Pixel Purchase kimarad. A plugin ilyenkor first-party cookie-ba jegyzi a rendelést, és a vásárló következő oldalletöltésekor pótolja az eseményt – ugyanazzal az `eventID`-val, így duplikáció nem keletkezik.
- A köszönőoldal soha nem kerül statikus gyorsítótárba.
- A CAPI-sor óránként önjavító futással ellenőrzi a beragadt rendeléseket (a Meta 7 napos eseményablakán belül), így egy kimaradt cron vagy egy átmeneti Meta-hiba nem jelent végleges veszteséget.
- Reklámblokkoló mellett az `_fbp` böngészőazonosítót – hozzájárulás esetén – a szerver írja meg first-party cookie-ként, hogy a CAPI-esemény egyezési minősége ne essen vissza.
- A diagnosztikában a böngészős oszlop `elküldve` / `pótolva` / `kiírva` / `nincs` értéket mutat; a `kiírva` azt jelenti, hogy a tag kikerült, de a küldés nem igazolt vissza.

## Üzembe helyezés

1. Nyisd meg a **Mockup Generator → Meta mérés** oldalt.
2. Ellenőrizd a Pixel ID-t és a mérhető rendelésállapotokat.
3. A Meta Events Managerben generálj Conversions API access tokent.
4. Add meg a tokent, kapcsold be a szerveroldali mérést, és rövid időre állíts be Test Event Code-ot.
5. Adj le consenttel rendelkező tesztrendelést.
6. Ellenőrizd, hogy a diagnosztikában `test_processed`, a Meta Test Events nézetben pedig azonos böngészős/szerveroldali `event_id` jelenik meg.
7. Töröld a Test Event Code-ot, majd ellenőrizd, hogy az új rendelések `processed` állapotba kerülnek.

Más Meta Pixel vagy CAPI integráció ugyanahhoz a Pixelhez ne küldjön külön Purchase eseményt.
