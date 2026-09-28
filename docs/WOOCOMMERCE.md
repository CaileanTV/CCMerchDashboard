# WooCommerce-integration

> Status: **Fase 17 færdig (2026-09-28):** produkt↔creator (manuel kobling, kategori-mapping og -afstemning, vagter og uninstall) er implementeret og verificeret i Merch Dashboard Local (roadmappens trin 12; blok-produkteditorens UI og drift uden WooCommerce er ikke kørt manuelt i Local, se TESTING.md). **Fase 18-20 (sales ledger, produkter/ordrer i dashboardet, statistik) er design.** Ingen WooCommerce-kode i tidligere faser. Beslutningerne for fase 17 er lukket i ADR-030, ADR-031, ADR-032, ADR-033 (kategori-mapping som vedvarende automatisk kobling; implementeret i fase 17, trin 7-13 og admin-værktøjet til fuld afstemning med "Needs attention"), ADR-034 (beskyttelse af koblingens meta) og ADR-035 (vagten som core-service; implementeret). Uninstall-mekanismen for de beskyttede nøgler er implementeret efter ADR-036. Kolonnen "Creators" i ordrelisten er udskudt til efter fase 17.

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

**Hvordan (fase 18, ADR-037 og ADR-038 med supplement; design-låst, ikke implementeret):**

**Invariant (ADR-038):** for en ordre i en tællende status er ledgerens aktuelle balance for hver ordrelinje med creator lig WooCommerce' aktuelle netto for linjen efter registrerede refunds; for en ordre, der ikke er tællende, er balancen 0. `net_amount` er netto efter rabatter, ekskl. moms, fragt og gebyrer. Historiske rækker ændres eller slettes aldrig. Alle triggere kalder den samme **synkronisering pr. ordre**, som skriver de typede rækker, der mangler; gentagne triggere ændrer intet.

| Hændelse | Ledger-effekt |
| --- | --- |
| Ordren bliver tællende første gang (også hvis den oprettes direkte i en tællende status) | Cyklus 1 åbnes: `sale` pr. ordrelinje med creator med **bruttobeløbet**; eksisterende refunds registreres som `refund`-rækker (balancen bliver nettoen) |
| Ordren forlader tællende status | `reversal` af den resterende balance (udelades ved 0); cyklussen lukkes med en `close`-række |
| Ordren bliver tællende igen | Ny cyklus med **den resterende netto-balance** (fx 100 − refund 30 = 70) |
| Refund med ordrelinjer | `refund`-række i ordrens seneste cyklus; er den lukket, også en modpost (`correction`/`closed_cycle_refund`), så der ikke opstår en ny salgsbalance |
| Refund uden ordrelinjer, fragt og gebyrer | Ingen rækker (ADR-037) |
| En refund slettes i WooCommerce | `correction`/`refund_deleted`; lå den i en lukket cyklus, også `correction`/`closed_cycle_refund_deleted` |
| Antal eller beløb på en tællende ordrelinje ændres, eller linjen fjernes | `correction`/`line_changed` eller `line_removed` med deltaet; en ny linje med creator får en `sale` i den åbne cyklus |
| Ordren flyttes til papirkurven eller gendannes | Ingen ændring (en ordre med status `trash` springes over) |
| Ordren slettes permanent | `reversal` af ledgerens resterende balance og en `close`-række; beregnes kun fra ledgeren |

Cyklus 1 og senere cyklusser er bevidst asymmetriske (brutto + refunds mod resterende netto), fordi refunds ved cyklus 1 endnu ikke står i ledgeren. Fortegn: `sale` positiv, `refund` og `reversal` negative, `correction` positiv eller negativ med en `correction_reason`. Skema og hændelsesnøgler: [DATABASE.md](DATABASE.md).

