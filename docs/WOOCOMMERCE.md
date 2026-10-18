# WooCommerce-integration

> Status: **Fase 17 færdig (2026-09-28):** produkt↔creator (manuel kobling, kategori-mapping og -afstemning, vagter og uninstall) er implementeret og verificeret i Merch Dashboard Local (roadmappens trin 12; blok-produkteditorens UI og drift uden WooCommerce er ikke kørt manuelt i Local, se TESTING.md). **Fase 18 færdig (2026-10-01):** sales ledgeren (registrering fra WooCommerce-hooks, tællende statusser og backfill) er implementeret og runtime-verificeret i Merch Dashboard Local med HPOS og legacy (ADR-037, ADR-038). **Fase 19 færdig:** creator'ens produkter og ordrelinjer i dashboardet (`/me/products`, `/me/orders`, ADR-039), runtime-verificeret i Merch Dashboard Local med HPOS (se TESTING.md). **Fase 20 (statistik) er design.** Ingen WooCommerce-kode i tidligere faser. Beslutningerne for fase 17 er lukket i ADR-030, ADR-031, ADR-032, ADR-033 (kategori-mapping som vedvarende automatisk kobling; implementeret i fase 17, trin 7-13 og admin-værktøjet til fuld afstemning med "Needs attention"), ADR-034 (beskyttelse af koblingens meta) og ADR-035 (vagten som core-service; implementeret). Uninstall-mekanismen for de beskyttede nøgler er implementeret efter ADR-036. Kolonnen "Creators" i ordrelisten er udskudt til efter fase 17.

## Principper

1. **WooCommerce er source of truth** for produkter, varianter, ordrer, refunds og kunder. Vi kopierer ikke masterdata.
2. **Valgfrit modul:** `Modules/WooCommerce` indlæses kun, hvis WooCommerce er aktivt. Resten af pluginet (creators, adgang og dashboard-shell) virker uden.
3. **HPOS-kompatibel fra dag ét:** kun CRUD-API'er (`wc_get_order()`, `wc_get_orders()`, `$order->get_items()`, `wc_get_product()`, `$product->get_meta()`). **Aldrig** direkte SQL mod `wp_posts`/`wp_postmeta` for ordrer. Kompatibilitet erklæres via `FeaturesUtil::declare_compatibility( 'custom_order_tables', MERCHBOARD_FILE, true )`.
4. Tungt arbejde (backfill og genberegning) køres asynkront via **Action Scheduler**, som følger med WooCommerce.
5. **Minimum WooCommerce 10.3** (header `WC requires at least: 10.3`, ADR-030). WooCommerce 10.3.x er den seneste linje, der kræver WordPress 6.7, som er pluginets generelle minimum. 10.4 og senere kræver WordPress 6.8. Er WooCommerce aktivt, men ældre, indlæses modulet ikke. Admin ser en neutral requirements-notice, og der er ingen fatal eller delvis integration.

## Kobling: produkt → creator

