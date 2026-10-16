# Fase 23 – Payouts og gavekort: plan og ADR-udkast

**Dato:** 2026-10-10.

> **Opdatering 2026-10-11:** trin 23.2–23.8 er implementeret (ikke committet); ADR-054–058 og ADR-060 er accepteret, ADR-059 er fortsat Foreslået. Runtime-testen af YITH WooCommerce Gift Cards er udført (16.5). Intet er juridisk eller regnskabsmæssigt godkendt; R1–R4, G1–G3 og ADR-059 blokerer produktionsbrug. Teksten nedenfor er planen, som den blev skrevet.
>
> **Opdatering 2026-10-13:** fase 23 er committet (`8519bd7`) og pushet. ADR-059 er accepteret og implementeret (B2, `c100a67`), fritekstvagten er committet (`84f6f0c`), og fase 24, trin 24.2 er committet (`a65169a`). Intet er juridisk eller regnskabsmæssigt godkendt; R1–R4, G1–G3 og ADR-059 (R3 og runtime-test af afbrydelsen) blokerer fortsat produktionsbrug.
>
> **Opdatering 2026-10-16:** runtime-testen fra trin 24.3 står i 16.7. G1-koden fra trin 24.4 er integreret i `main` via PR #2 (squash-commit `950890c`; dokumentationscommit `4b39121`) efter ejerbeslutningerne B2–B5 (2026-10-16) og projektejerens særskilte godkendelse af merge (2026-10-16); B1 er ikke registreret som en særskilt ejerbeslutning, se 16.8. Det er hverken en release eller en deploy. Kuponværnet og visningerne er ikke runtime-verificeret mod rigtig WooCommerce og YITH, kommissionsgrundlaget er ikke besluttet, og G1 blokerer fortsat produktionsbrug.

**Status (oprindelig):** planlægning. Intet er implementeret. Projektejeren har godkendt 12 planlægningsbeslutninger (afsnit 1). ADR-054–060 nedenfor er **udkast** (status "Foreslået"); de flyttes først til [DECISIONS.md](../DECISIONS.md), når projektejeren har accepteret dem. Gavekort oprettes manuelt i et gavekortplugin og registreres og valideres i Merch Dashboard gennem en udskiftelig adapter; YITH WooCommerce Gift Cards 4.38.0 er den anbefalede første integration (afsnit 8). Afregningen skal ifølge projektejerens oplysning fra SKAT (mundtlig) håndteres som omvendt fakturering med et afregningsbilag på creatorens vegne (afsnit 13); intet er juridisk eller regnskabsmæssigt godkendt. Økonomiske invariants, grænsetilfælde, fejlhåndtering og blokeringer er samlet i afsnit 14; ejerbeslutninger, implementeringsrækkefølge og beslutningsoversigt i afsnit 15; beslutningsregister, runtime-testprotokol for YITH WooCommerce Gift Cards (ikke udført), beslutningsregel for G1 og de tre spor i afsnit 16; kvalitetssikring og readiness i afsnit 17; plan for en fake `GiftCardProvider`-adapter, gennemgang og implementeringsklarhed for spor B i afsnit 18; beslutningsgrundlag for første skridt i spor B i afsnit 19.

**Grundlag:** kun dette repository (kode, docs, ADR-004, 016, 019, 022–025, 029, 037–053) og læsning (ingen skrivning) af Merch Dashboard Local: WordPress 7.1.3, WooCommerce 11.1.2, PHP 8.2.29; aktive plugins kun `merchboard` og `woocommerce`; WooCommerce-kuponer slået til, 0 kuponer; YITH WooCommerce Gift Cards 4.38.0 installeret, men ikke aktiveret (fundet ved en ny kontrol samme dag, se afsnit 8); ext-sodium findes, og WordPress' `sodium_compat` er fallback; ingen `MERCHBOARD_*`-krypteringskonstant defineret; DKK med 2 decimaler, moms slået til, priser inkl. moms.

## 1. Godkendte planlægningsbeslutninger (2026-10-10)

| # | Emne | Beslutning |
| --- | --- | --- |
| 1 | Payout-formel | ADR-049 genbruges uændret: payout = profit for momsregistrerede, 80 % af profit for ikke-momsregistrerede. De 20 % er en fast forretningsregel, ikke en beregning af den faktiske momssats. **Reglen skal valideres regnskabsmæssigt, før der foretages reelle udbetalinger.** |
| 2 | Karens | 14 dage som standard, konfigurerbar i Merch Dashboard → Settings. Beregnes server-side ud fra ledger-rækkens `occurred_at` og gælder for bank, PayPal og gavekort. |
| 3 | Ukendt historik | Eksplicit, audit-logget afregningsstart pr. creator. Den udfylder eller ændrer aldrig ukendte historiske rækker. Kun rækker fra startdatoen og frem indgår, og de skal stadig have kendt payout. Findes der ukendte relevante rækker efter startdatoen, beregnes der intet disponibelt beløb ved at ignorere dem. |
| 4 | Gavekort | Admin opretter gavekortet manuelt. Creatoren modtager det i sin private Merch Dashboard-visning, når det er oprettet og valideret. Værdien = payout-beløbet. En almindelig WooCommerce-kupon må ikke uden videre kaldes et fuldgyldigt gavekort, fordi restværdien kan gå tabt (afsnit 8). Ingen plugininstallation og ingen ekstern service. **Præciseret senere samme dag:** gavekortet oprettes manuelt i et gavekortplugin og registreres og valideres derefter i Merch Dashboard; YITH WooCommerce Gift Cards 4.38.0 er den anbefalede første integration, ikke en fast afhængighed (afsnit 8). **Præcisering 2026-10-10:** payout-gavekortets værdi er **uden moms**; en tidligere besked om, at værdien skulle være inklusive moms, gælder ikke. **Bekræftet samme dag:** værdien er lig det endelige payout-beløb beregnet efter ADR-049; der lægges ikke moms oveni, og der trækkes ikke en ekstra momsandel fra ved udstedelsen. Det afklarer ikke i sig selv moms- eller bogføringsforholdene. |
| 5 | Opbevaring | Payout-historik og auditdata må ikke slettes automatisk ved almindelig afinstallation. En særskilt opbevaringsbeslutning er nødvendig (ADR-059). |
| 6 | Kryptering | `MERCHBOARD_PAYOUT_KEY` i `wp-config.php`. Bank og PayPal er deaktiveret, hvis nøglen mangler eller er ugyldig. Ingen hemmeligheder i logs eller audit. |
| 7 | Adgang | Separat capability til at se fulde betalingsoplysninger. Almindelig payout-adgang giver ikke automatisk adgang til fulde kontooplysninger. |
| 8 | Annullering | Creatoren kan annullere sin egen ventende anmodning; reservationen frigives atomisk. |
| 9 | Loft | Højst 3 ventende anmodninger og 10 nye anmodninger pr. døgn pr. creator. |
| 10 | Minimum | Intet ekstra minimum i første version. Beløbet skal være > 0 og ≤ disponibelt. |
| 11 | Betalingsoplysninger | Bank- og PayPal-oplysninger gemmes ikke til fremtidige anmodninger. |
| 12 | Saldo | Ukendte relevante rækker behandles aldrig som 0 og springes aldrig over. Negativ saldo vises som udestående og modregnes i fremtidig payout. |

## 2. Eksisterende arkitektur, der genbruges

| Område | Genbrug |
| --- | --- |
| Payout pr. ledger-række | `CreatorShare::payout()`, `RealizedPayout::for_entry()`, `SalesProfit::payout_for_entry()` (ADR-049). Ingen ny formel. |
| Aggregering | `LedgerCreatorProfits::KNOWN`, `::PROFIT` og `::FROM` (ADR-050) udvides med payout-reglen i SQL; summen testes lig Σ `RealizedPayout` pr. række, som ADR-050 gør for profit. |
| Creator-identitet | `CreatorContext::current()`, `RestController::require_creator()`/`run()`. |
| Lås og transaktion | `Transaction::run()` og `GET_LOCK`-mønstret med forbindelseskontrol fra `SalesRepository::with_order_lock()`/`erase_order()` (MariaDB og MySQL 8 i CI). |
| Capabilities | `Capabilities`, `RoleManager::VERSION` (nu 4). |
| Audit | `AuditTrail::record()` i samme transaktion, `AuditAction`, `AuditLogFormatter`. |
| Admin | Mønstret fra `OrderHistoryPage`/`OrderHistoryActions`/`OrderHistoryEraser` (admin-post, capability → nonce → bekræftelse → service → Post/Redirect/Get), undermenu via `admin_menu` direkte. |
| Settings | Settings-siden og dens sektioner (som "Sales ledger") til karensperioden. |
| Dashboard | `DashboardSectionRegistration`, `Card`, `Table`, `Pagination`, `EmptyState`, `moneyText`, `ProfitValue`, strenge parsere i `api/*.ts`, seks temaer via tokens. |
| Database | `Migrator` og migrationer (schema-version 8 → 9), `Tables`, `SchemaTest`/`MigrationTest`. |
| Beløb | `SalesAmount` (1/10 000-heltal), `PricingMath`; ingen floats. |

## 3. Disponibelt beløb (ADR-054)

Pr. creator og valuta, server-side, i 1/10 000-heltal:

```
vindue      = ledger-rækker for creatoren i valutaen med
              occurred_at ≥ creatorens afregningsstart (hvis sat)
              og occurred_at < nu − karensperiode
optjent     = Σ RealizedPayout over vinduet
gennemført  = Σ payouts med status completed
reserveret  = Σ payouts med status pending
saldo       = optjent − gennemført − reserveret
disponibelt = saldo, hvis saldo > 0, ellers 0
```

- **Bemærk:** vinduet ovenfor er udgangspunktet. Hvordan rækker afgrænses ved afregningsstarten (D1) og hvornår negative rækker tæller (D2), er afgjort af D1 og D2 (15.2–15.3, godkendt 2026-10-10, ADR-054 i DECISIONS.md).
- **Status pr. valuta:** `complete` (alle rækker i vinduet har kendt payout) eller `unknown` (mindst én række mangler snapshot med andel, prisdecimaler, momsstatus eller gemt moms i rækkens valuta). Ved `unknown` vises intet disponibelt beløb, og der kan ikke anmodes; antallet af ukendte rækker vises.
- **Afregningsstart:** sættes eksplicit af en administrator pr. creator (audit). Den udfylder eller ændrer ingen ukendte rækker; den afgrænser kun vinduet. Uden afregningsstart tæller hele historikken.
- **Negativ saldo:** vises som "udestående" og modregnes automatisk i fremtidig payout. Ingen inddrivelse.
- **Profit vises separat** (ADR-050), så forskellen mellem profit og payout er tydelig.
- **Valutaer** summeres og omregnes aldrig på tværs.
- **Kilde:** én SQL-aggregering (`LedgerCreatorPayouts`) med de samme konstanter som profit plus payout-reglen; ingen separat profitformel.

## 4. Datamodel og migrationsstrategi

Migration 9 (`CreatePayoutTables`). Ledgeren, snapshots og den gemte moms ændres ikke.

**`merchboard_payouts`**

| Kolonne | Indhold |
| --- | --- |
| `id` | Primærnøgle |
| `creator_id` | Creatoren (fra `CreatorContext`) |
| `currency`, `amount` decimal(19,4), `price_decimals` | Beløb i butikkens præcision på anmodningstidspunktet |
| `method` | `bank`, `paypal`, `gift_card` |
| `status` | `pending`, `completed`, `rejected`, `cancelled` (alle metoder, også gavekort) |
| `destination_hint` | Maskeret: "•••• 1234" (bank), "j•••@g•••.com" (PayPal); tom for gavekort |
| `idempotency_key` | Klientens UUID; `UNIQUE(creator_id, idempotency_key)` |
| `requested_by`, `requested_at` | Bruger og tid |
| `decided_by`, `decided_at`, `decision_reason` | Admin eller creator ved afslutning; begrundelse ved afvisning |
| `reference` | Valgfri reference fra bank/PayPal |
| `gift_card_provider`, `gift_card_external_id` | Udbyder-ID (fx `yith-wcgc`) og kortets ID i pluginet (ikke koden), sat ved `completed`; `UNIQUE(gift_card_provider, gift_card_external_id)`, så ét kort aldrig knyttes til to payouts |

Indexes: `KEY(creator_id, status, currency)`, `KEY(status, requested_at)`. Beløb, metode og creator ændres aldrig; status ændres kun fremad med en betinget UPDATE (`WHERE id = %d AND status = 'pending'`).

**`merchboard_payout_secrets`**: `payout_id` (PK), `kind`, `ciphertext` (sodium `secretbox`: nonce + ciphertext, base64), `key_id`, `created_at`, `purged_at`. Adskilt fra payouts, så en læsning af anmodninger aldrig rammer hemmeligheder. Ciphertext slettes ved `completed`, `rejected` og `cancelled`.

**Afregningsstart pr. creator:** ny kolonne `merchboard_creators.payout_start_at` (NULL = hele historikken) eller en option pr. creator; ændres kun via en admin-handling med audit.

**Karens:** ny nøgle `payout_holding_days` i `merchboard_settings` (standard 14).

**Historik og korrektioner:** status- og beløbsændringer spores via audit (afsnit 6) og rækkens faste felter. En gennemført payout kan ikke ændres; en fejl rettes med en ny, eksplicit post i en senere fase (kræver egen beslutning).

**Uninstall (rettet 2026-10-12):**
- **Før ADR-059:** opt-in-afinstallationen slettede også payout- og bilagstabellerne (ADR-025, ADR-060 060-7 a). Den tidligere formulering her var forkert.
- **Fra ADR-059 (B2, commit `c100a67`):** bevares creators (arkiveret), audit-log, payouts, afregningsbilag, bilagsrækker og bilagstællere. `merchboard_payout_secrets` (de krypterede betalingsoplysninger) slettes altid, og en ventende payout afbryder sletningen. Se DATABASE.md, "Afinstallation og datalivscyklus".

## 5. Arbejdsgang

Statusser for alle metoder: `pending` → `completed` | `rejected`; `pending` → `cancelled` (creatoren). Intet separat godkendelsestrin: behandlingen er selve godkendelsen.

1. **Anmodning:** creatoren vælger valuta, beløb eller "hele beløbet" og metode. Serveren tager en creator-lås (`GET_LOCK('merchboard_payout:<creator_id>')`) og en transaktion, tjekker loftet (3 ventende, 10 pr. døgn), genberegner disponibelt, validerer (> 0, ≤ disponibelt, højst butikkens decimaler; reg.nr. 4 cifre og kontonr. 1–10 cifre; `is_email()` for PayPal), krypterer oplysningerne, indsætter `pending` og skriver audit i samme transaktion. Et gensendt request med samme idempotency-nøgle returnerer den eksisterende anmodning.
2. **Annullering (creator):** betinget overgang `pending` → `cancelled` under samme lås; reservationen frigives atomisk; hemmeligheden slettes; audit.
3. **Bank/PayPal (admin):** fulde oplysninger kun i detaljevisningen af en ventende anmodning og kun med den separate capability (audit ved visning). Admin overfører uden for systemet og registrerer derefter "Udbetalt" med bekræftelsen "Overførslen er gennemført" og valgfri reference. Betinget overgang til `completed`; dobbeltklik giver "allerede behandlet"; hemmeligheden slettes; audit.
4. **Gavekort (admin):** admin opretter kortet manuelt i det valgte gavekortplugin med det viste beløb, valuta og payout-reference og registrerer det i Merch Dashboard. Adapteren validerer identitet, beløb = saldo = payout-beløbet, valuta, status og at kortet ikke er knyttet til en anden payout (afsnit 8.5). Først derefter `completed`, og creatoren kan åbne gavekortet i sin private visning.
5. **Afvisning (admin):** begrundelse påkrævet; `rejected`; reservationen frigives; hemmeligheden slettes; audit (uden begrundelsestekst).

## 6. Sikkerhed og databeskyttelse

- **Kryptering:** sodium `secretbox` med `MERCHBOARD_PAYOUT_KEY` (32 bytes, base64) fra `wp-config.php`; `key_id` til rotation. Nøglen afledes aldrig af WordPress' salts. Mangler eller er nøglen ugyldig, er bank og PayPal deaktiveret i formularen og afvises server-side, og admin får en notits. Ventende anmodninger, der ikke kan dekrypteres, kan kun afvises.
- **Dataminimering:** fulde oplysninger findes kun, mens anmodningen venter. Historik viser kun `destination_hint`; reg.nr. vises aldrig i historik. Ingen REST-route returnerer fulde oplysninger. Oplysninger gemmes ikke til fremtidige anmodninger.
- **Adgang:**
  - Creator: `CreatorContext` og ejerskab i SQL (`WHERE id = %d AND creator_id = %d`); fremmede ID'er giver 404 (IDOR).
  - `merchboard_manage_payouts`: se køen og historikken, afvise, registrere udbetaling og gavekort.
  - `merchboard_view_payout_details` (separat): se fulde bank- og PayPal-oplysninger i en ventende anmodning.
  - Begge kun til administratorer (`RoleManager::VERSION` 5); creators får dem aldrig.
- **Requests:** admin-post med nonce bundet til payout og handling; betingede overgange; idempotency-nøgle for creator; loft 3/10.
- **Cache:** payout-svar med `Cache-Control: no-store, private`; payouts caches ikke; salgscachen er uændret.
- **Audit:** `payout_requested`, `payout_cancelled`, `payout_rejected`, `payout_completed`, `payout_gift_card_registered`, `payout_details_viewed`, `payout_start_changed`, `payout_settings_changed`. Kun ID'er, beløb, valuta, metode og flag (`has_reason`, `has_reference`); aldrig konti, e-mails, koder eller begrundelsestekst.
- **Logs:** `Logger` kun med fejltype og ID'er.

## 7. API og frontend

Alle routes under `merchboard/v1`, kun med WooCommerce, login og REST-nonce, creator via `CreatorContext`:

| Route | Formål |
| --- | --- |
| `GET /me/payouts` | Saldo pr. valuta (`profit`, `earned`, `completed`, `reserved`, `outstanding`, `available`, `status`, `unknown_rows`, `holding_days`, `payout_start`) og egne anmodninger (pagineret); kun maskerede oplysninger |
| `POST /me/payouts` | Ny anmodning: `{ currency, amount \| "all", method, details, idempotency_key }` |
| `POST /me/payouts/{id}/cancel` | Annullér egen ventende anmodning |
| `GET /me/payouts/{id}/gift-card` | Eget udstedt gavekort (`no-store`): kode, oprindeligt beløb, aktuel saldo, status og evt. udløb, læst live gennem gavekortadapteren (afsnit 8.5) |

Admin-handlinger går via `admin-post.php` som resten af admin.

Frontend: sektionen "Udbetalinger" (`path: 'payouts'`) med `PayoutsSection`, `PayoutBalance`, `PayoutRequestForm`, `PayoutList` og `GiftCardReveal` (maskeret, "Vis", kopiknap; koden kun i komponentens state, aldrig i `localStorage`); `types/payouts.ts` og `api/payouts.ts`; temaer og dansk som resten.

Admin: undermenuen "Payouts" med tabellerne "Afventende anmodninger" og "Gennemførte udbetalinger", detaljevisning og `PayoutActions`; Settings-sektion for karens; afregningsstart på creatorens redigeringsside.

## 8. Payout-gavekort: manuel oprettelse og udskiftelig integration

> **Opdatering 2026-10-10 (projektejeren):** payout-gavekort oprettes **manuelt** af en administrator i gavekortpluginet og registreres og valideres derefter i Merch Dashboard. YITH WooCommerce Gift Cards 4.38.0 er den **anbefalede første integration**, ikke en fast eller obligatorisk afhængighed: integrationen skal være afgrænset og kunne udskiftes. Den tidligere kupon-analyse (A–D) og forslaget om automatisk udstedelse via YITH's klasser (`issuing`) er erstattet af dette afsnit. Den foreløbige betegnelse er "Payout-gavekort". **Payout-gavekortets værdi er uden moms** (præcisering 2026-10-10).

Afsnittet skelner mellem fire ting:

| | |
| --- | --- |
| **Anbefalet integration** | YITH WooCommerce Gift Cards 4.38.0 (gratisversionen), 8.1–8.3 |
| **Arkitekturkrav** | Gavekortintegrationer er afgrænsede adaptere bag en lille kontrakt og kan udskiftes, 8.4 |
| **Godkendt arbejdsgang** | Manuel oprettelse i pluginet, derefter registrering og validering i Merch Dashboard, 8.5 |
| **Åbne beslutninger** | 8.9 og afsnit 12 |

### 8.1 Anbefalet integration: verificeret plugin

Verificeret ved læsning (ingen aktivering, ingen ændringer) af pluginets filer på Merch Dashboard Local, 2026-10-10:

| Felt | Værdi |
| --- | --- |
| Plugin | **YITH WooCommerce Gift Cards** (gratisversionen), `yith-woocommerce-gift-cards/init.php` |
| Version | **4.38.0**, forfatter YITH; "WC requires at least: 11.0", "WC tested up to: 11.2"; `Requires Plugins: woocommerce` |
| Status på Local | **Installeret, men ikke aktiveret.** Ingen gavekort, indstillinger eller pluginets produktkategori endnu. |

Kilder: pluginets kode (`includes/class-yith-ywgc-gift-card.php`, `includes/class-yith-woocommerce-gift-cards.php`, `includes/class-yith-ywgc-cart-checkout.php`, `includes/class-yith-ywgc-emails.php`, `includes/admin/…`) og `readme.txt`. YITH's udviklerdokumentation er ikke læst (ekstern kilde). Pluginets klasser er interne, ikke et dokumenteret offentligt API.

### 8.2 YITH's datamodel (verificeret i koden)

- Et gavekort er en post af typen `gift_card`, ikke offentlig, med `capability_type => 'product'`.
- **Koden er postens `post_title` i klartekst** og kan slås op med `YITH_YWGC()->get_gift_card_by_code()`.
- Beløb og saldo: post meta `_ywgc_amount_total` og `_ywgc_balance_total`, gemt som floats afrundet til `wc_get_price_decimals()`; valuta i `_ywgc_currency`; udløb i `_ywgc_expiration` (tidsstempel, 0 = ubegrænset); intern note i `_ywgc_internal_notes`; modtager i `_ywgc_recipient`.
- Status: `publish` (aktiv), `ywgc-disabled` (deaktiveret), `ywgc-dismissed` (afvist), samt `ywgc-pre-printed` og `ywgc-code-not-valid`.
- Manuel oprettelse i pluginets admin ("Create Code") afviser en kode, der allerede findes på et publiceret kort.

### 8.3 YITH's funktioner og begrænsninger

