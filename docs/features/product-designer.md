# Feature: Produktdesigner

## Purpose
Creators designer selv deres produkter i dashboardet, og en administrator godkender dem. Virksomheden skal så ikke lave design og produkter til alle creators ([ADR-080](../DECISIONS.md), ROADMAP fase 30).

## Current status
Planlagt (fase 30, version 1.5.0; plan godkendt 2026-10-28). 30.1 (ADR'er og plan) er gjort; intet er implementeret endnu.

## User-facing functionality
- **Designeren** ligger i creator-dashboardet, i sektionen Produkter → Designer:
  - venstre værktøjslinje: Produkt, Skabeloner, Kliparts, Upload, Tekst, Former, Lag;
  - fortryd/gentag, zoom og sider (fx Front, Back, ærme);
  - farve og størrelser.
  - Ét design gælder for alle de valgte farver.
- **Pris:** creatoren vælger sin fortjeneste i procent af grundprisen eller som et fast beløb og ser kundens pris med det samme (grundpris + fortjeneste, inkl. moms).
- **Indsendelse:** creatoren sender produktet til godkendelse. Indtil admin har godkendt, er det ikke i butikken. En ændring af et godkendt produkt venter også på godkendelse; imens sælges den godkendte version uændret.
- **Status:** creatoren ser sine produkter som kladde, venter, i butikken eller afvist med admins note. Fra trin 30.14 viser sektionen Produkter også kortet "Dine designs": i butikken (pris og link), venter på godkendelse (ny eller ændring, med prisen efter godkendelse), afvist (med admins note) og taget ud af butikken. Kladder står i Designer.
- **Notifikationer** (projektejerens ønske 2026-10-28). De hører til designeren og står ikke i fase 29's katalog:
  - Typerne defineres i ProductDesigner-modulet og registreres via et filter.
  - Til/fra, eksempel og testmail ligger på designerens admin-side (fanen Notifikationer), ikke på den generelle side Notifikationer.
  - Afsendelsen genbruger fase 29's motor: mailskabelonen, klokken, køen og creatorens e-mailvalg.
  - Typerne:
  - **admin:**
    - nyt designer-produkt til godkendelse;
    - ændring af et produkt til godkendelse;
    - mockups kunne ikke laves (Printful/Gelato);
    - et godkendt produkt kunne ikke lægges i butikken;
  - **creator:**
    - produktet er sendt til godkendelse (kvittering);
    - produktet er godkendt og i butikken;
    - ændringen er godkendt;
    - produktet eller ændringen er afvist (med admins note);
    - ny pris, fordi grundprisen er ændret;
    - produktet er taget ud af butikken (fx et grundprodukt udgår).
  - Notifikationerne udløses efter commit og har dedupe som de øvrige. De indeholder aldrig printfiler eller filer, kun produktnavn, status, pris og link til dashboardet eller admin-siden.

## Designeren i dashboardet (trin 30.8)
- Sektionen **Designer** vises kun, når designeren er slået til under Settings.
- "Design et nyt produkt" viser de aktive grundprodukter; et design starter som kladde med grundproduktets navn, første farve og alle størrelser.
- Værktøjer: **Produkt** (titel, beskrivelse, farver, størrelser, fortjeneste i beløb eller procent og kundens pris inkl. moms), **Tekst** (godkendte Google Fonts, tykkelse, kursiv, justering, farve), **Former** (rektangel, cirkel, trekant), **Kliparts**, **Upload** (egne billeder, PNG/JPG/WebP) og **Lag** (rækkefølge, skjul, lås, slet).
- Over lærredet: sider, forhåndsvisningsfarve, zoom, fortryd/gentag og "Gem kladde". Delete sletter det valgte element; Ctrl+Z/Ctrl+Y fortryder og gentager.
- Indsendelse: se nedenfor (trin 30.9).

## Indsendelse (trin 30.9)
- **"Send til godkendelse"** i designeren (ikke under "Se som creator"). Creatoren ser antallet af billeder og kundens pris og bekræfter.
- Designet gemmes, og browseren laver filerne: en printfil (transparent PNG) pr. side med mindst ét synligt element i sidens printstørrelse og et produktbillede (JPEG) pr. side og valgt farve. De sendes én ad gangen med fremdrift ("3 af 9 filer sendt").
- Serveren tjekker mål og plads og at filerne hører til den gemte kladde. Har vi ændret grundproduktet i mellemtiden, skal designet åbnes og sendes igen.
- Designet **venter** derefter og kan ikke ændres. Creatoren kan **trække det tilbage**; så bliver det en kladde igen med samme indhold.
- **Notifikationer (30.9):** kvittering til creatoren (mail og klokke) og "nyt designer-produkt"/"ændring af designer-produkt" til admin. Til/fra, eksempel og testmail: Merch Dashboard → Produktdesigner → Designs → Notifikationer.

## Godkendelse og butikken (trin 30.10)
- Admin godkender eller afviser under **Designs**. En afvisning kræver en note; creatoren får den (mail og klokke) og ser den over designeren, og designet er en kladde igen.
- Et godkendt design lægges i butikken i baggrunden: et variabelt produkt med farve × størrelse, mockups som billeder, kategori (creatorens, ellers grundproduktets) og prisen ud fra grundprisen, når det lægges i butikken (fra 30.11). Første gang udgives det; creatoren får "godkendt og i butikken".
- Går det galt (fx butikken bruger priser ekskl. moms), står årsagen under Designs med **Prøv igen**, og admin får en mail. Intet i butikken ændres halvt: næste forsøg fortsætter, hvor det stoppede. Er et forsøg gået i stå i mere end 10 minutter, kan det også prøves igen. Et nyt produkt bliver først synligt i butikken, når prisen til afregningen er gemt (review 30.15).
- **Ændringer:** et produkt i butikken har **Lav en ændring** i designeren. Ændringen sendes som et nyt design; butikken viser den godkendte version, til ændringen er godkendt. Udgåede farver/størrelser skjules (varianterne slettes aldrig).
- **Printfiler:** på ordren under hver designer-linje (den solgte version) og i boksen "Produktdesigner" på produktet. Kun admin kan hente dem.
- Pluginet skriver prisen, varianterne og billederne på designer-produkter; ret dem ikke i WooCommerce, de overskrives ved næste godkendelse.

## Ny grundpris og pensionering (trin 30.11)
- **Prisstigninger varsles 14 dage før (fase 32, ADR-094):** hæves en pris på et grundprodukt, som creators designer på, gemmes hele det nye prissæt som en planlagt ændring 14 dage frem (option `merchboard_designer_price_schedule`). Creators får notifikationen "Kommende prisstigning", og designeren viser den kommende pris i grundproduktets notis. På datoen sættes priserne, og genberegningen nedenfor kører. Prisfald gælder med det samme.
- **Ny grundpris:** gem grundproduktet med den nye pris. Produkterne i butikken får den nye pris i baggrunden; creatorens procent eller beløb bevares, og creatoren får "ny pris" (før/efter). Ordrer lagt før beholder deres pris, og et salg afregnes efter den pris, butikken havde.
- En ny pris gør ikke ventende designs forældede; det gør kun en ændret skabelon, et ændret printområde eller en omdøbt eller fjernet farve eller størrelse (fra review 30.15). En ny farve eller størrelse gør ikke.
- **Pensionér** (på grundproduktet, med afkrydsning): dets produkter tages ud af butikken (de bliver kladder og bevares til ordrehistorikken), ventende designs afvises med en note, og creatorne får besked. Det kan ikke fortrydes; brug "Tilbage til kladde", hvis du kun vil stoppe nye designs.

## Admin functionality
- **Designs (trin 30.9):** Merch Dashboard → Produktdesigner → Designs viser de ventende designs (creator, produkt, version, grundprodukt, nyt/ændring, kundens pris), ældste først. Godkend og afvis kommer i 30.10. Fanen Notifikationer kræver også `merchboard_manage_settings`.
- **Grundprodukter:**
  - kilde: egen, Printful eller Gelato;
  - farver, størrelser og grundpris inkl. moms;
  - sider med mockup-skabelon og printområde i mm og dpi.
  - Skabelonen kan importeres fra en PSD med lagene `mb_background`, `mb_color`, `mb_shading`, `mb_highlight` og `mb_print_area`. AI-filer eksporteres først til PSD.
- **Kliparts og startdesigns**, som creatorne kan bruge.
- **Implementeret (trin 30.6):** Merch Dashboard → Produktdesigner → Grundprodukter med fanen Kliparts. Skabelonen udfyldes i formularen med PNG-lag og printområde.
- **PSD-import (trin 30.7):** "Importér fra PSD" pr. side. Sådan laves PSD'en (projektejeren):
  1. Ét dokument pr. side i skabelonens størrelse (fx 2000 × 2000 px), 8 bit pr. kanal (Billede → Tilstand → 8 bit/kanal). AI-filer eksporteres først til PSD.
  2. Lagene navngives præcis: `mb_background` (fotoet uden produktets farve), `mb_color` (produktets form som maske; farves i hver produktfarve), `mb_shading` (folder og skygger, ganges på), `mb_highlight` (lys, lægges ovenpå). Mindst `mb_background` eller `mb_color` skal findes. Lagene må gerne ligge i grupper.
  3. `mb_print_area`: et fyldt rektangel, hvor designet må stå.
  4. Vælg PSD'en i feltet, tjek forhåndsvisningen efter gem, og angiv printstørrelsen i mm og dpi (fx 300 × 400 mm ved 300 dpi).
  - Lagenes opacitet bruges; blend modes og lageffekter gør ikke (designeren ganger skygge og lægger highlight ovenpå selv). Flad effekter ud i laget, hvis de skal med.
- **Godkendelse:**
  - en kø med preview af mockups og printfiler og creatorens pris;
  - godkend eller afvis med note;
  - status for anvendelsen i WooCommerce med "Prøv igen".
- **Kampagner:** navn, periode, kuponkoder og "omfatter tilbudspriser". I en kampagne deles rabatter forholdsmæssigt med creatoren.
- **Printfiler** kan hentes på designer-produktet og på ordren (den solgte version).
- **Printful og Gelato:** API-nøgler (krypteret) og kill switch i Settings.

## Designeren i creator-dashboardet (2026-10-31)
- Siden hedder Produktdesigner i menuen og har ingen synlig overskrift.
- Editoren fylder hele bredden: en topbjælke med gem og send, værktøjer til venstre, produktet i midten og til højre valgt element, trådfarver, pris og fortjeneste og vejen fra design til butik. Canvasset følger den plads, det har, og ligger på hvid baggrund som det færdige produktbillede.
- Fortjenesten sættes i boksen "Pris og fortjeneste", der altid er synlig og viser grundpris, ekstra områder, fortjeneste og kundens pris.
- Creatoren får at vide, at de færdige mockups oprettes automatisk, før vi kan godkende designet, og at vi giver svar, også hvis det ikke fremstår korrekt, eller der er andre fejl.
- Produktdesigneren har egne CSS-klasser (`merchboard-product-designer`). Den delte tidligere rodklasse med OBS-overlaydesigneren og blev klippet til en mørk 16:9-boks.

## Creatorens produkter (2026-10-31, eksperimentel)
- **Navn:** "Creator Grundprodukt Tilføjelse". Creatoren skriver kun tilføjelsen. Navnet følger creatorens navn, også i butikken, og to produkter kan ikke hedde det samme.
- **Beskrivelse:** kommer fra grundproduktet og kan ikke ændres af creatoren.
- **Annullér ændring:** en ændring af et produkt i butikken kan annulleres. Listen viser "I butikken · ændring i gang".
- **Sæt i kladde:** tager et godkendt produkt ud af butikken, og det kan sættes tilbage uden ny godkendelse.
- **Slet:** kræver ordet SLET. Designet flyttes til "Slettede designs", hvor billedet kan ses. Også afviste designs og designs på udgåede grundprodukter kan slettes.
- **Slet permanent:** fjerner designet. Har det været i butikken, bliver versioner og filer til ordrerne, men creatoren ser det aldrig igen.
- **Varianter:** en farve eller størrelse med ét valg vises på produktet uden at være et valg, og 1 × 1 er et simpelt produkt.

## Sider pr. størrelse fra kataloger og cm (2026-11-02, eksperimentel)
`BaseProductService::plan_views()` tager nu sider pr. størrelse fra alle kilder. `PrintfulCatalog::choice_v2()` laver sider for alle grupper fra `size_groups()` med `sizes` og størrelserne i navnet, så nøglerne er forskellige. `PrintifyCatalog::shape_groups()` grupperer efter printfeltets form rundet til 2 %. `CatalogImport::create()` tager en side med, når dens placering og mindst én af dens størrelser er valgt, og `flat_view()` gemmer sidens størrelser. `PrintfulMockups` laver én opgave pr. gruppe (`all:<n>`, n er gruppens nummer i `size_groups()`) med varianter i gruppens størrelser; de valgte fotos kommer fra gruppen for den første valgte størrelse, og forhåndsvisningen bruger samme gruppe. `SizeUnits` skriver tommer som hele cm i størrelser, varianter, sidenavne og "(in)" i navnet; giver to størrelser samme navn, beholdes tommerne. Formularen sender `units`, så importen omregner som siden viste.

## Printfuls katalog som oversigt (2026-11-02, eksperimentel)
`PrintfulCatalog::browse()` giver en side af den cachede, europæiske liste med søgning, kategori med underkategorier og teknik. Listen gemmer nu produkternes tekniknøgler; en liste gemt af en tidligere version uden dem hentes igen. `category_labels()` sætter hele stien af overkategorier foran, fordi navne går igen på flere niveauer. Printfuls egne billeder er 300–600 px, så de vises direkte uden små kopier. Siderne deler `BaseProductsPage::pager()` med Printify.

## Printifys katalog (2026-11-01, eksperimentel)
Kun katalogkald med `GET` (ADR-087). Oversigten går over hele kataloget, som caches i 12 timer med id, navn, mærke og første billede, fordi svaret er 5,5 MB. Kategorierne er `PrintifyCatalog::GROUPS`: mønstre på produktnavnet, hvor det første, der passer, vinder (hættetrøjer og sweatshirts før T-shirts, fordi "Sweatshirt" indeholder "tshirt"). En producents produkter læses fra `/catalog/print_providers/{id}.json` og caches pr. producent; "Kun producenter i EU" er foreningsmængden for producenterne med EU-adresse. Hvert kort viser produktets producenter med land, dem i EU først. En side bruger højst 3 sekunder på producenter, den ikke kender; resten læses af baggrundsjobbet `merchboard_designer_printify_producers`, der lægger sig i kø igen, til alle er læst (85 producenter tog flere minutter fra stg). En produktliste gemt af en version uden billeder hentes igen. Små billeder laves af `PrintifyThumbnails` (`admin-post.php?action=merchboard_printify_thumb`, én nonce pr. side) og gemmes som `<md5>.jpg` i designerens mappe. Producenterne af et produkt vises med sted, dem i EU først. Referencen er `<produkt>-<producent>`. `PrintifyCatalog::choice()` giver `flat` og `printed`: importen springer rendering over, men siderne er ikke flade, så printfiler er gennemsigtige PNG'er, og mockups tegnes i browseren. Hver side er et hvidt ark på 1000 × 1000 px med et foreløbigt printfelt i arkets midte. Printmålet er det mindste blandt varianterne ved 300 dpi. Farver starter som `#ffffff`, og et foto med farvens navn erstatter koden, når det vælges. Vælges fotos på en gemt side, vises første foto til tegning af printfeltet, og et hvidt ark vises ikke længere, når siden har farvefotos.

## Farvefotos i portioner (2026-11-01, eksperimentel)
En side kan have en baggrund pr. farve, og en side med 23 farver er flere filer, end PHP normalt tager i én anmodning (`max_file_uploads`, 20 som standard). Overskydende filer forsvinder uden fejl. Derfor sender browseren først formularen med første foto som baggrund og derefter fotoene i portioner til `do=color_photos`, højst 10 filer og 80 % af `wp_max_upload_size()` pr. portion. Siden findes på nøgle eller navn, fordi en ny side ikke har en nøgle i browseren, og farven findes på navnet uanset store og små bogstaver. Alle fotos skal have samme størrelse som skabelonen. En portion gemmes helt eller slet ikke, og et erstattet foto slettes. Filer fra Affinity skal først eksporteres eller udtrækkes til PNG uden creators' designs: pluginet tager kun imod PNG og kan ikke kende et design på et foto, så administratoren tjekker miniaturerne.

## Sidernes rækkefølge (2026-11-01)
Admin flytter en gemt side med pile på grundproduktet. Formularen sender siderne i den rækkefølge. Den giver ikke en ny version, så kladder og ventende designs forbliver gyldige. Fanerne i designeren følger den, og et godkendt design får sine billeder i samme rækkefølge, så første side giver hovedbilledet. Produkter i butikken ændres ikke, før en ændring af dem godkendes.

## Størrelsernes rækkefølge (2026-11-01)
Admin ændrer rækkefølgen med pile under feltet Størrelser. Designeren viser størrelserne i den rækkefølge, og et godkendt design følger grundproduktets rækkefølge, også hvis det blev gemt før ændringen. Rækkefølgen giver ikke en ny version af grundproduktet, så kladder og ventende designs forbliver gyldige. Produkter i butikken ændres ikke, før en ændring af dem godkendes.

## Fontvælger (2026-11-01)
Fontmenuen i designeren og i alert-overlayets indstillinger viser hver font i sin egen skrift. En almindelig select kan ikke det i de fleste browsere, så `FontPicker` er sin egen liste med tastaturstyring. Fontfilerne hentes først, når listen åbnes.

## Digitaliseringsgebyr (ADR-086, 2026-10-31, eksperimentel)
- **Formål:** en ny broderifil koster virksomheden et gebyr hos partneren, og creatoren betaler det.
- **Opsætning:** beløbet sættes under Produktdesigner → Gebyrer (kræver `merchboard_manage_payouts`). Det er inkl. moms, og 0 betyder intet gebyr.
- **I designeren:** creatoren ser beløbet og reglerne på en broderet side og igen ved indsendelse. Versionen beholder det beløb, creatoren så (`fee_quote`).
- **Pr. fil:** ved godkendelse får hver broderet sides printfil et gebyr, ét pr. creator og fil (SHA-256). En fil, creatoren har brugt før, koster ikke noget, og en ændret fil koster et nyt gebyr. Filen skal være helt ens, så samme logo i en anden størrelse tæller som ny.
- **Opkrævning:** første ordre i en tællende status på en version med filen opkræver gebyret, uanset variant og også på creatorens egen ordre. Det sker i et job, ikke under gemningen af ordren. Gebyret bliver en negativ regulering, der straks tæller i saldoen og står på næste afregningsbilag med referencen `DIG-000001`. Saldoen kan gå i minus og modregnes i kommende optjening (ADR-054).
- **Annullering:** et opkrævet gebyr bliver stående ved refundering. Admin kan annullere det på fanen Gebyrer: en positiv regulering betaler det tilbage, og næste salg opkræver det igen. Reguleringer ændres aldrig.

## Sider pr. størrelse (2026-10-31, eksperimentel)
- **Formål:** to størrelser af samme produkt kan have hver sin opsætning, fx kopper på 33 cl og 44 cl med hver sin PNG, PSD og printområde. Projektejeren valgte 2026-10-31, at creatoren designer hver størrelse for sig.
- **Opsætning:** på et eget grundprodukt krydser admin størrelser af på en side under "Kun til disse størrelser". Ingen krydser betyder alle størrelser. Listen følger størrelsesfeltet, så det virker under oprettelsen. Leverandørprodukter har det ikke, fordi leverandøren laver alle sider på alle størrelser. En ændring giver en ny version af grundproduktet.
- **I designeren:** hver side er sin egen fane. Hver valgt størrelse skal have et design, og et design på en størrelse, der ikke er valgt, skal fjernes, ellers afvises indsendelsen med `design_sizes`.
- **Pris:** kun områder på samme størrelse tæller som ekstra områder. Med én fælles pris gælder størrelsen med flest områder.
- **Butik:** alle størrelsers billeder ligger i galleriet, og en variant viser billedet af sin egen størrelse.
- **Ordre:** en ordrelinje viser kun printfilerne til den solgte størrelse. Kan størrelsen ikke findes, vises alle.

## Broderi (2026-10-31, eksperimentel)
- **Broderipakker:** under Produktdesigner → Broderipakker har hver pakke et navn, et maksimum pr. side og sine tråde (kode, navn, farve). Standardpakken "Gelato" har 15 tråde og højst 6. En ændring gælder fra næste gang et design gemmes. En pakke i brug kan ikke slettes.
- **Valg af pakke:** en side vælger "Broderipakke" på grundproduktet eller ved katalogimport. Ved import fra Gelato er første pakke forudvalgt, når sidens navn indeholder "embroider". Uden pakke er siden print med frie farver. En ændret pakke på en side giver en ny version af grundproduktet, så kladder skal gemmes igen.
- **I designeren:** hver farve bliver til nærmeste tråd, også i billeder og kliparts, og det ses med det samme. Har designet flere tråde end pakken tillader, beholdes dem, der fylder mest, og resten flyttes til den nærmeste af dem. Panelet "Trådfarver" viser trådene, og creatoren kan vælge en anden for hver. Farvevælgeren viser kun trådene, og gennemsigtighed er væk på siden.
- **Designet og filen:** designet beholder sine egne farver og gemmer kun creatorens skift (`threads`). Printfil og mockups renderes i browseren med trådfarver alene, uden halv gennemsigtighed.
- **Kontrol:** serveren tager en stikprøve af printfilen på en broderet side og afviser farver uden for pakken, flere tråde end maksimum og halv gennemsigtighed.
- **Billederne (ADR-085, ikke afprøvet i en browser):** på en broderet side tegner browseren tråden med en prøve af rigtig stingoverflade, en afrundet højde og en takket kant. Den laver billedet af hele produktet og et nærbillede på 1200 × 1200 px pr. farve. Gelatos flade billeder bruges ikke på sådan en side, og et design med kun broderede sider opretter intet produkt hos Gelato. Printful laver sine egne billeder og er uændret. "Se det færdige produkt" tegner begge billeder i første farve uden at sende noget.
- **Grænser:** et foto eller en gradient bliver til flade felter. Mindste tekststørrelse og tynde streger håndhæves ikke. Trådene gives ikke videre som data: ordrer produceres manuelt, og Gelato får dem via PNG'ens farver. Pakken "Gelato" passer ikke til Printfuls tråde.

## Afregning (ADR-080 pkt. 8)
- Kundens pris er grundpris + fortjeneste.
- Broderi ændrer ikke prisen (se "Broderi").
- **Ekstra designområder (2026-10-30):** ét område er med i grundprisen. Hvert ekstra område, creatoren bruger, lægger grundproduktets "Pris pr. ekstra designområde" oveni (0 som standard). Beløbet tilfalder virksomheden og er sikret som grundprisen. Eksempel: grundpris 150, ekstra 50, to områder og 20 % giver sikret grundpris 200, fortjeneste 40 og kundepris 240. Et område tæller, når det har mindst ét synligt objekt, og prisen i designeren følger med. Et nyt beløb når produkterne i butikken som en ny grundpris.
- **Store og små områder (projektejerens valg 2026-11-03):**
  - Hver side er et "stort" (standard) eller "lille" område. Det vælges på siden under Grundprodukter.
  - Grundproduktet har "Pris pr. ekstra stort designområde" (det hidtidige felt) og "Pris pr. ekstra lille designområde".
  - Det dyreste område, creatoren bruger, er altid med i grundprisen. Hvert andet brugt område koster prisen for et stort eller et lille område.
  - Med sider pr. størrelse gælder den størrelse, der koster mest.
  - Eksempel med grundpris 150, stort 50 og lille 20 (ærme):

    | Design | Sikret grundpris |
    | --- | --- |
    | forside + ærme | 170 |
    | forside + bagside | 200 |
    | kun ærme | 150 |
    | to ærmer | 170 |
  - Eksisterende sider er store, så eksisterende priser er uændrede.
  - Designerens prisboks viser hvert betalt område med navn og pris og nævner, hvad hver side koster som ekstra område.
  - En ny pris eller en side, der skifter mellem stort og lille, når butikken som en ny grundpris.
- **Uden for kampagner** får virksomheden altid grundprisen:
  - en rabat tages fra creatorens fortjeneste;
  - fortjenesten bliver aldrig negativ.
- **I en kampagne** deles rabatten forholdsmæssigt.
- Udbetalingen følger momsreglerne (ADR-062/064).
- Legacy-produkter afregnes som i dag (andel af prisen, ADR-049).
- **Kun virksomheden kan bringe sig under grundprisen:**
  - fortjenesten kan ikke være negativ;
  - creatorens egen rabatkode giver højst fortjenesten i rabat;
  - creatoren kan ikke sætte tilbudspriser eller oprette kuponer.
  - Kun virksomhedens egne kuponer, tilbudspriser og kampagner kan give den mindre end grundprisen.

| Eksempel (grundpris 150, fortjeneste 50, pris 200) | Creatorens fortjeneste |
| --- | --- |
| Ingen rabat | 50 |
| Creatorens egen rabatkode | 0 (rabatten er fortjenesten; virksomheden får 150) |
| 20 kr. rabat fra virksomhedens kupon, ingen kampagne | 30 |
| 80 kr. rabat fra virksomhedens kupon, ingen kampagne | 0 (virksomheden får 120; kun muligt, fordi rabatten er virksomhedens egen) |
| 20 kr. rabat i virksomhedens kampagne | 45 (50 × 180/200) |

- **Sådan regnes det (trin 30.3):** fortjenesten og udbetalingen beregnes én gang, når salget registreres, og gemmes ved salgsrækken. Saldo, statistik og afregningsbilag bruger de gemte beløb. En refundering tager fortjenesten tilbage i forhold til den refunderede andel af det betalte, så en fuldt refunderet linje altid ender på 0 for creatoren (projektejerens valg 2026-10-29, review 30.15).
- **Salg uden moms** (momsfritaget kunde, eksport): betalingen sammenlignes med grundpris og fortjeneste uden moms. Eksempel: grundpris 100 og fortjeneste 100 inkl. 25 % moms, solgt til 160 uden moms: creatoren får 80, virksomheden 80 (projektejerens valg 2026-10-29).
- **Priser:** grundprisen afrundes aldrig ned, og kundens pris er altid grundpris + fortjeneste.
- **Kampagner (trin 30.4):** admin opretter dem under Merch Dashboard → Produktdesigner → Kampagner. En ordrelinje er i en kampagne, når kampagnen kørte, da linjen blev købt, og ordren brugte en af kampagnens kuponkoder, eller kampagnen omfatter tilbudspriser, og linjen blev solgt under sin pris før kuponer. Afgørelsen tages én gang pr. ordrelinje: en senere refundering bruger samme kampagne, også hvis kampagnen er ændret. En creators egen rabatkode kan ikke være kampagnekode.

## Legacy-produkter berøres ikke (ADR-080 pkt. 7)
- **Hvilke produkter pluginet skriver til:** kun produkter, som designeren selv har oprettet. Det skal kunne ses på tre måder:
  - en række i designer-tabellen;
  - en beskyttet markør, som kun designeren kan skrive;
  - et token, der passer.
- **Alle andre produkter er legacy.** Det gælder alle eksisterende produkter og alle, der oprettes i WooCommerce på anden vis. De får aldrig ændret pris, billeder, varianter, kategori eller kobling af designeren, genprisningen eller Printful/Gelato.
- **Prisskrivning** sker ét sted (`WooCommerceDesignerWriter`) og kun på et produkt, som værnet har godkendt. En arkitekturtest fejler, hvis prisskrivning dukker op andre steder.
- **Printful og Gelato** får aldrig adgang til WooCommerce. De ser kun printfiler af designer-produkter via kortlivede URL'er.
- **Afregningen af legacy-salg** er uændret (andel af prisen, ADR-049).
- **Implementeret (trin 30.5):** markøren `_merchboard_designer_token`, `DesignerProductGuard`, `WooCommerceDesignerWriter` og arkitekturtesten `LegacyGuardArchitectureTest`.
- **Kontrol:** runtime-testen i 30.15 sammenligner alle legacy-produkter før og efter (pris, billeder, varianter, meta).

## Farvedesigns, produktbillede og "Kopiér design" (fase 34, ADR-096, 2026-11-07)

- **Farvedesigns:** som standard deler alle farver ét design. I farvelisten og over lærredet har hver farve et designvalg: "Fælles design", "Design 2" … eller "+ Nyt design", der starter som en kopi af farvens design. Flere farver kan dele et eget design (højst 8 egne designs). Lærredet redigerer designet for den viste farve, og linjen over lærredet viser, hvilke farver ændringerne gælder.
- **Når en farve flyttes ind i et andet design,** spørger designeren: brug måldesignet, brug farvens design (det erstatter måldesignet for alle farver i det) eller læg lagene sammen. Alt kan fortrydes.
- **Pris pr. farve:** en farve koster prisen for sit eget design, så ekstra trykområder regnes pr. farve. Designeren viser "Pris pr. farve", og overskriftsprisen er den højeste.
- **Filer og leverandører:** hvert design får sine egne printfiler. Hver farve får mockups af sit eget design, også hos Printful, Gelato og Prodigi. Ordresiden viser printfilerne for linjens farve og størrelse; kan farven ikke afgøres, vises alle med farvenavn.
- **Produktbillede:** creatoren vælger farve og side under "Produktbillede". Ved godkendelse bliver billedet produktets hovedbillede. På et live produkt kan billedet skiftes under "Produktbillede" uden ny godkendelse.
- **Kopiér design:** knappen på hvert design og i designeren laver et nyt produkt med samme opsætning, også fra et design, der venter eller er afvist. Filer kopieres ikke.
- **"Vælg alle"** ved farverne, og det valgte elements **størrelse i cm** ud fra sidens printstørrelse (også mens det skaleres).

## Baggrundsfarve på AOP-produkter (fase 33, ADR-095, 2026-11-07)

Admin markerer et grundprodukt med "Baggrundsfarve (AOP)". Kun på de produkter har designeren fanen "Baggrund".

- **Fanen:** creatoren vælger en farve (16 standardfarver, Hex, farvevælger, RGB eller CMYK) og trykker "Brug farven". Den gælder den viste side eller alle sider ("Alle sider", sat som standard). "Fjern baggrund" fjerner den igen.
- **Lærredet og printfilen:** farven dækker hele printområdet og ligger altid bagerst.
- **Pris:** en side med kun farve printes, men koster ikke som et ekstra område.
- **Broderede sider** har ingen baggrund.
- **Duplikér lag:** knappen i lagpanelet laver en kopi af et lag lige over det, lidt forskudt, ulåst og vist. Den gælder alle grundprodukter og følger loftet på 100 elementer pr. side.

Funktionen bygger på projektejerens Lumise-snippet (31658), men er bygget direkte i designeren.

## All-over-produkter og Printful v2 (2026-10-31, eksperimentel)
- **EU først:** kataloget filtrerer på Europa og levering til Danmark, og kun varianter på lager i Europa tilbydes. Regionen "Europe" er Printfuls salgsregion og er ikke et løfte om produktion i EU. Afsenderlandet kan kun ses pr. forsendelse.
- **Fladt design:** et all-over-produkt har intet foto at måle på. Creatoren designer på syemønsteret, én side pr. del, og skal fylde delen ud til kanten. Mønsteret ligger over designet med et slør uden for delen, som hos Printful.
- **Mockups:** når designet sendes til godkendelse, får Printful alle brugte dele i én opgave og syr dem sammen. Hver side får billedet fra sin mockup-stil (Front, Back, Left, Right). En del uden design bliver hvid.
- **Printfil:** JPEG på hvid baggrund. 4200 × 5400 px er for meget til en PNG af et foto.
- **Printmål, der skifter med størrelsen** (puder): ét grundprodukt pr. gruppe af størrelser.
- **v1 som reserve:** bruges, når v2 fejler, ikke ved 429. En all-over-import kræver v2. Importens egne markør-kørsler bliver på v1, for v2 afviser en fil, hvis form afviger mere end 2 % fra printområdet.
- **Strik** (`knitwear`) tilbydes ikke endnu.
- **Tekster på mønsteret:** kommer fra Printfuls ældre skabeloner (`GET /mockup-generator/templates/{id}`) og er en del af billedet. De er på engelsk på alle sprog, og Printfuls API har ikke dansk (`da_DK` giver 400), så en dansk forklaring står under designfladen. Mønstre hentet før 2026-10-31 har ingen tekster og kræver en ny import.
- **Se det færdige produkt:** på Printful-produkter sender creatoren kladdens filer og får rigtige billeder i første farve, før designet sendes til godkendelse. Dialogen viser en ventemarkør, mens billederne laves, og nævner ingen leverandør. Der gemmes intet hos Printful, og kladden ændres ikke. Printfuls grænse er 2 opgaver i minuttet for en butik uden ordrer. Knappen vises ikke under "Se som creator", fordi filer ikke kan sendes dér.
- **Valgte fotos:** når admin opretter et Printful-grundprodukt, vælges de mockups (fx "Men's", "Flat", "Ghost"), Printful laver af hvert design, grupperet efter stil og højst 8. De bliver produktets billeder i butikken pr. farve, og browserens mockups for den farve fjernes. Printful viser ingen billeder af stilene på forhånd, så valget sker ud fra navnet. Hvilke fotos der laves, kan ikke ændres på et eksisterende grundprodukt, men det kan deres rækkefølge. Leverandøren nævnes aldrig for creators og kunder.
- **Rækkefølge:** creatoren vælger kun farvernes rækkefølge, med pile under farvevalget, og butikken viser dem sådan: den første farve giver produktets første billede. Fotoenes rækkefølge sætter admin under "Mockuppernes rækkefølge" på grundproduktet, og den kan ændres når som helst. Det første foto er hovedbilledet, rækkefølgen gælder for hver farve, og den bruges til designs, der sendes bagefter, og til "Se det færdige produkt".
- **Syninger:** på all-over-produkter med flere syningsfarver vælger creatoren under Produkt mellem de farver, Printful tilbyder (hvid, sort, klar). Uden valg bruges den første. Valget følger med mockups og "Se det færdige produkt", og admin ser det under Designs, på ordrelinjen og i produktets boks, for produktionen er manuel. Et ældre all-over-grundprodukt henter sine syningsfarver hos Printful, første gang designeren åbnes.
- **Mockup-PSD (kopper og lignende, eksperimentel):** en side kan få en PSD med smart objects som mockup. Admin vælger PSD'en under "Mockup-PSD" på siden, og den læses kun i browseren. Smart objects med "design" i navnet får printfilen: "højre" eller "right" viser den højre ende, "venstre" eller "left" den venstre, og andre viser midten. Udsnittet er lige så højt som printfilen og har smart objectets form. Lagene omkring designet samles i ét billede, og bøjningen gemmes som tal. Når creatoren sender et design, tegner browseren mockuppen i højst 2000 px og bruger den for hver farve. Skabelonen (design-PNG'en) bruges stadig til at designe på. Effekter, lagmasker og justeringslag tegnes ikke og nævnes ved importen. Med fluebenet "Lad creators se denne mockup" får creatoren knappen "Se det færdige produkt" på produktet: mockuppen tegnes i browseren af designet, som det står, og der gemmes eller sendes intet.
- **Printområde:** admin tegner printområdet på skabelonen med musen under siden og flytter det ved at trække inde i det. Tallene i felterne følger med og kan stadig skrives ind.
- **Notis i designeren:** feltet "Notis i designeren" på grundproduktet vises for creators, mens de designer produktet, fx om at flere produkter kan samles i én listing. Tomt felt viser intet, og en ændring gør ingen designs forældede.
- **Pris pr. størrelse:** fluebenet "Forskellig grundpris pr. størrelse" på grundproduktet giver et prisfelt pr. størrelse. Uden flueben har alle størrelser samme grundpris, og et tomt felt bruger grundprisen. Procent regnes af hver størrelses pris, et fast beløb er ens for alle, og prisen for ekstra designområder er ens. Butikken får prisen pr. variant, creatoren ser kundeprisen pr. størrelse i designeren, og hver ordrelinje afregnes efter sin størrelse. Kan størrelsen ikke findes, bruges den højeste grundpris, så virksomheden aldrig får for lidt. En ændring når butikken i baggrunden som en ny grundpris, og et produkts pris i oversigten er stadig grundproduktets almindelige pris.
- **3D:** Printfuls 3D-visning er ikke en del af API'et. Den indlejrede designer (EDM) kræver en aftale med Printful og beskriver ingen 3D-visning, og den ville erstatte vores designer.