- Product meta **`_merchboard_creator_id`** på parent-produktet. Varianter arver fra parent.
- Sættes manuelt af admin i Merch Dashboard på creatorens redigeringsside eller automatisk af kategori-afstemningen (ADR-033), ikke på WooCommerce-produktskærmen, som hverken viser eller redigerer relationen (ADR-032, se [ADMIN.md](ADMIN.md#woocommerce-skærme-fase-17)). Produktlisten har en skrivebeskyttet kolonne og et filter. Ændringer audit-logges.
- Kun **aktive** creators kan få nye koblinger (manuelt eller via kategori). Hvis en creator bliver inaktiv, beholder produktet koblingen (historik), men creator'en kan ikke logge ind.
- **Én autoritativ domæneservice (ADR-030):** Alle ændringer går gennem pluginets domæneservice `ProductCreatorLink`: creator findes og er aktiv (ved nye koblinger), variant → parent, audit i samme transaktion. Der er to understøttede skriveveje (ADR-032, ændret af ADR-033):
  - **Manuel:** Merch Dashboard (wp-admin) → creatorens redigeringsside (`source: admin`). Kræver `merchboard_manage_creators` og `edit_product` på hvert parent-produkt for den aktuelle bruger.
  - **Automatisk kategori-afstemning** (`source: category`, ADR-033, implementeret). Kører som systemarbejde via Action Scheduler gennem en intern metode i `ProductCreatorLink` uden capability-tjek for den aktuelle bruger. Grundlaget er en mapping, som en administrator har oprettet. Audit har actor NULL og evt. `trigger_user_id`.

  Den tidligere engangs-backfill (ADR-031) er erstattet af den første afstemning, når en kategori mappes. Alle direkte ændringer af nøglerne uden for disse veje er lukket, også via WooCommerce-produktskærmen:
  - WooCommerce REST, CSV-import og XML-RPC kan ikke ændre `_merchboard_creator_id` eller `_merchboard_creator_link_source` direkte (tydelig afvisning via de hooks, som fase 17 verificerer i den faktiske WooCommerce-version). Ændrer de produktets **kategorier**, kan det udløse kategori-afstemningen (ADR-033). Det er bevidst.
  - Quick/bulk edit har intet Creator-felt.
  - "Duplicate product" kopierer ikke koblingen. Kopien arver kategorierne og kan derfor få en `category`-kobling via afstemningen (ADR-033).
  - Der er ingen nye offentlige REST-endpoints.
  - **Vagter (ADR-034, implementeret i fase 17, trin 6):**
    - **`ProtectedProductMeta` (deny-by-default på metadata-laget):** WordPress' pre-hooks `add_`, `update_` og `delete_post_metadata` samt `update_`/`delete_post_metadata_by_mid` afviser enhver skrivning, omdøbning eller sletning af `_merchboard_creator_id` og `_merchboard_creator_link_source` (sammenlignet trimmet og uden hensyn til store og små bogstaver; ved `_by_mid` også den gemte nøgle). Det dækker WooCommerce CRUD, REST, CSV-import, quick/bulk edit, XML-RPC, `update_post_meta()` fra vilkårlig kode og `wp post meta` (WP-CLI), for produkter og varianter. Afviste forsøg fejler, logges som advarsel (teknisk log, ingen værdier) og skriver ingen audit. Læsning påvirkes ikke, og anden meta skrives normalt.
    - **Eneste undtagelse:** en eksplicit `ProtectedMetaWrite` (formål `manual_link` eller, fra ADR-033's afstemning, `category_reconciliation`) for præcis ét produkt, som kun gælder under `ProtectedProductMeta::run()`. Den kan kun bruges én gang, kan ikke indlejres, bærer ingen bruger (virker også uden logget-ind bruger) og ryddes også ved fejl. `ProductCreatorLink` opretter den først efter sine egne tjek (capability, creator, produkt) og kun om selve lagerskrivningen i sin transaktion.
    - WordPress må slette et produkts beskyttede meta, når produktet slettes permanent (`before_delete_post` → `after_delete_post`), så der ikke efterlades forældreløse rækker.
    - **Tydelige afvisninger ved indgangene (`ProtectedMetaEntryPoints`):** REST create/update/batch af produkter og varianter giver 400 (`merchboard_protected_product_meta`), når de beskyttede felter ville ændres (også omdøbning via meta-ID og sletning); `…/variations/generate` giver 400 ved beskyttet `meta_data`; en CSV-række med en beskyttet `Meta:`-kolonne fejler med en besked; duplicate kopierer ikke felterne.
  - **Begrænsninger (ADR-034):**
    - Direkte SQL og kode, der fjerner eller omgår pluginets hooks (fx `remove_filter()` eller `merchboard_modules`-filteret), er uden for vagterne. Der er ingen garanti for fuld databaseintegritet.
    - **Registrering (ADR-035, implementeret):**
      - Metadata-vagten (`MerchBoard\Core\Security\ProtectedProductMeta`) er en core-service. `Plugin::register_hooks()` registrerer den, når `merchboard.php` indlæses, uden om `merchboard_modules`.
      - Den er aktiv uanset om WooCommerce er installeret, aktiveret eller opfylder minimumsversionen.
      - Kode, der kører før `merchboard.php` indlæses, er uden for den.
      - `ProtectedMetaEntryPoints` bliver i WooCommerce-modulet og kører kun med et understøttet WooCommerce.
      - Uden WooCommerce logges afviste forsøg i PHP's fejllog i stedet for WooCommerce-loggen.
    - **Uninstall (ADR-036, implementeret):** vagten har et uninstall-vindue, som kun tillader **sletning** af de to nøgler, og kun når `WP_UNINSTALL_PLUGIN` er Merch Dashboards basename og opt-in til sletning af data er aktivt. Oprettelse, ændring og omdøbning er fortsat blokeret. `Uninstaller` sletter via WordPress' meta-API uden direkte SQL, uden global adgang til vagten og uden at fjerne hooks. Tilladte sletninger logges uden værdier, og der skrives ingen audit. WordPress definerer `WP_UNINSTALL_PLUGIN` kun én gang pr. request; ved uninstall af flere plugins i samme request, hvor Merch Dashboard ikke er det første, sletter uninstall intet (accepteret begrænsning).
- **Ingen produktrettigheder til creators (ADR-030):** Creator-rollen får ingen WooCommerce-produktcapabilities og ingen produktadgang i fase 17. `Capabilities::EDIT_PRODUCT` tildeles aldrig og bruges kun til at autorisere brugere, der i forvejen må redigere produktet.
- **Livscyklus (ADR-030):** Koblingen bevares ved deaktivering af pluginet eller WooCommerce og ved opdateringer. Kun opt-in-uninstall sletter nøglerne (`_merchboard_creator_id` og `_merchboard_creator_link_source`, og kun dem); i forhold til vagten sker det gennem ADR-036's uninstall-vindue, som kun tillader sletning.
- **Oprindelse (ADR-033, implementeret):** product meta `_merchboard_creator_link_source` = `admin` (manuel) eller `category` (aktuelt afledt af en kategori-mapping). Den skrives og verificeres sammen med `_merchboard_creator_id`. Mangler den, er koblingen manuel. Den er ikke en ejerskabsrelation. Kilden `backfill` er fjernet.
- **Kategori-mapping som vedvarende automatisk kobling (ADR-033, erstatter ADR-031's engangs-backfill, implementeret):**
  - En administrator mapper eksplicit en `product_cat` til en creator i tabellen `merchboard_creator_categories` (én creator pr. kategori; teknisk flere kategorier pr. creator, men fase 17's UI tillader én; ikke standardkategorien; ingen match på navn eller slug). Mappingen er aldrig selv bevis for ejerskab eller adgang.
  - **Afstemning:** Hooks (`set_object_terms`, `deleted_term_relationships`, statusovergange, `pre_delete_term`, mapping-handlinger og genaktivering af creators) sætter kun en unik, asynkron afstemning i kø via Action Scheduler. Afstemningen er tilstandsbaseret og idempotent og ser kun på **direkte** kategoritilknytning (ingen arv fra underkategorier). Umappede kategorier ignoreres.
  - **Regler:**
    - En manuel (`admin`) kobling vinder altid og røres aldrig af afstemningen. Afviger den fra kategorien, vises det som en konflikt under "Needs attention" i værktøjet til fuld afstemning (implementeret). En manuel kobling (`admin`, uden eller med ukendt oprindelse, eller ugyldig) afviger, når produktets direkte kategorier er mappet til: én anden, aktiv creator ("manual_other_creator"); én anden, inaktiv creator ("manual_inactive_creator"); eller to eller flere creators ("manual_ambiguous"). Ingen mappet creator, eller kun den manuelt koblede (også inaktiv), er ingen afvigelse. Udfaldet er fortsat `skipped_manual`.
    - Uden kobling og med én mappet aktiv creator kobles produktet (`category`). Er creatoren inaktiv, oprettes intet nyt.
    - En `category`-kobling fjernes, når produktet ikke længere ligger i creatorens kategori, og erstattes ved flytning til en anden aktiv creators kategori.
    - Mappede kategorier for to eller flere creators er **tvetydige**: ingen kategori-kobling, og en eksisterende `category`-kobling fjernes.
    - Produkter i papirkurv eller auto-draft afstemmes ikke. Ved gendannelse afstemmes de igen.
  - **Admin:** Remove afvises for kategori-styrede produkter (`category_managed`, implementeret); "Keep as manual" gør koblingen manuel (pr. produkt i creatorens produktafsnit og som massekonvertering ved fjernelse af en mapping, implementeret). Produktafsnittet viser koblingens oprindelse. Oprettelse af en mapping starter med en tørkørsel og bekræftelse, og den første afstemning køres i batches. Ved fjernelse af en mapping vælger admin eksplicit "Remove category-managed links" eller "Keep as manual links".
  - **Keep as manual og Remove (den nuværende ADR-033-model):** "Keep as manual" gør den aktuelle kategori-styrede kobling manuel (samme creator, oprindelse `admin`), og Remove kan derefter fjerne koblingen. Produktet ligger dog stadig i den mappede kategori, så en senere kategori-trigger (fx en kategoriændring på produktet) eller en fuld afstemning kan give det en kategori-styret kobling igen. Skal produktet forblive uden kategori-baseret ejerskab, skal det fjernes fra den mappede kategori.
  - **Sletning af en mappet kategori** fjerner mappingen og de `category`-koblinger, der kun fandtes på grund af den (`reason: term_deleted`). Manuelle koblinger påvirkes ikke.
  - Et admin-værktøj til fuld afstemning (tørkørsel → bekræftelse → asynkron kørsel) er sikkerhedsnet for ændringer, som ingen hook ser (implementeret: "Category reconciliation" på Creators-listen, se ADMIN.md). Bekræftelsen gælder kun den viste tørkørsel: har produkter eller mappings ændret sig, starter intet. Kørslen går gennem den eksisterende Action Scheduler-kæde og `apply_category_link()`. "Needs attention" viser tvetydige produkter, produkter med kun en inaktiv mappet creator, produkter, der ikke kunne vurderes, og manuelle koblinger, der afviger fra kategorien.
- **Én creator pr. produkt i v1.** Collab-produkter med revenue split kræver en `merchboard_product_creators`-tabel. Det er et åbent spørgsmål, om det er nødvendigt.

## Sales ledger

**Hvorfor en ledger i stedet for at forespørge WooCommerce live?**

- Performance: dashboard-aggregater over måneder og mange ordrer fra `wc_get_orders()` pr. request skalerer ikke. `wc_order_product_lookup` er en intern analytics-tabel uden stabil kontrakt.
- Uforanderlig attribution: Hvis et produkt senere flyttes til en anden creator, må historisk salg ikke skifte ejer. Ledger-rækken er et snapshot.
- Grundlag for fremtidige payouts: Payouts skal kunne revideres række for række.

**Hvordan (fase 18, ADR-037 og ADR-038 med supplement og statusnoter; synkronisering, hooks og fejlmarkør implementeret i trin 5 og runtime-verificeret i Merch Dashboard Local med HPOS og legacy (ADR-038, statusnote "trin 5 afsluttet"); tællende statusser kan vælges fra trin 6 (standard `processing` og `completed`; se nedenfor); backfill fra trin 7 (se nedenfor)):**

**Invariant (ADR-038):** for en ordre i en tællende status er ledgerens aktuelle balance for hver ordrelinje med creator lig WooCommerce' aktuelle netto for linjen efter registrerede refunds; for en ordre, der ikke er tællende, er balancen 0. `net_amount` er netto efter rabatter, ekskl. moms, fragt og gebyrer. Historiske rækker ændres eller slettes aldrig. Alle triggere kalder den samme **synkronisering pr. ordre**, som skriver de typede rækker, der mangler; gentagne triggere ændrer intet.

| Hændelse | Ledger-effekt |
| --- | --- |
| Ordren bliver tællende første gang (også hvis den oprettes direkte i en tællende status) | Cyklus 1 åbnes: `sale` pr. ordrelinje med creator med **bruttobeløbet**; eksisterende refunds registreres som `refund`-rækker (balancen bliver nettoen) |
| Ordren forlader tællende status | `reversal` af den resterende balance (udelades ved 0); cyklussen lukkes med en `close`-række |
| Ordren bliver tællende igen | Ny cyklus med **den resterende netto-balance** (fx 100 − refund 30 = 70); kun ved en positiv balance |
| Refund med ordrelinjer | `refund`-række i ordrens seneste cyklus; er den lukket, også en modpost (`correction`/`closed_cycle_refund`), så der ikke opstår en ny salgsbalance |
| Refund uden ordrelinjer, fragt og gebyrer | Ingen rækker (ADR-037) |
| En refund slettes i WooCommerce | `correction`/`refund_deleted`; lå den i en lukket cyklus, også `correction`/`closed_cycle_refund_deleted` |
| Antal eller beløb på en tællende ordrelinje ændres, eller linjen fjernes | `correction`/`line_changed` eller `line_removed` med deltaet; en ny linje med creator får en `sale` i den åbne cyklus |
| Ordren flyttes til papirkurven eller gendannes | Ingen ændring (en ordre med status `trash` springes over) |
| Ordren slettes permanent | `reversal` af ledgerens resterende balance og en `close`-række; beregnes kun fra ledgeren |

Cyklus 1 og senere cyklusser er bevidst asymmetriske (brutto + refunds mod resterende netto), fordi refunds ved cyklus 1 endnu ikke står i ledgeren. Fortegn: `sale` positiv, `refund` og `reversal` negative, `correction` positiv eller negativ med en `correction_reason`. Skema og hændelsesnøgler: [DATABASE.md](DATABASE.md).

- **Triggere (ADR-038):** `woocommerce_after_order_object_save` (kun `shop_order`, ikke `trash`) er den fælles trigger; den dækker statusskift, ordrer oprettet direkte i en status og redigerede ordrelinjer, og den må koste mindst én indekseret ledger-forespørgsel pr. gemning for ordrer med mulige creator-linjer. Sletning af en ordre: `woocommerce_before_delete_order` og `before_delete_post` (prioritet under 10, posttypen `shop_order`). Sletning af en refund: `deleted_post` for `shop_order_refund` og `woocommerce_refund_deleted`; synkroniseringen får det slettede refund-ID med og behandler det som fraværende, uanset WooCommerce' caches (ADR-038, B6 og Q1). En ordre i papirkurven springes over uden fejlmarkør (Q8). Når sletningen af en ordre er startet, behandles alle senere kald for den i samme request som en slettet ordre og kan ikke genåbne en cyklus (Q5).
- **Samtidighed:** navngiven lås pr. ordre (`GET_LOCK()`), WooCommerce-data læses før transaktionen, ledger og cyklusser skrives i én kort transaktion, forbindelsen kontrolleres før commit, og låsen frigives i `finally`. `UNIQUE(event_key)` er sidste sikkerhedsnet.
- **Fejl og genbehandling:** fejler synkroniseringen (fx lås-timeout, deadlock, databasefejl, skiftet forbindelse eller inkonsistente refund-data), rulles den tilbage, og ordremetaen `_merchboard_ledger_failure` (`{v, first_at, last_at, code, count}`, ingen beløb eller PII) sættes eller opdateres. Den ryddes efter en vellykket synkronisering. Genbehandling sker eksplicit via senere WP-CLI-, backfill- eller reprocess-funktionalitet; ingen automatisk retry. Markøren følger opt-in-uninstall (ADR-025); i HPOS' ordremeta slettes den kun via WooCommerce CRUD, så den kan blive liggende, hvis WooCommerce er deaktiveret under uninstall (ADR-038, Q3). En skiftet forbindelse giver koden `connection_changed` via en særskilt exception-type (Q2); en tom eller ugyldig beløbsstreng og et brøkdelt antal giver `unexpected` (Q6). En re-entrancy-vagt forhindrer, at markørskrivningen udløser en ny synkronisering (under HPOS gemmer WooCommerce ordren efter en meta-ændring).
- **`occurred_at`:** `sale` i cyklus 1: `date_paid`, ellers `date_created`; senere cyklusser, `reversal`, `correction` og sletning: behandlingstidspunktet; `refund`: refundens `date_created`. Ved senere genbehandling er det genbehandlingens tidspunkt.
- **Refunds uden tidligere cyklus:** ingen rækker, hvis ordren aldrig har haft en cyklus og ikke er tællende (de registreres i cyklus 1, hvis den senere bliver tællende). Undtagelse med fejlmarkør og uden rækker: `_refunded_item_id` kan ikke løses, en refund ville give negativt antal eller negativ balance, og andre inkonsistente data.
- **Begrænsning (accepteret):** under HPOS uden synkronisering affyrer WooCommerce 10.3.8 intet hook ved sletning af en refund via REST. Korrektionen sker ved næste synkronisering af ordren eller ved eksplicit genbehandling eller backfill.
- **`item_name`-snapshot:** ordrelinjens navn gemmes for historisk læsbarhed. Privacy: ordrelinjenavne kan i særlige tilfælde indeholde personoplysninger; ingen kundefelter eller anden PII.
- **Tællende statusser (✅ trin 6, runtime-verificeret i Merch Dashboard Local med HPOS og legacy, ADR-038 statusnote "trin 6 afsluttet"; ADR-038 S1–S14 og statusnote 2026-09-30):** indstillingen `counted_order_statuses` i `merchboard_settings`, ændret af administratorer med `merchboard_manage_settings` under Merch Dashboard → Settings ("Sales ledger"). Valgbare er alle statusser, som `wc_get_order_statuses()` registrerer (også fra andre plugins) under en `wc-`-nøgle, gemt uden `wc-`, undtagen `pending`, `failed`, `cancelled`, `refunded`, `checkout-draft` og `trash`. Sluggen skal bestå af `a-z`, `0-9`, `_` og `-` (WordPress' `sanitize_key()` beholder `_`, så underscore-statusser fra andre plugins understøttes) og må højst være 17 tegn, fordi både `wp_posts.post_status` (legacy) og `wc_orders.status` (HPOS) er `varchar(20)` inkl. `wc-`. Registrerede nøgler uden `wc-`, med store bogstaver eller andre ugyldige tegn eller over 17 tegn vises særskilt som "Not supported" med årsag og kan hverken vælges eller tilføjes; de udelades aldrig uden forklaring. Listen kan ikke være tom; standarden er `processing` + `completed`. Statusser, der ikke længere er registreret, bevares og markeres. `CountedOrderStatuses` er fortsat det eneste sted, hvor en status afgøres som tællende, og håndhæver afvisningslisten også ved læsning; er den gemte værdi ugyldig eller tom, gælder standarden. Ændringen gælder for synkroniseringer efter ændringen – også af eksisterende ordrer, ikke kun nye ordrer. Der sker ingen masse-genberegning og ingen backfill; en ordre vurderes efter den aktuelle liste, næste gang den synkroniseres, og kan så få reversal og lukning eller en ny cyklus med behandlingstidspunktet som `occurred_at` (følger af den tilstandsbaserede synkronisering, ADR-038 pkt. 13). Historiske rækker ændres aldrig. Ændringer auditeres (`settings_updated`).
- **Backfill (trin 7; ADR-038 statusnote "ROADMAP trin 7", D1–D6):** registrerer historiske ordrer, der endnu ikke har ledger-historik. Kun ordrer uden cyklus registreres; eksisterende historik ændres aldrig, ordrer, der ikke tæller nu, får ingen rækker, tidligere statusforløb rekonstrueres ikke, og der er ingen genberegning eller afstemning. Tilskrivningen er den aktuelle creator-kobling (ADR-037 pkt. 6), cyklus 1 er brutto plus eksisterende refunds med `date_paid` (ellers `date_created`) og refundens dato som `occurred_at`. Udvælgelsen er `wc_get_orders()` (ID stigende, `limit`/`offset`, de tællende statusser med `wc-`, aldrig `trash`); en ordre, der slettes under en kørsel, kan få offset-pagineringen til at springe en senere ordre over, som en ny kørsel så registrerer. Hver ordre går gennem samme synkronisering som live (lås, transaktion, planner, fejlmarkør og log). WP-CLI: `wp merchboard sales-backfill dry-run` (inline, skriver intet, kun tællinger), `start` (sætter en kørsel i kø i Action Scheduler, hook `merchboard_sales_backfill`), `status` og `resume`; `--batch-size` (1–200, standard 50). Én kørsel ad gangen, ingen automatisk retry. En side, der fejler, efterlader et `failed` job med uændret cursor, som `resume` fortsætter fra. Ved deaktivering og uninstall annulleres ventende backfill-jobs; da Action Schedulers oprydning sletter annullerede jobs, er genoptagelse derefter ingen garanti, og kørslen startes i stedet forfra (datasikkert, fordi backfill er idempotent). Resuméet er audit-posten `sales_backfill_completed` (kun tællinger).
- **Tællende statusser (principper), backfill, audit, uninstall og afstemning:** som ADR-037 (central, auditeret og kun fremadrettet statusindstilling; backfill via WP-CLI og Action Scheduler med dry-run og resumé, tilskrivning ud fra den aktuelle creator-kobling; ingen audit pr. ledger-række; opt-in-uninstall; afstemning senere).
- **`merchboard_sale_recorded`:** ikke en del af fase 18 (ADR-038).
- **Creator-rabatkoder (✅ version 1.0.1, ADR-068; ikke runtime-verificeret):** `WooCommerceOrderSnapshotReader` læser ordrens kuponkoder via CRUD (`WC_Order_Item_Coupon::get_code()`, normaliseret med `CouponCode`), ens for HPOS og legacy. Et kuponelement, der ikke kan læses, får synkroniseringen til at fejle (`unexpected`), så ukendte kupondata aldrig læses som "ingen kode". I synkroniseringens transaktion og under ordrens lås afgør `OrderExclusionAssessor`, før planneren kører, om ordren er udelukket af en creators egen rabatkode. Det er den, når en kuponkode matcher en registrering, hvis periode [fra, til) indeholder ordrens WooCommerce-oprettelsestidspunkt. En udelukket ordre får ingen ledger-rækker, heller ikke senere, og eksisterende rækker ændres ikke (M2). Alle læsemodeller udelader dem med `OrderExclusionRepository`s fælles filter, også statistikken, ordrelisten, profit, payout-saldoen og nye bilag. Afregnede rækker tæller fortsat i saldoen. Har ordren afregnede rækker, bliver afgørelsen `open` (K2). Backfill rapporterer en udelukket ordre som "Skipped, not counted". Se [features/creator-discount-codes.md](features/creator-discount-codes.md).

**Dokumenteret restrisiko (ADR-038, B4):** der er ingen vagt mod, at et tredjeparts-plugin allerede har åbnet en transaktion på `wpdb`-forbindelsen; vores `START TRANSACTION` ville så committe den implicit. `@@in_transaction` findes ikke på MySQL 8.0 og bruges ikke. WooCommerce 10.3.8's egne ordreflows har ingen åben transaktion, når vores hooks kører. Låsens timeout er 5 sekunder.

**Verificeret i WooCommerce 10.3.8 og MariaDB (fase 18; kildekode og integrationstest-databasen, ikke Local-runtime, fordi sitet var stoppet):**
- **Statushooks** affyres fra `WC_Order::status_transition()` efter `save()` (ens for HPOS og legacy) og kun ved en reel ændring på en indlæst ordre, når ordre og linjer er gemt; de kører i `try … catch ( Exception )`. En ordre oprettet direkte i en status får intet `woocommerce_order_status_changed`. `woocommerce_new_order` affyres før `save_items()`; `woocommerce_after_order_object_save` efter. Redigering af ordrelinjer i admin gemmer ordren via `calculate_totals()`.
- **Datoer:** `date_created` sættes altid; `date_paid` sættes én gang, når en indlæst ordre når betalt status (eller ved `payment_complete()`), og mangler for ordrer oprettet direkte i en status, medmindre API'et eller importen sætter den; `date_completed` overskrives ved hver overgang til `completed`; en refunds `date_created` er altid sat.
- **Refunds:** en `WC_Order_Refund` med eget ID; linjerne har `_refunded_item_id`, negativt antal og negativ total ekskl. moms. Ved fuld refund skifter ordren status til `refunded` før `woocommerce_refund_created` og `woocommerce_order_refunded`; synkroniseringen registrerer derfor refunden, før den ser den ikke-tællende status.
- **Papirkurv:** intet statushook; gendannelse under HPOS affyrer `woocommerce_order_status_changed` med `from = trash`.
- **Sletning af ordrer og refunds, `GET_LOCK()`/`@@in_transaction`, genforbindelse i `wpdb` og HPOS' gemning efter meta-ændringer:** se ADR-038's supplement (A–D).

## Creator pricing (fase 22, ADR-049)

> ADR-045 og ADR-046 (grundpris + profit som WooCommerce-listepris, anvendelse, drift og fast profit) er erstattet af ADR-049 (2026-10-09). Koden blev fjernet, før den blev frigivet.

**Regel:** WooCommerce-salgsprisen er den endelige kundepris og indeholder allerede creatorens profit og moms. Merch Dashboard lægger intet oven i prisen og skriver aldrig en WooCommerce-pris.

| | Formel |
| --- | --- |
| Creator-profit | salgspris × markup % |
| Udbetaling, momsregistreret | = creator-profit |
| Udbetaling, ikke momsregistreret | = creator-profit (20 %-fradraget er fjernet, ADR-063) |

**Forudsætninger:** WooCommerce-prisen, kunden betaler, er inkl. moms; profitten beregnes af det faktisk betalte beløb inkl. moms. Mangler data, er profit og udbetaling ukendte, aldrig 0. Rækker, der allerede indgår i et afregningsbilag, beholder den udbetaling, der er gemt i bilaget (ADR-060); historiske afregninger efter ADR-049's fradrag reguleres ikke automatisk, kun via genberegningen (ADR-063) (ADR-063).

Eksempler: 100,00 / 20 % → profit og udbetaling 20,00. 200,00 / 10 % → 20,00. 100,00 / 0 % → 0,00.

**Data og ejerskab:**

| Data | Kilde | Skrives af Merch Dashboard? |
| --- | --- | --- |
| Regular, sale og aktiv pris | WooCommerce | **Aldrig** (`PricingProductStore` har ingen skrivemetode; ingen prisfiltre) |
| Creatorens markup (andel af salgsprisen) | `merchboard_creators.default_markup_bps` | Ja, af admin |
| Produktets egen markup (erstatter creatorens) | `merchboard_pricing_overrides.markup_bps` | Ja, af admin |
| Creatorens momsstatus | `merchboard_creators.vat_registered` | Ja, af admin |
| Andel, kilde, prisdecimaler og momsstatus pr. ordrelinje | `merchboard_sales_line_pricing` | Ja, én gang ved oprettelsen |
| Faktisk moms pr. ledger-række | `merchboard_sales_tax` | Ja, sammen med ledger-rækken (ADR-048) |

**Gældende markup (`MarkupResolver`):** produktets ejer findes via `ProductCreatorLink`. Har parent-produktet en egen markup, der blev sat for den nuværende ejer, gælder den for produktet og alle varianter (kilde `product`). Ellers gælder ejerens markup (kilde `creator`). En produkt-markup sat for en tidligere ejer ignoreres. De to summeres aldrig.

**Konfiguration (`CreatorPricingSettings`):** den eneste skrivevej for markup, produkt-markup og momsstatus.

- Kræver `merchboard_manage_creator_pricing`; produkt-markup kræver desuden `edit_product` på parent.
- Produkt-markup kun for simple og variable parent-produkter, bundet til den nuværende ejer.
- Procent med højst to decimaler (0–100, "." eller ","), ingen floats.
- Skrivning og audit i én transaktion; en uændret værdi skriver og auditerer intet.
- Ingen ændring påvirker en WooCommerce-pris, et snapshot eller en tidligere udbetaling. Den gælder for ordrelinjer, der oprettes bagefter.

### Admin-UI

Afsnittet "Pricing" på creatorens redigeringsside; se ADMIN.md. WooCommerces produktliste og produktskærm ændres ikke. Produktlisten viser WooCommerce-priserne skrivebeskyttet og et eksempel på profit og udbetaling ved den aktuelle pris, med creatorens nuværende momsstatus. Ingen SQL mod WooCommerces tabeller; hver enhed læses via CRUD.

### Snapshot pr. ordrelinje (ADR-047, ADR-049)

**Hook og timing:**

- `SalesLinePricingHooks` lytter på `woocommerce_new_order_item`. WooCommerce affyrer den én gang pr. linje, fra ordrelinjens data store (`create()`), lige efter at linjen er indsat. Det gælder for HPOS og legacy, ved checkout, i wp-admin, via REST og med `add_product()`.
- Kun produktlinjer på `shop_order` får et snapshot; refund-linjer gør ikke.
- Opdatering af en linje affyrer ingen create-hook. Er linjen allerede registreret, er det append-only-insert'et, der beskytter: første snapshot vinder.

**Indhold (`SalesLinePricingRecorder`):** ejeren på oprettelsestidspunktet, den gældende andel (`share_bps`), kilden (`creator`/`product`), butikkens prisdecimaler, ejerens momsstatus og ordrens valuta. Status `share`. Ingen priser: salgsprisen kommer fra ledger-rækken. Linjer uden ejer eller produkt og variationer, der ikke hører til linjens produkt, får intet snapshot.

**Fejl:** recorderen kaster aldrig og afbryder aldrig ordreoprettelsen. En fejl logges, og linjen får intet snapshot (ukendt profit). Snapshottet gættes eller skrives aldrig senere. Sales ledgeren er uafhængig af det.

### Realiseret profit og udbetaling

- **Salgspris pr. ledger-række** = rækkens `net_amount` (efter alle rabatter, ekskl. moms) + rækkens faktiske moms fra `merchboard_sales_tax` = det beløb, kunden betalte inkl. moms. Momsen bruges kun til at genskabe det betalte beløb, aldrig som sats eller som separat profitformel.
- **Profit** (`RealizedProfit`, via `SalesProfit::for_entry()`) = `round_half_away(salgspris × share_bps / 10 000)` til snapshottets prisdecimaler.
- **Udbetaling** (`RealizedPayout`, via `SalesProfit::payout_for_entry()`) = profit, når creatoren var momsregistreret ved salget, ellers andelen af rækkens beløb ekskl. moms (ADR-064, 2026-10-18; ADR-063 havde fjernet 20 %-fradraget); uden momsstatus i snapshottet er den ukendt (ADR-049 pkt. 7).
- Heltal i 1/10 000-enheder (`CreatorShare`, `PricingMath::share`), half away from zero, ingen floats. Afrundes pr. række.
- Rækkens beløb er linjebeløbet, så antallet ganges aldrig igen. Udsalg, kuponer og manuelle rabatter er indeholdt i det betalte beløb.
- Refunds, reversals og korrektioner (fx slettet refund) har fortegn og egen moms, så profit og udbetaling følger med fortegn; en fuldt refunderet linje summerer til 0.
- **Ukendt (null):** uden matchende snapshot med andel (ordre, ordrelinje, creator, valuta, parent-produkt og variation), uden rækkens gemte moms i rækkens valuta (rækker fra før schema-version 7), og for udbetalingen også uden momsstatus i snapshottet. Ingen valutaomregning og ingen fallback til nuværende værdier.
- Intet gemmes: ingen profit- eller udbetalingskolonne. Ledgerens schema, invarianter, idempotens og tællende statusser er uændrede.

**Livscyklus:** statusskift, refunds, sletning af refunds, papirkurv, gendannelse og permanent sletning af ordren ændrer kun ledgeren, aldrig snapshottet. Sletning af produkt eller variation, ejerskift, deaktivering af creatoren og ændret markup, produkt-markup, momsstatus eller WooCommerce-pris ændrer heller ikke snapshottet. Der er ingen fremmednøgler.

**Historiske data:** ordrer fra før fase 22 har intet snapshot, og snapshots fra før migration 8 (`priced`/`unpriced`) har ingen andel. De er gyldige ledger-rækker med ukendt profit; der sker ingen backfill og ingen genfortolkning.

**Afgrænsning:** der er endnu intet creator-API og ingen `/me/payout`.

**20 %-fradraget:** fjernet efter ADR-063 (erstatter ADR-049's åbne spørgsmål om andre momssatser). Historiske afregninger reguleres kun via admin-siden "Recalculations" med forhåndsvisning, godkendelse og udførelse som særskilte trin (ADR-063, R2 trin 2).

## Dashboard-data og privacy

✅ Fase 19 (ADR-039; kontrakterne i [API.md](API.md#get-meproducts--fase-19-trin-2-adr-039)). Creator'en kommer altid fra `CreatorContext` på serveren, aldrig fra requesten.

- **`/me/products`:** udvælgelsen er en read-model over produkterne (`CreatorProducts`): parent-produkter med status `publish` eller `private`, som har præcis én `_merchboard_creator_id`-række lig creator'ens ID; sorteret efter navn og ID, med præcis total. Produkter er posts uanset HPOS, så selektionen er ét SQL-opslag i `posts`/`postmeta` (samme regel som `ProductRecord::creator_id()`). Produktdata for den viste side læses via WooCommerce CRUD (`wc_get_product()`): aktiv pris (`get_price()`, variable produkter via de aktive variationspriser uden visningsjustering), `tax: "store_setting"`, lagerstatus og en thumbnail med samme origin.
- **`/me/orders`:** udvælgelse, creator-filter (i SQL), aggregering pr. ordrelinje og paginering sker alene på sales ledgeren (`SalesOrderLines`, ADR-039 P1/P2), uafhængigt af HPOS og legacy. Først derefter beriges de viste linjer via WooCommerce CRUD (`WooCommerceOrderLineEnricher`): højst ét `wc_get_order()` pr. unik ordre og ét `wc_get_product()` pr. unikt produkt i requesten, for status, statuslabel, ordrenummer og produktnavn. En ordre i papirkurven vises som `trashed`, en permanent slettet som `deleted` med ordre-ID'et som nummer; linjerne bevares (P4). Produktnavnet er det aktuelle (varianten, når linjen har en), ellers ledgerens `item_name` for et slettet produkt eller en slettet variant, aldrig parent-navnet (P6). Berigelsen ændrer aldrig udvælgelse eller totaler.
- **Privacy (bekræftet af virksomheden 2026-10-01, ADR-039 P5):** creators ser ingen kundedata: hverken navn, e-mail, telefon, adresse, by, postnummer, **land**, noter, IP, betaling eller kunde-ID (GDPR: creator'en er ikke databehandler for virksomhedens kunder). Ingen beløb eller valuta i `/me/orders` i fase 19. En ordre med linjer fra flere creators viser kun creator'ens egne linjer.
- `/me/sales` (fase 20): aggregeret fra `merchboard_sales` med caching (se nedenfor og API.md).

### Caching af salgsstatistik

✅ Fase 20, trin 4 (ADR-040 pkt. 10). `SalesCache` (object cache, gruppe `merchboard_sales`, 15 minutter) og `CachedSalesReports` omkring `SalesReport`. Cachen er aldrig kilden til tallene; en tabt post koster kun en ny beregning fra ledgeren.

- **Invalidering:** `SalesRepository::write()` samler `creator_id` for de rækker, der faktisk blev indsat (ikke dubletter og ikke cyklus-hændelser, som statistikken ikke læser), og kalder `SalesCacheInvalidator::invalidate()` én gang med de unikke creators, lige efter COMMIT. En rullet tilbage skrivning invaliderer intet. Fejler forbindelsestjekket efter COMMIT (udfaldet ukendt), er invalideringen allerede sket; det koster højst en ny beregning. Der er ingen offentlig hook (`merchboard_sale_recorded` forbliver udskudt). Alle ledger-skrivninger går gennem `write()` (via `SalesSynchronizer`: live-hooks, sletning af ordrer og refunds og backfill); `insert_entry()` og `insert_cycle_event()` kræver ordrens lås og bruges kun af `write()`.
- **Generation:** et nyt tilfældigt token pr. invalidering i stedet for en optælling. Den generiske object cache-API garanterer ikke atomisk `incr`, og en tabt optælling kunne genbruge en generation, der allerede har poster; to samtidige invalideringer giver altid to nye, forskellige generationer.
- **Miss under en skrivning:** generationen læses før ledgeren og igen efter; ændret generation betyder, at resultatet ikke gemmes.
- **Resterende grænser (højst 15 minutter):** (1) committer en skrivning mellem genlæsningen af generationen og gemningen, ligger posten under den gamle generation, som ingen læser længere; (2) mellem COMMIT og invalideringen (mikrosekunder i samme request) kan en anden request få den gamle post; (3) stopper processen mellem COMMIT og invalideringen, eller fjerner cachen generationsnøglen (eviction), falder creatoren tilbage til generation `1`, og poster gemt under `1` inden for de sidste 15 minutter kan blive vist, til de udløber.
- **Ingen invalidering ved:** statusskift uden nye ledger-rækker, omdøbte produkter, priser, lager eller ændrede tællende statusser (ledgeren genberegnes ikke, ADR-037 pkt. 4).

## Øvrige hensyn

- **Multi-currency:** Hvis shoppen sælger i flere valutaer, aggregeres pr. valuta. Der sker ingen omregning i v1.
- **Produkter slettet:** Ledger-rækker bevares. Ledgeren gemmer et snapshot af ordrelinjens navn til visning efter sletning (ADR-038).
- **WooCommerce deaktiveret:** Modulet indlæses ikke. Dashboard-sektioner, der kræver WooCommerce, registreres ikke. Eksisterende ledger-data bevares.
