# Forme – végleges közös taglista

Ez az egyetlen kanonikus lista szolgál a mintaelemzéshez, az ajándékkeresőhöz, a belső kereséshez és a kiválasztott SEO-oldalakhoz.

A külső GPT-5 mini csak ebből a listából választhat. A keresési szinonimák (például `szülinap`, `szülinapra`) aliasok legyenek, ne külön tagek.

Nem kanonikus tag: szín, háttérszín, felirat, tipográfia, grafika, illusztráció, rajzolt, retro, vintage, meme, minimalista, konkrét idézet vagy teljes SEO-mondat. Az évszám külön `year` mezőben maradjon.

## Címzett

Anyának  
Apának  
Mamának  
Papának  
Dédinek  
Feleségnek  
Férjnek  
A szerelmemnek  
Egy párnak  
Tesónak  
Gyereknek  
Babának  
Keresztszülőnek  
Barátnak vagy barátnőnek  
Kollégának  
Főnöknek  
Anyósnak  
Apósnak  
Pedagógusnak  
Családnak  
Baráti társaságnak  
Csapatnak  
Cégnek  
Magamnak

## Alkalom

Születésnap  
Névnap  
Karácsony  
Húsvét  
Halloween  
Valentin-nap  
Anyák napja  
Apák napja  
Ballagás  
Óvodai ballagás  
Érettségi  
Diplomaosztó  
Szalagavató  
Esküvő  
Eljegyzés  
Házassági évforduló  
Keresztelő  
Babaköszöntő  
Babaváró  
Tejfakasztó  
Lánybúcsú  
Legénybúcsú  
Nyugdíjazás  
Pedagógusnap  
Nőnap  
Lakásavató vagy költözés  
Csapatépítő  
Céges rendezvény  
Családi rendezvény  
Sportesemény  
Nyaralás  
Fesztivál  
Csak úgy vagy meglepetés

## Ajándékozási és használati cél

Saját kép vagy fotó  
Saját szöveg  
Névre szóló  
Egyedi tervezés  
Páros póló  
Családi póló  
Anya–gyerek  
Apa–gyerek  
Csapatpóló  
Osztálypóló  
Céges póló  
Logózott póló  
Munkaruha  
Rendezvénypóló

## Érdeklődés és téma

Humoros  
Gamer  
Sport  
Horgászat  
Állatok  
Állatbarát  
Filmek és sorozatok  
Zene  
Zenekarok  
Irodalom  
Kávé  
Sör  
Alkohol  
Sütés, főzés vagy grillezés  
Kertészkedés  
Horgolás  
Tetoválás  
Fitness és edzés  
Futás  
Jóga  
Túrázás  
Utazás  
Vadászat  
Buli  
Csillagjegyek  
Mobil és technológia  
Bitcoin és kriptó

## Állatok és természet

Macska  
Kutya  
Ló vagy lovaglás  
Hal  
Cápa  
Méh  
Dinoszaurusz  
Hörcsög  
Kígyó  
Nyuszi  
Rovarok  
Unikornis  
Vidra  
Capybara

## Járművek

Autó  
Busz  
Kamion  
Motor  
Traktor  
Bicikli

## Sportágak

Labdarúgás  
Forma–1  
Karate  
Kézilabda  
Konditerem  
Kosárlabda  
Röplabda  
Úszás  
Vízilabda

## Játékok

Among Us  
Apex Legends  
Brawl Stars  
Clash  
Diablo  
Dota  
Fall Guys  
FNAF  
Fortnite  
GTA  
League of Legends  
Minecraft  
Overwatch  
Palworld  
PUBG  
Roblox  
Stumble Guys  
Valorant  
World of Warcraft

## Filmek, sorozatok és karaktervilágok

Bud Spencer  
Star Wars  
Anime  
Labubu  
A nagy pénzrablás  
Squid Game  
Stranger Things  
Wednesday

## Foglalkozások

Ács  
Ápoló  
Asztalos  
Autószerelő  
Biztonsági őr  
Bölcsődei gondozó  
Buszsofőr  
Cukrász  
Edző  
Eladó  
Favágó  
Fényképész  
Festő  
Fodrász  
Fogorvos  
Futár  
Grafikus  
Gyógyszerész  
Gyógytornász  
Hegesztő  
HR-es  
Katona  
Kőműves  
Könyvelő  
Kozmetikus  
Kutyakozmetikus  
Marketinges  
Masszőr  
Méhész  
Mentős  
Mérnök  
Művész  
Orvos  
Óvónő  
Pék  
Pénztáros  
Pincér  
Postás  
Programozó  
Pultos  
Rendőr  
Sofőr  
Szabó  
Szakács  
Szerelő  
Takarító  
Tanár  
Táncos  
Targoncás  
Taxi sofőr  
Tűzoltó  
Ügyvéd  
Villanyszerelő

## Dinamikusan kezelendő entitások

Konkrét zenekar-, előadó-, film-, sorozat-, játék-, márka- vagy karakternevek már az első megfelelő mintánál is kaphatnak taget. A darabszám nem a tag létrehozásának feltétele.

Három egyszerű állapot legyen:

1. **Ismert tag:** a név szerepel a listában, ezért az AI az első mintától kezdve használhatja.
2. **Új javaslat:** ha a név nincs a listában, az AI `unmatched_concept` javaslatot ad; jóváhagyás után bekerül, és az első minta is megkaphatja.
3. **SEO-érett tag:** csak akkor készül belőle külön indexelt SEO-oldal vagy kiemelt kategória, ha már van elegendő minta és önálló tartalom.

Így egy új Minecraft-, zenekar- vagy filmsorozat-minta sem marad címke nélkül, miközben nem keletkezik üres SEO-oldal minden egyszeri témából.

Az évszámok (például `2026`) külön strukturált mezőként kezelendők, nem szöveges tagként.
