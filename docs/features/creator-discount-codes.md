# Feature: Creatorens egen rabatkode

## Purpose
Hver creator får fra virksomheden en WooCommerce-kupon, så creatoren kan købe sit eget merchandise uden at betale for sin egen fortjeneste. Bruger en ordre en sådan kode, er creatorens egne varer i ordren ikke et salg: de må hverken tælle med i creatorens salg, fortjeneste, kommission, payout-saldo eller afregningsbilag. Andre creators' varer i samme ordre tæller normalt (projektejerens præcisering 2026-10-18) ([ADR-068](../DECISIONS.md), krav og designbeslutninger i [ROADMAP.md](../ROADMAP.md), fase 24, "creatorens egen rabatkode").

## Current status
Færdig i version 1.0.1 (trin a og b): registrering af koden på creatoren, creatorens visning af koden og udelukkelse af ordrer, der ikke var afregnet. Trin c er ikke implementeret: tilbageholdelse af afregnede beløb, "Bekræft"/"Ophæv" og spejlrækker for åbne sager (K2). Testet automatisk (unit, Jest, integration mod MariaDB); runtime-test på Merch Dashboard Local udestår.

## User-facing functionality
- **Profil → "Din rabatkode":** et kort med forklaringen "Ordrer købt med din egen rabatkode tæller ikke med i dit salg og din fortjeneste." Koden er skjult, indtil creatoren trykker "Vis rabatkode" (projektejerens beslutning 2026-10-18). Først da hentes den fra serveren (`GET /me/discount-code`), og "Skjul rabatkode" fjerner den fra siden igen. Uden aktiv kode står der "Du har endnu ingen rabatkode."
- Creatorens egne varer i en ordre med koden indgår ikke i creatorens salg, fortjeneste, ordreliste eller payout-saldo. Andre creators' varer i samme ordre tæller normalt hos dem. Creatoren ser ingen ordreoplysninger om udelukkelser (K3).

## Admin functionality
- **Creators → rediger creator → "Rabatkode"** (kun med `merchboard_manage_creator_pricing`, K4):
  - Den aktive kode, siden hvornår den gælder, og et link til kuponen i WooCommerce.
  - "Opret rabatkode" eller "Opret ny rabatkode" (fra trin 28.5, ADR-078): pluginet opretter koden og dens WooCommerce-kupon, hvis rabat er creatorens fortjeneste på egne produkter; en ny kode afslutter den aktive nu og flytter dens kupon til papirkurven. Og "Afslut kode". (Til og med 1.2.0: "Registrér kode" med en eksisterende kupon.)
  - Historikken over alle registreringer med periode, og hvem der registrerede og afsluttede dem.
  - En advarsel, når kuponen ikke længere er den registrerede (K5): kuponen findes ikke, eller koden tilhører en anden kupon end den registrerede. Registreringen afsluttes ikke automatisk, og ordrer med koden udelukkes fortsat.
- **Order history:** en ordre med en afgørelse er markeret "Udelukket – creator-rabatkode (creator)" eller "Åben sag – creator-rabatkode (creator)" for hver creator, hvis linjer er udelukket. Andre creators' linjer vises og tæller normalt. Sektionen "Ordrer udelukket af creator-rabatkoder" viser alle afgørelser med creator, kode, ordrens oprettelse og afgørelsestidspunkt, også for ordrer uden ledger-rækker. Den kan filtreres på åbne sager, og på ordrens side forklares afgørelsen.
- **Audit log:** "Creator-rabatkode registreret", "Creator-rabatkode afsluttet" og "Ordre udelukket (creator-rabatkode)".

**Fra trin 28.5 (ADR-078):** kuponen har rabattypen `merchboard_creator_profit` (`CreatorProfitCoupon`). For hver vare gælder den kun, når kuponen er en aktiv registrering, og produktet (eller variantens forælder) tilhører creatoren; rabatten pr. stk. er stykprisen × den effektive andel (`CreatorProfitDiscount`, ADR-049). Én kode håndterer dermed forskellige andele på forskellige produkter. Koder registreret manuelt før 1.3.0 virker som før, indtil en ny kode oprettes.