## Prodigi (ADR-084, ikke afprøvet mod Prodigi)
- **Status:** koden ligger bag afbryderen "Prodigi", der er slået fra. Der er ingen konto og ingen nøgle endnu.
- **Grundprodukter:** kun produkter produceret i EU. Kataloget har hverken fotos eller farvekoder, så creatoren designer på et hvidt ark, og admin retter farverne.
- **Mockups:** efter indsendelse sendes printfilen til Prodigi, et gemt produkt oprettes, og mockups hentes. Det gemte slettes igen.
- **Ved første nøgle skal det afprøves:** svarenes form, om tøj understøtter mockups, om mockups koster penge, og hvilke scener der findes.
- **Prodigis svar om mockups (2026-11-04, ikke bygget):** Prodigi anbefaler `POST /assets/{assetId}/mockups:fromConfig`. Det passer til vores flow: vi laver selv designet og printfilen og beder kun Prodigi om billedet. Svaret siger "v4", men endpointet findes kun i v5 (offentlig specifikation `https://www.prodigi.com/print-api/docs/openapi.yml`, version 5.0.0).
  - **Nøgle:** `X-API-Key` med `sandbox_<nøgle>` eller `live_<nøgle>` på `https://api.prodigi.com/v5`. `test_`-nøgler virker ikke. Sandkassen deler assets og gemte produkter med live-kontoen, så alt, vi sender, skal slettes igen.
  - **Asset først:**
    - enten `POST /assets:ingest` med en offentlig `https://`-URL (Prodigi afviser private og interne adresser, så det virker fra stg, ikke fra Local);
    - eller `POST /assets` med filnavn, type og størrelse og derefter `PUT` af filen til den returnerede `uploadUrl`, højst 100 MB.
    - Assettet får status `pending`, `processing`, `ready` eller `failed`.
  - **Mockup:** `mockups:fromConfig` tager kun `catalogueVariantId` (påkrævet), `printArea` (påkrævet) og `scene` (valgfri, standard `default`).
    - Der er **ingen placering eller skalering**. Assettet skal være den færdige printfil til hele printfladen, som designeren allerede laver.
    - Én mockup pr. side og farve er derfor ét kald pr. side og katalogvariant.
  - **Asynkront:** svaret er `202` med en mockup med `status` `pending`, `processing`, `completed` eller `failed`.
    - Vi skal polle `GET /assets/{assetId}/mockups/{mockupId}`, indtil den er `completed`.
    - Billedet ligger i `result.url` med `widthPx` og `heightPx`, eller hentes via `…/download` som PNG eller JPEG.
    - Mockups og assets kan slettes med `DELETE`.
  - **I forhold til i dag:** koden bruger `mockups:fromProduct`, som kræver, at vi opretter et gemt produkt, binder filen og sletter produktet igen. `fromConfig` springer det gemte produkt over: færre kald, mindre at rydde op og ingen produkter i Prodigi-kontoen. Kun assettet og mockuppen skal slettes.
  - **Stadig uvist:**
    - hvilke scener der findes (specifikationen har ingen liste over scener);
    - om tøj og andre produkter understøtter mockups;
    - om mockups koster penge;
    - hvor længe `result.url` virker;
    - om printfladens navn er det samme som katalogets `printAreas`.
  - **Grænser:** 1.000 kald i timen pr. nøgle med `Retry-After` efter `429`, som i ADR-084.
  - **Gennemgang mod vores behov (2026-11-04, Prodigi bad om eksempler):**
    - **Farve og andre attributer mangler:** én katalogvariant kan have flere kombinationer (`attributeOptions`, fx `color` på tøj og `framecolour` på rammer). Ordrer og gemte produkter sender `attributes` med, men `fromConfig` har kun `catalogueVariantId`, `printArea` og `scene`. Vi kan derfor ikke vælge farven på en mockup. Det er derfor, ADR-084 bruger et gemt produkt (`fromProduct`). Prodigi er spurgt.
    - **Andet til Prodigi:**
      - hvilke scener der findes pr. SKU;
      - om en side uden design vises blank på et produkt med flere printflader;
      - om gennemsigtighed i PNG bevares;
      - hvilke produkttyper der har mockups;
      - pris pr. mockup;
      - hvor længe `result.url` virker;
      - om polling tæller med i grænsen på 1.000 kald i timen.
    - **Gaming-måtter (projektejerens ønske 2026-11-04):** ifølge Prodigis offentlige produktside (`prodigi.com/products/technology/gaming/gaming-mats/`) er der tre SKU'er:
      - `GLOBAL-GAMINGMAT`, stor, 78,7 × 38 cm, produceret i UK;
      - `DESK-MAT-SML` og `DESK-MAT-MED`, produceret i US.
      - Dye sublimation på hvid neopren, én printflade og ingen farvevarianter, så `fromConfig` dækker mockups uden farveproblemet.
      - **Ingen af dem produceres i EU.** ADR-084 pkt. 3 kræver `EU` i `manufacturingRegions`.
      - **Beslutning 2026-11-04:** projektejeren tillader UK-produktion for gaming-måtter (statusnote på ADR-084), så `GLOBAL-GAMINGMAT` må tilbydes. US-SKU'erne er stadig udelukket. Told og moms ved levering fra UK er accepteret.
      - Importen tilbyder endnu kun `EU`. Undtagelsen bygges, når Prodigi genoptages.
    - **Andre nyttige kald i Assets:**
      - `POST /assets/{id}/validate` tjekker opløsning og format mod variant og printflade (`resolution_low`, `aspect_mismatch`, `print_area_unfilled`);
      - `POST /assets` med `PUT` til `uploadUrl` lægger filen op uden en offentlig URL, så det også virker fra Local;
      - et gemt produkts binding (`POST /products/{id}/assets`) kan placere filen i mm (`x`, `y`, `width`, `height`, `rotation`) eller med `fillPrintArea`, `fitPrintArea` eller `stretchToPrintArea`.
  - **Næste skridt:** først når projektejeren genoptager Prodigi, og der er en `sandbox_`- eller `live_`-nøgle:
    1. afprøv `fromConfig` med én printfil;
    2. opdatér de tilladte kald i ADR-084 (projektejerens godkendelse);
    3. skift mockup-flowet fra `fromProduct` til `fromConfig`.