| Emne | Fund |
| --- | --- |
| Manuel oprettelse | Admin kan oprette, redigere og deaktivere gavekort og ændre saldoen i backend (readme). |
| Restsaldo | **Verificeret:** saldoen nedskrives ved hver ordre, og kortet kan bruges flere gange. |
| Refund/annullering af en ordre betalt med kortet | **Verificeret:** saldoen gendannes ved `cancelled`, `refunded` og `failed` (med markør mod dobbelt gendannelse). Delvise refunds er ikke analyseret. |
| Deaktivering | **Verificeret:** `ywgc-disabled`/`ywgc-dismissed`. Brugt saldo kan ikke tages tilbage. |
| Ejerbinding | **Ingen:** `is_owner()` returnerer altid `true` ("todo" i koden). Filteret `yith_ywgc_gift_card_can_be_used` kaldes **ikke** ved indløsning. To andre filtre (`yith_ywgc_check_gift_card` i YITH's formular og `ywgc_verify_coupon_code_condition` i kuponfeltet) kan muligvis bruges, men den endelige saldo-fratrækning tjekker ikke ejerskab igen. Kortet er derfor en **ihændehaverkode**. |
| Indløsningsveje | **To veje med forskellig virkning:** (1) kuponfeltet (altid aktivt i gratisversionen) gør koden til en virtuel `fixed_cart`-kupon, som nedsætter ordrelinjernes beløb og moms; (2) YITH's egen formular nedsætter kun ordrens total, linjerne er uændrede. Blokcheckout/Store API bruger kuponvejen. |
| E-mail | **Verificeret:** sendes kun via `send_gift_card_email()` og kun til et digitalt kort med modtager; kald findes ved ordreoprettede kort og ved admins "Send email". Filteret `yith_wcgc_deny_gift_card_email` kan blokere. Om pluginets admin markerer manuelt oprettede kort som digitale med modtager, er **ikke verificeret**. |
| Udløb | **Verificeret:** feltet gemmes og håndhæves i begge indløsningsveje. Gratisversionens admin har ingen brugerflade til at sætte det (premium ifølge readme); ordreoprettede kort får `0`. |
| Hvem kan se koderne | Alle med produktrettigheder kan se alle koder i pluginets liste. Merch Dashboard kan ikke ændre det uden at ændre pluginet. |
| Moms ved indløsning | **Ikke analyseret** i detaljer; afhænger af indløsningsvejen (se ovenfor). Skal afprøves. |
| Tal | Pluginet regner med floats (`round( (float) … )`). |

### 8.4 Arkitekturkrav: en lille, udskiftelig integrationskontrakt

**Princip:** den centrale payout-logik kender kun en lille kontrakt og et udbyder-ID. Alt YITH-specifikt (klasser, post-type, meta-nøgler, statusværdier, floats) ligger i én adapter. Der bygges ikke et generisk plugin-framework: én kontrakt, én adapter (YITH), og et valg i Settings.

**Kontrakt (forslag), `GiftCardProvider`:**

| Metode | Formål |
| --- | --- |
| `id(): string` | Stabilt udbyder-ID, fx `yith-wcgc`, gemt på payouten |
| `availability(): GiftCardAvailability` | Tilgængelig og kompatibel? Pluginets version, og en fejlkode hvis ikke (`not_installed`, `inactive`, `unsupported_version`, `self_test_failed`) |
| `find_by_code( string $code ): ?GiftCardSnapshot` | Find præcis ét kort ud fra koden, som admin indtaster; `null`, når intet kort har koden (projektets konvention, 18.8); struktureret fejl ved flere kort eller læsefejl |
| `find_by_id( string $external_id ): ?GiftCardSnapshot` | Læs et registreret kort igen (visning og afstemning); `null`, når kortet ikke længere findes |
| `reveal_code( string $external_id ): string` | Hent koden til creatorens private visning, læst fra pluginet |
| `admin_url( ?string $external_id ): ?string` | Link til pluginets egen side for at oprette eller se kortet |

**`GiftCardSnapshot`** (værdiobjekt, uden koden): `external_id`, `amount_units` og `balance_units` (1/10 000-heltal), `currency`, `status` (`active`, `disabled`, `expired`, `other`), `expires_at` (eller null), samt flaget `can_reveal_code`.

**Fejl:** `GiftCardProviderException` med faste koder (`unavailable`, `ambiguous`, `invalid_data`, `read_failed`); "findes ikke" er `null`, ikke en undtagelse (18.8). Hverken beskeder, logs eller audit indeholder koden; logs indeholder kun udbyder-ID, fejlkode og payout-ID.

**Bevidst ikke i kontrakten:** automatisk udstedelse, deaktivering og ændring af saldo. Oprettelsen er manuel, og annullering eller deaktivering af et udstedt kort hører til den senere korrektionsfase. Kontrakten udvides først, når der er et reelt behov.

**Valg af integration:** en ny indstilling `payout_gift_card_provider` i Settings (standard: ingen). Merch Dashboard vælger aldrig selv en udbyder, fordi et plugin er installeret, og installerer, aktiverer, opdaterer eller konfigurerer aldrig et plugin. Gavekort kan kun vælges af creatoren, når en udbyder er valgt i Settings **og** `availability()` er grøn. Bank og PayPal fungerer uafhængigt af det.

**YITH-adapteren (`YithGiftCardProvider`)**, kun læsning:
- `availability()`: `YITH_YWGC_Gift_Card` og `YITH_YWGC()` findes, pluginversionen er i den testede række (4.38.x), og en selvtest af de læste felter lykkes. Ellers utilgængelig med fejlkode.
- `find_by_code()`: slår op via `YITH_YWGC()->get_gift_card_by_code()` og tjekker, at præcis ét kort i post-typen har koden (ellers `ambiguous`).
- Floats konverteres eksplicit til heltal: værdien læses som streng, normaliseres til butikkens decimaler og konverteres med `SalesAmount`; et tal, der ikke kan repræsenteres præcist, giver `invalid_data`. Det testes.
- Statusværdierne oversættes til kontraktens status; udløb ud fra `_ywgc_expiration`.
- `reveal_code()`: koden er postens titel, så Merch Dashboard behøver ikke gemme en kopi.
- Ingen skrivning til pluginets data.

**En senere adapter** til et andet plugin implementerer samme kontrakt og tilføjes i containeren og som valgmulighed i Settings; payout-logikken ændres ikke. Kan et andet plugin ikke hente koden igen ud fra et ID (`can_reveal_code = false`), gemmes koden krypteret med `MERCHBOARD_PAYOUT_KEY` i `merchboard_payout_secrets`. Det er en fallback for en senere adapter og bruges ikke for YITH.

### 8.5 Godkendt arbejdsgang: manuel oprettelse og validering

1. Admin (`merchboard_manage_payouts`) åbner en ventende gavekort-payout. Merch Dashboard viser **præcist beløb** (gavekortets værdi, uden moms), **valuta** og en entydig **payout-reference** (fx `MB-PAYOUT-123`) samt et link til pluginets oprettelsesside (`admin_url()`).
2. Admin opretter gavekortet manuelt i pluginets egen administration med beløbet (uden moms) og valutaen. Anbefalet: referencen skrives i pluginets interne note, hvis pluginet har et sådant felt (YITH har feltet `_ywgc_internal_notes`; om gratisversionens admin viser det, er **ikke verificeret**).
3. Admin registrerer kortet i Merch Dashboard via en beskyttet formular: POST til `admin-post.php`, nonce bundet til payout og handling, `merchboard_manage_payouts`, koden i et felt med `autocomplete="off"`. Koden står aldrig i URL'en og gentages aldrig i svaret.
4. Under creator-låsen og en transaktion validerer Merch Dashboard via adapteren:
   - integrationen er tilgængelig, og præcis ét kort har koden;
   - status `active` og ikke udløbet;
   - **beløb = saldo = payout-beløbet** (som heltal, uden moms), så kortet er ubrugt;
   - valuta = payoutens valuta;
   - kortet er ikke knyttet til en anden payout (`UNIQUE(gift_card_provider, gift_card_external_id)` i databasen, så det håndhæves også ved samtidige requests);
   - hvis en reference er påkrævet (8.9), at den interne note indeholder payout-referencen.
5. Kun hvis alt er bestået: betinget overgang `pending` → `completed`, gem udbyder-ID og eksternt ID (ikke koden), audit `payout_gift_card_registered` med udbyder-ID og eksternt ID.
6. Fejler valideringen, forbliver payouten `pending`; admin får en struktureret fejl ("beløbet passer ikke", "kortet er allerede brugt", "kortet er knyttet til en anden payout" …), og intet gemmes.
7. Creatoren ser kortet i sin private visning: `GET /me/payouts/{id}/gift-card` læser kortet live gennem adapteren (`find_by_id` + `reveal_code`), med `Cache-Control: no-store`. Viser kode (maskeret med "Vis"/"Kopiér"), oprindeligt beløb, aktuel saldo, status og evt. udløb. Er integrationen utilgængelig, vises "kan ikke vises lige nu"; payouten forbliver `completed`.

**Genforsøg og samtidighed:**
- Registreringen er idempotent: er payouten allerede `completed` med det samme kort, svarer Merch Dashboard "allerede registreret"; med et andet kort afvises den.
- To samtidige registreringer: låsen, den betingede UPDATE og den unikke nøgle sikrer, at kun én lykkes, og at et kort aldrig knyttes til to payouts.
- Et fejlet eller afbrudt forsøg ændrer intet; payouten bliver aldrig `completed` uden et valideret kort, og en fejl bliver aldrig til en payout på 0.

**Hvad der automatiseres (uden at automatisere oprettelsen):** forudfyldte oplysninger og payout-reference, link til pluginets side, al validering, kontrol mod dobbelt tilknytning, live visning af saldo og status, og en læse-afstemning i admin-listen (fx "kortet er deaktiveret eller slettet i pluginet").

**Administratorinput er ikke bevis:** koden bruges kun til at finde kortet; alt, der valideres, læses fra pluginet. Begrænsning: pluginet kan ikke bevise, at netop *denne* admin oprettede kortet til *denne* payout. Den interne note med payout-referencen (hvis den kan verificeres) og kravet om ubrugt saldo lig beløbet reducerer risikoen, men fjerner den ikke.

### 8.6 Beskyttelse af koden

- **Opbevaring:** for YITH gemmer Merch Dashboard **ingen** kopi af koden; den hentes fra pluginet ved visning. Krypteret opbevaring bruges kun for en senere adapter, der ikke kan hente koden igen (8.4).
- **Adgang:** kun den creator, payouten tilhører (`CreatorContext` + ejerskab i SQL), kan hente koden; `no-store`; koden kun i komponentens state.
- **Aldrig** i audit, logs, fejlbeskeder, URL'er eller Merch Dashboards admin-lister (de viser udbyder, eksternt ID og status). Fulde gavekortoplysninger i Merch Dashboards admin kræver `merchboard_view_payout_details`.
- **Kendte begrænsninger i YITH:** koden ligger i klartekst i pluginets data og kan ses af alle med produktrettigheder; kortet er en ihændehaverkode uden ejerbinding.

### 8.7 Begrænsninger og risici

- Adapteren bruger YITH's interne klasser og meta-nøgler; en opdatering kan ændre dem. Afbødning: versionstjek og selvtest i `availability()`, integrationstest mod den installerede version; gavekort bliver utilgængelige i stedet for at fejle stiltiende.
- Pluginet regner med floats; adapteren konverterer eksplicit og afviser upræcise værdier.
- Manuel oprettelse kan give fejl (forkert beløb, forkert valuta, kort brugt før registrering); valideringen fanger dem, men kan ikke bevise hvem der oprettede kortet.
- Indløsningsvejene påvirker ledgeren forskelligt (8.3); se den åbne beslutning om payout-grundlaget.
- Aktivering af pluginet er projektejerens konfigurationsændring.

### 8.8 Konsekvenser for de foreslåede ADR'er (forslag)

- **ADR-057** omskrives: payout-gavekort oprettes manuelt og registreres og valideres via en udskiftelig `GiftCardProvider`-adapter; YITH 4.38.0 er den anbefalede første adapter; ingen kopi af koden, når adapteren kan hente den; valg i Settings; gavekort utilgængelige uden grøn `availability()`.
- **ADR-055:** ingen `issuing`-status; gavekort følger `pending` → `completed` | `rejected` | `cancelled` som de andre metoder.
- **ADR-056:** `merchboard_payout_secrets` bruges til bank og PayPal og kun til gavekort for en adapter uden `reveal_code`.
- **ADR-016:** et gavekortplugin er ikke en ekstern service, men en valgfri tredjepartsintegration; den godkendes og registreres i ADR-057.
- Beslutning 4 i afsnit 1 ("admin opretter manuelt") gælder; den foreslåede automatiske udstedelse er trukket tilbage.

### 8.9 Åbne beslutninger om gavekort

1. Om ordrer betalt med payout-gavekort skal udelukkes fra payout-grundlaget.
2. Om og hvordan gavekort kan begrænses til creatoren (kræver runtime-test af alle indløsningsveje; indtil da en ihændehaverkode).
3. Udløb, restværdi, annullering og refundering.
4. Bogføring og moms (revisor eller juridisk rådgiver).
5. Om payout-referencen i pluginets interne note skal være et krav ved registrering (kræver verificering af, at feltet kan udfyldes i pluginets admin).
6. E-mail fra pluginet ved manuelt oprettede kort (ikke verificeret).
7. Godkendelse af syntetiske `MB-T23`-testgavekort på Local.
8. Betegnelsen "Payout-gavekort" og vilkårene (foreløbig; kræver regnskabsmæssig og juridisk godkendelse).

**Fastlagt (præcisering og bekræftelse 2026-10-10):** payout-gavekortets værdi er lig det endelige payout-beløb beregnet efter ADR-049. Der lægges ikke moms oveni, og der trækkes ikke en ekstra momsandel fra ved udstedelsen. Valideringen i 8.5 (beløb = saldo = payout-beløbet) gælder uændret. Det betyder ikke, at moms- eller bogføringsforholdene er afklaret (punkt 4 ovenfor og afsnit 13).

## 9. ADR-udkast

Alle har status **Foreslået**, undtagen ADR-054, ADR-055, ADR-056 og ADR-058 (accepteret 2026-10-10) samt ADR-057 og ADR-060 (accepteret 2026-10-11), som står i [DECISIONS.md](../DECISIONS.md). ADR-059 er fortsat Foreslået. **Opdatering 2026-10-12:** ADR-059 er accepteret og implementeret (B2, commit `c100a67`), se DECISIONS.md.

| ADR | Indhold | Forholdet til eksisterende ADR'er |
| --- | --- | --- |
| ADR-054: Disponibelt beløb | Vindue (afregningsstart, karens), optjent = Σ `RealizedPayout`, gennemført, reserveret, negativ saldo som udestående, `unknown` uden beløb, valutaer | Bygger på ADR-049 (uændret, inkl. den regnskabsmæssige validering af 20 %), ADR-050 (aggregering), ADR-037/038 (ledgeren læses kun) |
| ADR-055: Payout-statusser og reservation | `pending`/`completed`/`rejected`/`cancelled` for alle metoder (ingen `issuing`, afsnit 8.8), creator-lås + transaktion, idempotency, loft 3/10, annullering, intet minimum | Lås- og transaktionsmønster fra ADR-024/038 |
| ADR-056: Bank- og PayPal-oplysninger | Sodium med `MERCHBOARD_PAYOUT_KEY`, deaktivering uden gyldig nøgle, maskering, sletning efter afslutning, ingen genbrug | Ny; ingen konflikt (ADR-004: sodium findes i PHP/WordPress) |
| ADR-057: Gavekort | Manuel oprettelse i et gavekortplugin; registrering og validering gennem en udskiftelig `GiftCardProvider`-adapter; YITH 4.38.0 som anbefalet første adapter; valg i Settings; ingen kopi af koden, når adapteren kan hente den; gavekort utilgængelige uden grøn `availability()` (afsnit 8.4–8.8) | ADR-016 (valgfri tredjepartsintegration godkendes og registreres i ADR-057) |
| ADR-058: Adgang og audit | `merchboard_manage_payouts` og separat `merchboard_view_payout_details` (rolleversion 5), audit-actions uden hemmeligheder | ADR-022 (rollesynkronisering), ADR-023 (audit) |
| ADR-060: Afregningsbilag ved omvendt fakturering (**accepteret 2026-10-11**, se DECISIONS.md) | Afregningsbilag dannet af Merch Dashboard på creatorens vegne pr. gennemført payout (forslag), uforanderligt, fortløbende nummereret, sporbart til ordrer, beregning og payout; foreløbigt indhold; formkrav valideres med revisor/SKAT (afsnit 13) | Bygger på ADR-049 (beregning uændret) og ADR-059 (opbevaring); ingen ændring af eksisterende ADR'er |
| ADR-059: Opbevaring | Payout-historik og audit slettes ikke automatisk ved almindelig afinstallation; opbevaringsperiode og opt-in-sletning kræver særskilt beslutning | **Kræver supplement til ADR-025** (opt-in-uninstall sletter i dag alle plugin-tabeller, også `merchboard_audit_log`) og berører ADR-029 (audit-retention) |

**Andre berørte ADR'er (ingen ændres i denne fase):**
- **ADR-052:** sletning af en testordres historik ændrer "optjent". Når en creator har gennemførte payouts, kan saldoen blive negativ. Kræver en dateret note eller et supplement, fx en advarsel eller afvisning, når ordren ligger før en gennemført payout.
- **ADR-049:** den regnskabsmæssige validering af 20 %-reglen er en forudsætning for reelle udbetalinger.

## 10. Implementeringsplan

> Den detaljerede rækkefølge med kategorier, afhængigheder og blokeringer står i afsnit 15.7; tabellen nedenfor er den overordnede trinopdeling.

| Trin | Indhold | Accept |
| --- | --- | --- |
| 23.1 | Projektejeren godkender de ADR-forslag og tekniske ejerbeslutninger, der åbner spor B (17.4); ADR'erne flyttes først til DECISIONS.md, når de er godkendt. Gavekortbeslutningerne G1–G3 blokerer kun produktion; aktivering af YITH WooCommerce Gift Cards og `MB-T23`-testdata (D7) kræves først til YITH-adapterens runtime-test | Ingen åbne blokerende spørgsmål for det næste trin |
| 23.2 | `PayoutSummary` og `LedgerCreatorPayouts` (vindue, karens, afregningsstart) | Hver sum = Σ `RealizedPayout` (refunds, reversals, afrunding, momsstatus); `unknown` er aldrig 0; én forespørgsel |
| 23.3 | Migration 9, `PayoutRepository`, `PayoutBalance`, `PayoutRequests` (lås, transaktion, idempotency, loft), `PayoutSecrets` (sodium), capabilities (version 5), audit-actions, Settings (karens), afregningsstart | Samtidige anmodninger kan ikke overskride saldoen; gensendte requests giver én post; hemmeligheder krypteret og slettet; schema-/migrationstests på MariaDB og MySQL |
| 23.4 | `MePayoutsController` | IDOR, ingen fulde oplysninger i svar, `no-store`, validering, loft, annullering |
| 23.5 | Dashboardsektionen "Udbetalinger" | Jest: saldo, fuld og delvis anmodning, maskering, annullering, temaer, dansk |
| 23.6 | Admin "Payouts" (bank/PayPal), detaljevisning med separat capability | Capability og nonce, betingede overgange, fuld visning kun mens ventende og kun med capability, audit uden hemmeligheder |
| 23.7 | Gavekort: `GiftCardProvider`-kontrakten, `YithGiftCardProvider` (kun læsning, versionstjek og selvtest), valg i Settings, registreringsformularen og -handlingen, creatorens visning med live saldo, afstemning i admin-listen | Gavekort utilgængelige uden valgt og grøn integration, bank/PayPal uberørt; validering af identitet, beløb = saldo = payout, valuta og status som heltal; et kort kan aldrig knyttes til to payouts (også samtidige requests); ingen `completed` uden valideret kort; kode kun til den rette creator, `no-store`, aldrig i logs/audit/URL; payout-logikken bruger ingen YITH-klasser eller meta-nøgler; test mod den installerede version 4.38.0 |
| 23.8 | Afregningsbilag (afsnit 13): creator- og virksomhedsstamdata, bilag og korrigerende bilag med rækkesporbarhed (intet accept-flow; aftaler indgås uden for Merch Dashboard, 13.7), dannelse i samme transaktion som `completed`, nummerserie, creator- og adminvisning | Forudsætter produktejerens foreløbige beslutninger om punkt 12, 13/14 (feltsæt), 16 og 14/17 (nummerserie og model) i 13.6; produktionsbrug kræver revisor/SKAT-afklaringerne og at aftalegrundlaget er på plads uden for Merch Dashboard (punkt 11); intet bilag uden gennemført payout og ingen gennemført payout uden bilag; uforanderligt; fortløbende numre uden genbrug (også ved samtidige payouts); sporbart til ordrer og beregning; ingen fulde betalingsoplysninger eller gavekortkoder; markeret som foreløbigt indtil revisor/SKAT har godkendt formkravene |
| 23.9 | Oversættelser, docs, runtime på Local med syntetiske `MB-T23`-data, final review | Fuld validering; oprydning til baseline; rigtige data kun læst |

## 11. Testplan

- **Unit:** beløbsvalidering og decimaler, maskering, krypteringsrundtur og ugyldig nøgle, statusmaskine, parsere.
- **Integration (rigtig database):** saldo = Σ `RealizedPayout`; karens og afregningsstart; `unknown` blokerer; negativ saldo; valutaer; fuld og delvis anmodning; over saldo; samtidighed med to forbindelser og lås; idempotency; loft 3/10; annullering og afvisning frigiver; betingede adminovergange (dobbeltklik); IDOR; capabilities inkl. den separate visnings-capability; audit uden hemmeligheder; migration 9; uninstall bevarer payouts.
- **Afregningsbilag, tekniske tests (kan forberedes nu):**
  - unikke bilagsnumre ved samtidige udstedelser (to forbindelser), ingen dubletter og ingen tælling af eksisterende bilag;
  - genkørsel af samme udstedelse giver det eksisterende bilag, ikke et nyt nummer;
  - fejl midt i udstedelsen ruller tæller, bilag, rækker og payout-status tilbage;
  - et udstedt bilag er uændret efter ændring af creatorens stamdata, markup, momsstatus og beregningsversion;
  - hver ledger-række afregnes højst én gang (`UNIQUE(event_key)`); dobbeltkorrektion af samme bilag afvises;
  - et korrigerende bilag henviser til det oprindelige, som aldrig ændres; en senere rettelse retter det seneste korrigerende bilag;
  - en refund efter et bilag kommer med i næste bilag og reducerer saldoen; et negativt resultat bliver udestående uden automatisk udbetaling eller opkrævning;
  - en korrektion efter en gennemført payout ændrer ikke payouten;
  - adgangskontrol: creator A kan ikke se creator B's bilag (`no-store`); admin uden `merchboard_manage_payouts` afvises;
  - sporbarhed fra ordre til payout og tilbage (ordrehistorik → bilagsrække → bilag → payout → korrigerende bilag);
  - ingen fulde betalingsoplysninger eller gavekortkoder i bilag eller audit; gavekort-payouts har samme sporbarhed som bank og PayPal;
  - ét bilag pr. payout (`UNIQUE(settlement_payout_id)`), periode fra forrige bilag til karens-grænsen, delvis payout overfører resten.
- **Afregningsbilag, tests der afhænger af regnskabsregler** (venter på revisor/SKAT): obligatoriske felter og momstekst for begge momsstatusser; nummerformat og om huller er tilladt; om en refund efter et bilag kræver et korrigerende bilag (kreditnota) i stedet for fremadrettet modregning; dokumenttypens betegnelse; krav om DKK-beløb ved anden valuta.
- **Regression:** profit, sales inkl. moms, ordrehistorik, WooCommerce-priser og -ordrer uændrede.
- **Jest:** komponenter, parsere, temaer og dansk.
- **Gavekort, kontrakt (unit):** payout-logikken mod en fake-adapter: tilgængelighed, alle valideringsfejl, dobbelt tilknytning, idempotent registrering, strukturerede fejl uden kode.
- **Gavekort, YITH-adapter (integration mod den installerede version, når pluginet er aktiveret):** opslag på kode, flere kort med samme kode giver `ambiguous`, float-konvertering til heltal (inkl. upræcise værdier), statusoversættelse, udløb, deaktiveret og slettet kort, utilgængelig ved manglende plugin eller anden version; creatoren ser kun egne kort; restsaldo efter delvis indløsning; samtidige registreringer af samme kort.
- **Runtime på Local:** syntetiske creators, ordrer og syntetiske gavekort i YITH WooCommerce Gift Cards med prefix `MB-T23` (kræver godkendelse af testdata i pluginet); oprydning til baseline; ordre 333, creator 1, logo 198 og `dark-pink` kun læst eller bevaret.

## 12. Resterende beslutninger

> **Krydsreference til beslutningsregistret (16.1):** 1 → G1, 2 → G2, 3 → G3, 4 → R1, 5 → R3, 6 → R2, 7 → D5, 8 → D6, 9 → L1, 10 → D7, 11 → R4, 12 → D11 (+ R1), 13 → R1 (+ D10), 14 → D9 og D10 (+ R1), 15 → R3, 16 → D10, 17 → D8. Status og anbefalinger står i registret.

**Godkendt (2026-10-10):** manuel oprettelse af gavekort efterfulgt af registrering og validering i Merch Dashboard; gavekortintegrationer som afgrænsede, udskiftelige adaptere; YITH WooCommerce Gift Cards 4.38.0 som anbefalet første integration; foreløbig betegnelse "Payout-gavekort"; payout-gavekortets værdi er lig det endelige payout-beløb efter ADR-049, uden tillæg eller fradrag af moms (afsnit 8.9). Merch Dashboard skal kunne danne et afregningsbilag på creatorens vegne ved omvendt fakturering (brugeroplyst baggrund fra SKAT, afsnit 13).

**Åbne beslutninger** (forbliver åbne, indtil projektejeren udtrykkeligt godkender dem):

1. Om ordrer betalt med payout-gavekort skal udelukkes fra payout-grundlaget.
2. Om og hvordan gavekort kan begrænses til creatoren.
3. Udløb, restværdi, annullering og refundering.
4. Bogføring og moms (revisor eller juridisk rådgiver).
5. Opbevaring og uninstall efter ADR-025 og ADR-029.
6. Regnskabsmæssig validering af 20 %-reglen efter ADR-049.
7. Testordrer og ADR-052.
8. Afregningsstart som databasekolonne eller konfiguration.
9. Senere korrektion af gennemførte payouts.
10. Godkendelse af syntetiske `MB-T23`-testgavekort på Local.

**Åbne punkter om omvendt fakturering** (prioriteret i afsnit 13.6 med hvad der skal besluttes, hvorfor, hvad det blokerer, hvem og hvad der lukker det):

11. Aftalegrundlaget for omvendt fakturering (prio 1; blokerer produktion, ikke implementering). Aftaler indgås i dag uden for Merch Dashboard; fase 23 har intet accept-flow. Før produktion skal det være dokumenteret uden for pluginet, at aftalegrundlaget er på plads. En eventuel digital accept i Merch Dashboard er en mulig senere forbedring (ROADMAP, "Senere").
12. Rettelser og kreditnotaer, herunder refunds efter et bilag (prio 3; teknisk mekanisme foreslået i 13.9: uforanderlige bilag, korrigerende bilag med henvisning, fremadrettet modregning; den regnskabsmæssige regel og dokumenttypen blokerer produktion).
13. Momsstatus og momstekst på bilaget (prio 2; blokerer produktion).
14. Formkrav: obligatoriske oplysninger (prio 2), nummerering, sprog og valuta (prio 6) (revisor eller SKAT; blokerer produktion). Teknisk forslag til nummerering i 13.8 (transaktionssikker tællerrække, ingen tælling af eksisterende bilag); format, serier og om huller er tilladt afklares.
15. Opbevaring af bilag (prio 5; sammen med ADR-059; blokerer produktion).
16. Creator- og virksomhedsstamdata på bilaget og deres beskyttelse (prio 4; feltlisten blokerer implementering).
17. Periodemodel: ét bilag pr. payout (foreløbigt anbefalet, 13.3) eller periodiske bilag (prio 6; valideres med revisor eller SKAT).

**Nye forretningsbeslutninger fra afsnit 14.6** (konkretiseret med alternativer og anbefalinger i afsnit 15 som D1–D4 og G1; samlet oversigt i 15.8) (påvirker saldoen og blokerer de berørte beregnings- og låseregler): refunds af salg fra før afregningsstart (vindue pr. række eller pr. ordrelinje); om negative rækker tæller uden karens; om afregningsstarten kan ændres, når der findes et afregningsbilag; inddrivelse af udestående uden nyt salg. Punkt 1 ovenfor (ordrer betalt med payout-gavekort) er uddybet med valg A–C i 14.4.

Desuden tekniske spørgsmål til afklaring under implementeringen (afsnit 8.9): om payout-referencen i pluginets interne note skal være et krav, og om pluginet sender e-mail ved manuelt oprettede kort.

## 13. Afregningsbilag ved omvendt fakturering (self-billing)

> **Baggrund (brugeroplyst, 2026-10-10):** projektejeren har været i kontakt med SKAT, som mundtligt har oplyst, at afregningen skal håndteres som en form for **omvendt fakturering (self-billing)**, fordi samarbejdet i praksis er affiliate marketing/kommission i forbindelse med salg af merchandise. Det er projektejerens gengivelse af en mundtlig vejledning, **ikke** en verificeret skriftlig afgørelse og ikke en fuldstændig juridisk eller regnskabsmæssig vurdering. Intet i dette afsnit er juridisk eller regnskabsmæssigt godkendt.

### 13.1 Hvad bilaget er

- Merch Dashboard skal kunne danne et **afregningsbilag på vegne af creatoren** for creatorens kommissionsindtægt, som led i den aftalte omvendte fakturering.
- Bilaget er creatorens afregning over for virksomheden, udarbejdet af Merch Dashboard på creatorens vegne. Det er **ikke** en almindelig faktura, som virksomheden udsteder i eget navn, og ikke blot en udbetalingskvittering. Betegnelsen og teksterne i brugerfladen skal afspejle det (fx "Afregningsbilag (udstedt af [virksomheden] på vegne af [creatoren])"; den endelige ordlyd valideres).
- Hvert bilag knyttes entydigt til **én creator**, **én afregningsperiode** og **den payout**, det vedrører (foreløbig model: ét bilag pr. payout, 13.3).

### 13.2 Foreløbigt indhold (dokumentdesign, ikke endelige formkrav)

Oversigten skelner mellem felter, der er **teknisk nødvendige for sporbarhed** (T), og felter, der **skal bekræftes med revisor eller SKAT** (R), før bilaget bruges i produktion. Ingen af felterne er fastlagt som juridisk obligatoriske.

| Gruppe | Felt | T | R | Kilde (forslag) |
| --- | --- | --- | --- | --- |
| Creatoroplysninger | Creator-ID | ✓ | | `merchboard_creators` |
| | Navn | ✓ | ✓ | Ny stamdata (punkt 16) |
| | Adresse, CVR/SE-nr. | | ✓ | Ny stamdata, kun hvis kravet bekræftes |
| | Momsstatus på udstedelsestidspunktet | ✓ | ✓ | `vat_registered` (ADR-048/049) |
| Virksomhedsoplysninger | Navn, adresse, CVR | | ✓ | Ny indstilling eller butikkens WooCommerce-adresse |
| Bilagsidentifikation | Internt bilags-ID | ✓ | | Teknisk, vises ikke som bilagsnummer |
| | Bilagsnummer | ✓ | ✓ (format, serie) | Nummerserie (13.8) |
| | Bilagstype (afregningsbilag / korrigerende bilag) | ✓ | ✓ (betegnelse) | 13.9 |
| | Tekst om omvendt fakturering ("udstedt på vegne af …") | | ✓ | Fast tekst i skabelonen (13.7) |
| Periode og datoer | Afregningsperiode (fra–til) | ✓ | ✓ | 13.3 |
| | Udstedelsesdato | ✓ | ✓ | Udstedelsestidspunkt |
| Kommissionsgrundlag og beregning | Ledger-rækker (event-nøgler) og ordrer | ✓ | | Bilagsrækker |
| | Salgsbeløb inkl. moms (grundlag) | ✓ | ✓ (om det skal vises) | ADR-049 pkt. 4 |
| | Andel (markup) pr. række | ✓ | | Snapshot (ADR-047/049) |
| | Beregningsversion | ✓ | | 13.4 |
| Beløb og valuta | Kommission (profit), payout-beløb (netto), overført saldo/udestående | ✓ | ✓ (betegnelser) | ADR-049, ADR-054 |
| | Valuta | ✓ | ✓ (krav om DKK-beløb ved anden valuta) | Payoutens valuta |
| Momsoplysninger | Momsbeløb og momstekst | | ✓ | Ikke fastlagt (punkt 13) |
| Payout | Payout-ID og -reference | ✓ | | `merchboard_payouts` |
| | Metode og maskeret destination ("•••• 1234", "Payout-gavekort, udbyder + ID") | ✓ | | Aldrig fulde konti, e-mails eller gavekortkoder |
| Korrektioner | Henvisning til det oprindelige bilag (på et korrigerende bilag) og til senere korrigerende bilag (i visningen) | ✓ | ✓ (form) | 13.9 |

Creator-aftaler indgås uden for Merch Dashboard; bilaget kræver ingen accept i pluginet (13.7).

### 13.3 Foreløbigt anbefalet model: ét afregningsbilag pr. payout

**Status:** planlægningsanbefaling, **ikke** en juridisk eller regnskabsmæssig konklusion. Modellen forbliver foreløbig, indtil den er valideret med revisor eller SKAT (punkt 17 i 13.6).

Hvert bilag knyttes entydigt til:
- **creatoren**;
- **kommissionen og beregningsgrundlaget**, som payouten bygger på;
- **payout-ID'et og udbetalingsmetoden** (bank, PayPal eller Payout-gavekort);
- **bilagsdatoen og afregningsperioden**: fra slutningen af creatorens forrige bilag (eller afregningsstarten) til den karens-grænse, payouten blev beregnet ud fra; bilaget indeholder de rækker, der endnu ikke er afregnet og er frigivet efter D1/D2 (15.4);
- **eventuelle senere rettelser, refunds eller kreditnotaer**, som henviser til bilaget.

Bilaget viser kommissionen optjent i perioden, en eventuel overført saldo eller et udestående fra tidligere og det beløb, der udbetales med denne payout. Udbetales kun en del af den disponible saldo, overføres resten til næste bilag.

**Gavekort** følger samme sporbarhed som bank og PayPal: bilaget henviser til payouten og til gavekortet ved udbyder og eksternt ID (aldrig koden). Gavekortets værdi er fortsat det godkendte payout-beløb efter ADR-049, uden moms oveni og uden et ekstra momsfradrag ved udstedelsen.

**Alternativ: periodiske bilag** (fx månedlige) for optjent kommission, med payouts som betalinger mod saldoen:

| Konsekvens | Ét bilag pr. payout (anbefalet) | Periodiske bilag |
| --- | --- | --- |
| Sporbarhed | 1:1 mellem bilag og payout; perioden og rækkerne følger af payouten | Et bilag kan dække flere payouts og en payout flere bilag; kræver en fordeling af betalinger på bilag |
| Rettelser og refunds | En refund efter et bilag indgår som negativ kommission i næste bilag (saldomodellen), eller som kreditnota, hvis revisor kræver det | Refunds indgår naturligt i næste periode; perioden er uafhængig af, hvornår creatoren anmoder |
| Afstemning | Bilagsbeløb = payout-beløb (plus/minus overført saldo); let at afstemme mod betalingen | Bilagsbeløb ≠ betalinger; afstemning sker mod en løbende saldo |
| Uden payout | Intet bilag, så længe creatoren ikke anmoder | Bilag dannes også uden payout (kan være et formkrav, afklares) |

### 13.4 Datamodel og sporbarhed (analyse, ikke vedtaget schema)

Den tidligere foreslåede `merchboard_settlement_documents` dækkede creator, payout, periode og beløb, men ikke rettelser, nummerserie og beregningsversion. Analysen anbefaler følgende felter eller tilsvarende:

| Felt (forslag) | Formål | Snapshot? |
| --- | --- | --- |
| `id` | Internt, stabilt bilags-ID (auto-increment); bruges i referencer, vises ikke som bilagsnummer | – |
| `document_number` (`UNIQUE`) | Synligt bilagsnummer fra nummerserien (13.8) | ✓ (ændres aldrig) |
| `document_series` | Nummerserien, nummeret er taget fra | ✓ |
| `document_type` | `settlement` (afregningsbilag) eller `correction` (korrigerende bilag; om det juridisk er en kreditnota, afklares) | ✓ |
| `creator_id` | Creator-reference | ✓ |
| `payout_id` | Payout-reference for et afregningsbilag (ét pr. payout, foreløbig model) | ✓ |
| `settlement_payout_id` (`UNIQUE`, NULL for korrektioner) | Håndhæver ét afregningsbilag pr. payout på databaseniveau (MySQL/MariaDB har ingen delvise indexes) | – |
| `corrects_document_id` (`UNIQUE`, NULL for afregningsbilag) | Reference fra et korrigerende bilag til det bilag, det retter; `UNIQUE` hindrer to korrektioner af samme bilag (en senere rettelse retter det seneste korrigerende bilag) | ✓ |
| `created_at`, `issued_at`, `issued_by` | Oprettelse og udstedelse; i den foreslåede arbejdsgang er de ens (bilaget dannes og udstedes i samme transaktion, ingen kladder) | ✓ |
| `period_from`, `period_to` | Afregningsperioden | ✓ |
| `currency` og beløb (grundlag, kommission, evt. moms, netto/payout, overført saldo) | Decimaler, aldrig genberegnet | ✓ |
| `vat_registered` | Creatorens momsstatus på udstedelsestidspunktet | ✓ |
| `calculation_version` | Hvilken beregningsregel og aggregering bilaget er dannet med (fx ADR-049-reglen og versionen af payout-aggregeringen) | ✓ |
| `template_version` | Bilagsskabelonens version (tekster og layout) | ✓ |
| `snapshot` (JSON) | Creator- og virksomhedsstamdata, momsstatus, maskeret betalingsmetode og totaler, som de var ved udstedelsen | ✓ |

**`merchboard_settlement_document_rows`** (forslag): `document_id`, `event_key` med `UNIQUE(event_key)`, så hver ledger-række indgår i højst ét bilag (afregningsbilag eller korrigerende bilag), samt et snapshot pr. række (ordre-ID, ordrelinje, type, netto, gemt moms, andel, profit, payout). Rækkens værdier gemmes, så et bilag aldrig skal genberegnes, og en senere ændring af beregningslogikken ikke ændrer det.

**Uforanderlighed:** bilag og bilagsrækker indsættes kun; de opdateres og slettes aldrig (fra ADR-059, 2026-10-12, heller ikke ved opt-in-uninstall; indtil da slettede opt-in-uninstall dem efter ADR-060 060-7 a). Om et bilag er korrigeret, afledes af, om et korrigerende bilag henviser til det; der skrives ikke en ny status på det oprindelige.

**Hvad der er snapshot på udstedelsestidspunktet:** bilagsnummer og -type, alle beløb, momsstatus, beregnings- og skabelonversion, perioden, creatorens og virksomhedens stamdata, den maskerede betalingsmetode og hver bilagsrækkes værdier. Ændres creatorens stamdata, markup, momsstatus eller beregningslogikken senere, ændrer det intet på et udstedt bilag.

**Audit:** `settlement_document_issued` og `settlement_document_corrected` med bilags-ID, bilagsnummer, payout-ID og evt. henvisning; ingen betalingsoplysninger, gavekortkoder eller stamdata.

Der er **ingen accepttabel** i fase 23 (13.7). **Ikke vedtaget:** endeligt schema, migrationer og API-kontrakter kræver særskilt godkendelse.

### 13.5 Adgang og visning (forslag)

- **Creator:** ser og kan udskrive/gemme egne bilag i sektionen "Udbetalinger" (`GET /me/payouts/{id}/settlement-document`, `no-store`; `CreatorContext` og ejerskab i SQL).
- **Admin:** ser bilag via payout-administrationen med `merchboard_manage_payouts`.
- **Format:** en udskriftsvenlig HTML-visning (browserens "Gem som PDF"); en PDF-generator ville kræve en ny afhængighed (ADR-004) og er ikke foreslået.
- Bilaget viser aldrig fulde bankoplysninger, PayPal-adresser eller gavekortkoder.

### 13.6 Åbne spørgsmål om afregningsbilag, prioriteret

Numrene 11–17 er de samme som i afsnit 12. Prioriteten afgør, hvad der skal afklares før **implementering** (I), **test** (T) og **produktionsbrug** (P). Intet her er juridisk eller regnskabsmæssigt godkendt; SKAT-oplysningen er brugeroplyst mundtlig vejledning.

| Prio | Nr. | Hvad skal besluttes | Hvorfor | Blokerer | Hvem | Hvad lukker spørgsmålet |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 11 | Aftalegrundlag for omvendt fakturering: (a) den eksisterende aftaleproces uden for Merch Dashboard; (b) dokumentation for, at det nødvendige aftalegrundlag er på plads før produktion; (c) en eventuel fremtidig digital accept i Merch Dashboard (ikke i fase 23) | Omvendt fakturering forudsætter et aftalegrundlag | **P** (ikke **I**: fase 23 har intet accept-flow og ingen accepttabel) | Produktejer (aftaleproces og dokumentation), revisor/jurist (vilkår og om aftalegrundlaget er tilstrækkeligt) | Skriftligt bekræftet aftalegrundlag og vilkår, opbevaret uden for pluginet; evt. bilagstekst, der henviser til aftalen, hvis revisor kræver det |
| 2 | 13, 14 (oplysninger) | Momsstatus og obligatoriske bilagsoplysninger afhængigt af creatorens og virksomhedens forhold; momstekst for momsregistrerede og ikke-momsregistrerede | Bilagets indhold og momsbehandling skal være korrekt, før det bruges | **I** (et foreløbigt feltsæt), **P** (endeligt indhold og momstekst) | Produktejer (foreløbigt feltsæt), revisor/SKAT (krav) | Skriftlig bekræftelse fra revisor/SKAT af obligatoriske felter og momstekst; test af bilag for begge momsstatusser |
| 3 | 12 | Rettelser, refunds og kreditnotaer (teknisk forslag i 13.9): korrigerende bilag med henvisning (aldrig ændring); om en refund efter et bilag indgår som negativ kommission i næste bilag eller kræver en kreditnota | Ordrer og kommissionsgrundlag kan ændre sig efter udstedelsen | **I** (datamodellen skal kunne rumme korrigerende bilag), **P** (den konkrete regel) | Produktejer og udvikler (model), revisor (regel) | Godkendt rettelsesregel; test af refund efter bilag og af et korrigerende bilag |
| 4 | 16 | Creator- og virksomhedsstamdata: hvilke data der mangler (fx navn, adresse, CVR/SE-nr. og momsstatus for creatoren; virksomhedens navn, adresse og CVR), hvordan de indsamles og valideres, og hvordan de beskyttes | Data findes ikke i dag; de er personoplysninger og skal på bilaget | **I** (felter og indsamling), **P** (endeligt krav) | Produktejer (felter og flow), udvikler (beskyttelse), revisor (krav) | Godkendt feltliste; test af indsamling, validering, adgang og at bilaget bruger et øjebliksbillede |
| 5 | 15 | Opbevaring og adgang: periode, form og genfinding; forholdet til ADR-025 (opt-in-uninstall), ADR-029 (audit) og ADR-059 | Bilag skal kunne genfindes og kontrolleres | **P** (implementeringen kan som udgangspunkt aldrig slette bilag) | Produktejer, revisor | Opbevaringsbeslutning (ADR-059 og supplement til ADR-025); test af at bilag bevares ved uninstall |
| 6 | 14 (nummerering, valuta), 17 | Nummerserie (teknisk forslag i 13.8; format, én eller flere serier, pr. år, krav om fortløbende numre uden huller), afregningsperiode og valuta; periodemodel (ét bilag pr. payout, 13.3) | Entydige bilag og sporbarhed mellem bilag, beregning og payout | **I** (foreløbig nummerserie og periodemodel), **P** (endelig godkendelse) | Produktejer (foreløbig), revisor/SKAT (krav) | Godkendt nummerformat og periodemodel; test af samtidige payouts, ingen genbrug af numre, én valuta pr. bilag |

**Kræver afklaring med revisor eller SKAT før produktionsbrug:** aftalegrundlag og vilkår, håndteret uden for Merch Dashboard (11), obligatoriske oplysninger og momstekst (13, 14), rettelses- og kreditnotaregel (12), krav til stamdata (16), opbevaring (15), nummerering og periodemodel (14, 17).

**Kan besluttes af produktejeren før implementering (foreløbigt):** et foreløbigt feltsæt (13, 14), at rettelser sker med korrigerende bilag (12), feltlisten og indsamlingen af stamdata (16), en foreløbig nummerserie og modellen ét bilag pr. payout (14, 17). Implementeringen kan som udgangspunkt aldrig slette bilag (15). Aftalegrundlaget (11) blokerer ikke implementeringen.

### 13.7 Aftaler med creators

- **Nuværende arbejdsgang:** alle aftaler og al kommunikation med creators foregår uden for Merch Dashboard. Fase 23 implementerer **intet** accept-flow, ingen aftaleportal og ingen digital aftalehåndtering.
- **Afregningsbilag** knyttes til creator og payout uden at forudsætte, at aftalen er indgået eller accepteret i pluginet.
- **Før produktionsbrug** skal det nødvendige aftalegrundlag for omvendt fakturering være på plads og dokumenteret uden for pluginet (fx underskrevne aftaler i virksomhedens egne arkiver). Kræver revisor, at bilaget henviser til aftalen, kan det ske med en fast tekst i bilagsskabelonen; det kræver ingen ny digital acceptfunktion.
- **Mulig senere forbedring** (ikke godkendt, ingen fastlagt leverance): digital registrering af creatorens accept af vilkårene for omvendt fakturering i Merch Dashboard (ROADMAP, "Senere"). Den er ikke en teknisk afhængighed for afregningsbilag i fase 23.

### 13.8 Bilagsidentitet og nummerering (forslag)

**Identiteter:**

| Identitet | Hvad | Vises på bilaget |
| --- | --- | --- |
| Internt bilags-ID | Teknisk, stabilt `id`; bruges i referencer og audit | Nej |
| Bilagsnummer | Fra en nummerserie, fx `<præfiks>-<år>-<løbenummer>` (format afklares, punkt 14) | Ja |
| Creator-ID | Hvem bilaget vedrører | Nej (navnet vises) |
| Payout-ID | Den payout, et afregningsbilag hører til | Som payout-reference |
| Bilagstype og status | Afregningsbilag / korrigerende bilag; udstedt (og afledt: korrigeret) | Typen vises |
| Datoer | Oprettelse og udstedelse (ens i den foreslåede arbejdsgang) | Udstedelsesdato |

**Tildeling uden dubletter** (planlægningsniveau):
- Nummeret tages fra en **dedikeret tællerrække** pr. nummerserie (fx en lille tabel med `series` og `next_value`), ikke ved at tælle eksisterende bilag.
- I **samme transaktion** som payoutens overgang til `completed`: tælleren låses og øges med en atomisk `UPDATE` (rækkelås i InnoDB), bilaget og rækkerne indsættes, og payouten afsluttes. Først ved COMMIT er nummeret brugt.
- **Samtidige requests:** rækkelåsen på tælleren serialiserer udstedelsen; to bilag kan ikke få samme nummer, og `UNIQUE(document_number)` er en ekstra sikring.
- **Fejl midt i udstedelsen:** hele transaktionen rulles tilbage (tæller, bilag, rækker og payoutens status), så hverken et nummer, et halvt bilag eller en gennemført payout uden bilag efterlades. Om der må opstå huller i nummerserien i andre situationer (fx en mislykket COMMIT efter en reconnect), afklares med revisor (punkt 14); forslaget undgår huller i den normale fejlvej.
- **Genkørsel:** udstedelsen er idempotent via `UNIQUE(settlement_payout_id)`: er payouten allerede afsluttet med et bilag, returneres det eksisterende; der dannes aldrig et nyt bilag og tages aldrig et nyt nummer.
- **Kladder:** foreslås ikke. Skulle en forhåndsvisning blive ønsket senere, får den intet nummer; nummeret tildeles først ved udstedelse.

Én nummerserie for både afregningsbilag og korrigerende bilag eller separate serier er en åben beslutning (punkt 14).

### 13.9 Rettelser, refunds og korrigerende bilag (teknisk mekanisme; regnskabsregler afklares)

**Tekniske principper (forslag):**
1. **Et udstedt bilag forbliver uændret.** Beløb, beregningsgrundlag, rækker og stamdata overskrives aldrig.
2. **Hver ledger-række afregnes højst én gang** (`UNIQUE(event_key)` i bilagsrækkerne). Det hindrer, at samme forhold medregnes eller korrigeres to gange.
3. **Ledgeren er allerede append-only** (ADR-037/038): en refund, reversal eller korrektion af en ordre er en ny ledger-række med egen dato. Den ændrer ikke de rækker, et bilag indeholder.

**To mekanismer:**

| Situation | Mekanisme (forslag) |
| --- | --- |
| Refund, annullering eller korrektion af en ordre **efter** et bilag | Den nye (negative eller positive) ledger-række falder i en senere periode og kommer med i **næste afregningsbilag** (fremadrettet modregning via saldoen, ADR-054). Den knyttes til ordren og det oprindelige bilag via ordre-ID. Om dette er tilstrækkeligt, eller om der kræves et korrigerende bilag (kreditnota) med henvisning til det oprindelige, afklares med revisor/SKAT. |
| **Fejl i selve bilaget** (fx forkerte stamdata eller en række, der ikke burde være med) | Et **korrigerende bilag** (`correction`) med `corrects_document_id` til det oprindelige, eget bilagsnummer, eget snapshot og de rækker eller beløb, der rettes. Det oprindelige bilag ændres ikke. `UNIQUE(corrects_document_id)` hindrer to korrektioner af samme bilag; en yderligere rettelse retter det seneste korrigerende bilag. |
| Sletning af en testordres historik (ADR-052), når ordren allerede indgår i et bilag | Bør afvises eller kræve et korrigerende bilag, fordi bilagets rækker ellers ikke længere kan genfindes i ledgeren (hænger sammen med det åbne punkt om ADR-052). |

**Når payouten allerede er gennemført:** payouten ændres aldrig ved en statusændring. En korrektion påvirker kun saldoen: et negativt beløb bliver et **udestående**, der modregnes i fremtidige payouts (ADR-054); et positivt beløb øger det disponible beløb, som creatoren selv skal anmode om. Der oprettes **aldrig automatisk en ny udbetaling eller en opkrævning**. Hvordan et udestående inddrives, hvis der ikke kommer nyt salg, er en forretnings- og regnskabsbeslutning.

**Afgrænsning:** den juridiske dokumenttype (kreditnota eller andet), om en refund efter et bilag kræver et korrigerende bilag, og hvordan det bogføres, skal afklares med revisor eller SKAT (punkt 12).

### 13.10 Sporbarhed og adgangskontrol (forslag)

**Kæden for en administrator** med `merchboard_manage_payouts`:

`ordre → ledger-rækker (kommissionsberegning) → afregningsbilag → payout → eventuelt korrigerende bilag`

- **Fra ordren:** ordrehistorikkens detalje (ADR-051) viser pr. ledger-række, om den er afregnet, i hvilket bilag (bilagsnummer, link) og med hvilken payout.
- **Fra bilaget:** bilagsrækkerne viser ordre-ID, ordrelinje og den beregning (snapshot), der indgik; links til ordrehistorikken og til payouten; henvisninger til et korrigerende bilag eller til det bilag, der rettes.
- **Fra payouten:** link til afregningsbilaget og evt. korrigerende bilag.
- Betalingsoplysninger vises kun maskeret; fulde oplysninger kræver `merchboard_view_payout_details` og kun mens payouten venter (afsnit 6). Gavekortkoder vises aldrig i admin, bilag eller audit.

**Creatoren** ser kun egne bilag (`CreatorContext` og ejerskab i SQL, `no-store`), med egne ordrer og beløb, men ingen andre creators data og ingen fulde betalingsoplysninger.

**Genfinding og kontrol:** bilag og bilagsrækker slettes eller ændres aldrig automatisk. Opbevaringsperiode og uninstall afstemmes med ADR-025, ADR-029 og ADR-059 (punkt 15).

## 14. Økonomiske invariants og grænsetilfælde

Afsnittet samler reglerne for sammenhængen mellem ordrer, kommissionsberegning, afregningsbilag, refunds, gavekort og payouts. Alt er **forslag på planlægningsniveau**; intet er godkendt af revisor eller SKAT, og ADR-049 er uændret. "Settlement start" kaldes her **afregningsstart** (afsnit 3).

### 14.1 Økonomiske hændelser og poster

| Begreb | Hvad det er | Hvor | Opretter nyt / refererer |
| --- | --- | --- | --- |
| Økonomisk hændelse fra en ordre | Salg, refund, statusskift, sletning eller ændring i WooCommerce | WooCommerce (ejes ikke af Merch Dashboard) | Udløser synkronisering; ændres aldrig af Merch Dashboard |
| Ledger-række | Uforanderlig postering pr. ordrelinje og hændelse med fortegn (`sale`, `refund`, `reversal`, `correction`), med gemt moms og snapshot af andelen | `merchboard_sales` (+ `_sales_tax`, `_sales_line_pricing`), ADR-037/038/047/048 | **Opretter** nye rækker; eksisterende rækker ændres aldrig (eneste undtagelse: ADR-052-sletning af en testordre) |
| Kommissionsberegning | Profit og payout pr. ledger-række efter ADR-049, beregnet ved læsning (`RealizedProfit`, `RealizedPayout`) | Ingen egen tabel | **Opretter intet**; refererer til ledger-rækker; ukendt er aldrig 0 |
| Afregningsbilag | Uforanderligt bilag med snapshot af rækker, beløb og stamdata | `merchboard_settlement_documents` (+ rækker), 13.4 | **Opretter** et nyt bilag; refererer til ledger-rækker (højst én gang hver) og til payouten |
| Payout-anmodning | Reservation af et beløb | `merchboard_payouts` med `pending` | **Opretter** en anmodning; refererer ikke til bestemte ledger-rækker (saldomodel, ADR-054) |
| Gennemført payout | Betalingen eller gavekortet er registreret | `merchboard_payouts` med `completed` | **Refererer** til afregningsbilaget og evt. gavekortets udbyder og eksterne ID; beløb og status ændres derefter aldrig |
| Efterfølgende refund eller korrektion | Ny ledger-række (refund, reversal, correction) eller et korrigerende bilag ved fejl i et bilag (13.9) | Ledger / bilag | **Opretter** en ny post; refererer til ordren og evt. det oprindelige bilag; ændrer aldrig den historiske post |

**Grundregel:** en historisk post (ledger-række, snapshot, bilag, gennemført payout, audit) ændres, overskrives eller slettes aldrig stiltiende, fordi en ordre, creator-oplysning, kommissionsregel eller payout ændres senere. Ændringer udtrykkes som nye poster med reference.

### 14.2 Saldo-begreber

Pr. creator og valuta, beregnet deterministisk fra ledgeren og payouts (heltal i 1/10 000):

| Begreb | Definition (forslag) |
| --- | --- |
| Beregnet kommission | Σ profit (ADR-049) for creatorens ledger-rækker; vises som i dag (ADR-050) |
| Ikke frigivet | Rækker i holdperioden: `occurred_at ≥ nu − karens` (14 dage som standard, konfigurerbar i Settings) |
| Optjent payout (frigivet) | Σ `RealizedPayout` for rækker med `occurred_at ≥ afregningsstart` og `< nu − karens` (udgangspunkt; afhænger af D1 og D2, 15.2–15.3) |
| Reserveret | Σ payouts med `pending` (inkl. anmodninger, som admin er ved at behandle; der er bevidst ingen separat "under afregning"-status) |
| Gennemført | Σ payouts med `completed` |
| Saldo | Optjent − gennemført − reserveret |
| Disponibelt | Saldo, hvis > 0 og status `complete`; ellers 0 |
| Udestående | −saldo, hvis saldoen er negativ; modregnes automatisk i fremtidig optjening |

**Determinisme:** reservation og afslutning sker under en lås pr. creator og i en transaktion, hvor saldoen beregnes på ny. To samtidige requests kan derfor ikke reservere eller udbetale det samme beløb (14.5).

### 14.3 Grænsetilfælde

| # | Scenarie | Systemet må | Systemet må ikke | Handling | Referencer og audit | Åbent |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Refund før afregningsstart | Lade både salg og refund ligge uden for vinduet | Medregne refunden alene | Automatisk | Ledger-rækkerne som i dag | Hvad hvis salget er før afregningsstart, men refunden efter (refunden ville trække saldoen ned for et salg, der ikke indgik)? **Forretningsbeslutning**: vindue pr. række (enkelt) eller pr. ordrelinje ud fra salgsdatoen |
| 2 | Refund efter afregningsstart, før bilaget udstedes | Medregne refunden som negativ række, når den er i vinduet; genberegne saldoen ved afslutning af en ventende payout | Afslutte en payout, hvis saldoen uden payoutens egen reservation nu er mindre end beløbet, uden manuel stillingtagen | Ved afslutning: **manuel behandling**, hvis saldoen ikke længere dækker beløbet | Refund-rækken; audit for afslutning eller afvisning | Skal negative rækker tælle med det samme (uden karens), så der ikke udbetales for meget? **Forretningsbeslutning** (anbefalet at overveje) |
| 3 | Refund efter bilaget, før payout | – | – | Kan ikke opstå i den foreløbige model: bilaget dannes i samme transaktion som `completed`. Ved periodiske bilag (alternativet i 13.3) som scenarie 4 | – | Afhænger af periodemodellen (punkt 17) |
| 4 | Refund efter gennemført payout | Medregne refunden i næste bilag (fremadrettet modregning); vise udestående | Ændre payouten eller bilaget; oprette en automatisk opkrævning | Automatisk modregning; inddrivelse uden nyt salg kræver manuel beslutning | Refund-rækken refererer til ordren; næste bilag til rækken | Om der kræves et korrigerende bilag (kreditnota): **revisor/SKAT** (punkt 12) |
| 5 | Ordre slettes, mens dens hændelser er med i et bilag | WooCommerce' permanente sletning registreres som reversal (ADR-037 pkt. 8) og indgår i næste bilag | Slette ledger-rækker, der indgår i et bilag (ADR-052-sletning) | ADR-052-sletning **blokeres**, hvis en af ordrens rækker er afregnet | Bilagsrækkerne; audit for forsøget | ADR-052's samspil (punkt 7 i de åbne beslutninger) |
| 6 | Ordre ændres efter en tidligere beregning | Registrere ændringen som nye correction-rækker; beregne dem med den oprindelige linjes snapshot (ADR-047) | Genberegne eller ændre tidligere rækker, snapshots eller bilag | Automatisk | Correction-rækker med reference til ordrelinjen | Ingen ny |
| 7 | Ukendt eller manglende ledger-række | Vise status `unknown` og antallet | Behandle rækken som 0 eller springe den over; tillade anmodning eller afslutning | **Blokeres**: ingen anmodning; en afslutning, hvor saldoen er blevet ukendt, kræver manuel behandling | Antal ukendte rækker i visningen; ingen audit pr. række | Afregningsstart som løsning for gammel historik (beslutning 3) |
| 8 | Korrektion gør saldoen negativ | Vise udestående; disponibelt = 0 | Oprette automatisk udbetaling eller opkrævning | Automatisk modregning; inddrivelse er manuel | Korrektionsrækken; næste bilag | Inddrivelse uden nyt salg: **forretnings- og regnskabsbeslutning** |
| 9 | Payout-request gentaget efter timeout | Returnere den eksisterende anmodning (idempotency-nøgle) | Oprette en ny reservation | Automatisk | Én anmodning og én audit-post | Ingen |
| 10 | To samtidige requests reserverer eller afregner samme beløb | Serialisere via creator-lås og genberegne saldoen | Lade summen af reservationer overstige saldoen; afslutte samme payout to gange | Automatisk (den ene afvises eller får "allerede behandlet") | Betinget statusovergang; audit kun for den gennemførte | Ingen |

### 14.4 Gavekort og kommissionsgrundlag

Fastlagt i planen (afsnit 8):
- Gavekortets værdi er lig det endelige payout-beløb efter ADR-049; der lægges ikke moms oveni, og der trækkes ikke en ekstra momsandel fra ved udstedelsen.
- Den regnskabsmæssige og momsmæssige klassifikation kræver fortsat ekstern afklaring.
- Gavekortkoder gemmes ikke unødvendigt i Merch Dashboard (for YITH slet ikke) og står aldrig i URL'er, logs eller generelle admin-visninger.
- En payout markeres først `completed`, når gavekortet er valideret (8.5).
- `UNIQUE(gift_card_provider, gift_card_external_id)` hindrer, at samme gavekort knyttes til flere payouts, også ved gentagen registrering.
- Adapteren udsteder, deaktiverer eller ændrer aldrig gavekort.

**Åbent og blokerende for den berørte produktionsadfærd:** skal ordrer betalt med et payout-gavekort udelukkes fra kommissionsgrundlaget, og hvordan håndteres refunds og genoprettet gavekortsaldo? Mulige valg og konsekvenser:

| Valg | Konsekvens |
| --- | --- |
| A. Behandles som almindeligt salg | Ingen ny teknik. Creatoren kan optjene kommission på køb betalt med sin egen udbetaling. Virkningen afhænger af YITH's indløsningsvej (kuponfeltet nedsætter linjebeløbet, YITH's formular gør ikke, 8.3), så resultatet er ikke ens. |
| B. Hele ordren udelukkes fra payout-grundlaget | Kræver, at Merch Dashboard kan se, hvilke ordrer der er betalt med et payout-gavekort (en ny læsemetode i adapterkontrakten; YITH registrerer ordrer på kortet, men det er ikke verificeret i runtime). Refunds og reversals af en udelukket ordre skal udelukkes symmetrisk. Delvist betalte ordrer mister hele kommissionen. |
| C. Kun den gavekortbetalte andel udelukkes | Mest præcis, men kræver en fordeling pr. ordrelinje og afhænger af indløsningsvejen; mest kompleks og sværest at teste. |

