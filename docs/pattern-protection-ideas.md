# Ötletek a minták letöltésének nehezítésére

Az alábbiakban olyan frontendes és szerveroldali megoldások szerepelnek, amelyek segíthetnek a minta PNG-k jogosulatlan letöltésének megnehezítésében. A legtöbb ötlet kombinálható egymással, így érdemes egy védelmi réteges megközelítést kialakítani.

## Frontendes rétegek

- **Erősebb vízjel**: a jelenlegi ismétlődő vízjel mellé vagy helyett a design kulcsfontosságú részeire illesztett diagonális, kontrasztos felirat; opcionálisan variációnként eltérő szöveggel, így kiszivárgás esetén visszakövethető a forrás.
- **Vágott / részleges minta**: a teljes PNG helyett csak egy kivágást vagy csökkentett méretű („proof”) verziót mutatni a modálban, a teljes felbontású fájlt pedig csak megrendelés után kiszolgálni.
- **Canvas alapú render**: a mintát `<canvas>`-re rajzolva, majd a vízjelet és egy enyhe zajréteget is ott generálva; ezzel a direkt képmentés (jobb klikk, képként mentés) kevésbé hatékony, mert a PNG nem egy külön URL.
- **Blokkolt interakciók**: jobb klikk tiltása, drag&drop és másolás letiltása már él; kiegészíthető azzal, hogy a modálban kikapcsoljuk a pinch-zoomot és a kontextusmenü billentyűparancsait is.
- **CSS-noise/overlay**: a vízjel felett egy halvány zaj- vagy rács-overlay, ami megnehezíti az automatikus vízjel-eltávolítást, de még nem zavaró a szemnek.

## Kiszolgálási kontroll

- **Aláírt, rövid élettartamú URL-ek**: a mintákat időkorlátos, aláírt URL-en kiszolgálni (pl. query token vagy CloudFront Signed URL), így a linkek gyorsan érvényüket vesztik és nem ágyazhatók be könnyen.
- **Referer / domain ellenőrzés**: a képeket csak a saját domainről érkező kérésekre kiszolgálni; hotlink esetén alacsony felbontású vagy vízjelezett fallback-et adni.
- **Throttle és rate limit**: ugyanarról a kliensről érkező tömeges letöltések korlátozása, gyanús mintát látva a token élettartamának csökkentésével.

## Üzleti/logikai megoldások

- **Vízjel nélküli verzió csak rendelés után**: a végleges, tiszta PNG-t csak fizetett rendelés után (vagy PDF bizonyítékként) elérhetővé tenni, ügyfélfiókhoz kötve.
- **Nyomkövető metaadat**: minden letöltött PNG-be beégetett, ügyfélhez kötött mikrovonal-kód vagy metaadat (pl. steganográfia, EXIF), amely visszakövethető.
- **Jogosultság szerepkörökhöz**: a nagy felbontású minta megjelenítését csak bejelentkezett, jogosult felhasználóknak engedélyezni, a nyilvános nézetben csak kisméretű proof jelenjen meg.

## Monitorozás

- **Vízjel variáció visszakövetése**: véletlenszerű, variációhoz kötött vízjel felirat vagy szín használata, így ha egy minta kiszivárog, azonosítható, melyik termékváltozathoz vagy sessionhöz tartozott.
- **Anonim analitika**: események gyűjtése a gyanús műveletekről (pl. sokszori modál megnyitás, nagyítás próbálkozások), hogy időben reagálhassunk.