## Opsætning af Printful og Gelato (projektejeren)
Nøglerne indtastes i Merch Dashboard → Produktdesigner → Indstillinger (Printful fra trin 30.12, Gelato fra 30.13). De gemmes krypteret og vises aldrig igen. Vejledningen herunder står også på Settings-siden.

**Printful** (ADR-081: kun katalog og mockups):
1. **Butik:** opret en konto på printful.com og en butik af typen "Manual order platform / API". Den må ikke være koblet til en webshop, så intet publiceres eller sælges via Printful.
2. **Token:** gå til Printfuls Developer Portal (developers.printful.com), og opret en **privat token** med adgang til **én butik** (den fra pkt. 1).
   - Med adgang til én butik skal vi ikke sende butiks-id. En konto-token kræver header'en `X-PF-Store-Id`.
   - **Scopes:** vi bruger kun kataloget (kræver intet scope) og mockup-generatoren (kræver en gyldig token). Vælg færrest muligt. Kræver portalen mindst ét scope, så vælg et skrivebeskyttet, fx `file_library/read`.
   - Vælg **ikke** `orders`, `sync_products` eller `webhooks`. Vi opretter aldrig ordrer eller produkter hos Printful.
3. **Merch Dashboard:** indsæt token'en i Produktdesigner → Indstillinger → "Printful API token", slå Printful til, og gem.
4. **Grundprodukter (fra review 30.15):** under Merch Dashboard → Produktdesigner → Grundprodukter → "Nyt grundprodukt" → "Fra Printfuls katalog" søger du på ord (fx "gildan tee") og/eller kategori og vælger produktet. Sæt flueben ved farver, størrelser og sider (forside og bagside er valgt på forhånd), angiv grundprisen inkl. moms, og vælg "Opret og importér".
   - Kladden oprettes straks. I baggrunden (et par minutter) renderer Printful produktet uden design i hver valgt farve og én gang med en farvet markør pr. side. Vi tager ét rent foto pr. farve og side og måler printområdet der, hvor markøren lander. Designeren viser altid produktets eget foto i den valgte farve; Printfuls skabelongrafik bruges ikke længere.
   - Tjek hvert printområde, og aktivér så grundproduktet. Fejler importen, står årsagen, det afviste kald og Printfuls egen besked på grundproduktet sammen med "Prøv igen", og du får en notifikation. "Vis leverandørens svar" viser Printfuls svar om produktet (uden token) til fejlfinding.
   - Printful henter vores gennemsigtige fil og markørfiler fra `wp-content/plugins/merchboard/assets/designer/`. Sitet skal derfor kunne nås fra internettet.
   - Et afvist kald (401/403) betyder, at token'en mangler adgang til butikken eller er udløbet; lav en ny med adgang til butikken fra pkt. 1.
   - Når et design på et Printful-grundprodukt sendes til godkendelse, laver Printful mockups (det tager typisk under et minut). Indtil da viser Designs creatorens egne mockups og kan ikke godkende. Fejler Printful, bruges creatorens mockups, og du får en mail.
   - Printful henter printfilen fra en kortlivet adresse på sitet. Det virker derfor kun på et site, Printful kan nå (devpage eller produktion), ikke på Local.