Genoprettet gavekortsaldo ved refund (YITH gør det ved `cancelled`, `refunded` og `failed`) ændrer ikke payouten; den gennemførte payout står ved magt, og creatoren kan bruge saldoen igen. Om det er i orden regnskabsmæssigt, afklares eksternt.

Beslutningen blokerer **produktionsbrug af payout-gavekort** (og den tilhørende kommissionsadfærd), ikke implementeringen af bank, PayPal eller den tekniske gavekortregistrering.

### 14.5 Fejlhåndtering og idempotens

Et uklart resultat (fx en mistet databaseforbindelse efter COMMIT) behandles **aldrig** automatisk som succes eller fiasko, hvis det kan give dobbeltbetaling eller dobbeltregistrering: tilstanden læses igen, før der gøres noget.

| Operation | Mulig fejl / timeout | Risiko | Idempotent adfærd | Transaktion / unik begrænsning | Audit | Genoptagelse |
| --- | --- | --- | --- | --- | --- | --- |
| Sæt afregningsstart | Dobbelt indsendelse; ændring efter udstedte bilag | Rækker tælles to gange eller springes over | Samme værdi igen er en no-op | Nonce, capability; **forslag:** kan ikke ændres, når der findes et afregningsbilag | `payout_start_changed` (gammel/ny dato) | Automatisk; ændring efter bilag kræver beslutning |
| Kommissionsberegning (saldo) | Læsefejl | Ingen (ren læsning) | Altid samme resultat for samme data | Én forespørgsel; ukendt giver `unknown` | Ingen | Automatisk (prøv igen) |
| Payout-request (reservation) | Timeout før/efter COMMIT | Dobbelt reservation | Samme idempotency-nøgle returnerer samme anmodning | Creator-lås + transaktion; `UNIQUE(creator_id, idempotency_key)`; loft 3/10 | `payout_requested` (én gang) | Automatisk |
| Annullering (creator) | Dobbelt klik | Ingen | Anden gang: "allerede annulleret" | Betinget `pending` → `cancelled` | `payout_cancelled` | Automatisk |
| Afvisning (admin) | Dobbelt klik; samtidig afslutning | Afvist og gennemført samtidig | Kun én overgang lykkes | Betinget overgang under creator-lås | `payout_rejected` | Automatisk |
| Afslutning bank/PayPal | Timeout efter COMMIT; saldoen dækker ikke længere | Dobbelt registrering; overbetaling | Betinget overgang; genforsøg ser `completed` | Creator-lås + transaktion; saldo genberegnes | `payout_completed` | Uklart resultat: læs status igen; manglende dækning: **manuel** |
| Bilagsudstedelse | Fejl midt i; samtidige udstedelser | Dobbelte bilag eller numre | Eksisterende bilag returneres | Samme transaktion som afslutningen; tællerrække med rækkelås; `UNIQUE(document_number)`, `UNIQUE(settlement_payout_id)`, `UNIQUE(event_key)` | `settlement_document_issued` | Automatisk (alt rulles tilbage) |
| Gavekortregistrering | Timeout; samme kort to gange; kort brugt imens | Ét kort på to payouts; afslutning uden gyldigt kort | Samme kort på samme payout: "allerede registreret"; andet kort: afvist | Creator-lås + transaktion; `UNIQUE(gift_card_provider, gift_card_external_id)`; validering via adapteren | `payout_gift_card_registered` (udbyder + eksternt ID) | Validering fejler: payouten forbliver `pending`; uklart: læs status igen |
| Korrektion (korrigerende bilag) | Dobbelt indsendelse; samme forhold rettet to gange | Dobbelt korrektion | Eksisterende korrektion returneres | `UNIQUE(corrects_document_id)`; `UNIQUE(event_key)` i bilagsrækker; transaktion | `settlement_document_corrected` | **Manuel** (kræver admins stillingtagen) |
| Ledger-synkronisering (eksisterende) | Som i dag (ADR-038) | Som i dag | Som i dag (event-nøgler) | Som i dag | Som i dag | Som i dag |