- **Triggere (ADR-038):** `woocommerce_after_order_object_save` (kun `shop_order`, ikke `trash`) er den fælles trigger; den dækker statusskift, ordrer oprettet direkte i en status og redigerede ordrelinjer, og den må koste mindst én indekseret ledger-forespørgsel pr. gemning for ordrer med mulige creator-linjer. Sletning af en ordre: `woocommerce_before_delete_order` og `before_delete_post` (prioritet under 10, posttypen `shop_order`). Sletning af en refund: `woocommerce_refund_deleted`, `woocommerce_delete_order_refund` og `before_delete_post` for `shop_order_refund`.
- **Samtidighed:** navngiven lås pr. ordre (`GET_LOCK()`), WooCommerce-data læses før transaktionen, ledger og cyklusser skrives i én kort transaktion, forbindelsen kontrolleres før commit, og låsen frigives i `finally`. `UNIQUE(event_key)` er sidste sikkerhedsnet.
- **Fejl og genbehandling:** fejler synkroniseringen (fx lås-timeout, deadlock, databasefejl, skiftet forbindelse eller inkonsistente refund-data), rulles den tilbage, og ordremetaen `_merchboard_ledger_failure` (`{v, first_at, last_at, code, count}`, ingen beløb eller PII) sættes eller opdateres. Den ryddes efter en vellykket synkronisering. Genbehandling sker eksplicit via senere WP-CLI-, backfill- eller reprocess-funktionalitet; ingen automatisk retry. Markøren følger opt-in-uninstall (ADR-025). En re-entrancy-vagt forhindrer, at markørskrivningen udløser en ny synkronisering (under HPOS gemmer WooCommerce ordren efter en meta-ændring).
- **`occurred_at`:** `sale` i cyklus 1: `date_paid`, ellers `date_created`; senere cyklusser, `reversal`, `correction` og sletning: behandlingstidspunktet; `refund`: refundens `date_created`. Ved senere genbehandling er det genbehandlingens tidspunkt.
- **Refunds uden tidligere cyklus:** ingen rækker, hvis ordren aldrig har haft en cyklus og ikke er tællende (de registreres i cyklus 1, hvis den senere bliver tællende). Undtagelse med fejlmarkør og uden rækker: `_refunded_item_id` kan ikke løses, en refund ville give negativt antal eller negativ balance, og andre inkonsistente data.
- **Begrænsning (accepteret):** under HPOS uden synkronisering affyrer WooCommerce 10.3.8 intet hook ved sletning af en refund via REST. Korrektionen sker ved næste synkronisering af ordren eller ved eksplicit genbehandling eller backfill.
- **`item_name`-snapshot:** ordrelinjens navn gemmes for historisk læsbarhed. Privacy: ordrelinjenavne kan i særlige tilfælde indeholde personoplysninger; ingen kundefelter eller anden PII.
- **Tællende statusser, backfill, audit, uninstall og afstemning:** som ADR-037 (central, auditeret og kun fremadrettet statusindstilling; backfill via WP-CLI og Action Scheduler med dry-run og resumé, tilskrivning ud fra den aktuelle creator-kobling; ingen audit pr. ledger-række; opt-in-uninstall; afstemning senere).
- **`merchboard_sale_recorded`:** ikke en del af fase 18 (ADR-038).

**Resterende OPEN (ikke kritiske for skemaet):**
- **Fremmed transaktion på samme forbindelse:** der er ingen eksplicit vagt mod, at anden kode allerede har åbnet en transaktion på `wpdb`-forbindelsen (`START TRANSACTION` ville committe den implicit). `@@in_transaction` bruges ikke. Skal afklares, før ledgeren skrives fra recorder-flowet (ADR-038, statusnote 2026-09-28). Låsens timeout (5 sekunder) er afklaret.

**Verificeret i WooCommerce 10.3.8 og MariaDB (fase 18; kildekode og integrationstest-databasen, ikke Local-runtime, fordi sitet var stoppet):**
- **Statushooks** affyres fra `WC_Order::status_transition()` efter `save()` (ens for HPOS og legacy) og kun ved en reel ændring på en indlæst ordre, når ordre og linjer er gemt; de kører i `try … catch ( Exception )`. En ordre oprettet direkte i en status får intet `woocommerce_order_status_changed`. `woocommerce_new_order` affyres før `save_items()`; `woocommerce_after_order_object_save` efter. Redigering af ordrelinjer i admin gemmer ordren via `calculate_totals()`.
- **Datoer:** `date_created` sættes altid; `date_paid` sættes én gang, når en indlæst ordre når betalt status (eller ved `payment_complete()`), og mangler for ordrer oprettet direkte i en status, medmindre API'et eller importen sætter den; `date_completed` overskrives ved hver overgang til `completed`; en refunds `date_created` er altid sat.
- **Refunds:** en `WC_Order_Refund` med eget ID; linjerne har `_refunded_item_id`, negativt antal og negativ total ekskl. moms. Ved fuld refund skifter ordren status til `refunded` før `woocommerce_refund_created` og `woocommerce_order_refunded`; synkroniseringen registrerer derfor refunden, før den ser den ikke-tællende status.
- **Papirkurv:** intet statushook; gendannelse under HPOS affyrer `woocommerce_order_status_changed` med `from = trash`.
- **Sletning af ordrer og refunds, `GET_LOCK()`/`@@in_transaction`, genforbindelse i `wpdb` og HPOS' gemning efter meta-ændringer:** se ADR-038's supplement (A–D).

## Dashboard-data og privacy

- `/me/products`: læses fra WooCommerce (`wc_get_products( [ 'meta_key' => '_merchboard_creator_id', 'meta_value' => $creator_id ] )`) med paginering.
- `/me/orders`: bygges fra ledger og ordrelinjer. Creators ser **ikke** kundens navn, e-mail, telefon eller adresse (GDPR: creator'en er ikke databehandler for virksomhedens kunder). Højst ordrenummer, dato, status, produkt, antal og evt. land. Beslutningen skal bekræftes af virksomheden.
- `/me/sales`: aggregeret fra `merchboard_sales` med caching.

## Øvrige hensyn

- **Multi-currency:** Hvis shoppen sælger i flere valutaer, aggregeres pr. valuta. Der sker ingen omregning i v1.
- **Produkter slettet:** Ledger-rækker bevares. Ledgeren gemmer et snapshot af ordrelinjens navn til visning efter sletning (ADR-038, ikke implementeret).
- **WooCommerce deaktiveret:** Modulet indlæses ikke. Dashboard-sektioner, der kræver WooCommerce, registreres ikke. Eksisterende ledger-data bevares.
