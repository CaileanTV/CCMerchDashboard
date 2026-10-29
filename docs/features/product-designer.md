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

## Admin functionality
- **Grundprodukter:**
  - kilde: egen, Printful eller Gelato;
  - farver, størrelser og grundpris inkl. moms;
  - sider med mockup-skabelon og printområde i mm og dpi.
  - Skabelonen kan importeres fra en PSD med lagene `mb_background`, `mb_color`, `mb_shading`, `mb_highlight` og `mb_print_area`. AI-filer eksporteres først til PSD.
- **Kliparts og startdesigns**, som creatorne kan bruge.
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
Nøglerne indtastes i Merch Dashboard → Settings, når 30.12/30.13 er implementeret. De gemmes krypteret og vises aldrig igen.

**Printful** (ADR-081: kun katalog og mockups):
1. **Butik:** opret en konto på printful.com og en butik af typen "Manual order platform / API". Den må ikke være koblet til en webshop, så intet publiceres eller sælges via Printful.
2. **Token:** gå til Printfuls Developer Portal (developers.printful.com), og opret en **privat token** med adgang til **én butik** (den fra pkt. 1).
   - Med adgang til én butik skal vi ikke sende butiks-id. En konto-token kræver header'en `X-PF-Store-Id`.
   - **Scopes:** vi bruger kun kataloget (kræver intet scope) og mockup-generatoren (kræver en gyldig token). Vælg færrest muligt. Kræver portalen mindst ét scope, så vælg et skrivebeskyttet, fx `file_library/read`.
   - Vælg **ikke** `orders`, `sync_products` eller `webhooks`. Vi opretter aldrig ordrer eller produkter hos Printful.
3. **Merch Dashboard:** indsæt token'en i Settings → Printful, og slå Printful til.
4. **Grundprodukter:** importér produkter fra Printfuls katalog under Merch Dashboard → Grundprodukter (vælg produkt, farver og størrelser, sæt grundpris).
5. **Begrænsninger:** 120 kald pr. minut og 2–10 mockup-tasks pr. minut. Vi venter selv ved 429.

**Gelato** (ADR-082: katalog og mockups via Gelato-skabeloner):
1. **Butik:** opret en konto på gelato.com og en **ren API-butik** uden tilknyttet salgskanal (ikke Shopify eller Etsy), så produkterne aldrig publiceres. Notér butikkens id (`storeId`).
2. **API-nøgle:** gå til Developer → API Keys i Gelato-dashboardet, opret en nøgle, og kopiér den med det samme (den kan ikke ses igen). Nøglen giver adgang til ordrer, så den skal opbevares sikkert. Vi bruger den kun til kataloget og til skabelon-flowet.
3. **Skabelon pr. grundprodukt:** under Templates laver du en skabelon på det Gelato-produkt, du vil tilbyde, med mockups.
   - Tilføj et **billedlag pr. side** med et entydigt navn, fx `front` og `back`. Navnet bruges som "image placeholder" i API'et.
   - Gem, og kopiér skabelonens **Template ID**.
4. **Merch Dashboard:** indsæt nøgle og `storeId` i Settings → Gelato, og slå Gelato til.
5. **Grundprodukter:** importér produktet fra Gelatos katalog under Grundprodukter, og angiv Template ID. Vi henter skabelonens varianter og billedlag, og du kobler farver, størrelser og sider til dem.
6. **Bemærk:** hver indsendelse opretter et produkt i Gelato-butikken (her laves mockups). Produkterne bliver liggende dér.

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