5. **Begrænsninger:** 120 kald pr. minut og 2–10 mockup-tasks pr. minut. Vi venter selv ved 429.

**Gelato** (ADR-082: produkt og mockups via én Gelato-skabelon pr. grundprodukt som "render-motor"):
1. **Butik:** opret en konto på gelato.com og en **ren API-butik** uden tilknyttet salgskanal (ikke Shopify eller Etsy), så produkterne aldrig publiceres. Notér butikkens id (`storeId`).
2. **API-nøgle:** gå til Developer → API Keys i Gelato-dashboardet, opret en nøgle, og kopiér den med det samme (den kan ikke ses igen). Nøglen giver adgang til ordrer, så den skal opbevares sikkert. Vi bruger den kun til skabelon-flowet.
3. **Skabelon pr. grundprodukt:** Gelato renderer kun produkter ud fra en skabelon. Under Templates laver du én skabelon af det katalogprodukt, du vil tilbyde:
   - vælg de farver og størrelser, creators må vælge imellem;
   - tilføj et **billedfelt pr. side** (fx forside og bagside);
   - designet i skabelonen er ligegyldigt og vises aldrig: vi erstatter det altid med en tom fil og senere med creatorernes printfiler;
   - gem, og kopiér skabelonens **Template ID** fra dens adresse.