### 14.6 Beslutninger og blokeringer

**Tekniske invariants** (kan implementeres uden at afgøre juridiske spørgsmål):
- Historiske poster ændres aldrig; ændringer er nye poster med reference (14.1).
- Ukendte rækker giver `unknown`, blokerer anmodninger og er aldrig 0 (scenarie 7).
- Idempotens og unikke begrænsninger fra 14.5; lås pr. creator og genberegning ved reservation og afslutning.
- Hver ledger-række afregnes højst én gang; ét afregningsbilag pr. payout (foreløbig model); et gavekort på højst én payout.
- ADR-052-sletning blokeres for afregnede ordrer (scenarie 5).
- Afslutning blokeres til manuel behandling, hvis saldoen ikke længere dækker beløbet eller er blevet ukendt (scenarie 2 og 7).
- Ingen automatiske udbetalinger, opkrævninger eller ændringer af gavekort.

**Forretningsbeslutninger** (ejerens stillingtagen):
1. Vindue pr. række eller pr. ordrelinje ved refunds af salg fra før afregningsstart (scenarie 1).
2. Om negative rækker skal tælle med det samme uden karens (scenarie 2).
3. Om afregningsstarten kan ændres, når der findes et afregningsbilag (14.5).
4. Inddrivelse af udestående uden nyt salg (scenarie 4 og 8).
5. Ordrer betalt med payout-gavekort i kommissionsgrundlaget, valg A–C (14.4).
6. De øvrige åbne punkter i afsnit 12 (begrænsning til creator, udløb, ADR-052, afregningsstart som kolonne eller konfiguration, senere korrektion, `MB-T23`-testdata).

**Regnskabs- og juridiske afklaringer** (revisor eller SKAT):
- Moms og momstekst; 20 %-reglen (ADR-049).
- Dokumenttype for bilag og korrektioner (kreditnota), og om en refund efter et bilag kræver et korrigerende bilag.
- Gavekortets regnskabs- og momsmæssige behandling, restværdi, udløb og genoprettet saldo.
- Formkrav, nummerering og valuta; opbevaring.
- Aftalegrundlaget for omvendt fakturering (håndteres uden for Merch Dashboard).

**Blokeringer:**

| Område | Blokerer implementering | Blokerer produktion |
| --- | --- | --- |
| Saldo, reservation, bank og PayPal | Nej (de tekniske invariants kan bygges) | Ja: 20 %-reglen, moms, aftalegrundlag og bilagets formkrav |
| Afregningsbilag | Nej for den tekniske del (nummerering, uforanderlighed, sporbarhed); foreløbigt feltsæt, nummerserie og periodemodel besluttes af produktejeren | Ja: formkrav, momstekst, dokumenttyper, rettelsesregel, opbevaring, aftalegrundlag |
| Payout-gavekort | Nej for adapter og registrering | Ja: valg A–C (14.4), gavekortets regnskabs- og momsbehandling, udløb og restværdi |
| Forretningsbeslutning 1–3 | Ja for de berørte beregnings- og låseregler (påvirker saldoen) | Ja |
| Forretningsbeslutning 4 | Nej (intet automatisk) | Ja, før rigtige udeståender skal inddrives |

Uafklarede regnskabsregler forhindrer ikke teknisk forberedelse, men behandles ikke som godkendte.

## 15. Ejerbeslutninger og implementeringsrækkefølge

Alle anbefalinger i dette afsnit er **forslag**, ikke vedtagne beslutninger. Uændret: ADR-049, karens 14 dage fra `occurred_at`, ukendt er aldrig 0, historiske bilag og gennemførte payouts ændres ikke, gavekort bag `GiftCardProvider`, aftaler uden for pluginet, ét bilag pr. payout er foreløbigt.

### 15.1 Fakta om ledgeren, som beslutningerne bygger på

Verificeret i DATABASE.md og `SalesPlanner`:
- En ordrelinje kan have flere **cyklusser** (en ordre, der bliver ikke-tællende og derefter tællende igen, får en ny cyklus med den resterende netto, ADR-038).
- Hver cyklus har én salgsrække `s:<ordrelinje>:<cyklus>` med sin egen `occurred_at`.
- En refund er en særskilt række `r:<ordrelinje>:<refund-ID>` med ordrelinjen, **cyklussen på refund-tidspunktet**, refund-ID og refundens egen dato. Den har ingen direkte kolonne, der peger på salgsrækken, men salgsrækken for samme cyklus kan findes entydigt via (ordrelinje, cyklus).
- Reversals (`v:<ordrelinje>:<cyklus>`), linjekorrektioner (`c-l:…:<cyklus>:<n>`) og refund-relaterede korrektioner (`c-ro`, `c-rd`, `c-rdo`) bærer også ordrelinje og cyklus.
- `UNIQUE(event_key)` betyder, at samme refund, reversal eller korrektion aldrig registreres to gange, uanset hvor ofte en hook eller synkronisering køres igen. En slettet refund giver en ny modpost (`c-rd`), ikke en ændring.

### 15.2 Beslutning D1: refunds af salg fra før afregningsstart

| Model | Hvordan | Delvise og flere refunds | Ny hook-kørsel | Refund efter afregningsstart af et salg før | Vurdering |
| --- | --- | --- | --- | --- | --- |
| a) Den oprindelige rækkes salgsdato | Hver række hører til vinduet, hvis **salgsrækken i samme cyklus** (`s:<linje>:<cyklus>`) ligger på eller efter afregningsstart | Hver refund er sin egen række; alle følger deres cyklus' salg | Ingen effekt (`UNIQUE(event_key)`) | Udelukkes sammen med salget (symmetrisk) | Bruger kun eksisterende data |
| b) Ordrelinjens salgsdato | Som a), men forankret i ordrelinjens **første** salg (cyklus 1) | Som a) | Som a) | Som a) | Adskiller sig fra a) **kun** ved flere cyklusser: rækker i cyklus 2+ vurderes efter salget i cyklus 1, selvom cyklus 2's salg er en ny økonomisk hændelse med egen dato |
| c) Særskilt negativ hændelse | Hver række vurderes efter **sin egen** `occurred_at` (den nuværende plan i afsnit 3) | Hver refund tæller for sig | Som a) | **Tæller med** og trækker saldoen ned for et salg, der aldrig indgik | Enkelst, men asymmetrisk ved afregningsstart |

**Anbefaling (forslag): a)** Rækker forankres i deres cyklus' salgsrække: en række indgår i vinduet, når cyklussens salg ligger på eller efter afregningsstart. Historikken bevares (intet ændres), samme refund tælles aldrig to gange (`UNIQUE(event_key)` i ledgeren og i bilagsrækkerne), og et salg fra før afregningsstart og dets refunds behandles ens. Mangler eller er salgsrækken ukendt, er rækken ukendt (blokerer). Karensen (D2) beregnes stadig ud fra rækkens egen `occurred_at`.

**Tests:** salg før og refund efter afregningsstart udelukkes begge; delvise og flere refunds på samme linje; ny cyklus efter genåbning; slettet refund (`c-rd`); gentagen synkronisering giver ingen nye rækker; manglende salgsrække giver `unknown`.

### 15.3 Beslutning D2: negative rækker og karens

Tre slags rækker:
1. **Positive rækker i karens** (salg, positive korrektioner): ikke frigivet endnu.
2. **Negative rækker, der retter et frigivet salg** (refund eller reversal efter karens): det salg, der allerede tæller, bliver mindre.
3. **Negative rækker, hvis salg stadig er i karens:** salget er ikke frigivet endnu.

| Regel | Konsekvens for disponibelt | Samtidige og ventende payouts | Negativ saldo |
| --- | --- | --- | --- |
| Alle rækker venter på karens (nuværende plan) | Kan være for højt i op til 14 dage efter en refund | En payout kan reservere beløb, som en kendt refund allerede har fjernet | Opstår oftere, efter udbetaling |
| **Negative rækker tæller straks; positive efter karens** | Aldrig for højt; type 3 gør det midlertidigt lavere, indtil salget frigives (nettoeffekten er 0 bagefter) | Genberegning ved afslutning (14.3, scenarie 2) fanger refunds, der kommer under behandling | Sjældnere |
| Negative rækker følger deres salgs karens | Præcist pr. salg, men en refund af et frigivet salg tæller straks og en refund af et salg i karens venter | Som ovenfor | Som ovenfor |

**Anbefaling (forslag):** deterministisk regel: en række med payout **< 0** tæller fra sin egen `occurred_at`; en række med payout **≥ 0** tæller fra `occurred_at + karens`. Den er enkel, kan aldrig udbetale for meget, og type 3 udlignes, når salget frigives. Ukendte rækker blokerer uanset fortegn. Om midlertidigt lavere disponibelt (type 3) er acceptabelt, er en **forretningsbeslutning**.

**Tests:** refund i og efter karens; refund af et salg i karens; to samtidige requests med en refund imellem; afslutning af en ventende payout efter en refund (manuel); negativ saldo.

### 15.4 Beslutning D3: ændring af afregningsstart

Afregningsstarten afgrænser kun **den første** periode; senere perioder følger af de udstedte bilag (rækker, der endnu ikke er afregnet).

| Model | Historiske beregninger | Dubletter | Uafregnede rækker | Sporbarhed |
| --- | --- | --- | --- | --- |
| Permanent låst, når der findes et bilag | Uændrede | Umulige | Uændrede | Fuld |
| Kan ændres med audit-logget handling | Bilag ændres ikke, men rækker før/efter den nye start kan komme ind eller falde ud af fremtidige perioder | Hindres af `UNIQUE(event_key)`, men en flytning bagud kan trække gamle, ukendte rækker ind (blokering) | Kan ændre sig | Bevaret via audit, men sværere at forklare |
| Ny periode uden at ændre starten | – | – | – | Sker allerede: hvert bilag starter en ny periode |