## Technical implementation
- **Registrering (K1, K5):** `CreatorCodeService` kontrollerer capability'en og normaliserer koden, som WooCommerce gør (`CouponCode`: `wc_format_coupon_code()` + `wc_strtolower()`). Derefter kontrollerer den:
  - at koden ikke er en gavekortkode hos en tilgængelig gavekortudbyder (kan det ikke kontrolleres, afvises registreringen);
  - at kuponen findes.

  Kuponen behøver ikke at være begrænset til en e-mailadresse, og creatoren behøver ingen koblet bruger (projektejerens ændring af K5, 2026-10-18).

  Registreringen serialiseres med navngivne låse, først pr. creator og derefter pr. kode, og kontrolleres igen i transaktionen. Tabellens UNIQUE-nøgler er sidste sikkerhedsnet. Registrering og afslutning auditeres i samme transaktion. En kode gælder fra registreringstidspunktet og frem; ældre ordrer omfattes aldrig (projektejerens beslutning 2026-10-18).
- **Vurdering i synkroniseringen (M2):** `OrderExclusionAssessor` kører i `SalesSynchronizer` under ordrens lås og i synkroniseringens transaktion, før planneren.
  - **Pr. creator:** udelukkelsen gælder kun linjerne fra den creator, hvis kode ordren brugte. Bruger en ordre creator A's kode og indeholder varer fra A og B, får A's linjer ingen ledger-rækker, mens B's linjer registreres og tæller normalt. Bruger ordren begge creators' koder, udelukkes begges linjer.
  - En gemt afgørelse er varig: fjernes kuponen, eller kommer der refunderinger, får den creators linjer stadig ingen nye ledger-rækker. De andre creators' linjer synkroniseres som altid, også deres refunderinger.
  - Ellers matches ordrens kuponkoder (læst via WooCommerce CRUD, `WC_Order_Item_Coupon`, ens for HPOS og legacy) mod alle registreringer af koderne. En registrering omfatter ordren, når dens periode [fra, til) indeholder ordrens WooCommerce-oprettelsestidspunkt. Der træffes kun en afgørelse, når ordren har linjer eller ledger-rækker fra registreringens creator.
  - Har den creator allerede ledger-rækker på ordren, låses creatorens række `FOR UPDATE` (den godkendte låseprotokol). En låsende læsning afgør så `open` (creatorens rækker ligger i et udstedt bilag, K2) eller `excluded`.
  - Afgørelsen og audit-posten skrives på låsens forbindelse. Planneren ser ordren uden de udelukkede linjer (`OrderExclusionView`): deres eksisterende rækker hverken tilbageføres eller korrigeres, og deres refunderingslinjer ignoreres.
  - Ukendte kupondata læses aldrig som "ingen creator-kode": synkroniseringen fejler og skriver intet (fejlmarkør `unexpected`).
  - Backfill rapporterer en ordre, hvor alle linjer er udelukket, som "Skipped, not counted". En ordre med andre creators' linjer registreres med dem.
- **Filtret (M2):** reglen ligger ét sted, i `OrderExclusionRepository::JOIN`/`COUNTS` og `SALES_NOT_EXCLUDED`, og bruges af alle læsemodeller af ledgeren:
  - salgsstatistik (`LedgerSalesStatistics`), fortjeneste (`LedgerCreatorProfits`), creatorens ordrelinjer (`SalesOrderLines`);
  - payout-saldo og nye bilag (`LedgerCreatorPayouts::earned()` og `unsettled_rows()`).

  Eksisterende ledger-rækker ændres og tilbageføres aldrig. Afregnede rækker tæller fortsat med deres gemte værdi i saldoen, fordi udstedte bilag aldrig ændres. Når en ordre med eksisterende rækker udelukkes, invalideres cachen af de berørte creators' salgsstatistik.

## Relevant files
- `src/Modules/WooCommerce/CreatorCodes/` (`CouponCode`, `CouponLookup`, `WooCommerceCouponLookup`, `CouponRecord`, `CreatorCode`, `CreatorCodeRepository`, `CreatorCodeService`, `CreatorCodeDeviations`, `CreatorCodeException`, `OrderExclusion`, `OrderExclusionStatus`, `OrderExclusionRepository`, `OrderExclusionAssessor`, `OrderExclusionAssessment`)
- `src/Core/Database/Migrations/CreateCreatorCodeTables.php`
- `src/Modules/WooCommerce/Admin/CreatorCodeSection.php`, `CreatorCodeActions.php`, `OrderHistoryPage.php`
- `src/Modules/WooCommerce/Rest/MeDiscountCodeController.php`
- `src/Modules/WooCommerce/Sales/SalesSynchronizer.php`, `OrderSnapshot.php`, `WooCommerceOrderSnapshotReader.php`, `LedgerSalesStatistics.php`, `SalesOrderLines.php`
- `src/Modules/WooCommerce/Pricing/LedgerCreatorProfits.php`, `LedgerCreatorPayouts.php`
- `assets/src/dashboard/api/discountCode.ts`, `assets/src/dashboard/sections/profile/DiscountCodeCard.tsx`
- `tests/Integration/WooCommerce/CreatorCodesTest.php`, `tests/Integration/Support/FakeCouponLookup.php`, `assets/src/dashboard/test/discount-code.test.tsx`

