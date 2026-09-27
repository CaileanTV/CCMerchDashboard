# Feature: Produkt↔creator-kobling og kategori-afstemning

## Purpose
Butikken skal vide, hvilken creator et WooCommerce-produkt tilhører, som grundlag for salg, produkter og statistik i fase 18-20. Administratorer kobler produkter manuelt eller lader en produktkategori styre koblingen automatisk. Relationen administreres i Merch Dashboard, ikke på WooCommerce-produktskærmen (ADR-030, ADR-032, ADR-033).

## Current status
Færdig (fase 17, 2026-09-28; endnu ikke udgivet i en version). Implementeret i trin 1-11, 12a, 13, 13a og 14 og verificeret i Merch Dashboard Local i trin 12 (se [ROADMAP.md](../ROADMAP.md)). Blok-produkteditorens UI og drift uden WooCommerce er ikke kørt manuelt i Local (se [TESTING.md](../TESTING.md#woocommerce-foundation-fase-17)).

## User-facing functionality
Ingen for creators i fase 17. Creator-rollen får ingen produktrettigheder, og koblingen vises ikke i creator-dashboardet endnu (fase 19).

## Admin functionality
Kun med WooCommerce ≥ 10.3 aktivt. Alt ligger under Merch Dashboard → Creators og kræver `merchboard_manage_creators`; intet nyt menupunkt og ingen ny capability. Detaljer i [ADMIN.md](../ADMIN.md#woocommerce-skærme-fase-17).

- **Produktkobling (manuel, `source: admin`):** afsnittet "Products" på creatorens redigeringsside lister creatorens produkter og lader admin søge (navn, varenummer og direkte produktkategori), koble, overtage og fjerne produkter. Hvert produkt kræver også `edit_product`. En eksisterende kobling til en anden creator erstattes kun ved eksplicit overtagelse, og resultatet er altid en manuel kobling.
- **Oprindelse:** hvert koblet produkt viser "Manual" eller "Product category" med de mappede kategorier og, når afstemningen vil fjerne eller flytte koblingen, en note.
- **Kategori-mapping:** afsnittet "Product category" mapper én `product_cat` til creatoren (tabellen understøtter teknisk flere). Standardkategorien kan ikke mappes, kategorier mappet til andre creators er deaktiverede, og kun aktive creators kan få nye mappings. Oprettelse sker efter en tørkørsel og eksplicit bekræftelse. Fjernelse kræver et eksplicit valg efter tørkørsel: "Remove category-managed links" eller "Keep as manual links". Mappings til slettede kategorier vises og ryddes op med samme valg.
- **Keep as manual:** gør en kategori-styret kobling manuel (samme creator, oprindelse `admin`), pr. produkt med bekræftelse eller som massekonvertering ved fjernelse af en mapping (højst 200 pr. kørsel).
- **Remove:** afvises for kategori-styrede produkter (`category_managed`); brug "Keep as manual" først, eller fjern produktet fra kategorien.
- **Efter Keep as manual og Remove (den nuværende ADR-033-model):** produktet ligger stadig i den mappede kategori. En senere kategori-trigger eller en fuld afstemning kan derfor give det en kategori-styret kobling igen. Skal produktet forblive uden kategori-baseret ejerskab, skal det fjernes fra den mappede kategori.
- **Creators-listen:** skrivebeskyttet kolonne "Product categories".
- **Category reconciliation (`action=category-sync`):** tørkørsel over alle relevante produkter med tællinger for de otte udfald, forventede ændringer og "Needs attention" → eksplicit bekræftelse → asynkron kørsel via Action Scheduler.
- **Produktlisten i WooCommerce:** skrivebeskyttet kolonne "Creator" og creator-filter. WooCommerce-produktskærmen viser og redigerer ikke relationen.
- **Audit log:** produkt- og kategori-hændelser vises læsbart (trin 14).

## Technical implementation
- **Ejerskab:** kun `_merchboard_creator_id` på parent-produktet. Varianter følger parent. En mapping er aldrig bevis for ejerskab eller adgang.
- **Én skrivevej:** `ProductCreatorLink`. Manuelle ændringer (`link()`, `unlink()`, `keep_as_manual()`) skriver oprindelsen `admin`; kun kategori-afstemningen kalder `apply_category_link()` (oprindelse `category`, systemarbejde uden capability-tjek for den aktuelle bruger, audit med actor NULL). Audit og skrivning sker i samme transaktion.
- **Beskyttet meta (ADR-034/035/036):** core-vagten `ProtectedProductMeta` afviser alle generiske skrivninger af `_merchboard_creator_id` og `_merchboard_creator_link_source`. Kun en `ProtectedMetaWrite` for præcis ét produkt under `ProtectedProductMeta::run()` slipper igennem. `ProtectedMetaEntryPoints` giver tydelige afvisninger ved WooCommerce REST, variant-generering, CSV-import og duplicate. Ved opt-in-uninstall må kun de to nøgler slettes.
- **Afstemning (tilstandsbaseret og idempotent):** `CreatorCategoryReconciler` ser på produktets direkte kategorier, de mappede creators' status og den nuværende kobling.
  - En manuel kobling (`admin`, uden eller med ukendt oprindelse, eller ugyldig) vinder altid og ændres aldrig.
  - Uden kobling og én mappet aktiv creator kobles produktet (`category`); er creatoren inaktiv, oprettes intet.
  - En `category`-kobling fjernes, når ingen mappet kategori begrunder den, og flyttes ved én anden aktiv creator.
  - Mappede kategorier for to eller flere creators er tvetydige: ingen kategori-kobling, og en eksisterende `category`-kobling fjernes.
  - Papirkurv og auto-draft afstemmes ikke; gendannelse afstemmes igen.
- **Hooks → jobs:** `CategoryReconciliationHooks` sætter kun deduplikerede Action Scheduler-jobs i kø (gruppe `merchboard`): pr. produkt ved kategoriændringer og statusovergange, pr. kategori eller mapping ved oprettelse, fjernelse, kategorisletning og genaktivering af en creator. `CategoryReconciliationJobs` kører dem; fejlede jobs gentages højst 3 gange (1, 5 og 15 minutter).
- **Fuld afstemning:** `CategoryReconciliationBatch` gennemløber kandidater med keyset-paginering (100 pr. side) og ét `run_id` pr. kørsel og skriver `category_sync_completed` efter sidste side. `preview()` er en tørkørsel uden skrivninger, audit eller jobs.
- **Konflikter ("Needs attention"):** tvetydige produkter, produkter hvis eneste mappede creator er inaktiv, produkter, der ikke kunne vurderes, og manuelle koblinger, der afviger fra kategorierne (`ManualLinkConflict`: `manual_other_creator`, `manual_inactive_creator`, `manual_ambiguous`). Kun diagnostisk: udfaldet er fortsat `skipped_manual`.
- **Fingeraftryk:** bekræftelsen sender `CategorySyncReport::fingerprint()` (SHA-256 af tællinger, forventede ændringer og "needs attention"). Handleren laver en ny tørkørsel; afviger den, starter intet, og den nye tørkørsel vises.

## Relevant files
- `src/Modules/WooCommerce/` (fx `WooCommerceModule`, `ProductCreatorLink`, `WooCommerceProductStore`, `CreatorCategoryService`, `CreatorCategoryReconciler`, `CategoryReconciliationBatch`, `CategoryReconciliationHooks`, `CategoryReconciliationJobs`, `CategoryReconciliationScheduler`, `ManualLinkConflict`, `ProtectedMetaEntryPoints`)
- `src/Modules/WooCommerce/Admin/` (`CreatorProductsSection`/`CreatorProductsActions`, `CreatorCategoriesSection`/`CreatorCategoriesActions`, `CreatorListCategories`, `ProductListCreators`, `CategorySyncScreen`/`CategorySyncActions`)
- `src/Core/Security/` (`ProtectedProductMeta`, `ProtectedMetaWrite`, `ProtectedMetaPurpose`), `src/Core/Database/Migrations/CreateCreatorCategoriesTable.php`, `src/Core/Lifecycle/Uninstaller.php`
- `src/Modules/Admin/AuditLogFormatter.php`, `AuditLogListTable.php`, `AuditContextLabels.php` (audit-labels)
- `tests/Unit/Modules/WooCommerce/`, `tests/Integration/WooCommerce/`, `tests/Integration/Lifecycle/UninstallTest.php`, `tests/Integration/Admin/AuditLogPageTest.php`

## Database/data used
- Product meta `_merchboard_creator_id` og `_merchboard_creator_link_source` (`admin` | `category`) på parent-produktet.
- Tabellen `{prefix}merchboard_creator_categories` (migration 4, schema-version 4).
- Audit-actions `product_creator_linked`, `product_creator_unlinked`, `creator_category_linked`, `creator_category_unlinked` og `category_sync_completed`.
- Action Scheduler-jobs `merchboard_reconcile_product` og `merchboard_reconcile_run`.
- Se [DATABASE.md](../DATABASE.md) og [WOOCOMMERCE.md](../WOOCOMMERCE.md).

## Permissions
- Alle admin-handlinger: `merchboard_manage_creators` og nonce via `admin-post.php` (`merchboard_link_creator_products`, `merchboard_unlink_creator_product`, `merchboard_keep_creator_product_manual`, `merchboard_map_creator_category`, `merchboard_unmap_creator_category`, `merchboard_category_full_sync`). Ingen AJAX-, REST- eller `nopriv`-ruter.
- Manuelle produktændringer kræver desuden `edit_product` for det konkrete produkt.
- Kategori-afstemningen er systemarbejde uden capability-tjek for den aktuelle bruger; grundlaget er en mapping, som en administrator har oprettet (ADR-033 pkt. 7).
- `Capabilities::EDIT_PRODUCT` tildeles aldrig; creator-rollen har kun `read` og `merchboard_access_dashboard`.

## API endpoints
Ingen. Der er ingen nye REST-endpoints i fase 17 (ADR-030).

## Hooks
- **Lytter på:** `set_object_terms`, `deleted_term_relationships`, `transition_post_status`, `pre_delete_term`, `delete_term`, `merchboard_creator_category_linked`, `merchboard_creator_category_unlinked`, `merchboard_creator_status_changed`, `merchboard_reconcile_product`, `merchboard_reconcile_run`, metadata-pre-hooks (`add_`/`update_`/`delete_post_metadata` og `*_by_mid`) samt WooCommerce' REST-, CSV-import- og duplicate-hooks (`ProtectedMetaEntryPoints`).
- **Udløser:** `merchboard_creator_category_linked` og `merchboard_creator_category_unlinked` (`CreatorCategoryService`).
- **Bruger Creators-sidens udvidelsespunkter:** `merchboard_creators_edit_sections`, `merchboard_creators_list_columns`, `merchboard_creators_list_items`, `merchboard_creators_list_column`, `merchboard_creators_list_tools` og `merchboard_creators_view_<action>`.

## Dependencies
- WooCommerce ≥ 10.3 (valgfrit; uden det indlæses modulet ikke). Action Scheduler følger med WooCommerce; uden den sættes intet i kø (logges), og den fulde afstemning starter ikke.
- Creators-modulet (`CreatorRepository`, statusændringer) og core (audit, transaktioner, vagten).
- Ingen nye tredjeparts-dependencies.

## Tests
Se [TESTING.md](../TESTING.md#woocommerce-foundation-fase-17). Unit- og integrationstests dækker skriveveje og rettigheder, vagten og uninstall-scenarierne, afstemningens regler inkl. manuel-vinder, batch, dedup og retry, mapping-UI og fjernelse, Keep as manual, Remove-blokering, Category Sync inkl. fingeraftryk og manuelle konflikter samt audit-labels. Hele kæden mod et rigtigt WooCommerce er ikke automatisk testet, men verificeret manuelt i Merch Dashboard Local (trin 12).

## Known limitations
- Blok-produkteditorens UI er ikke verificeret manuelt (kun indirekte via WooCommerce REST), og drift uden WooCommerce er kun dækket af automatiske tests.
- Efter Keep as manual og Remove kan en senere afstemning give produktet en kategori-kobling igen, så længe det ligger i den mappede kategori.
- Tørkørslen i Category reconciliation kører synkront i admin-requesten over hele kataloget, med ét mapping-opslag pr. direkte kategori pr. produkt; store kataloger kan give lange svartider.
- Fingeraftrykket dækker højst de første 500 forventede ændringer og "needs attention"-poster.
- Flere fulde afstemninger kan køre samtidig (hver med eget `run_id`); produktfejl i en fuld afstemning gentages ikke pr. produkt.
- Én creator pr. produkt og, i fase 17's UI, én kategori pr. creator.
- Direkte SQL og kode, der omgår pluginets hooks, er uden for vagterne (ADR-034).
- Uden WooCommerce vises produktkategorier i audit-loggen kun som "#ID".

## Future improvements
- Forhåndsindlæste mappings og cache af creator-status i tørkørsler (kræver godkendelse).
- Produkter og ordrer i creator-dashboardet (fase 19) og sales ledger (fase 18).
