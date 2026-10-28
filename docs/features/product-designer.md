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
- **Notifikationer** (ADR-079):
  - admin: nyt produkt eller ændring til godkendelse;
  - creator: godkendt, afvist (med note) og ny pris, når grundprisen ændres.

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
- **Gelato:** kun katalog.

## Permissions
- Admin: `merchboard_manage_designer`.
- Creator: `CreatorContext` (ADR-007); kun egne produkter og filer.
- Under "Se som creator" kan kladder redigeres, men ikke indsendes.

## Known limitations
- Kopper vises fladt (ingen perspektiv eller kurve).
- Printful kan kun testes med en offentlig URL (devpage).
- Gelato-mockups kræver egne skabeloner.
- Ordrer produceres manuelt.

## Future improvements
- Perspektiv/kurve-warp til kopper.
- Et fuldt skabelonbibliotek.
- Automatisk produktion hos Printful/Gelato (kræver ny ADR).