4. **Merch Dashboard:** indsæt nøglen i Produktdesigner → Indstillinger → "Gelato API key" og butiks-id'et i "Gelato store id", slå Gelato til, og gem.
5. **Grundprodukter (fra review 30.15):** under Merch Dashboard → Produktdesigner → Grundprodukter → "Nyt grundprodukt" → "Fra en Gelato-skabelon" indtaster du skabelonens Template ID og vælger "Hent". Gelatos API kan hverken vise skabeloner eller produktbilleder, så produktet vælges i Gelatos eget katalog, når skabelonen laves. Sæt flueben ved farver, størrelser og sider, angiv grundprisen inkl. moms, og vælg "Opret og importér".
   - Farver og størrelser tages fra katalogets attributter i hver variants `productUid` (ikke fra skabelonens egne navne), og varianterne genkendes på deres id. Omdøbning i skabelonen gør derfor intet.
   - I baggrunden opretter vi først et skjult produkt fra skabelonen med en farvet markør pr. side. Giver det et forhåndsbillede, opretter vi ét skjult produkt pr. farve med en tom fil i hvert billedfelt (et produkt hos Gelato har ét `previewUrl`). Printområdet måles på billedet, og farvekoderne måles på de rene fotos. Kun den side, forhåndsbilledet viser, får et foto; de andre udelades og nævnes på grundproduktet.
   - **Skabelonen skal (set på devpage 2026-10-30):** være et printet produkt med et navngivet billedlag pr. side og en rigtig mockup (t-shirt Gildan 5000 i DTG med mockup `new/flatlay/front` gav fotos i alle 13 farver på ca. 6 minutter). En broderet hue med et automatisk navngivet billedlag og kun `editor/front-embroidery` gav `active` uden billeder; importen stoppede så efter 10 minutter. Broderi skal virke (projektejerens krav 2026-10-30). Fem testprodukter på hue-skabelonen med forskellige filer og `fitMethod` gav heller ingen billeder, så filen er ikke årsagen. Da projektejeren valgte en standard-mockup i hue-skabelonen, gav Gelato fotos, og importen af den broderede hue blev færdig automatisk på stg 2026-10-30 (printområde 144 × 140 px, 38 × 37 mm). **Uden en valgt mockup laver Gelato ingen billeder, heller ikke for broderi.**
   - Printområdet følger det, fotoet viser: en hue med et lag på 132 × 84 mm viste kun 484 × 170 px på ombukket, så printstørrelsen blev 132 × 46 mm. Gelato klipper resten af laget væk.
   - Hver mockup-scene giver ét billede. Hver import laver ét produkt pr. farve plus markør-produktet (14 ved 13 farver), og de bliver i Gelato-butikken.
   - Tjek printområder og farvekoder, og aktivér så grundproduktet. Fejl, "Prøv igen" og "Vis leverandørens svar" virker som for Printful.