**Anbefaling (forslag):** afregningsstarten kan sættes og ændres med en audit-logget handling, **så længe der ikke findes et afregningsbilag** for creatoren; derefter er den **låst**. Et forsøg på at ændre en låst afregningsstart afvises med en tydelig fejl, intet ændres, og forsøget logges teknisk (ikke som en ændring i audit). Afregnede rækker genafregnes aldrig (`UNIQUE(event_key)` i bilagsrækkerne). Skal en låst start alligevel rettes, er det en sag for den senere korrektionsfase.

**Tests:** ændring før første bilag (audit); afvist ændring efter første bilag; ingen genafregning; flytning bagud, der trækker ukendte rækker ind, giver `unknown`.

### 15.5 Beslutning D4: udestående uden nyt salg

| Mulighed | Teknik | Risiko for utilsigtet opkrævning | Audit | Afhænger af |
| --- | --- | --- | --- | --- |
| Kun modregning i fremtidig kommission | Ingen ny teknik: negativ saldo vises som udestående og modregnes automatisk | Ingen | Ledger-rækker og bilag dokumenterer det | – |
| Manuel håndtering uden automatisk opkrævning | Merch Dashboard viser udestående pr. creator i admin; selve håndteringen sker uden for systemet | Ingen i systemet | Kun visning; en registrering af "afregnet uden for systemet" ville kræve en ny posttype (senere) | Aftalegrundlag, regnskab |
| Senere proces til særskilt afregning eller opkrævning | Ny posttype, eget bilag (kreditnota/opkrævning), godkendelse | Høj, hvis den automatiseres | Fuld | Aftalegrundlag, revisor/SKAT, egen ADR |

**Kan implementeres nu:** modregning og visning af udestående (creator og admin). **Kræver særskilt godkendelse:** enhver registrering eller proces for opkrævning eller afregning uden for modregning. Merch Dashboard opretter aldrig automatisk opkrævninger eller udbetalinger.

**Anbefaling (forslag):** modregning plus visning nu; manuel håndtering uden for systemet; en eventuel opkrævningsproces i en senere fase.

### 15.6 Gavekortbetalte ordrer (G1): sammenligning

| | A. Almindeligt salg | B. Hele ordren udelukkes | C. Kun gavekortandelen udelukkes |
| --- | --- | --- | --- |
| Kommissionsgrundlag | Uændret | Ordrens rækker tæller ikke | Reduceres med gavekortandelen |
| Delvis betaling / blandede metoder | Fuld kommission | Hele kommissionen mistes, også af den kontante del | Præcis, men kræver en fordeling pr. linje |
| Refunds | Som i dag | Refunds og reversals af ordren skal udelukkes symmetrisk | Fordelingen skal også gælde refunds |
| Genoprettet gavekortsaldo (YITH ved annulleret/refunderet/fejlet ordre) | Payouten står ved magt | Som A | Som A |
| Risiko for dobbeltberegning | Creatoren kan tjene på sin egen udbetaling | Lav | Lav, hvis fordelingen er korrekt |
| Indløsningsvejens betydning (8.3) | Kuponvejen nedsætter linjebeløbet (≈ C automatisk); YITH's formular gør ikke (fuld kommission): **resultatet er ikke ens** | Uafhængig af vejen | Kun kuponvejen giver data pr. linje; YITH's formular kræver en egen fordeling |

**Tilgængelige data (tekniske afhængigheder):**
- **B** kræver, at adapteren kan oplyse, hvilke ordrer der er betalt med et bestemt kort. YITH registrerer ordrer på kortet (`register_order` → `_ywgc_orders`; set i koden for kuponvejen). For YITH's formular er det **ikke verificeret**; kræver runtime-test. Kontrakten skal udvides med en læsemetode.
- **C** kræver gavekortbeløbet pr. ordre og en fordeling pr. ordrelinje. Kuponvejen giver kuponlinjer i WooCommerce; YITH's formular gemmer beløbet på ordren (ikke verificeret). Fordelingen findes ikke i dag og skulle bygges.
- **A** kræver ingen nye data, men giver forskelligt resultat afhængigt af indløsningsvejen.

**Anbefaling (forslag): B**, fordi den er uafhængig af indløsningsvejen og ikke kan give kommission på egen udbetaling, **forudsat** at runtime-testen viser, at begge indløsningsveje kan identificeres pålideligt. Ellers skal der vælges igen. Regnskabs- og momsbehandlingen afklares eksternt. G1 blokerer **produktionsbrug af payout-gavekort**, ikke implementeringen af bank og PayPal.

### 15.7 Implementeringsrækkefølge

Kategorier: **1** kan forberedes nu · **2** kræver ejerens beslutning · **3** kræver revisor/SKAT før produktion · **4** kræver teknisk undersøgelse eller runtime-test · **5** senere fase.

| Aktivitet | Kat. | Afhængigheder | Leverance | Accept | Blokerer |
| --- | --- | --- | --- | --- | --- |
| Payout-aggregering (ledger og saldo) | 1 + 2 | D1, D2 for vindues- og karensreglen | `LedgerCreatorPayouts`, `PayoutSummary`; reglen samlet ét sted | Sum = Σ `RealizedPayout`; `unknown` blokerer; tests for D1/D2 efter beslutning | Reservation, bilag |
| Afregningsstart | 1 + 2 | D3; lagring som kolonne eller konfiguration (D6) | Sæt/ændr med audit; lås efter første bilag | Audit; afvist ændring efter bilag | Aggregering |
| Payout-reservationer og statusser | 1 | Aggregering | Tabeller, lås, idempotens, loft, annullering, afvisning | 14.5-tabellen; samtidighed med to forbindelser | Bank/PayPal, gavekort, bilag |
| Kryptering og bank/PayPal | 1 | Reservationer | `MERCHBOARD_PAYOUT_KEY`, maskering, sletning | Krypteringsrundtur; deaktiveret uden nøgle | Admin-behandling |
| Adgangskontrol og capabilities | 1 | – | `merchboard_manage_payouts`, `merchboard_view_payout_details`, rolleversion 5 | IDOR- og capability-tests | Alle visninger |
| Audit | 1 | – | Nye audit-actions uden hemmeligheder | Ingen konti, koder eller stamdata i audit | – |
| Afregningsbilag (teknik) | 1 + 2 | Reservationer; foreløbigt feltsæt, nummerserie, periodemodel og rettelsesmekanisme (D8–D11) | Tabeller, tællerrække, uforanderlighed, rækkesporbarhed, visning | 13.8–13.10; tekniske tests i afsnit 11 | Produktion af payouts |
| Afregningsbilag (formkrav) | 3 | Revisor/SKAT | Endelige felter, momstekst, nummerformat | Skriftlig bekræftelse | Produktion |
| Korrigerende bilag | 1 (mekanisme) / 3 (regel) / 5 (korrektion af gennemførte payouts) | Bilag | Korrigerende bilag med henvisning | `UNIQUE(corrects_document_id)`; ingen ændring af originalen | Produktion |
| ADR-052-blokering | 1 + 2 (D5) | Bilag | Afvis sletning af afregnede ordrer | Test af afvist sletning | – |
| `GiftCardProvider` og registrering | 1 | Reservationer | Kontrakt, fake-adapter, registreringsflow | Unit-tests mod fake | YITH-adapter |
| YITH-adapter | 4 | Aktivering af YITH på Local (ejeren), `MB-T23`-testdata (D7) | Kun-læse-adapter, versionstjek | Runtime-tests (8.9, 15.6) | Payout-gavekort |
| Gavekortbetalte ordrer (G1) | 2 + 4 | Runtime-test af indløsningsveje | Valgt model (A/B/C) og evt. ny læsemetode | Test af begge indløsningsveje | Produktion af payout-gavekort |
| Udestående (D4) | 1 (visning) / 5 (opkrævning) | Aggregering | Visning af udestående | Ingen automatisk opkrævning | – |
| Opbevaring og uninstall | 1 (bevar altid) / 2 + 3 (ADR-059, ADR-025) | – | Payout- og bilagstabeller slettes ikke (implementeret med ADR-059 2026-10-12; `payout_secrets` slettes altid) | Uninstall-test (`UninstallTest`, `ReinstallAfterUninstallTest`) | Produktion |
| 20 %-regel og moms | 3 | Revisor | Skriftlig validering | – | Produktion |
| Aftalegrundlag | 3 (uden for pluginet) | Ejer, revisor | Dokumenteret aftalegrundlag | – | Produktion |
| Tests og runtime | 1 + 4 | Alt ovenfor | Afsnit 11 og runtime på Local | Grøn CI; oprydning | Release |
| Digital accept, opkrævning, betalingsintegrationer | 5 | Egen fase og ADR | – | – | – |

Teknisk implementering er **ikke** tilladelse til produktionsbrug; produktion kræver de kategori 3-afklaringer, der gælder for den pågældende funktion.

### 15.8 Beslutningsoversigt

> Den gældende status pr. beslutning står i beslutningsregistret (16.1).

| ID | Beslutning | Anbefaling (forslag, ikke vedtaget) | Konsekvens ved udsættelse | Blokerer implementering? | Blokerer produktion? |
| --- | --- | --- | --- | --- | --- |
| D1 | Refunds af salg fra før afregningsstart | Forankring i cyklussens salgsrække (15.2 a) | Vindues-reglen kan ikke færdiggøres | Ja (vindues-reglen) | Ja |
| D2 | Negative rækker og karens | Negative tæller straks, positive efter karens (15.3) | Karens-reglen kan ikke færdiggøres; risiko for overbetaling | Ja (karens-reglen) | Ja |
| D3 | Ændring af afregningsstart | Ændres med audit indtil første bilag, derefter låst (15.4) | Afregningsstart-handlingen kan ikke færdiggøres | Ja (den handling) | Ja |
| D4 | Udestående uden nyt salg | Modregning og visning nu; manuel håndtering; opkrævning senere (15.5) | Ingen for implementeringen | Nej | Ja, før rigtige udeståender håndteres |
| G1 | Ordrer betalt med payout-gavekort | B, hvis runtime-test bekræfter data (15.6) | Payout-gavekort kan ikke bruges i produktion | Nej (kun G1-logikken) | Ja (payout-gavekort) |
| G2 | Begrænsning af gavekort til creatoren | Ihændehaverkode, indtil runtime-test viser en sikker begrænsning | Kendt begrænsning bevares | Nej | Ja, hvis ejeren kræver binding |
| G3 | Udløb, restværdi, annullering, refundering | Intet udløb; øvrigt afklares eksternt | Ingen for implementeringen | Nej | Ja |
| D5 | ADR-052 og afregnede ordrer | Blokér sletning af afregnede ordrer (14.3, scenarie 5) | Risiko for bilag uden ledger-rækker | Nej (blokeringen kan bygges) | Ja |
| D6 | Afregningsstart som kolonne eller konfiguration | Kolonne på creatoren (enklest at låse og auditere) | Teknisk valg udestår | Ja (datamodel) | Nej |
| D7 | Syntetiske `MB-T23`-testgavekort på Local | Godkend, når YITH WooCommerce Gift Cards er aktiveret | YITH-adapteren kan ikke runtime-testes | Nej (blokerer runtime-testen og dermed accept af YITH-adapteren) | Nej |
| D8 | Periodemodel | Ét bilag pr. payout (foreløbig) | Bilagsdesign udestår | Ja (bilag) | Ja |
| D9 | Nummerformat og serier | Foreløbig serie fastsat af ejeren; endeligt af revisor | Bilagsnummer udestår | Ja (bilag) | Ja |
| D10 | Foreløbigt bilagsindhold og stamdata (feltliste og indsamling, 13.2 og 13.6 punkt 13, 14, 16) | Foreløbig feltliste efter 13.2 (T-felter); R-felter afventer revisor/SKAT | Bilag og stamdata kan ikke færdiggøres | Ja (bilag, stamdata) | Ja |
| D11 | Rettelsesmekanisme (13.9) | Uforanderlige bilag, korrigerende bilag med henvisning, fremadrettet modregning | Datamodellen for korrektioner kan ikke færdiggøres | Ja (korrigerende bilag) | Ja (regnskabsreglen, R1) |
| R1 | Moms, momstekst, dokumenttyper, bogføring, gavekortbehandling | Afklares med revisor/SKAT | – | Nej | Ja |
| R2 | 20 %-reglen (ADR-049) | Valideres af revisor | – | Nej | Ja |
| R3 | Opbevaring og uninstall (ADR-025, ADR-029, ADR-059) | Bevar altid indtil beslutning | – | Nej | Ja |
| R4 | Aftalegrundlag for omvendt fakturering | Dokumenteres uden for pluginet | – | Nej | Ja |
| L1 | Korrektion af gennemførte payouts | Senere fase | – | Nej | Afhænger af R1 |

## 16. Beslutningsregister, runtime-testprotokol og spor

**Scope:** "YITH" betyder i fase 23 udelukkende **YITH WooCommerce Gift Cards 4.38.0**. Andre YITH-plugins, herunder YITH WooCommerce Order Tracking, er uden for scope og ignoreres: de undersøges, aktiveres, konfigureres, testes og integreres ikke, og de er ingen afhængighed for gavekort eller payouts.

Registret er den gældende status for de åbne beslutninger; oversigten i 15.8 er et resumé. Status ændres kun til "godkendt" efter projektejerens udtrykkelige godkendelse. **Pr. 2026-10-11:** D1–D3, D5, D6, D7 (a, b, c, e; ikke d) og D8–D11 er godkendt (D8–D11 foreløbigt); D4 afventer ejerbeslutning; G1–G3 er uafklarede og blokerer produktionsbrug.

### 16.1 Beslutningsregister

Status: *forslag* · *afventer ejerbeslutning* · *afventer test* · *afventer ekstern afklaring* · *godkendt*.

