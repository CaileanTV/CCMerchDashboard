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
- **Status:** creatoren ser sine produkter som kladde, venter, i butikken eller afvist med admins note.
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
- **Notifikationer (30.9):** kvittering til creatoren (mail og klokke) og "nyt designer-produkt"/"ændring af designer-produkt" til admin. Til/fra, eksempel og testmail: Merch Dashboard → Designs → Notifikationer.

## Godkendelse og butikken (trin 30.10)
- Admin godkender eller afviser under **Designs**. En afvisning kræver en note; creatoren får den (mail og klokke) og ser den over designeren, og designet er en kladde igen.
- Et godkendt design lægges i butikken i baggrunden: et variabelt produkt med farve × størrelse, mockups som billeder, kategori (creatorens, ellers grundproduktets) og prisen ud fra grundprisen, når det lægges i butikken (fra 30.11). Første gang udgives det; creatoren får "godkendt og i butikken".
- Går det galt (fx butikken bruger priser ekskl. moms), står årsagen under Designs med **Prøv igen**, og admin får en mail. Intet i butikken ændres halvt: næste forsøg fortsætter, hvor det stoppede.
- **Ændringer:** et produkt i butikken har **Lav en ændring** i designeren. Ændringen sendes som et nyt design; butikken viser den godkendte version, til ændringen er godkendt. Udgåede farver/størrelser skjules (varianterne slettes aldrig).
- **Printfiler:** på ordren under hver designer-linje (den solgte version) og i boksen "Produktdesigner" på produktet. Kun admin kan hente dem.
- Pluginet skriver prisen, varianterne og billederne på designer-produkter; ret dem ikke i WooCommerce, de overskrives ved næste godkendelse.

## Ny grundpris og pensionering (trin 30.11)
- **Ny grundpris:** gem grundproduktet med den nye pris. Produkterne i butikken får den nye pris i baggrunden; creatorens procent eller beløb bevares, og creatoren får "ny pris" (før/efter). Ordrer lagt før beholder deres pris, og et salg afregnes efter den pris, butikken havde.
- En ny pris gør ikke ventende designs forældede; det gør kun en ændret skabelon eller et ændret printområde.
- **Pensionér** (på grundproduktet, med afkrydsning): dets produkter tages ud af butikken (de bliver kladder og bevares til ordrehistorikken), ventende designs afvises med en note, og creatorne får besked. Det kan ikke fortrydes; brug "Tilbage til kladde", hvis du kun vil stoppe nye designs.

## Admin functionality
- **Designs (trin 30.9):** Merch Dashboard → Designs viser de ventende designs (creator, produkt, version, grundprodukt, nyt/ændring, kundens pris), ældste først. Godkend og afvis kommer i 30.10. Fanen Notifikationer kræver også `merchboard_manage_settings`.
- **Grundprodukter:**
  - kilde: egen, Printful eller Gelato;
  - farver, størrelser og grundpris inkl. moms;
  - sider med mockup-skabelon og printområde i mm og dpi.
  - Skabelonen kan importeres fra en PSD med lagene `mb_background`, `mb_color`, `mb_shading`, `mb_highlight` og `mb_print_area`. AI-filer eksporteres først til PSD.
- **Kliparts og startdesigns**, som creatorne kan bruge.
- **Implementeret (trin 30.6):** Merch Dashboard → Grundprodukter med fanen Kliparts. Skabelonen udfyldes i formularen med PNG-lag og printområde.
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

## Afregning (ADR-080 pkt. 8)
- Kundens pris er grundpris + fortjeneste.
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

- **Sådan regnes det (trin 30.3):** fortjenesten og udbetalingen beregnes én gang, når salget registreres, og gemmes ved salgsrækken. Saldo, statistik og afregningsbilag bruger de gemte beløb. En refundering af hele stk. tager præcis deres fortjeneste tilbage. En refundering kun af et beløb ændrer intet for creatoren.
- **Priser:** grundprisen afrundes aldrig ned, og kundens pris er altid grundpris + fortjeneste.
- **Kampagner (trin 30.4):** admin opretter dem under Merch Dashboard → Kampagner. En ordrelinje er i en kampagne, når kampagnen kørte, da linjen blev købt, og ordren brugte en af kampagnens kuponkoder, eller kampagnen omfatter tilbudspriser, og linjen blev solgt under sin pris før kuponer. Afgørelsen tages én gang pr. ordrelinje: en senere refundering bruger samme kampagne, også hvis kampagnen er ændret. En creators egen rabatkode kan ikke være kampagnekode.

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

## Opsætning af Printful og Gelato (projektejeren)
Nøglerne indtastes i Merch Dashboard → Settings → Produktdesigner (Printful fra trin 30.12, Gelato fra 30.13). De gemmes krypteret og vises aldrig igen. Vejledningen herunder står også på Settings-siden.