6. **Mockups:** hver indsendelse opretter et produkt i Gelato-butikken med én variant pr. valgt farve og printfilerne i billedfelterne. Billederne er koblet til varianterne, så hver farve kan få sit eget mockup på den importerede side. Det er ikke afprøvet endnu; en farve uden billede beholder creatorens mockup. Imens kan designet ikke godkendes. Fejler det, bruges creatorens mockups, og du får en mail. Produkterne bliver liggende i Gelato-butikken.

**Begge:** runtime-test kræver, at sitet kan nås fra internettet (devpage), fordi Printful og Gelato henter printfilen via en URL. Local kan ikke nås udefra.

## Technical implementation
Planen (ADR-080–082):
- **Modul:** `src/Modules/ProductDesigner/`.
- **Tabeller (migration 35):** grundprodukter, designer-produkter, versioner, filer, leverandør-tasks og kampagner.
- **Designeren** er bygget med Fabric.js (≥ 7.2, lazy) i `assets/src/dashboard/sections/designer/`.
- **Rendering:** printfiler og mockups renderes i browseren og valideres på serveren.
- **Anvendelse i WooCommerce:**
  - idempotent og kan genoptages via Action Scheduler;
  - variabelt produkt, farve × størrelse;
  - kobling med kilden `designer`.