| ID | Nuværende anbefaling | Hvad skal besluttes | Alternativer | Konsekvens ved udsættelse | Blokerer | Beviser for at lukke | Status |
| --- | --- | --- | --- | --- | --- | --- | --- |
| D1 | Forankring i cyklussens salgsrække (15.2 a) | Hvordan rækker afgrænses ved afregningsstart | b) ordrelinjens første salg; c) rækkens egen dato | Vindues-reglen kan ikke færdiggøres | Implementering (vindues-regel), produktion | Ejergodkendelse; integrationstests i 15.2 | **Godkendt** 2026-10-10 (ADR-054) |
| D2 | Negative rækker straks, positive efter karens (15.3) | Hvornår negative rækker påvirker saldoen | Alle efter karens; negative følger salgets karens | Karens-reglen kan ikke færdiggøres; risiko for overbetaling | Implementering (karens-regel), produktion | Ejergodkendelse, inkl. accept af midlertidigt lavere saldo; tests i 15.3 | **Godkendt** 2026-10-10 (ADR-054) |
| D3 | Ændres med audit indtil første bilag, derefter låst (15.4) | Hvornår afregningsstarten kan ændres | Altid låst; altid ændrbar med audit | Afregningsstart-handlingen kan ikke færdiggøres | Implementering (den handling), produktion | Ejergodkendelse; tests i 15.4 | **Godkendt** 2026-10-10 (låsen efter første bilag følger med bilagene, trin 23.8) |
| D4 | Modregning og visning nu; manuel håndtering; opkrævning senere (15.5) | Hvordan udestående uden nyt salg håndteres | Senere proces til afregning/opkrævning | Ingen for implementeringen | Produktion (før rigtige udeståender håndteres) | Ejergodkendelse; for en senere opkrævning også aftalegrundlag og revisor | Afventer ejerbeslutning |
| D5 | Blokér ADR-052-sletning af afregnede ordrer (14.3, scenarie 5) | Samspillet mellem ADR-052 og bilag | Tillad sletning med korrigerende bilag | Risiko for bilag uden ledger-rækker | Produktion | Ejergodkendelse; test af afvist sletning | **Godkendt** 2026-10-11: sletning efter ADR-052 afvises, når ordrens historik indgår i et afregningsbilag (ADR-060) |
| D6 | Kolonne på creatoren | Lagring af afregningsstart | Separat konfiguration (option/tabel) | Datamodellen kan ikke færdiggøres | Implementering (datamodel) | Ejergodkendelse; migrations- og schema-tests | **Godkendt** 2026-10-10 (`merchboard_creators.payout_start_at`) |
| D7 | Godkend syntetiske `MB-T23`-testgavekort, når YITH WooCommerce Gift Cards er aktiveret | Om og hvornår testdata må oprettes i YITH WooCommerce Gift Cards på Local | Kun læsning af koden (ingen runtime-test) | YITH-adapteren og G1/G2 kan ikke runtime-testes | Runtime-test (og dermed accept af YITH-adapteren); **ikke** isoleret arbejde med fake-adapteren (18.7) | Ejergodkendelse af YITH-aktivering og testdata | **Godkendt** D7-a (aktivering kun på Local), D7-b (MB-T23-data), D7-c (midlertidigt mu-plugin, der blokerer e-mail) og D7-e (deaktivering efter testen); **D7-d (filtertest for G2) ikke godkendt**. Udført 2026-10-10/11 (16.5) |
| D8 | Ét afregningsbilag pr. payout (foreløbig, 13.3) | Periodemodel | Periodiske bilag | Bilagsdesignet kan ikke færdiggøres | Implementering (bilag), produktion | Foreløbig ejergodkendelse; endelig validering hos revisor/SKAT | **Godkendt** 2026-10-11 (foreløbigt, a): ét afregningsbilag pr. payout; endelig validering hos revisor/SKAT |
| D9 | Foreløbig nummerserie fastsat af ejeren (13.8) | Format, serier, huller | Én serie / separate serier for korrektioner; pr. år | Bilagsnummer kan ikke færdiggøres | Implementering (bilag), produktion | Foreløbig ejergodkendelse; endelig validering hos revisor/SKAT | **Godkendt** 2026-10-11 (foreløbigt, a): én fortløbende serie ``AFR-000001`` for afregningsbilag og korrigerende bilag, uden årlig nulstilling; endelig validering hos revisor/SKAT |
| D10 | Foreløbig feltliste efter 13.2 (T-felter nu, R-felter afventer revisor/SKAT) | Bilagets foreløbige indhold og hvilke creator- og virksomhedsstamdata der indsamles, hvordan og med hvilken beskyttelse | Færre eller flere felter | Bilag og stamdata kan ikke færdiggøres | Implementering (bilag, stamdata), produktion | Ejergodkendelse af den foreløbige feltliste; endelig liste hos revisor/SKAT | **Godkendt** 2026-10-11 (foreløbigt, a): kun eksisterende data (creatorens ID, visningsnavn og momsstatus; butikkens navn og WooCommerce-adresse; T-felter); ingen nye personoplysninger; R-felter afventer revisor/SKAT |
| D11 | Uforanderlige bilag, korrigerende bilag med henvisning, fremadrettet modregning (13.9) | Den tekniske rettelsesmekanisme | Kreditnota for hver refund efter et bilag | Datamodellen for korrektioner kan ikke færdiggøres | Implementering (korrigerende bilag), produktion | Ejergodkendelse af mekanismen; regnskabsreglen hos revisor/SKAT (R1) | **Godkendt** 2026-10-11 (a): uforanderlige bilag, fremadrettet modregning, korrigerende bilag med henvisning (datamodel og service; admin-formular uden for 23.8); regnskabsreglen afventer R1 |
| G1 | Model B (hele ordren udelukkes), **betinget af en bestået runtime-test** (16.3) | Kommissionsgrundlag for ordrer betalt med payout-gavekort | A almindeligt salg; C kun gavekortandelen | Payout-gavekort kan ikke bruges i produktion | Produktion af payout-gavekort; implementering af G1-logikken (ikke registrering og visning med fake-adapteren, 18.7) | Bestået G1-beslutningsregel (16.3), derefter ejergodkendelse; regnskabsbehandling eksternt | **Ikke opfyldt** ved runtime-testen 2026-10-11 (16.5): vej d og e registrerer intet på kortet. **2026-10-16:** kuponværnet for vej d og e og visningen af pluginets registrering er integreret i `main` (PR #2, 16.8); ikke runtime-verificeret, og kommissionsgrundlaget er ikke besluttet. Forbliver produktionsblokering |
| G2 | Ihændehaverkode, indtil en sikker begrænsning er vist (bekræftet ved runtime-testen: en gæst indløste via Store API; filtertesten, D7-d, er ikke godkendt) | Om gavekort skal begrænses til creatoren | Begrænsning via YITH-filtre (`yith_ywgc_check_gift_card`, `ywgc_verify_coupon_code_condition`) | Kendt begrænsning bevares | Produktion, hvis ejeren kræver binding (ikke fake-adapter-arbejdet, 18.7) | Runtime-test af alle indløsningsveje (16.2, trin 9), derefter ejerbeslutning | Afventer test og ejerbeslutning |
| G3 | Intet udløb; restværdi, annullering og refundering afklares eksternt (runtime-testen: kuponvejen gendanner ikke saldoen ved annullering/refund, 16.5) | Udløb, restværdi, annullering og refundering | Udløbsdato (felt findes i YITH, ingen UI i gratisversionen) | Ingen for implementeringen | Produktion | Revisor/jurist; runtime-test af YITH's adfærd (16.2, trin 4, 6, 7) | Afventer ekstern afklaring |

**Eksterne afklaringer og senere fase** (status uden for registret ovenfor, se 15.8): R1 moms, momstekst, dokumenttyper, bogføring og gavekortbehandling; R2 20 %-reglen; R3 opbevaring og uninstall; R4 aftalegrundlag uden for pluginet: alle *afventer ekstern afklaring*. L1 korrektion af gennemførte payouts: *senere fase*.

### 16.2 Runtime-testprotokol for YITH WooCommerce Gift Cards (udført 2026-10-10/11 undtagen trin 9, se 16.5)

**Udføres ikke** før projektejeren udtrykkeligt har godkendt aktivering af YITH WooCommerce Gift Cards på Merch Dashboard Local og oprettelse af `MB-T23`-testdata (D7). Hvad hvert trin kræver (aktivering, registrering, testdata), står i 17.3. Protokollen har to dele: **del 1** kan køres, så snart pluginet er aktivt (kun YITH WooCommerce Gift Cards 4.38.0 og WooCommerce' betalings-, refund- og ordreforløb; ingen andre plugins); **del 2** kræver, at Merch Dashboards registrering og adapter er implementeret (spor B).

| # | Del | Handling | Forventet resultat | Observerbare beviser | Bestået hvis |
| --- | --- | --- | --- | --- | --- |
| 1 | 1 | **Forudsætninger og sikkerhed:** bekræft godkendelse; kun Merch Dashboard Local; noter baseline (antal `gift_card`-posts, ordrer, ledger-, cyklus-, moms-, snapshot- og payout-rækker), WooCommerce' moms- og kuponindstillinger; bloker udgående e-mail via et `pre_wp_mail`-filter, der kun registrerer modtager og emne; brug kun syntetiske creators, produkter, ordrer og kort med prefix `MB-T23` | Intet rigtigt data berøres; e-mail sendes ikke | Baseline-tal; filteret aktivt | Baseline er noteret, og ingen rigtige data indgår |
| 2 | 1 | **Manuel oprettelse** af et `MB-T23`-gavekort i YITH's admin ("Create Code") med beløb og valuta; noter om "internal notes" og udløb kan sættes i gratisversionens brugerflade | Kortet findes med status `publish`, beløb = saldo | Post-type `gift_card`, meta `_ywgc_amount_total`, `_ywgc_balance_total`, `_ywgc_currency` | Kortet kan oprettes manuelt med korrekt beløb og valuta |
| 3 | 2 | **Registrering i Merch Dashboard** via POST-formularen med nonce for en syntetisk ventende gavekort-payout | Payouten bliver `completed` med udbyder og eksternt ID | Payout-rækken; audit `payout_gift_card_registered`; ingen kode i URL eller svar | Registrering lykkes kun med korrekt nonce og capability |
| 4 | 2 | **Validering:** registrér kort med forkert beløb, forkert valuta, deaktiveret status, udløbet kort (udløb sat i data), delvist brugt kort og ukendt kode | Hver registrering afvises med sin fejlkode; payouten forbliver `pending` | Fejlkoder; uændret payout; ingen audit for afviste | Alle seks afvises, og intet gemmes |
| 5 | 1 | **Indløsningsveje:** gennemfør `MB-T23`-ordrer med kortet via (a) kuponfeltet i klassisk checkout, (b) kuponfeltet i blokcheckout/Store API, (c) YITH's egen formular (AJAX), (d) en admin-oprettet ordre med kupon og (e) en ordre via REST med kuponlinje | Hver vej enten virker eller afvises; saldoen nedskrives kun ved gennemførte ordrer | Ordrens linjer, kuponlinjer og meta; kortets `_ywgc_orders` og saldo; ledger-rækker (net og moms) | Pr. vej er det dokumenteret: virker ja/nej, saldo nedskrevet ja/nej, ordren registreret på kortet ja/nej, effekt på linjebeløb og moms |
| 6 | 1 | **Delvis indløsning:** brug kortet til en ordre mindre end saldoen | Restsaldo = beløb − brugt | Kortets saldo før/efter | Restsaldoen er korrekt i heltal efter butikkens decimaler |
| 7 | 1 | **Refund, annullering og fejlet ordre** for en ordre betalt (helt/delvist) med kortet; også en delvis refund | YITH gendanner saldoen ved `cancelled`, `refunded`, `failed`; ledgeren registrerer refund/reversal | Kortets saldo; ordremarkøren `_ywgc_is_gift_card_amount_refunded`; ledger-rækker | Adfærden for hel og delvis refund er dokumenteret; ingen dobbelt gendannelse |
| 8 | 1 | **E-mail:** opret kort manuelt med og uden modtager/digital-markering | Om pluginet forsøger at sende e-mail | Registrerede afsendelsesforsøg i `pre_wp_mail`-filteret | Det er dokumenteret, hvornår der sendes, og at det kan undgås |
| 9 | 1 | **Begrænsning til creatoren:** forsøg indløsning som en anden bruger, som gæst og via alle veje fra trin 5; afprøv, om `yith_ywgc_check_gift_card` og `ywgc_verify_coupon_code_condition` kan afvise (kun i et midlertidigt test-mu-plugin, der fjernes igen, hvis ejeren godkender det) | Uden filtre: kortet virker for alle (ihændehaver). Med filtre: dokumenteret pr. vej | Ordreresultat pr. vej og bruger | Det er dokumenteret pr. vej, om en begrænsning kan håndhæves server-side, inkl. den endelige saldo-fratrækning |
| 10 | 2 | **Idempotens og fejl:** registrér samme kort to gange på samme payout, på to payouts, samtidig fra to forbindelser, og efter en simuleret timeout | Samme payout: "allerede registreret"; anden payout: afvist; samtidig: kun én lykkes | Payout-rækker; `UNIQUE(gift_card_provider, gift_card_external_id)`; audit | Ét kort er aldrig knyttet til to payouts, og ingen payout er `completed` uden valideret kort |
| 11 | 1 + 2 | **Lækage:** søg efter testkoderne i URL'er (adgangslog/requests), Merch Dashboards logs, audit, fejlbeskeder og Merch Dashboards admin-lister | Koderne findes kun i YITH's egne data og i creatorens private visning | Søgeresultater | Ingen kode uden for YITH og den private visning; YITH's egen liste noteres som kendt begrænsning |
| – | – | **Oprydning:** slet `MB-T23`-ordrer, -kort, -produkter, -creators og deres ledger-, cyklus-, moms-, snapshot- og payout-rækker; fjern evt. test-mu-plugin; deaktiver YITH, hvis ejeren ønsker det | Baseline genoprettet | Tal mod baseline | Alle tal = baseline; rigtige data uændrede |

En bestået runtime-test godkender **ikke** den regnskabsmæssige behandling.

### 16.3 Beslutningsregel for G1 (model B)

**Hvad koden viser (ikke runtime-verificeret):** kuponvejen fratrækker saldoen og kalder `register_order()`, som gemmer ordre-ID'et på kortet (`_ywgc_orders`); WooCommerce gemmer desuden en kuponlinje med koden på ordren. YITH's formular gemmer koderne i kurvens session, fratrækker ordrens total og sætter en markør på ordren; om ordren også registreres på kortet, er ikke verificeret.

**Model B kan kun vælges, hvis runtime-testen (16.2, trin 5 og 7) viser for hver indløsningsvej, der kan bruges i butikken:**
1. Der skrives en **deterministisk registrering**, der knytter ordren til netop det gavekort (fx ordre-ID på kortet), og som Merch Dashboard kan læse gennem adapteren uden at læse eller gemme koden.
2. Registreringen findes for **alle** ordrer betalt (helt eller delvist) med kortet og for **ingen** andre ordrer.
3. Registreringen er på plads, **før** ordrens ledger-rækker kan indgå i et bilag, og bevares ved refund, annullering og genoprettet saldo.
4. En vej, der ikke opfylder 1–3, skal være dokumenteret og håndhævet som utilgængelig for payout-gavekort i butikken; ellers er kriteriet ikke opfyldt.

**Hvis kriteriet ikke er opfyldt:** G1 forbliver uafklaret, og payout-gavekort må ikke bruges i produktion. Der bruges ingen heuristik (fx beløbssammenfald, tidspunkter eller kupontekst). Ukendte betalingsforhold fortolkes ikke som almindeligt salg; de kan kun undgås ved, at metoden ikke er i produktion.

**Skel mellem:**

| | Indhold |
| --- | --- |
| Hvad YITH/WooCommerce registrerer | Afgøres af runtime-testen pr. vej |
| Hvad Merch Dashboard pålideligt kan udlede | Kun en registrering, der opfylder punkt 1–3 |
| Hvilke data der mangler | Dokumenteres pr. vej i testen (fx YITH's formular) |
| Konsekvens af manglende data | G1 uafklaret; ingen produktion af payout-gavekort; kommissionsberegningen ændres ikke |

### 16.4 Tre spor

| Spor | Indhold | Afhængigheder | Accept |
| --- | --- | --- | --- |
| **A – Dokumentation og arkitektur** | Plan, ADR-forslag, beslutningsregister, testprotokol | Ingen | Kan fortsætte nu; dokumenterne er konsistente; intet markeret som godkendt uden ejerens godkendelse |
| **B – Isoleret teknisk implementering** | Aggregering, reservationer, kryptering, capabilities, audit, bilag (teknik), `GiftCardProvider` med fake-adapter (afsnit 18), registrering, YITH-adapter | Ejergodkendelse af ADR-forslagene og af D1–D3, D6, D8–D11 for de berørte dele (rækkefølge i 17.4); for YITH-adapterens runtime-test også D7 og aktivering | Afsnit 11 og 15.7; grøn CI; bank, PayPal og gavekort er bag feature-gates (gavekort utilgængelige uden grøn `availability()`); intet aktiveret i produktion |
| **C – Produktionsaktivering** | Brug med rigtige creators og rigtige penge | Bestået runtime-test (16.2) for de relevante dele; ejerbeslutninger D4, D5, G1–G3; ekstern afklaring R1–R4 og 20 %-reglen; dokumenteret aftalegrundlag uden for pluginet | Skriftlige afklaringer foreligger; projektejeren godkender aktivering pr. metode (bank, PayPal, gavekort hver for sig) |

En bestået runtime-test eller en grøn CI giver ikke i sig selv tilladelse til produktionsbrug, og godkender ikke den regnskabsmæssige behandling.

### 16.5 Runtime-testens resultater (2026-10-10/11)

Udført på Merch Dashboard Local efter D7-a/b/c/e (D7-d ikke godkendt: trin 9 med filtre er ikke udført). Kun YITH WooCommerce Gift Cards 4.38.0 var aktiveret; udgående e-mail var blokeret af et midlertidigt mu-plugin, der kun registrerede modtager og emne. Syntetiske data: creator `MB-T23 Creator` (20 %, ikke momsregistreret), produkt `MB-T23 Tee` (100 kr. inkl. 25 % moms, virtuelt), kort `MB-T23-*`, ordrer med `mb-t23-customer@example.invalid`. Kort blev oprettet gennem pluginets egen admin-gemmekode (`YIT_Metabox::save_postdata` med nonce), emuleret i WP-CLI.

**Del 1 (YITH og WooCommerce)**

| Trin | Resultat |
| --- | --- |
| 1 | Baseline noteret og uændret ved genoptagelse; e-mail blokeret. |
| 2 | "Create Code" gemmer beløb og saldo som fri tekst præcis som indtastet (også `99,99` med komma), intern note gemmes, men **ingen `_ywgc_currency` og ingen `_ywgc_expiration`**. Kode = posttitel. Pluginets opslag på kode ser kun publicerede kort og tager det nyeste ved dubletter; pluginets kortklasse **skriver** beløb og saldo 0 ved læsning af et kort uden disse felter. |
| 5 | Se tabellen nedenfor. |
| 6 | Delvis indløsning (150 kr.-kort, 100 kr.-ordre): restsaldo 50 i vej a, b og c. |
| 7 | Kuponvejen (a, b): annullering og status "refunded" gendanner **ikke** saldoen. YITH's formular (c): annullering og "failed" gendanner (loftet ved kortets beløb); markøren `_ywgc_is_gift_card_amount_refunded` forhindrer dobbelt gendannelse; delvis refund gendanner intet, fuld refund (status "refunded") gendanner hele gavekortandelen. Ledgeren registrerer refunds og reversals som for andre ordrer. |
| 8 | Oprettelse af kort (med og uden digital-markering og modtager) sendte **ingen** e-mail. WooCommerce' egne ordre-e-mails blev forsøgt for hver ordre og status (blokeret). |
| 9 | **Ikke udført** (D7-d). Uden filtre: en gæst indløste kortet via Store API (ihændehaverkode, G2). |

| Vej | Kørsel | Virker | Saldo fratrukket | Ordre på kortet (`_ywgc_orders`) | Ordrelinjer | Ledger (net/moms) |
| --- | --- | --- | --- | --- | --- | --- |
| a klassisk checkout, kuponfelt | Emuleret (kurv, `WC_Checkout`, `woocommerce_checkout_order_processed`) | Ja | Ja | Ja | Kuponlinje; linje 0/0 | 0/0 |
| b blokcheckout/Store API, kuponfelt | Rigtig HTTP som gæst | Ja | Ja | Ja | Kuponlinje; linje 0/0 | 0/0 |
| c YITH's formular | Emuleret (session + `WC_Checkout`) | Ja | Ja | Ja; ordren har `_ywgc_applied_gift_cards` med koden | Ingen kuponlinje; linjer uændret (80/20), ordretotal 0 | 80/20 (fuldt salg) |
| d admin-oprettet ordre med kupon | Som admin-AJAX'en (`apply_coupon`) | Rabat gives | **Nej** | **Nej** | Kuponlinje; linje 0/0 | 0/0 |
| e REST `/wc/v3/orders` med kuponlinje | Intern REST-dispatch som admin | Rabat gives | **Nej** | **Nej** | Kuponlinje; linje 0/0 | 0/0 |

**Yderligere fund:** `register_gift_cards_usage` kører på enhver `woocommerce_new_order` og bruger kurvens gavekortbeløb i den aktuelle PHP-proces; i én CLI-proces blev en efterfølgende admin- og REST-ordre derfor også trukket på et kort fra vej c (testartefakt, ordrerne blev ryddet op, men en reel risiko for requests, der opretter flere ordrer med en indlæst kurv).

**Del 2 (Merch Dashboard, efter implementeringen)** med `YithGiftCardProvider` valgt i Settings og karensperiode midlertidigt 0 (auditeret, derefter 14 igen):

| Trin | Resultat |
| --- | --- |
| 3 | Gyldigt kort (10,00 kr.): payouten `completed` med `yith-wcgc` og kortets post-ID; én `payout_gift_card_registered` uden kode; resultatkoden i redirect-URL'en, aldrig koden. Gavekort-payouts har ingen række i `merchboard_payout_secrets`. |
| 4 | Afvist uden ændringer og uden audit: ukendt kode (`gift_card_not_found`), forkert beløb (`gift_card_amount`), `_ywgc_currency` = EUR (`gift_card_currency`), deaktiveret (`gift_card_not_active`), udløbet (`gift_card_expired`), delvist brugt (`gift_card_used`), beløb med komma (`gift_card_invalid_data`), to kort med samme kode (`gift_card_ambiguous`), tom kode. |
| 10 | Samme kort igen: "allerede registreret", ingen ny audit. Samme kort på en anden payout (også med små bogstaver): `gift_card_linked`. To samtidige WP-CLI-processer med samme kort på to payouts: præcis én lykkedes, den anden fik `gift_card_linked`. |
| 11 | Ingen testkode i audit, payouts, secrets, options, logs (`debug.log` findes ikke; PHP-fejlloggen indeholder ingen gavekortkode) eller Merch Dashboards admin-sider. Kendte steder uden for Merch Dashboard: YITH's egne data og WooCommerce' kuponlinjer på ordrer betalt via kuponfeltet. Creatorens visning viste den rigtige kode og live saldo; en anden creator fik `not_found`. |

**Oprydning:** alle `MB-T23`-ordrer (12), refunds (4), kort (27), produktet, creatoren og deres ledger-, cyklus-, moms-, snapshot- og payout-rækker er slettet; mu-pluginet og log-option er fjernet; YITH er deaktiveret igen; karensperiode 14 og ingen gavekortudbyder. Sammenlignet med baseline: uændret undtagen schema-version 10 (migration 10) og 13 bevarede audit-rækker (append-only: creator_created, creator_markup_changed, product_creator_linked, 4 × payout_requested, 4 × payout_settings_changed, 2 × payout_gift_card_registered). Creator 1 (16,67 %), brugertema `dark-pink`, logo 198 og ordre 333 er uberørt.

**Konklusion:** registreringen og YITH-adapteren virker mod 4.38.0. **G1 er ikke opfyldt** (vej d og e), **G2** er bekræftet som ihændehaverkode, **G3** har reelle huller (kuponvejen gendanner ikke saldo). G1–G3 og R1 er fortsat produktionsblokeringer; ADR-059 er uafklaret.

### 16.6 Runtime-test af payouts og afregningsbilag (trin 23.9, 2026-10-12)

**Før:** Local's database svarede igen; baseline stemte med tilstanden efter 23.7-oprydningen. Indlæsningen af WordPress lod pluginets automatiske migration køre til schema-version 11 (repo-koden er live via junction, samme mønster som version 9 og 10); de nye tabeller var tomme. YITH WooCommerce Gift Cards forblev inaktivt.

**Fremgangsmåde:** én WP-CLI-proces; udgående e-mail blokeret i processen (7 WooCommerce-e-mails stoppet); `MERCHBOARD_PAYOUT_KEY` som testnøgle defineret kun i processen (`wp-config.php` uændret; payout-services og REST-serveren genopbygget i processen, fordi WP-CLI fyrer `rest_api_init` under opstarten); salg dateret 30 dage tilbage, så karensperioden på 14 dage var uændret. Syntetisk creator `MB-T23 Creator 239` (20 %, ikke momsregistreret) med en syntetisk bruger, produkt 100 kr. inkl. moms. Et første forsøg uden genopbygning (bank/PayPal utilgængelige) blev ryddet op, før testen blev kørt igen.

**Resultat (28/30 bestået; de 2 øvrige var fejl i testens forventninger og er verificeret):** uden nøgle er bank/PayPal utilgængelige; saldo 32,00 (16,00 pr. salg); gavekort afvist uden valgt udbyder; anmodning via REST med samme nøgle giver samme payout; kun maskeret modtager og `no-store`; krypterede oplysninger; audit-logget visning af fulde oplysninger; gennemførelse kræver bekræftelse, gentagelse ændrer intet; **AFR-000001** (kommission 32,00, udbetalt 20,00, saldo efter 12,00, 2 rækker) i samme transaktion; hemmeligheden slettet; adminsiden viser bilaget mærket foreløbigt uden kontonummer; creatoren ser eget bilag (`no-store`), listen viser bilagsnummeret, en anden creator får 404; refund efter bilaget giver udestående 4,00; nyt salg giver disponibelt 12,00; annullering og afvisning; **AFR-000002** afregner kun refunden og det nye salg (kommission 0, overført 12,00, udbetalt 10,00, saldo 2,00); ingen række afregnet to gange; afregningsstarten låst (D3); korrigerende bilag **AFR-000003** henviser til AFR-000001; sletning af en afregnet ordres historik afvist (D5); ingen konto, e-mail, reference eller begrundelse i audit eller bilag.

**Oprydning:** ordrer, refund, produkt, creator, bruger, payouts, hemmeligheder, bilag, bilagsrækker og tæller slettet. Baseline efter: uændret undtagen schema-version 11 og 31 bevarede audit-rækker (13 fra 23.7, 18 fra 23.9; append-only). Creator 1, `dark-pink`, logo 198 og ordre 333 uberørt.

**Ikke testet på Local i 23.9:** gavekortregistrering med afregningsbilag (testet i 24.3, se 16.7), samtidige bilagsudstedelser fra to forbindelser, browser-UI. **MySQL 8 er verificeret via CI** (CI for commit `14ddfd0` (run 38240753611, 2026-10-12): alle fire jobs grønne (PHP lint og statisk analyse, PHP-integration på MySQL 8.0.46 med 589 tests, PHP 7.2-syntakstjek, JS lint/typer/tests/build)). En grøn CI er ikke en produktionsgodkendelse: R1–R4, G1–G3 og ADR-059 er uafklarede.

### 16.7 Runtime-test af gavekortregistrering med afregningsbilag (trin 24.3, 2026-10-12)

> **Note 2026-10-16:** testen blev udført 2026-10-12 på den daværende kodestand: seneste commit var `14ddfd0`, plus ikke-committede ændringer (bl.a. fra 24.2). Den samlede arbejdstrætilstand blev ikke registreret som en hash. Testen er ikke gentaget på den nuværende `main` og er historisk test-evidens, ikke en runtime-godkendelse af PR #2 eller den nuværende kode. Projektejerens godkendelse af selve kørslen er ikke dokumenteret i repoet. Teksten nedenfor er uændret fra 2026-10-12.

Fase 24 har ingen egen rapport; resultatet står her ved de øvrige runtime-test af payouts.

**Før:** skrivebeskyttet baseline på Local: schema-version 11, roller version 5, YITH WooCommerce Gift Cards 4.38.0 aktiv (aktiveret af projektejeren), ingen gavekort, payouts, bilag eller `MB-T23`-rester, karensperiode 14 og ingen gavekortudbyder, `MERCHBOARD_PAYOUT_KEY` ikke defineret, audit 439 rækker. Pluginet kører repo-koden via junction, inkl. de ikke-committede ændringer fra 24.2. Ingen kodeændringer; testen kørte som WP-CLI-scripts uden for repoet.

**Fremgangsmåde:** syntetisk creator `MB-T23 Creator 243` (20 %, ikke momsregistreret) med en syntetisk bruger, et produkt til 100 kr. inkl. moms og 3 ordrer betalt for 30 dage siden (karensperioden uændret). Udgående e-mail blokeret i alle processer (6 WooCommerce-ordremails stoppet), `wp_die()` kastede en undtagelse. Den eneste indstilling, der blev ændret, var gavekortudbyderen (YITH via den auditerede indstillingshandling), som blev nulstillet bagefter. Gavekort oprettet som i 16.5 gennem YITH's egen gemmerutine med syntetiske koder `MB-T23-GC-243-*`. Fejlinjektionen var et `query`-filter kun i testprocessen.

**Resultat (41/44 tjek bestået; de 3 øvrige var en fejl i testens audit-tæller, der talte fra id 0 og derfor medregnede de bevarede rækker fra 23.7 og 23.9; audit-loggen for kørslen er efterfølgende gennemgået række for række og bekræfter de forventede tal):**

- **Anmodning:** gavekort kan først anmodes, når udbyderen er valgt. Saldo 48,00 (16,00 pr. salg). Bank/PayPal er utilgængelige uden nøgle. Anmodningen på 20,00 via REST giver 201, og samme nøgle igen giver samme payout. Ingen hemmeligheds-række.
- **Fejlscenarier (intet ændres, ingen bilag, ingen tæller, ingen registrerings-audit):** ukendt kode `gift_card_not_found`, forkert beløb `gift_card_amount`, delvist brugt `gift_card_used`, deaktiveret `gift_card_not_active` og tom kode `invalid_gift_card_code`. Ugyldig nonce og en creator uden `merchboard_manage_payouts` afvises med 403.
- **Gyldig registrering:** payouten er gennemført med udbyder og kortets ID. **AFR-000001** (kommission 48,00, overført 0, udbetalt 20,00, saldo efter 28,00, de 3 salg), foreløbigt, metode `gift_card`, ingen kode i snapshot.
- **Idempotens:** samme kort på samme payout igen ændrer intet (stadig ét bilag, én `payout_gift_card_registered`, én `settlement_document_issued`). Samme kort på en anden payout giver `gift_card_linked`.
- **Rollback:** en fejlende indsættelse af bilagsrækker under registreringen giver resultatet `error`. Payouten forbliver ventende uden kort, og der kommer intet bilag, ingen rækker, ingen tællerændring og ingen audit. Et nyt forsøg giver **AFR-000002** (intet nummer tabt; ingen nye rækker, kommission 0, overført 28,00, udbetalt 20,00, saldo 8,00).
- **To reelt parallelle PHP-processer** (startet på samme tidspunkt, færdige inden for 0,04 s):
  - Dobbeltindsendelse af samme kort på samme payout gennemfører payouten én gang; begge svar er "registreret".
  - Ét kort på to forskellige payouts gennemfører præcis én; den anden får `gift_card_linked` og blev derefter afvist.
  - Bilag AFR-000001–000004 er unikke, og tælleren står på 5. Ét bilag pr. gennemført payout, 4 registreringer og 4 udstedelser i audit, ingen række afregnet to gange, og saldoen er overført 8 → 6 → 4.
- **Visninger (24.2):** payoutsiden viser AFR-000001 mærket foreløbigt uden kode. Fanen "Saldi" viser 48,00 frigivet, 44,00 udbetalt og saldo 4,00. Ordrehistorikken viser de 3 ordrer afregnet i AFR-000001 med link til payouten.
- **Creatorens visning:** creatoren ser sit eget kort med kode (bevidst, ADR-057) og sit eget bilag (`no-store`); en anden creator får 404.
- **Lækager:** gavekortkoden findes ikke i audit, payouts, bilag, bilagsrækker, options, andre posts' meta, ordrelinjer eller `debug.log` (findes ikke).

**Sideeffekter:** ingen e-mails sendt. Ingen YITH-handlinger i Action Scheduler; de nye rækker der er WooCommerce' og Merch Dashboards egne rutinejob (ordreimport, produktopslag) og er efterladt som hos 23.9.

**Oprydning:** de 3 ordrer, produktet, de 7 gavekort med meta, creatoren, brugeren, 5 payouts, 4 bilag, 3 bilagsrækker, tælleren, ledger-, cyklus-, moms- og snapshot-rækker samt kundeopslaget er slettet. Udbyderen er nulstillet via servicen, og indstillings-optionen er sat tilbage til den gemte værdi. Baseline efter: identisk med baseline før (inkl. YITH-options og `merchboard_settings`), undtagen 19 bevarede audit-rækker (append-only): `creator_created`, `creator_markup_changed`, `product_creator_linked`, 2 × `payout_settings_changed`, 5 × `payout_requested`, 4 × `settlement_document_issued`, 4 × `payout_gift_card_registered` og 1 × `payout_rejected`. Schema-version uændret (11). Creator 1 (16,67 %), `dark-pink`, logo 198 og ordre 333 er uberørte. YITH er fortsat aktiv som før testen.

**Ikke verificeret:** browser-UI; G1–G3 (uændrede produktionsblokeringer; testen brugte kun admin-registrering, ikke indløsning af kortene); samtidighed på MySQL 8 og under webserverens egne requests (de parallelle processer var WP-CLI). Afregningsbilagene er fortsat foreløbige og ikke godkendt af revisor/SKAT (R1–R4).

### 16.8 G1 i kode: trin 24.4 og integration i `main` (PR #2, 2026-10-16)

**Status:** kuponværnet og visningen af gavekortpluginets egen registrering er integreret i `main` via PR #2, squash-merget som `950890c` (2026-10-16, parent `9b60b8a`). Den efterfølgende dokumentationscommit er `4b39121`. Det er hverken en release eller en deploy; seneste tag er fortsat `v0.1.0`, som ikke indeholder payouts. G1 er **ikke** afsluttet og forbliver produktionsblokering: kommissionsgrundlaget (model A/B/C) er ikke besluttet, og ledger, saldo og afregningsbilag er uændrede.

**Forløb:**

- **24.4 (2026-10-12):** første implementering, lokalt og ikke committet. Videre implementering blev standset ved kommissionsgrundlaget. Koden og den daværende tekst til dette afsnit, inkl. beslutningsnoten til ejer og revisor (16.8.1–16.8.9: modellerne A/B/C, regneeksempel, refunderinger, revisorspørgsmål, teknisk restrisiko og manglende godkendelser), er bevaret i branchen `backup/g1-24.4-wip-2026-10-14` (`5904db9`). Beslutningsnoten er ikke ført ind i `main` og beskriver 24.4-udgaven af koden.
- **Ejerbeslutninger B2–B5 (registreret 2026-10-16, truffet i udviklingssamtalen):** visningen bruger midlertidigt pluginets registrering og mærker den tydeligt; E2-vurderingen (E3-rapporten) er fortsat den autoritative kilde (B2). Værnet og visningerne afhænger hverken af G1-aktiveringen eller af cutoff (B3). Ordreopslaget er en særskilt, valgfri grænseflade, så ADR-057's kontrakt er uændret (B4). En manglende `YWGC_META_GIFT_CARD_ORDERS` gør kun opslaget utilgængeligt; værnet afviser (fail closed), når registrerede payout-kort ikke kan kontrolleres (B5).
- **Integration:** B1 (integration på `main` og omfanget) er ikke registreret som en særskilt ejerbeslutning. Integrationen skete ved projektejerens særskilte godkendelse af squash-merge af PR #2 (2026-10-16).
- **PR #2** samler tre commits: `ae84a0d` (24.4-koden oven på `9b60b8a`), `1a261ee` (B2–B5) og `31d33a7` (b1/b2: en undtagelse i gavekortopslaget vises i ordrehistorikken som "kunne ikke kontrolleres" i stedet for at afbryde visningen af den gemte historik).

**Indløsningsveje** (kode i WooCommerce 11.1.2 og YITH WooCommerce Gift Cards 4.38.0 på Merch Dashboard Local, kun læst 2026-10-12):

| Vej | Hvordan kuponen anvendes | Registrering på kortet | Håndtering i `main` |
| --- | --- | --- | --- |
| a/b checkout (klassisk, Store API) med kuponfeltet | Kurvens `WC_Discounts`; YITH trækker saldoen og kalder `register_order()` ved `woocommerce_checkout_order_processed`/`woocommerce_store_api_checkout_order_processed` | Ja (`_ywgc_orders`) | Tilladt; værnet berører ikke kurven; vises som pluginets registrering |
| c YITH's formular | Kurvens session; `register_gift_cards_usage` ved `woocommerce_new_order` | Ja | Tilladt; vises som pluginets registrering |
| d admin `apply_coupon` | `WC_Order::apply_coupon()` → `WC_Discounts( $order )->is_coupon_valid()` | Nej | **Afvises** med besked |
| e REST API v3 `coupon_lines` | `calculate_coupons()` → `WC_Discounts( $order )->is_coupon_valid()`; ved fejl 400, og den allerede gemte ordre efterlades som `checkout-draft` uden kupon | Nej | **Afvises** (400) |
| REST API v1/v2 `coupon_lines` | Kode og rabat skrives direkte som ordrelinje, ingen validering, intet YITH | Nej (kortet bruges ikke) | Ikke dækket af værnet. Kortets saldo ændres ikke; rabatten er klientens. Dokumenteret restrisiko |

**Implementeret i `main`:**

- **`PayoutGiftCardCouponGuard`** på `woocommerce_coupon_is_valid` (prioritet 20), registreret, når WooCommerce-modulet er indlæst (understøttet WooCommerce). Værnet handler kun, når WooCommerce ikke allerede har afvist kuponen, og rabatten beregnes for en ordre (`WC_Abstract_Order`), aldrig for kurven. Det spørger `PayoutGiftCardOrders::is_payout_card()`:
  - Findes ingen gennemført gavekort-payout med registreret kort, kontaktes ingen udbyder, og kuponen godkendes.
  - Ellers slås udbyderen af hvert registreret kort op blandt de registrerede adaptere (uafhængigt af valget i Settings), og koden slås op med `find_by_code()`. Er koden et kort på en gennemført payout, afvises kuponen med beskeden om, at kortet er udstedt som creator-payout.
  - Kan kontrollen ikke gennemføres (udbyderen er ukendt eller ikke tilgængelig, opslaget fejler, payouts kan ikke læses, eller en uventet fejl), afvises kuponen med "Gift card order registration could not be checked" (da: "Gavekortregistreringen kunne ikke kontrolleres"). Loggen indeholder ordre-ID, fejlklasse og årsagskode, aldrig koden.
- **`PayoutGiftCardOrders`** (kun læsning; intet gemmes): `for_orders()` og `for_payout()` skelner mellem "ikke understøttet" (adapteren har ikke opslaget, eller opslaget er utilgængeligt, fx fordi pluginet mangler registreringen) og "kunne ikke kontrolleres": udbyderen af et registreret kort er ukendt eller ikke tilgængelig (fx fordi YITH er deaktiveret), opslaget kaster en fejl, eller et registreret kort mangler i udbyderens svar. Visningerne viser også en undtagelse fra selve opslaget (fx når payouts ikke kan læses) som "kunne ikke kontrolleres".
- **`GiftCardOrderRegistry`** (ny, valgfri): `order_registry_availability()` og `orders_paid_with()`. `YithGiftCardProvider` implementerer den og læser `_ywgc_orders`; opslaget kræver `YWGC_META_GIFT_CARD_ORDERS` = `_ywgc_orders`. `availability()` og dermed registreringen fra 23.7 er uændret. `GiftCardProvider` er uændret.
- **`PayoutRepository::registered_gift_cards()`** (ny læsemetode).
- **Visninger:** en gennemført gavekort-payout og ordrehistorikken (liste og ordre) viser pluginets registrering mærket "Registreret af gavekort-plugin — payout-status er ikke bekræftet"; ellers "Gavekort-pluginet understøtter ikke opslag af ordreregistreringer" eller "Gavekortregistreringen kunne ikke kontrolleres". Ordren forklarer, at kommissionsgrundlaget ikke er besluttet. Payout-nummeret linker kun med `merchboard_manage_payouts`.
- **Ikke ændret af PR #2:** schema og migrationer, ledger, saldo, afregningsbilag, audit og synkroniseringen.

**Kommissionsgrundlaget (uændret, ikke besluttet):** ved runtime-testen 2026-10-11 (16.5) afhang resultatet af indløsningsvejen: kuponfeltet (a/b) nedsatte linjebeløbene, så der kun var kommission af den kontante del (≈ C); YITH's formular (c) ændrede ikke linjerne, så der var fuld kommission (≈ A). Det er en historisk observation, ikke en ny verifikation af den nuværende `main`; ledgeren er ikke ændret af PR #2. Det ændres ikke uden en ejerbeslutning og revisorens bekræftelse.

**Test og CI:** unit- og integrationstestene er beskrevet i [TESTING.md](../TESTING.md). CI er grøn i alle fire jobs for PR-headen `31d33a7` (run `38833492674`), merge-committen `950890c` (run `38835281035`: PHP 8.2.34, MySQL 8.0.46, 2017 unit-tests, 768 integrationstests med de 2 kendte MySQL-skips) og dokumentationscommitten `4b39121` (run `38837323272`). CI og de automatiske tests kører uden rigtig WooCommerce og YITH.

**Ikke verificeret:**

- Vej a–e efter ændringen mod rigtig WooCommerce og YITH (Local-runtime-test), herunder fail closed med deaktiveret YITH.
- At `YWGC_META_GIFT_CARD_ORDERS` = `_ywgc_orders` i YITH 4.38 (kun læst i pluginets kode) og `orders_paid_with()`-forespørgslen mod rigtige data. `find_by_code()` er uændret siden kodestanden fra 24.3 og blev runtime-testet dér (16.7, 2026-10-12, ikke gentaget på den nuværende `main`).
- "Pay for order"-siden, andre plugins eller egen kode, der tilføjer kuponlinjer direkte (POS, import, abonnementer), og REST API v1/v2.
- YITH kan i en request, der opretter flere ordrer med en indlæst kurv, knytte en forkert ordre til et kort (16.5), og registreringen forsvinder, hvis kortet slettes i pluginet.

**Kendt risiko (fail closed):** findes der gennemførte gavekort-payouts med registrerede kort, og er YITH-udbyderen ikke aktiv, ikke en understøttet version (4.38.x free) eller ikke bestået sin selvtest, afvises **alle** kuponer, som WooCommerce ellers ville acceptere, på ordrer i admin og via REST API v3, også almindelige WooCommerce-kuponer. Kassen berøres ikke. Uden gennemførte gavekort-payouts gør værnet intet. Om staging eller produktion har gennemførte gavekort-payouts, er ikke undersøgt; risikoen dér er uafklaret.

**Kendt fejl, ikke rettet af PR #2:** en databasefejl ved læsning af ledgeren vises på den enkelte ordres side som "ingen gemt historik" i stedet for en fejl (`SalesRepository` behandler et tomt svar fra `wpdb::get_results()` ved en fejl som ingen rækker). Listen viser fejlen korrekt.

**Local:** sitet er stoppet (2026-10-16). Schema-versionen er sidst dokumenteret som 11 (16.7). `main` indeholder migration 12 og 13 (T2 og T4, se [E3-rapporten](2026-10-13-g1-e3-design.md)), så næste start med `main`'s kode forventes at migrere databasen til schema-version 13. Start afventer en dokumenteret og verificeret backup- og gendannelsesprocedure og særskilt godkendelse; først derefter kan runtime-testen planlægges.

**Udestående:** kommissionsmodel A/B/C (efter revisorens svar), listen over understøttede ordrekanaler og håndteringen af resten, backup-procedure og runtime-test på Local, fail closed-risikoen i staging og produktion samt release.

## 17. Kvalitetssikring og readiness (2026-10-10)

Statusvurdering; ingen beslutningsstatus ændres her.

### 17.1 Rettede modsigelser og uklarheder

| Sted | Problem | Rettelse |
| --- | --- | --- |
| Afsnit 3 og 14.2 | Vindues- og karensreglen ("`occurred_at ≥ afregningsstart` og `< nu − karens`" for alle rækker) kunne læses som vedtaget, selvom D1 og D2 foreslår en anden regel | Note om, at reglen er udgangspunktet før D1/D2, og henvisning til 15.2–15.3 |
| 13.3 | Afregningsperioden "fra forrige bilag til karens-grænsen" stemte ikke helt med 15.4 (senere perioder = rækker, der endnu ikke er afregnet) | Note med henvisning til 15.4 og D2 |
| Afsnit 10, trin 23.1 | Krævede gavekortbeslutningerne og aktivering af YITH WooCommerce Gift Cards før start; i strid med 16.4, hvor de kun blokerer produktion og YITH-adapterens runtime-test | Omformuleret efter sporene i 16.4 |
| 15.8 og 16.1, D7 | 15.8 sagde "blokerer implementering (YITH-adapteren)", 16.1 "blokerer runtime-test" | Ensrettet: blokerer runtime-testen og dermed accept af YITH-adapteren; adapterkoden kan skrives mod den fake-adapter |
| 13.6 og 16.1 | Foreløbigt feltsæt/stamdata og rettelsesmekanismen var ejerbeslutninger, der blokerer implementering (13.6), men manglede i registret | Tilføjet som **D10** og **D11** i 16.1 og 15.8 (allerede åbne punkter, ikke nye beslutninger) |
| Afsnit 12 og 16.1 | Afsnit 12's numre og registrets ID'er var ikke koblet | Krydsreference i afsnit 12 |
| 16.1 | R1–R4 og L1 havde kun status i 15.8 | Henvisning med status i 16.1 |
| 16.2 | Testprotokollen skelnede ikke tydeligt mellem kodeanalyse, aktivering, registrering, testdata og produktionsafklaringer | Tilføjet kategorier i 17.3 |

### 17.2 Tekniske påstande: grundlag eller antagelse

| Påstand | Grundlag | Status |
| --- | --- | --- |
| Refund-rækker kan knyttes entydigt til salgsrækken i samme cyklus | DATABASE.md (event-nøgler `s:<linje>:<cyklus>`, `r:<linje>:<refund-ID>`, kolonnen `cycle`) og `SalesPlanner` (refund-rækker oprettes med linjens cyklus) | **Underbygget** for nøgler og kolonner. **Antagelse, der testes:** at der altid findes en salgsrække for (linje, cyklus), når en refund registreres, også ved refunds på lukkede cyklusser (`c-ro`) |
| `UNIQUE(event_key)` hindrer dobbeltregistrering | DATABASE.md (`UNIQUE(event_key)`) og `SalesRepository` (en dublet er en registreret no-op); integrationstests i fase 18 | **Underbygget** for ledgeren. `UNIQUE(event_key)` i bilagsrækker er et **forslag** (13.4) |
| Ukendte rækker blokerer og er aldrig 0 | ADR-049/050, `ProfitSummary` og `LedgerCreatorProfits` (ukendt giver `unknown` uden beløb; testet) | **Underbygget** for profit. For payout-saldoen er det en **planlagt regel** (`LedgerCreatorPayouts` findes ikke endnu) |
| Afregnede rækker kan ikke medtages igen | Foreslået `UNIQUE(event_key)` i bilagsrækker (13.4) | **Antagelse**; verificeres under implementering |
| Samtidige reservationer og afslutninger giver ingen dobbeltudbetaling | Mønstret `GET_LOCK` + forbindelseskontrol + `Transaction` i `SalesRepository`, verificeret i CI på MariaDB og MySQL 8 | **Mønstret er underbygget**; låsen pr. creator og den betingede statusovergang er **nye** og testes med to forbindelser |
| Idempotens er defineret for alle kritiske operationer | Tabellen i 14.5 dækker afregningsstart, beregning, reservation, annullering, afvisning, afslutning, bilag, gavekortregistrering og korrektion | **Defineret som plan**; ingen af operationerne findes endnu |
| Bilagsnummerering og transaktionsgrænse passer til arkitekturen | ADR-024 (InnoDB, fail closed), `Transaction::run()` (afviser indlejring), rækkelås i InnoDB | **Foreneligt**, forudsat at afslutning, tæller, bilag og payout-status kører i **én** transaktion uden indlejring. **Antagelse, der testes:** opførsel ved mistet forbindelse under COMMIT (kendt restrisiko, ADR-038) |

### 17.3 YITH WooCommerce Gift Cards: hvad kræver hvad

| Kategori | Indhold | Status |
| --- | --- | --- |
| Undersøgelse af eksisterende adfærd (kun læsning af kode) | Datamodel, indløsningsveje, e-mail-betingelser, udløbsfelt, manglende ejerkontrol (8.2–8.3) | Udført |
| Kræver aktivering af pluginet | 16.2, trin 2, 5, 6, 7, 8, 9 | Afventer ejerens godkendelse af aktivering (D7) |
| Kræver Merch Dashboards registrering | 16.2, trin 3, 4, 10 og dele af 11 | Afventer spor B |
| Kræver eksplicit godkendte testdata (`MB-T23`) | Alle trin, der opretter kort, ordrer eller payouts (2–11) | Afventer ejerens godkendelse (D7) |
| Kan ikke afgøres af runtime-tests | Regnskabs- og momsbehandling af gavekort, restværdi, udløb og annullering (G3, R1); om resultatet af G1 er regnskabsmæssigt korrekt | Afventer ekstern afklaring |

### 17.4 Hvilke ejerbeslutninger åbner næste spor

**Mindste første skridt** (fake-adapteren til payout-gavekort, 18.9) kræver kun ADR-055 og ADR-057, og ADR-058, hvis adgang og audit skal med. Det afhænger ikke af punkt 1–3 nedenfor.

For **hele spor B** (isoleret implementering) skal disse besluttes, i denne rækkefølge:
1. Godkendelse af ADR-054, 055, 056 og 058 som grundlag (saldo, statusser og reservation, kryptering, adgang og audit).
2. **D1** og **D2** (vindues- og karensreglen i aggregeringen) samt **D6** (lagring af afregningsstart) og **D3** (låsning).
3. For afregningsbilag: **D8**, **D9**, **D10** og **D11** (foreløbige) samt ADR-060.
4. For gavekort: ADR-057 (manuel registrering bag `GiftCardProvider`); kode og tests mod fake-adapteren (afsnit 18) kræver intet mere, heller ikke D7, G1, G2 eller regnskabsgodkendelse. YITH-adapterens runtime-test kræver **D7** og aktivering.

D4, D5 og G1–G3 blokerer ikke spor B.

### 17.5 Readiness

| Spørgsmål | Vurdering |
| --- | --- |
| Tilstrækkeligt afklaret til at fortsætte dokumentationsarbejdet | Ja: spor A kan fortsætte; arkitektur, invariants, testprotokol og beslutningsregister er konsistente |
| Kræver ejerens godkendelse før implementering | ADR-forslagene (054–060) og D1–D3, D6, D8–D11 for de berørte dele (17.4) |
| Kræver teknisk runtime-test | YITH WooCommerce Gift Cards-adfærd (16.2), G1-beslutningsreglen (16.3), G2; desuden samtidighed og nummerering under implementeringen (17.2) |
| Kræver revisor/SKAT før produktion | R1 (moms, momstekst, dokumenttyper, bogføring, gavekortbehandling), R2 (20 %-reglen), R3 (opbevaring), R4 (aftalegrundlag), D8/D9 endeligt, G3 |

## 18. Fake-adapter til payout-gavekort (plan, ikke implementeret)

Formål: den generelle payout-logik for gavekort (registrering, validering, idempotens, adgang, audit og creatorens visning) kan udvikles og testes **uden** YITH WooCommerce Gift Cards. Intet her ændrer beslutningsstatusser; ADR-054–060 er fortsat forslag.

### 18.1 Følger eksisterende konventioner

Projektet bruger allerede samme mønster for udskiftelige afhængigheder: et interface uden teknologipræfiks i `src/` (fx `ProductStore`, `OrderSnapshotReader`, `SalesCacheInvalidator`), en produktionsimplementering med præfiks (fx `WooCommerceProductStore`) og fakes **kun** i `tests/Unit/Support` og `tests/Integration/Support` (fx `FakeProductStore`, `FakeOrderReader`, `RecordingSalesCacheInvalidator`). Fake-adapteren følger det mønster; der laves intet parallelt system, og ingen fake ligger i produktionskoden.

### 18.2 Grænser

| Lag | Ansvar | Kender YITH? |
| --- | --- | --- |
| **Payout-logik** (fx `GiftCardRegistration` og creatorens visning) | Lås, transaktion, validering mod payouten (beløb = saldo = payout, valuta, status, ikke knyttet til anden payout), statusovergang, audit | **Nej** |
| **`GiftCardProvider`** (interface) og **`GiftCardProviders`** (opslag af den udbyder, der er valgt i Settings) | Kontrakten (8.4): kun læsning af fakta om et kort | Nej |
| **`FakeGiftCardProvider`** (kun i `tests/*/Support`) | Programmerbare kort og fejl i hukommelsen; registrerer kald | Nej |
| **`YithGiftCardProvider`** (senere) | Læser YITH WooCommerce Gift Cards' data | Ja, og kun den; implementeres og runtime-testes først efter de relevante godkendelser (D7, aktivering) |

**Reglerne ligger i payout-logikken, ikke i adapteren:** adapteren leverer kun fakta (`GiftCardSnapshot`); payout-logikken afgør, om kortet passer til payouten. Dermed gælder samme regler for enhver adapter, og fake-adapteren tester de rigtige regler.

### 18.3 Minimalt interface (forslag, uddyber 8.4)

| Metode | Returnerer | Bruges af |
| --- | --- | --- |
| `id(): string` | Stabilt udbyder-ID (fx `fake` i tests, `yith-wcgc` senere) | Gemmes på payouten |
| `availability(): GiftCardAvailability` | Tilgængelig ja/nej, version, fejlkode (`not_installed`, `inactive`, `unsupported_version`, `self_test_failed`) | Om gavekort kan vælges og registreres |
| `find_by_code( string $code ): ?GiftCardSnapshot` | Præcis ét kort; `null`, når intet kort har koden; `GiftCardProviderException` (`ambiguous`, `invalid_data`, `read_failed`, `unavailable`) | Registrering |
| `find_by_id( string $external_id ): ?GiftCardSnapshot` | Som ovenfor (`null`, når kortet ikke længere findes) | Creatorens visning og afstemning |
| `reveal_code( string $external_id ): string` | Koden, kun til creatorens private visning | Creatorens visning |
| `admin_url( ?string $external_id ): ?string` | Link til udbyderens egen side, eller null | Admin |

`GiftCardSnapshot` indeholder `external_id`, `amount_units`, `balance_units`, `currency`, `status` (`active`, `disabled`, `expired`, `other`), `expires_at` og `can_reveal_code`, men **ikke koden**. Et snapshot har altid et beløb og en saldo; mangler de eller kan de ikke læses præcist, er resultatet `invalid_data`, aldrig 0. Der er bevidst ingen metoder til at oprette, deaktivere eller ændre kort.

**`GiftCardProviders`** giver én af tre entydige tilstande, før en adapter bruges: `not_configured` (intet valg i Settings), `unknown_provider` (valget svarer ikke til en registreret adapter) eller `unavailable` (adapterens `availability()` er ikke grøn, med dens kode). I alle tre er gavekort utilgængelige; bank og PayPal er upåvirkede. En adapter med `can_reveal_code = false` behandles i fase 23 som utilgængelig; den krypterede fallback i 8.4 er ikke en del af spor B.

### 18.4 `FakeGiftCardProvider`

- Samme opbygning som `FakeOrderReader`: offentlige felter for kortene (kode → snapshot), `$fail` (en undtagelse, der kastes), `$on_read` (callback, fx til at ændre saldoen) og `$reads` (registrerede opslag med ID'er, ikke koder).
- Indeholder **ingen valideringsregler**: kun data og fejl. Reglerne ligger i payout-logikken (18.2).
- `availability()` kan sættes til hver tilstand.
- Registrerer hvilke metoder der blev kaldt og med hvilke ID'er (ikke koder), så testene kan bevise, at intet skrives, og at koden ikke videregives unødigt.
- Kontakter ikke YITH, WooCommerce eller andre plugins og har ingen netværk.

En bestået test med fake-adapteren beviser **ikke**, at YITH-integrationen virker, og godkender **ikke** gavekortets regnskabsmæssige behandling.

### 18.5 Testscenarier (isoleret fra YITH)

Integrationstests mod en rigtig database (som `OrderHistoryAdminTest`) med `FakeGiftCardProvider` indsat; unit-tests for ren validering.

| # | Scenarie | Forventet resultat | Accept |
| --- | --- | --- | --- |
| 1 | Gyldigt kort, beløb = saldo = payout, korrekt valuta, `active` | Payouten bliver `completed` med udbyder og eksternt ID; audit `payout_gift_card_registered` | Én overgang, én audit-post, ingen kode gemt |
| 2 | Ukendt kort (adapteren returnerer `null`) | Afvist med payout-logikkens fejlkode `not_found`; payouten forbliver `pending` | Ingen rækker ændret, ingen audit |
| 3 | Udløbet eller inaktivt kort | Afvist | Som 2 |
| 4 | Forkert valuta | Afvist | Som 2 |
| 5 | For lav, for høj eller delvist brugt saldo (saldo ≠ beløb) | Afvist | Som 2 |
| 6 | Samme eksterne kort-ID registreres igen på samme payout | "Allerede registreret"; ingen ny audit | Idempotent |
| 7 | Samme kort forsøgt knyttet til en anden payout (også samtidigt fra to forbindelser) | Afvist; kun den første lykkes | `UNIQUE(gift_card_provider, gift_card_external_id)` holder |
| 8 | Gentagen POST (dobbeltklik, genindsendelse) | Samme resultat som første gang; ingen dubletter | Idempotent |
| 9 | Manglende eller ugyldig nonce | 403 | Ingen ændringer, adapteren kaldes ikke |
| 10 | Manglende `merchboard_manage_payouts` | 403 | Som 9 |
| 11 | Uventet adapterfejl (`read_failed`, exception) | Struktureret fejl "kunne ikke validere"; payouten forbliver `pending` | Ingen ændringer; teknisk log uden kode |
| 12a | Ingen udbyder valgt i Settings (`not_configured`) | Gavekort kan ikke vælges; registrering afvist | Bank og PayPal upåvirket |
| 12b | Valgt udbyder findes ikke (`unknown_provider`) | Som 12a | Som 12a |
| 12c | Udbyderen er utilgængelig (`unavailable` med kode) | Som 12a; adminvisningen viser koden | Som 12a |
| 13 | Saldoen er ændret siden et tidligere, afvist forsøg (kort delvist brugt) | Ny registrering læser kortet igen under låsen og afviser | Ingen `completed` uden gyldige data på tidspunktet for transaktionen |
| 14 | Payouten er ikke længere `pending` (annulleret/afvist imens) | Afvist | Betinget overgang holder |
| 15 | Creatorens visning: kun egen payout; kode via `reveal_code` med `no-store`; anden creator giver 404 | Som beskrevet | Ingen kode i lister eller audit |
| 16 | Lækage | Testkoden findes ikke i: URL'er og query-strenge; audit-tabellen; Merch Dashboards tekniske logs; fejlbeskeder og formularen efter en fejl (feltet er tomt); admin-lister og -detaljer; REST-svar bortset fra creatorens egen visning; options og transients | Søgning efter testkoden giver kun creatorens visning og fake-adapterens data |

### 18.6 Sikkerhed og idempotens

| Krav | Mekanisme | Følger af |
| --- | --- | --- |
| Samme kort ikke på flere payouts | `UNIQUE(gift_card_provider, gift_card_external_id)` + lås pr. creator | Forslag (afsnit 4, 8.5); testes (18.5, 7) |
| Gentagne requests giver ingen dubletter | Betinget overgang `pending` → `completed`; samme kort på samme payout er en no-op | Eksisterende mønster (betingede overgange, `OrderHistoryEraser`); forslag for payouts |
| Ingen kode i URL'er, logs eller admin-lister | POST-body; adapteren returnerer snapshot uden kode; logs kun med udbyder-ID, fejlkode og payout-ID | Forslag (8.5–8.6); testes (18.5, 16) |
| Adgang og nonce på serveren | admin-post med capability og nonce bundet til payout og handling | Eksisterende mønster (`OrderHistoryActions`, `CreatorPricingActions`) |
| Saldo og status kontrolleres igen | Adapteren kaldes under låsen umiddelbart før overgangen | Forslag; testes (18.5, 13) |
| Ukendte resultater er hverken succes eller fiasko | En adapterfejl giver "kunne ikke validere" uden ændringer; et uklart resultat i Merch Dashboard (fx mistet forbindelse efter COMMIT) læses igen, før der gøres noget | Forslag (14.5) |
| Ingen delvise ændringer | Overgang, gemte ID'er og audit i én transaktion; adapteren skriver intet | Eksisterende `Transaction`; forslag for payouts |
| Koden vises ikke igen | Formularen tømmer kodefeltet efter enhver fejl; svaret gentager aldrig koden | Forslag; testes (18.5, 16) |
| Kendt restrisiko | Udbyderen er ikke under Merch Dashboards lås: kortet kan i teorien bruges i butikken mellem adapterens læsning og COMMIT. Risikoen er lille (koden er netop oprettet af admin og endnu ikke udleveret), men kan ikke elimineres af Merch Dashboard | Kendt begrænsning |

**Kræver stadig ejerbeslutning eller senere test:** ADR-055/057 (grundlaget), at YITH-adapteren opfører sig som fake-adapteren (runtime-test, D7), G1 og G2 (kun for produktion).

### 18.7 Hvad fake-adapteren gør muligt uden YITH

Kan udvikles og testes **uden** aktivering af YITH WooCommerce Gift Cards 4.38.0, **uden** `MB-T23` eller andre testdata i YITH, **uden** beslutning om G1 eller G2 og **uden** godkendelse af gavekortets regnskabsbehandling:
- `GiftCardProvider`, `GiftCardSnapshot`, `GiftCardProviderException`, `GiftCardProviders` og valget i Settings;
- registreringsformularen og -handlingen (nonce, capability, validering, idempotens, lås, transaktion, audit);
- creatorens visning af gavekortet;
- feature-gaten: gavekort er utilgængelige uden en valgt udbyder med grøn `availability()`;
- alle scenarier i 18.5.

Forudsætningen er fortsat ejerens godkendelse af ADR-055 og ADR-057 (og ADR-058, hvis adgang og audit skal med) som grundlag for dette skridt. **D7** er kun en forudsætning for YITH-adapterens runtime-test, ikke for dette arbejde.

### 18.8 Gennemgang af planen (2026-10-10)

Klassifikation: **E** følger af et eksisterende mønster i koden · **F** foreslået arkitektur · **B** kan først bevises ved implementering og tests.

| Spørgsmål | Vurdering | Klasse | Rettet i planen |
| --- | --- | --- | --- |
| Adskiller `GiftCardSnapshot` kortdata fra koden? | Ja: snapshottet har ingen kode; koden findes kun via `reveal_code()` og kun i creatorens visning efter ejerskabskontrol | F, B | – |
| Følger interfacet projektets konvention for "findes ikke"? | **Nej:** eksisterende interfaces returnerer `null` for "findes ikke" (`OrderSnapshotReader::read(): ?OrderSnapshot`, `ProductStore::find(): ?ProductRecord`) og bruger undtagelser til egentlige fejl; 8.4 og 18.3 lod `find_by_code()` kaste `not_found` | E | `find_by_code()`/`find_by_id()` returnerer `?GiftCardSnapshot`; `not_found` fjernet fra adapterens fejlkoder (payout-logikken giver selv fejlkoden `not_found` til admin) |
| Er fejlkoderne stabile og uden følsomme data? | Ja, faste koder; beskeder og logs uden kode. Tilføjet: `invalid_data` også for manglende eller ulæselig saldo, så en læsefejl aldrig bliver saldo 0 | F | 18.3 |
| Håndterer `GiftCardProviders` ukendt, ikke-konfigureret og utilgængelig udbyder entydigt? | **Ikke eksplicit** (scenarie 12 samlede dem) | F | Tre tilstande: `not_configured` (intet valg i Settings), `unknown_provider` (valget svarer ikke til en registreret adapter), `unavailable` (adapterens `availability()` er ikke grøn, med dens kode); scenarie 12 delt i tre |
| Ejer payout-logikken valideringsreglerne? | Ja (18.2); præciseret, at fake-adapteren kun indeholder data og fejl, ingen regler | F | 18.4 |
| Atomiske overgange og genlæsning? | Registreringen er **én** request: lås pr. creator → payouten genlæses (`pending`) → `find_by_code()` → validering → én transaktion (betinget overgang, unik tilknytning, audit) → COMMIT. Scenarie 13 omformuleret til "saldoen ændret siden et tidligere, afvist forsøg" | E (lås, `Transaction`, betingede overgange), F, B | 18.5 |
| Kan kortet bruges i butikken mellem adapterens læsning og COMMIT? | **Restrisiko, der ikke kan elimineres af Merch Dashboard:** udbyderen er ikke under Merch Dashboards lås. Risikoen er lille, fordi kortet netop er oprettet af admin og koden endnu ikke er udleveret, men den skal stå som kendt begrænsning | F | 18.6 |
| Nonce, capability, creator-isolation og lækagetest konkrete nok? | Ja for nonce, capability og isolation (E: `OrderHistoryActions`, `CreatorContext`). Lækagetesten præciseret med konkrete steder | E, B | 18.5, scenarie 16 |
| Undgår registreringen unødig lagring, logging og visning af koden? | Ja for YITH (`can_reveal_code = true`). Præciseret: koden vises aldrig igen i formularen efter en fejl, og en adapter uden `reveal_code` behandles i fase 23 som utilgængelig (den krypterede fallback i 8.4 er ikke en del af spor B) | F | 18.3, 18.6 |
| Bliver fejl til succes, afvisning eller saldo 0? | Nej: adapterfejl giver "kunne ikke validere" uden ændringer; ulæselig saldo giver `invalid_data`; et uklart resultat i Merch Dashboard læses igen | F, B | 18.3, 18.6 |

### 18.9 Implementeringsklarhed for spor B

**1. Arkitektoniske forudsætninger for at begynde spor B** (alle foreligger som plan): kontrakten og grænserne (18.2–18.3), testscenarierne (18.5), sikkerheds- og idempotenskravene (18.6) og de eksisterende mønstre, de bygger på (lås, `Transaction`, betingede overgange, admin-post med nonce, `CreatorContext`, fakes i `tests/*/Support`).

**2. Skal godkendes eksplicit, før de relevante dele implementeres** (status: *forslag* / *afventer ejerbeslutning*):

| Del | Kræver |
| --- | --- |
| Gavekortregistrering med fake-adapteren | ADR-055 (statusser, reservation, idempotens) og ADR-057 (manuel registrering bag `GiftCardProvider`) |
| Adgang og audit | ADR-058 |
| Kryptering af bank/PayPal | ADR-056 (ikke nødvendig for gavekort med YITH-lignende adaptere) |
| Saldo og aggregering | ADR-054, D1, D2 |
| Afregningsstart | D3, D6 |
| Afregningsbilag | ADR-060, D8–D11 |

**3. Blokerer kun YITH-runtime-testen:** D7 (`MB-T23`-testdata) og ejerens aktivering af YITH WooCommerce Gift Cards 4.38.0. Blokerer ikke fake-adapter-arbejdet.

**Mindste første skridt:** fake-adapteren med registrering og creatorens visning. Kræver godkendelse af **ADR-055** og **ADR-057**; **ADR-058** kun, hvis adgangs- og auditændringerne skal indgå i samme leverance (vurderet nødvendig for registreringen, delvist, i 19.3). Payout-rækker oprettes i testene som fixtures, så saldoberegning og reservation (ADR-054, D1, D2), kryptering (ADR-056), afregningsstart (D3, D6) og afregningsbilag (ADR-060, D8–D11) indgår ikke, medmindre implementeringen konkret viser sig at afhænge af dem.

**4. Blokerer produktionsbrug, ikke isoleret fake-adapter-arbejde:** G1–G3, D4, D5, R1–R4, 20 %-reglen, en bestået YITH-runtime-test (16.2, 16.3) og dokumenteret aftalegrundlag uden for pluginet.

Fake-testene beviser ikke, at YITH-integrationen virker, godkender ikke regnskabsbehandlingen og fjerner ikke den kendte restrisiko (18.6): gavekortets saldo kan ændres i pluginet uden for Merch Dashboards lås. Ingen beslutning er godkendt ved, at planen er detaljeret.

## 19. Beslutningsgrundlag for første skridt i spor B (2026-10-10)

Kun et beslutningsgrundlag. Intet er godkendt, ingen status er ændret, og ADR-054–060 er fortsat forslag (afsnit 9). Afsnittet afgrænser den mindste første leverance, så en godkendelse ikke ved en fejl kommer til at omfatte andre dele af fase 23. **Note 2026-10-10:** projektejeren har bestemt, at implementeringen følger roadmapens trin i rækkefølge fra 23.2; afsnittet er derfor grundlaget for gavekortdelen i trin 23.7, ikke for det første implementeringstrin.

### 19.1 ADR-055: payout-statusser, reservation og idempotens

**Foreslåede regler (afsnit 4, 5, 8.8 og 14.5):**
- Fire statusser for alle metoder: `pending`, `completed`, `rejected`, `cancelled`. Ingen `issuing` og intet separat godkendelsestrin.
- Overgange kun fremad og kun fra `pending`: `pending` → `completed` | `rejected` (admin), `pending` → `cancelled` (creatoren). En afsluttet payout ændres aldrig; beløb, metode og creator ændres aldrig.
- Hver overgang er en betinget UPDATE (`WHERE id = %d AND status = 'pending'`) under en lås pr. creator (`GET_LOCK('merchboard_payout:<creator_id>')` med forbindelseskontrol) og i én transaktion (`Transaction::run()`), sammen med audit.
- Reservation: `pending` er reservationen; `completed`, `rejected` og `cancelled` frigiver den. Saldoen beregnes på ny under låsen.
- Gentagne requests: creatorens anmodning bærer en idempotency-nøgle (`UNIQUE(creator_id, idempotency_key)`); en gensendt anmodning returnerer den eksisterende. En gentagen adminhandling giver "allerede behandlet" og ingen ny audit.
- Samtidige requests: låsen serialiserer; den betingede UPDATE sikrer, at kun én overgang lykkes; summen af reservationer kan ikke overstige saldoen.
- Loft på 3 ventende og 10 nye anmodninger pr. døgn pr. creator; intet minimum.

**Hvad første leverance bruger af ADR-055:**

| Del af ADR-055 | I første leverance? |
| --- | --- |
| Statusværdierne og reglen om kun fremadrettede, betingede overgange | **Ja** |
| Overgangen `pending` → `completed` ved gavekortregistrering, under creator-lås og i én transaktion | **Ja** |
| Idempotent registrering (samme kort på samme payout er en no-op) og samtidige registreringer | **Ja** |
| Tabellen `merchboard_payouts` (afsnit 4), så statusser og den unikke gavekortnøgle findes i databasen | **Ja, se 19.3** |
| Creatorens anmodning med idempotency-nøgle, loft 3/10 og saldokontrol | Nej (payouts er testfixtures) |
| Reservation og frigivelse målt mod en beregnet saldo | Nej (kræver ADR-054, D1, D2) |
| Annullering (creator) og afvisning (admin) | Nej |

**Automatiserede tests, der skal bestå (ADR-055-delen):**
- Kun `pending` kan blive `completed`; en payout i `completed`, `rejected` eller `cancelled` afvises (18.5, 14).
- Gentagen registrering af samme kort på samme payout: "allerede registreret", én overgang, én audit-post (18.5, 6 og 8).
- Andet kort på en allerede gennemført payout: afvist, intet ændret.
- To samtidige registreringer fra to databaseforbindelser: præcis én lykkes; låsen og den betingede UPDATE holder (18.5, 7).
- En fejl midt i transaktionen ruller overgang, gemte ID'er og audit tilbage.
- Schema- og migrationstests for `merchboard_payouts` på MariaDB og MySQL 8, som for de eksisterende tabeller.

### 19.2 ADR-057: manuel gavekortregistrering bag `GiftCardProvider`

**Afgrænset flow (8.5, 18.2–18.3):**
1. Admin opretter gavekortet manuelt uden for Merch Dashboard (i første leverance: kortet er defineret i `FakeGiftCardProvider` i testen).
2. Admin indsender koden via en beskyttet POST til `admin-post.php` (nonce bundet til payout og handling, capability, `autocomplete="off"`).
3. Under creator-låsen: payouten genlæses (`pending`), `GiftCardProviders` giver den valgte, tilgængelige udbyder, `find_by_code()` læser kortet, og payout-logikken validerer: præcis ét kort, `active`, ikke udløbet, beløb = saldo = payout-beløbet (heltal, uden moms), valuta, og at kortet ikke er knyttet til en anden payout.
4. Kun ved fuld validering: betinget overgang til `completed`, udbyder-ID og eksternt ID gemmes (ikke koden), audit i samme transaktion.
5. Creatorens private visning: `GET /me/payouts/{id}/gift-card` via `CreatorContext` og ejerskab i SQL; læser kortet live (`find_by_id()` + `reveal_code()`) med `Cache-Control: no-store, private`.

**Hvad fake-adapteren beviser, og hvad den ikke beviser:**

| Beviser (med fake-tests) | Beviser ikke (kræver YITH-runtime-test, D7 og aktivering) |
| --- | --- |
| At payout-logikken anvender valideringsreglerne korrekt på et givet snapshot | At YITH-adapteren læser kode, beløb, saldo, valuta, status og udløb korrekt |
| Idempotens, samtidighed, lås, transaktion og den unikke nøgle | At YITH's floats konverteres præcist, og at upræcise værdier afvises |
| Nonce, capability, creator-isolation og `no-store` | At `availability()` genkender version 4.38.x, og at selvtesten virker |
| At koden ikke lækker til URL'er, audit, logs, fejlbeskeder, admin-lister, options eller transients | At `ambiguous` opdages i YITH's rigtige data |
| At adapterfejl giver "kunne ikke validere" uden ændringer, og at ulæselige data aldrig bliver 0 | Indløsningsveje, ejerbinding, e-mail, moms og genoprettet saldo i YITH (8.3) |
| | At saldoen ikke ændres i pluginet mellem læsning og COMMIT (restrisikoen, 19.4) |

**Håndtering af koden:**
- Koden findes kun i POST-bodyen og sendes direkte til `find_by_code()`; den gemmes ikke, logges ikke og skrives ikke i audit.
- `GiftCardSnapshot` indeholder ikke koden; Merch Dashboard gemmer kun udbyder-ID og eksternt ID.
- Svaret og formularen gentager aldrig koden, heller ikke efter en fejl (feltet er tomt).
- Koden hentes kun til creatorens egen visning via `reveal_code()`, med `no-store`.
- En adapter med `can_reveal_code = false` behandles som utilgængelig; krypteret opbevaring (ADR-056) er ikke med.

**Ukendt, ugyldigt eller utilgængeligt kort:**

| Tilfælde | Resultat |
| --- | --- |
| Ingen udbyder valgt (`not_configured`), ukendt udbyder (`unknown_provider`) eller udbyderen er utilgængelig (`unavailable`) | Gavekort kan ikke registreres; payouten forbliver `pending`; bank og PayPal berøres ikke |
| Intet kort med koden (`null`) | Fejlkoden `not_found` til admin; intet ændret, ingen audit |
| Flere kort med koden (`ambiguous`) | Afvist; intet ændret |
| Inaktivt, udløbet, forkert valuta, forkert beløb eller saldo ≠ beløb | Afvist med struktureret fejlkode; intet ændret |
| Manglende eller ulæselig saldo (`invalid_data`) | Afvist; aldrig behandlet som 0 |
| Læsefejl eller uventet undtagelse (`read_failed`) | "Kunne ikke validere"; intet ændret; teknisk log kun med udbyder-ID, fejlkode og payout-ID |
| Kortet er ikke længere tilgængeligt i creatorens visning | "Kan ikke vises lige nu"; payouten forbliver `completed` |

### 19.3 Omfanget af første leverance

**Med:**
- `GiftCardProvider`, `GiftCardSnapshot`, `GiftCardAvailability`, `GiftCardProviderException` og `GiftCardProviders` i `src/`.
- `FakeGiftCardProvider` kun i `tests/*/Support`. Der findes ingen produktionsadapter, så gavekort er utilgængelige på et rigtigt site efter denne leverance: feature-gaten er altid lukket.
- Indstillingen `payout_gift_card_provider` (standard: ingen). Uden en rigtig adapter kan den kun stå på "ingen".
- Registreringsservicen og admin-post-handlingen med nonce, capability, lås, transaktion, betinget overgang og audit.
- Creatorens private visning som REST-endpointet `GET /me/payouts/{id}/gift-card`, med tests. En React-komponent til visningen kan indgå med Jest-tests, men ikke sektionen "Udbetalinger" (den viser saldo og kræver ADR-054).
- Payouts oprettes **kun som testdata** i testene. Der er ingen kode, der lader en creator eller admin oprette en payout.

**Ikke med:** rigtige udbetalinger; creatorens anmodning, loft og annullering; afvisning; saldoberegning og reservation mod saldo (ADR-054, D1, D2); bank- og PayPal-udbetalinger og kryptering (ADR-056, `merchboard_payout_secrets`); afregningsstart (D3, D6); afregningsbilag (ADR-060, D8–D11); admin-siden "Payouts" med køen; YITH-adapteren og YITH-runtime-testen (D7, aktivering); `MB-T23`.

**Delpunkter, der i praksis kræver noget, der endnu ikke er godkendt:**

| Delpunkt | Kræver | Vurdering |
| --- | --- | --- |
| Tabellen `merchboard_payouts` (en migration, schema-version 8 → 9) | Godkendelse af datamodellen i afsnit 4 som en del af ADR-055 | **Nødvendig.** Statusser, betingede overgange og `UNIQUE(gift_card_provider, gift_card_external_id)` kan ikke testes mod en rigtig database uden tabellen. Kun `merchboard_payouts`; `merchboard_payout_secrets`, `payout_start_at` og bilagstabellerne er ikke med. Migrationen kører på ethvert site, der opdaterer pluginet, men tabellen forbliver tom, fordi intet kan oprette payouts |
| Capability for registreringen og audit-handlingen `payout_gift_card_registered` | **ADR-058** (delvist) | **Nødvendig.** Registreringen er en skrivende adminhandling; projektets regler (ADR-022, ADR-023) kræver en capability-kontrol og audit i samme transaktion. At genbruge en eksisterende capability (fx `merchboard_manage`) ville være en adgangsbeslutning uden ADR, som senere skal laves om. Kun `merchboard_manage_payouts` (rolleversion 5) og den ene audit-handling er nødvendige; `merchboard_view_payout_details` og de øvrige audit-handlinger kan vente |
| Uninstall af den nye tabel | ADR-025 (accepteret) eller ADR-059 (forslag) | **Ikke nødvendig at afgøre nu.** Følger den accepterede ADR-025: en ny tabel i `Tables::all()` slettes kun ved opt-in-uninstall. Det afviger fra afsnit 4's forslag om at bevare payout-tabeller, men er uskadeligt, så længe der kun findes testdata. **ADR-059 skal besluttes, før der kan oprettes rigtige payouts**. **Opdatering 2026-10-12:** besluttet og implementeret i ADR-059 (B2); payout- og bilagstabellerne bevares nu ved opt-in-uninstall |
| Saldo, D1–D11, ADR-054, ADR-056, ADR-060 | – | Ikke nødvendige; indgår ikke |

### 19.4 Risici og acceptkriterier

**Kendt restrisiko (uændret, 18.6):** gavekortets saldo kan ændres i gavekortpluginet uden for Merch Dashboards lås, mellem adapterens læsning og COMMIT. Merch Dashboard kan ikke eliminere den; fake-testene beviser ikke det modsatte. Risikoen er lille, fordi kortet netop er oprettet af admin og koden endnu ikke er udleveret. Den skal stå som kendt begrænsning i ADR-057.

**Acceptkriterier for første leverance:**

| Område | Kriterium (testbart) | Bevises med |
| --- | --- | --- |
| Idempotens | Gentagen POST med samme kort på samme payout giver samme resultat, én overgang og én audit-post; et andet kort på en gennemført payout afvises uden ændringer | Fake-tests (integration) |
| Samtidighed | To forbindelser registrerer samme kort på to payouts eller to kort på samme payout: præcis én lykkes; `UNIQUE(gift_card_provider, gift_card_external_id)` holder på MariaDB og MySQL 8 | Fake-tests (integration, to forbindelser) |
| Adgangskontrol | Manglende eller ugyldig nonce og manglende capability giver 403; adapteren kaldes ikke; intet ændres | Fake-tests |
| Creator-isolation | Creator A får 404 for creator B's payout; ingen kode i lister; `no-store, private` på visningen | Fake-tests (REST) |
| Fejlhåndtering | Hver fejlkode og hver `GiftCardProviders`-tilstand giver en struktureret fejl, payouten forbliver `pending`, ingen audit; ulæselige data bliver aldrig 0; bank og PayPal er upåvirkede | Fake-tests |
| Lækager | Testkoden findes ikke i URL'er og query-strenge, audit, tekniske logs, fejlbeskeder, formularen efter fejl, admin-lister, REST-svar (bortset fra creatorens egen visning), options eller transients | Fake-tests (søgning efter testkoden) |
| Ingen produktionsvirkning | Uden en registreret produktionsadapter er gavekort utilgængelige; ingen WooCommerce-priser, ordrer eller ledger-rækker ændres | Fake-tests og regression |
| Validering | `npm run lint` og `npm test` grønne; CI grøn på MariaDB og MySQL 8 | CI |

**Kan først verificeres med YITH** (efter D7 og aktivering, protokollen i 16.2): adapterens læsning og konvertering, `availability()` og selvtesten, `ambiguous` i rigtige data, YITH's statusser og udløb, indløsningsveje, ejerbinding, e-mail og restrisikoen i praksis.

**Uafklaret til produktion (uændret):** G1–G3, D4–D5, R1–R4, 20 %-reglen (ADR-049), gavekortets regnskabs- og momsbehandling, aftalegrundlaget uden for pluginet og ADR-059 (opbevaring).

### 19.5 Beslutningskontrol

Hvert punkt besvares særskilt. Et "ja" gælder kun den afgrænsede første leverance i 19.3; det godkender ikke resten af spor B, andre ADR'er, D-beslutninger, YITH-aktivering, `MB-T23` eller produktionsbrug. Indtil der er svaret, er status uændret.

**Godkendelsespunkt 1 — ADR-055** (statusser, betingede overgange, lås, transaktion og idempotent registrering, inklusive tabellen `merchboard_payouts` fra afsnit 4; uden anmodning, loft, annullering, afvisning og saldo): ☐ godkendt ☐ ikke godkendt ☐ godkendt med ændringer

**Godkendelsespunkt 2 — ADR-057** (manuel registrering bag `GiftCardProvider`, fake-adapter i tests, creatorens private visning, ingen kopi af koden, den kendte restrisiko som begrænsning; uden YITH-adapter og runtime-test): ☐ godkendt ☐ ikke godkendt ☐ godkendt med ændringer

**Godkendelsespunkt 3 — ADR-058, kun delvist** (`merchboard_manage_payouts` med rolleversion 5 og audit-handlingen `payout_gift_card_registered`; uden `merchboard_view_payout_details` og de øvrige audit-handlinger): ☐ godkendt ☐ ikke godkendt ☐ godkendt med ændringer

Punkt 3 er med, fordi registreringen ikke kan implementeres efter projektets regler uden en capability-kontrol og audit (19.3). Godkendes punkt 3 ikke, kan første leverance kun omfatte kontrakten, fake-adapteren og den rene validering (unit-tests) uden admin-handlingen.