## Database/data used
Schema-version 17: `merchboard_creator_codes` (registreringer, bevares permanent) og `merchboard_order_exclusions` (én afgørelse pr. ordre og creator, slettes aldrig automatisk). Begge bevares ved opt-in-afinstallation. Se [DATABASE.md](../DATABASE.md#creator-rabatkoder--schema-version-17-adr-068). Ordrens kuponkoder læses fra WooCommerce og gemmes kun som den matchede, normaliserede kode på afgørelsen.

## Permissions
- Registrering og afslutning: `merchboard_manage_creator_pricing` (K4; ingen ny capability), kontrolleret i admin-handlingen og igen i servicen, med en nonce bundet til handlingen og creatoren.
- Visning på creatorens side og i ordrehistorikken: `merchboard_manage_creator_pricing` og `merchboard_manage_creators`.
- `GET /me/discount-code`: en aktiv creator via `CreatorContext` (ADR-007); ruten tager ingen parametre.

## API endpoints
`GET /merchboard/v1/me/discount-code` → `{ "code": "nova10" }` eller `{ "code": null }`, med `Cache-Control: no-store`. Se [API.md](../API.md).

## Hooks
Ingen nye. Bruger `merchboard_creators_edit_sections` (prioritet 9), `admin_post_merchboard_register_creator_code`, `admin_post_merchboard_end_creator_code` og den eksisterende synkronisering af ledgeren.

## Dependencies
WooCommerce (kuponer og ordrer via CRUD), gavekortudbyderne (YITH) til kontrollen af gavekortkoder, sales ledger, settlement-tabellerne og `CreatorRowLocks`.

## Tests
- Integration (MariaDB, `CreatorCodesTest`):
  - registrering, normalisering og audit, og alle afvisninger efter K5 og K4;
  - én aktiv kode pr. creator og pr. kode, også direkte mod UNIQUE-nøglerne;
  - serialisering med to databaseforbindelser (lås pr. kode og pr. creator);
  - skift og afslutning med historik, og afvigelser;
  - kun den kode-ejende creators linjer udelukkes, mens en anden creators linje (og dens refundering) registreres, med en varig afgørelse og audit uden aktør; en kode uden creatorens linjer udelukker intet; to creators' koder udelukker begges linjer;
  - perioden [fra, til) og ordrer før registreringen;
  - ukendte kupondata (fail closed);
  - filtret på alle fem læsemodeller, åben sag ved afregnede rækker med afregnede rækker, der fortsat tæller i saldoen, og backfill.
- Unit: tabel- og migrationslister, audit-handlinger, forespørgslen for creatorens ordrelinjer.
- Jest: validering af svaret, at koden ikke hentes eller vises før klik, skjul igen, ingen kode og fejl med "Prøv igen" uden servermeddelelser.

## Known limitations
- **Trin c er ikke bygget:** for en åben sag holdes det afregnede beløb ikke tilbage, hændelser efter udelukkelsen registreres ikke på sagen, og "Bekræft"/"Ophæv" og spejlrækker findes ikke (K2). En åben sag kan kun opstå, hvis koden tilføjes en ordre, efter at den er afregnet. Den vises tydeligt for administratoren.
- Ordrer oprettet før registreringen omfattes aldrig (beslutning 2026-10-18).
- **Ingen e-mailbegrænsning (beslutning 2026-10-18):** creatorens varer udelukkes i alle ordrer med koden, uanset hvem der køber. Deler creatoren koden med andre, fx fans, tæller deres køb af creatorens varer heller ikke med i creatorens salg, fortjeneste og payouts.
- En registrering, der committes, mens en ordre med koden synkroniseres i samme sekund, ses først ved ordrens næste synkronisering (snapshot-læsning). Ordren udelukkes så med sine eksisterende rækker.
- Skæringsdatorapporten (`bin/mb-245-cutover-report.php`, ikke i pakken) kender ikke udelukkelserne.

## Future improvements
Trin c (K2): tilbageholdt beløb pr. creator og valuta, registrerede hændelser på åbne sager, "Bekræft" med spejlrækker og "Ophæv".