- **Legacy-værn:**
  - markør i `ProtectedProductMeta::KEYS`;
  - `DesignerProductGuard`;
  - prisskrivning kun i `WooCommerceDesignerWriter`;
  - arkitekturtest.
- **Printful:** mockup-generator med kortlivet signeret URL til printfilen.
- **Gelato:** katalog og mockups via en Gelato-skabelon pr. grundprodukt (produkt oprettes i en ren API-butik hos Gelato).
- **Katalogimport (review 30.15):** `CatalogImport` (jobbet `merchboard_designer_import`, en lås pr. grundprodukt) med `PrintfulCatalog`/`GelatoCatalog` som `CatalogRenderer`. `ImageProbe` (PHP-udvidelsen GD) sammenligner det tomme og markør-renderingen og finder printområdet som afgrænsningsboksen for markørens farvetone. De statiske filer ligger i `assets/designer/` (`blank.png`, `marker-1.png` … `marker-6.png`). Fremdriften ligger i `import_state`/`import_data` (migration 38).
- **Baggrund pr. farve (review 30.15):** en side kan have et foto pr. farve (lagnøglen `background:<farve>`); designeren og mockups bruger farvens foto og farver ikke masken.

## Permissions
- Admin: `merchboard_manage_designer`.
- Creator: `CreatorContext` (ADR-007); kun egne produkter og filer.
- Under "Se som creator" kan kladder redigeres, men ikke indsendes.

