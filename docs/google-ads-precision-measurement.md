# Google Ads tűpontos vásárlásmérés – üzembe helyezés

Ehhez a pluginban nincs külön előfizetés, a Google Ads API használata pedig díjmentes. A Google Ads hirdetési költés és a webáruház szokásos üzemeltetési költsége ettől független.

## 1. Böngészős konverzió

1. A Google Adsben nyisd meg a **Célok → Konverziók → Vásárlás** műveletet.
2. A plugin **Mockup Generator → Google Ads mérés** oldalán add meg:
   - a Google tag azonosítóját (`AW-...`);
   - a Vásárlás konverziós címkéjét;
   - a mérhető WooCommerce-rendelésállapotokat.
3. Ugyanahhoz a Purchase művelethez más plugin ne küldjön vásárlást.

## 2. Data Manager API

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

Az admin diagnosztika elvárt végállapota szerveroldali küldésnél `processed`. A `skipped_no_consent` nem technikai hiba: azt jelenti, hogy a szerveroldali felhasználóiadat-küldéshez nem volt eltárolt hozzájárulás. A böngészős Consent Mode mérés ettől még működhet modellezett/cookie nélküli módban.
