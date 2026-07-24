# Google Ads pontos vásárlásmérés – API nélkül is

A Google API nem feltétele a jó vásárlásmérésnek. Az elsődleges mérési út a böngészős Google tag: Consent Mode v2, Enhanced Conversions, egyedi tranzakcióazonosító és kosáradatok. A Data Manager API csak opcionális, tartós szerveroldali kiegészítés.

## 1. Böngészős konverzió

1. A Google Adsben nyisd meg a **Célok → Konverziók → Vásárlás** műveletet.
2. A plugin **Mockup Generator → Google Ads mérés** oldalán add meg:
   - a Google tag azonosítóját (`AW-...`);
   - a Vásárlás konverziós címkéjét;
   - a mérhető WooCommerce-rendelésállapotokat.
3. Ugyanahhoz a Purchase művelethez más plugin ne küldjön vásárlást.
4. A Google Ads Google tag beállításainál engedélyezd a felhasználó által megadott adatok használatát és a bővített konverziókat.
5. API nélküli használatnál hagyd kikapcsolva a **Szerveroldali kiegészítés** kapcsolót.

A **Purchase Label kitöltése kötelező**: címke nélkül a `send_to` csak a Google tag azonosítóját tartalmazza, így a Google Ads egyetlen vásárlást sem könyvel el konverzióként. A beállítási oldal ezt külön hibaüzenettel jelzi.

## 1/b. Hozzájárulás és kimaradt konverziók

- A consent híd (`MG_Consent_Bridge`) automatikusan felismeri a WP Consent API-t, a Complianzot, a CookieYes-t, a Cookiebotot, a Borlabs Cookie-t, az Iubendát, a Moove GDPR-t, a Cookie Notice-t és a Real Cookie Bannert, valamint bármely `dataLayer`-be írt Consent Mode frissítést. Enélkül a bővített konverziók és a szerveroldali küldés hozzájárulás híján kimaradnának.
- Ha a rendelés a köszönőoldalon még nem mérhető állapotú (átutalás, késve visszaigazolt fizetés), a böngészős Purchase kimarad. A plugin ilyenkor first-party cookie-ba jegyzi a rendelést, és a vásárló következő oldalletöltésekor pótolja a konverziót ugyanazzal a `transaction_id`-val – a Google duplikációvédelme miatt dupla mérés nem keletkezhet. Ez API nélküli üzemmódban is működik.
- A köszönőoldal soha nem kerül statikus gyorsítótárba.
- Teljes oldalgyorsítótár mellett a saját landing-cookie-nk elmaradhat; ilyenkor a kattintásazonosítót a gtag.js `_gcl_aw` / `_gcl_gb` / `_gcl_gs` cookie-jából pótoljuk.
- Bekapcsolt szerveroldali küldésnél óránkénti önjavító futás állítja újra sorba a beragadt rendeléseket.

## 2. Opcionális Data Manager API

Ha a Google-fiókodban vagy a Cloud projektedben ez nem használható, ezt a teljes fejezetet hagyd ki. A böngészős vásárlásmérés ettől még aktív marad.

1. Hozz létre vagy válassz Google Cloud projektet.
2. Engedélyezd benne a **Google Data Manager API** szolgáltatást.
3. Hozz létre service accountot, és adj neki **Service Usage Consumer** jogosultságot.
4. Készíts JSON-kulcsot a service accounthoz.
5. A service account e-mail-címét add hozzá a Google Ads ügyfélfiókhoz vagy az azt kezelő MCC-fiókhoz.
6. A Google Adsben a Vásárlás konverziós művelethez kapcsold hozzá a további adatforrást, ha ez a fiókban elérhető.
7. A pluginban add meg:
   - Google Ads ügyfélazonosító;
   - szükség esetén MCC/login ügyfélazonosító;
   - a Vásárlás konverziós művelet ID-je (`ctId` az Ads URL-ben);
   - a service account JSON tartalma.
8. Először kapcsold be a **Csak validálás** módot. Hibamentes próba után kapcsold ki, majd indíts újrapróbát a diagnosztikai táblából.

## 3. Refund- és törléskorrekció

1. Hozz létre Google Ads manager (MCC) fiókot, ha még nincs.
2. Az MCC **API Center** oldalán kérj developer tokent.
3. A service accountnak a `adwords` OAuth scope használatához is legyen hozzáférése az Ads-fiókhoz.
4. Add meg a developer tokent, majd kapcsold be a korrekciókat.

A teljes refund vagy törlés `RETRACTION`, a részleges refund `RESTATEMENT` korrekciót hoz létre. A plugin a konverzió feldolgozására hagyott várakozási idő után küldi a korrekciót.

## 4. Éles ellenőrzés

Végezz legalább három külön próbát:

1. sikeres online fizetés marketing consenttel;
2. consent nélküli rendelés;
3. sikeres rendelés, majd részleges és teljes refund.

API nélküli módban az admin diagnosztikában a `browser_only` állapot és a `renderelve` köszönőoldali tag a várt eredmény. A tényleges hálózati küldést Google Tag Assistanttal ellenőrizd: a Purchase eseményben egyezzen a `send_to`, legyen egyedi `transaction_id`, valós `value`/`currency`, és a termékazonosítók egyezzenek a Merchant Center feed azonosítóival.

Szerveroldali küldésnél az elvárt végállapot `processed`. A `skipped_no_consent` nem technikai hiba: azt jelenti, hogy a szerveroldali felhasználóiadat-küldéshez nem volt eltárolt hozzájárulás. A böngészős Consent Mode mérés ettől még működhet modellezett, cookie nélküli módban.
