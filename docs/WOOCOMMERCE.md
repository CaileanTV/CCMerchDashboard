# WooCommerce-integration

> Status: **Fase 17 i gang:** produkt↔creator (manuel kobling, kategori-mapping og -afstemning, vagter og uninstall) er implementeret; verifikationen i Merch Dashboard Local (roadmappens trin 12) er ikke udført. **Fase 18-20 (sales ledger, produkter/ordrer i dashboardet, statistik) er design.** Ingen WooCommerce-kode i tidligere faser. Beslutningerne for fase 17 er lukket i ADR-030, ADR-031, ADR-032, ADR-033 (kategori-mapping som vedvarende automatisk kobling; implementeret i fase 17, trin 7-13 og admin-værktøjet til fuld afstemning med "Needs attention"), ADR-034 (beskyttelse af koblingens meta) og ADR-035 (vagten som core-service; implementeret). Uninstall-mekanismen for de beskyttede nøgler er implementeret efter ADR-036. Kolonnen "Creators" i ordrelisten er udskudt til efter fase 17.

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
  - **Sletning af en mappet kategori** fjerner mappingen og de `category`-koblinger, der kun fandtes på grund af den (`reason: term_deleted`). Manuelle koblinger påvirkes ikke.
  - Et admin-værktøj til fuld afstemning (tørkørsel → bekræftelse → asynkron kørsel) er sikkerhedsnet for ændringer, som ingen hook ser (implementeret: "Category reconciliation" på Creators-listen, se ADMIN.md). Bekræftelsen gælder kun den viste tørkørsel: har produkter eller mappings ændret sig, starter intet. Kørslen går gennem den eksisterende Action Scheduler-kæde og `apply_category_link()`. "Needs attention" viser tvetydige produkter, produkter med kun en inaktiv mappet creator, produkter, der ikke kunne vurderes, og manuelle koblinger, der afviger fra kategorien.
- **Én creator pr. produkt i v1.** Collab-produkter med revenue split kræver en `merchboard_product_creators`-tabel. Det er et åbent spørgsmål, om det er nødvendigt.

## Sales ledger

**Hvorfor en ledger i stedet for at forespørge WooCommerce live?**

- Performance: dashboard-aggregater over måneder og mange ordrer fra `wc_get_orders()` pr. request skalerer ikke. `wc_order_product_lookup` er en intern analytics-tabel uden stabil kontrakt.
- Uforanderlig attribution: Hvis et produkt senere flyttes til en anden creator, må historisk salg ikke skifte ejer. Ledger-rækken er et snapshot.
- Grundlag for fremtidige payouts: Payouts skal kunne revideres række for række.

**Hvordan (fase 18):**

| Hændelse | Hook | Ledger-effekt |
| --- | --- | --- |
| Ordre bliver "tællende" (default: `processing` eller `completed`; kan konfigureres) | `woocommerce_order_status_changed` | Én `sale`-række pr. ordrelinje med creator |
| Ordre forlader tællende status (fx → `cancelled`, `failed`) | `woocommerce_order_status_changed` | `reversal`-rækker, der modposterer |
| Refund oprettet | `woocommerce_order_refunded` / `woocommerce_refund_created` | `refund`-række pr. refunderet linje (negativt beløb/antal) |
| Ordre slettet permanent | `woocommerce_before_delete_order` (HPOS) / `before_delete_post` | `reversal` hvis der er poster |

- **Idempotens:** `UNIQUE(order_item_id, refund_id, type)`. Gentagne hooks giver ingen dubletter (`INSERT IGNORE`). Et salg, der er reverseret og bliver tællende igen, håndteres med en løbende saldo pr. linje, som skal specificeres i fase 18.
- **Beløb:** `net_amount` = linjetotal efter rabatter (`$item->get_total()`), **ekskl. moms og fragt**. Valuta pr. ordre gemmes. Om creator-omsætning skal opgøres før eller efter rabat/moms er en forretningsbeslutning (åbent spørgsmål).
- **Backfill:** WP-CLI-kommando og/eller admin-knap, der via Action Scheduler gennemløber historiske ordrer i batches.
- **Reconciliation:** en senere job-type, der sammenligner ledger med WooCommerce for en periode og logger afvigelser.

## Dashboard-data og privacy

- `/me/products`: læses fra WooCommerce (`wc_get_products( [ 'meta_key' => '_merchboard_creator_id', 'meta_value' => $creator_id ] )`) med paginering.
- `/me/orders`: bygges fra ledger og ordrelinjer. Creators ser **ikke** kundens navn, e-mail, telefon eller adresse (GDPR: creator'en er ikke databehandler for virksomhedens kunder). Højst ordrenummer, dato, status, produkt, antal og evt. land. Beslutningen skal bekræftes af virksomheden.
- `/me/sales`: aggregeret fra `merchboard_sales` med caching.

## Øvrige hensyn

- **Multi-currency:** Hvis shoppen sælger i flere valutaer, aggregeres pr. valuta. Der sker ingen omregning i v1.
- **Produkter slettet:** Ledger-rækker bevares. Visning falder tilbage til snapshot-navn, så produktnavn bør overvejes tilføjet til ledgeren i fase 18.
- **WooCommerce deaktiveret:** Modulet indlæses ikke. Dashboard-sektioner, der kræver WooCommerce, registreres ikke. Eksisterende ledger-data bevares.