## Known limitations
- Kopper vises fladt (ingen perspektiv eller kurve).
- Printful kan kun testes med en offentlig URL (devpage).
- Gelato-mockups kræver en skabelon i Gelatos dashboard pr. grundprodukt, og designs samler sig som produkter i Gelato-butikken.
- Katalogimporten er eksperimentel: Printful er ikke verificeret mod det rigtige API. Gelato-importen er afprøvet på devpage 2026-10-30 med en printet t-shirt; mockups fra Gelato efter indsendelse er ikke afprøvet med den. Størrelser er fælles for alle farver, også når leverandøren ikke har en størrelse i en farve. Den kræver GD på serveren og et site, leverandørerne kan nå. En side, hvis markør ikke kan ses på fotoene, udelades.
- Importens status opdateres af sig selv på grundproduktet. Baggrundsjobbet følger WP-Cron og kan køre langt sjældnere end hvert 30. sekund.
- Ordrer produceres manuelt.
- En browser skal tegne op til 7200 × 6225 px på leggings. Det er ikke testet med et stort foto.
- Prodigi er ikke afprøvet mod det rigtige API.

## Future improvements
- Perspektiv/kurve-warp til kopper.
- Et fuldt skabelonbibliotek.
- Automatisk produktion hos Printful/Gelato (kræver ny ADR).