**Printful** (ADR-081: kun katalog og mockups):
1. **Butik:** opret en konto på printful.com og en butik af typen "Manual order platform / API". Den må ikke være koblet til en webshop, så intet publiceres eller sælges via Printful.
2. **Token:** gå til Printfuls Developer Portal (developers.printful.com), og opret en **privat token** med adgang til **én butik** (den fra pkt. 1).
   - Med adgang til én butik skal vi ikke sende butiks-id. En konto-token kræver header'en `X-PF-Store-Id`.
   - **Scopes:** vi bruger kun kataloget (kræver intet scope) og mockup-generatoren (kræver en gyldig token). Vælg færrest muligt. Kræver portalen mindst ét scope, så vælg et skrivebeskyttet, fx `file_library/read`.
   - Vælg **ikke** `orders`, `sync_products` eller `webhooks`. Vi opretter aldrig ordrer eller produkter hos Printful.
3. **Merch Dashboard:** indsæt token'en i Settings → Produktdesigner → "Printful API token", slå Printful til, og gem.
4. **Grundprodukter:** under Merch Dashboard → Grundprodukter → "Importér fra Printful" indtaster du Printful-produktets id (tallet i produktets adresse hos Printful) og grundprisen inkl. moms. Produktet bliver en kladde med farver, størrelser og sider (skabelonbillede og printområde). Tjek det, tilføj gerne en farvemaske, så editoren kan farve produktet, og aktivér det.
   - Når et design på et Printful-grundprodukt sendes til godkendelse, laver Printful mockups (det tager typisk under et minut). Indtil da viser Designs creatorens egne mockups og kan ikke godkende. Fejler Printful, bruges creatorens mockups, og du får en mail.
   - Printful henter printfilen fra en kortlivet adresse på sitet. Det virker derfor kun på et site, Printful kan nå (devpage eller produktion), ikke på Local.
5. **Begrænsninger:** 120 kald pr. minut og 2–10 mockup-tasks pr. minut. Vi venter selv ved 429.

**Gelato** (ADR-082: katalog og mockups via Gelato-skabeloner):
1. **Butik:** opret en konto på gelato.com og en **ren API-butik** uden tilknyttet salgskanal (ikke Shopify eller Etsy), så produkterne aldrig publiceres. Notér butikkens id (`storeId`).
2. **API-nøgle:** gå til Developer → API Keys i Gelato-dashboardet, opret en nøgle, og kopiér den med det samme (den kan ikke ses igen). Nøglen giver adgang til ordrer, så den skal opbevares sikkert. Vi bruger den kun til kataloget og til skabelon-flowet.
3. **Skabelon pr. grundprodukt:** under Templates laver du en skabelon på det Gelato-produkt, du vil tilbyde, med mockups.
   - Tilføj et **billedlag pr. side** med et entydigt navn, fx `front` og `back`. Navnet bruges som "image placeholder" i API'et.
   - Gem, og kopiér skabelonens **Template ID**.
4. **Merch Dashboard:** indsæt nøglen i Settings → Produktdesigner → "Gelato API key" og butiks-id'et i "Gelato store id", slå Gelato til, og gem.
5. **Grundprodukter:** under Merch Dashboard → Grundprodukter → "Importér fra Gelato" indtaster du skabelonens Template ID og grundprisen inkl. moms. Skabelonen bliver en kladde med farver og størrelser fra varianterne og én side pr. billedlag med printstørrelsen i mm.
   - Gelato giver ingen farvekoder og intet skabelonbillede pr. side. Sæt farvekoderne, flyt printområdet ind på produktet (skabelonens forhåndsvisning er baggrund på alle sider), eller læg dine egne lag/PSD på, og aktivér så produktet.
   - Farver og størrelser skal hedde det samme som i skabelonen; ellers kan vi ikke finde skabelonvarianten, og mockups fejler.
6. **Mockups:** hver indsendelse opretter et produkt i Gelato-butikken med én variant pr. valgt farve og printfilerne i billedlagene. Når Gelato har lavet billederne (typisk 1–2 minutter, vi venter højst 20), bliver de designets mockups på første side; de øvrige sider beholder creatorens mockups. Imens kan designet ikke godkendes. Fejler det, bruges creatorens mockups, og du får en mail. Produkterne bliver liggende i Gelato-butikken.

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

## Permissions
- Admin: `merchboard_manage_designer`.
- Creator: `CreatorContext` (ADR-007); kun egne produkter og filer.
- Under "Se som creator" kan kladder redigeres, men ikke indsendes.

## Known limitations
- Kopper vises fladt (ingen perspektiv eller kurve).
- Printful kan kun testes med en offentlig URL (devpage).
- Gelato-mockups kræver en skabelon i Gelatos dashboard pr. grundprodukt, og designs samler sig som produkter i Gelato-butikken.
- Ordrer produceres manuelt.

## Future improvements
- Perspektiv/kurve-warp til kopper.
- Et fuldt skabelonbibliotek.
- Automatisk produktion hos Printful/Gelato (kræver ny ADR).
