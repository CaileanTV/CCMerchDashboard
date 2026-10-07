# Changelog

Større ændringer: features, databaseændringer, arkitekturændringer, breaking changes og større bugfixes. Formatet følger [Keep a Changelog](https://keepachangelog.com/da/1.1.0/), og versionering følger [SemVer](https://semver.org/).

## [Unreleased]

### Added
- **Salgsstatistik (fase 20, ADR-040):** read-modellen `SalesStatistics` (`LedgerSalesStatistics`) på sales ledgeren alene: totaler (`sales`, `adjustments`, `net_sales = sales + adjustments`, `units`, `orders` = unikke ordrer med salg i perioden), serie pr. dag, ISO-uge eller kalendermåned og produkter grupperet på parent, altid pr. valuta uden omregning eller samlet total. Perioder beregnes i PHP i sitets tidszone (sommertid-sikkert, intet `CONVERT_TZ()`).
- **`GET /merchboard/v1/me/sales` (fase 20, ADR-040):** kun med WooCommerce; `from`, `to` (inklusive) og `interval`, standard de seneste 30 dage pr. dag; højst 5 år og 366 delperioder (400 `merchboard_invalid_period`); tæt serie, højst 100 produkter med `truncated` og aktuelt produktnavn med fallback til ledger-navnet. Beløb som decimalstrenge, ingen kundedata. Der findes intet `/me/overview`.
- **Caching af salgsstatistik (fase 20, ADR-040 pkt. 10):** WordPress' object cache (gruppe `merchboard_sales`, 15 minutter) med en generation pr. creator, der fornyes efter hver committet ledger-skrivning med nye rækker. Ingen transients, intet i databasen, ingen offentlig hook.
- **Salg i creator-dashboardet (fase 20, ADR-040/041):** Overview viser nøgletallene "Nettosalg (ekskl. moms)", enheder og ordrer for de seneste 30 dage pr. valuta. Den nye sektion **Sales** viser en valgt periode og et interval med nøgletal, en egen SVG-søjlegraf (ingen chart-bibliotek, beløb skaleres uden floats) og altid en semantisk tabel med samme tal.
- **Sales på creatorens redigeringsside (fase 20, ADR-040 pkt. 13 og statusnote 2026-10-07):** server-renderet afsnit med de seneste 30 dage pr. valuta (nettosalg, enheder, ordrer og daglig serie), kun for `merchboard_manage_creators`, med samme definitioner og cache som `/me/sales`.
- **Sales-benchmark (fase 20, trin 8):** opt-in test-fixture (`tests/Integration/Benchmark`, gruppen `benchmark`) med et syntetisk datasæt. ADR-040's ydelseskrav (p95 < 200 ms uden cache) er ikke erklæret opfyldt, fordi repoet ikke har et volumenmål; målt kapacitet er cirka 40.000 ledger-rækker pr. creator pr. år (se ROADMAP).
- **Danske oversættelser (fase 20):** strengene fra Overview-nøgletallene, Sales-sektionen og admin Sales.
- **REST-paginering for liste-routes (fase 19, trin 1):** `RestController::pagination_args()` (`page` ≥ 1, standard 1; `per_page` 1-100, standard 20) og `paginated()` med et rent JSON-array og headerne `X-WP-Total` og `X-WP-TotalPages`, samt `Core\Http\Pagination`. Ugyldige værdier giver 400 `rest_invalid_param`, total 0 giver 0 sider, og en side efter sidste side giver 200 med `[]`. Konventionen står i API.md og bruges af `/me/products` og `/me/orders`.
- **Creator-produkter (fase 19, trin 2 og 6, ADR-039):** `GET /merchboard/v1/me/products` (kun med WooCommerce) med creator'ens produkter med status `publish` og `private`, sorteret efter navn: navn, status, den aktive WooCommerce-pris som strenge med `tax: "store_setting"` (min/max for variable produkter), lagerstatus og en thumbnail med samme origin. Sektionen **Products** i creator-dashboardet viser dem pagineret, med prisen præcis som sendt.
- **Creator-ordrelinjer (fase 19, trin 3, 4 og 7, ADR-039):** read-modellen `SalesOrderLines` på sales ledgeren (creator-filter i SQL, én række pr. ordrelinje, saldo, markeringerne `refunded` og `counted`) og `GET /merchboard/v1/me/orders` (kun med WooCommerce), som beriger de viste linjer via WooCommerce CRUD (højst ét opslag pr. ordre og produkt): ordrenummer, status, `trashed`/`deleted` og produktnavn med fallback til ordrelinjens gemte navn. Sektionen **Orders** viser linjerne pagineret med markeringerne som tekst. Ingen kundedata, ingen land og ingen beløb.
- **Liste-fundament i dashboardet (fase 19, trin 5):** `requestList()` (validerer body og `X-WP-Total`/`X-WP-TotalPages`), `usePagedApi` og komponenterne `Table` og `Pagination`. WooCommerce-modulet registrerer sektionerne Products og Orders efter Overview (`DashboardSectionRegistration`).
- **Danske oversættelser (fase 19, trin 9):** 38 nye strenge fra Products, Orders og pagineringen.
- **Backfill af sales ledgeren (fase 18, trin 7, ADR-038 statusnote "ROADMAP trin 7"):** WP-CLI-kommandoen `wp merchboard sales-backfill` (`dry-run`, `start`, `status`, `resume`, `--batch-size`) registrerer historiske WooCommerce-ordrer, der endnu ikke har ledger-historik, side for side via Action Scheduler. Eksisterende historik ændres aldrig, ikke-tællende ordrer får ingen rækker, og der sker ingen genberegning eller afstemning. Dry-run skriver intet og viser kun tællinger. Én kørsel ad gangen, ingen automatisk retry; en fejlet side kan genoptages med `resume`, mens en kørsel afbrudt ved deaktivering startes forfra (idempotent). Ny audit-action `sales_backfill_completed` med kun tællinger. Ventende backfill-jobs annulleres ved deaktivering og uninstall. Danske oversættelser.
- **Tællende ordrestatusser kan vælges (fase 18, trin 6, ADR-038 S1–S14):** sektionen "Sales ledger" på Merch Dashboard → Settings (kun `merchboard_manage_settings`, kun med WooCommerce) med egen formular, nonce og notices. Valgbare er alle statusser, WooCommerce registrerer under en `wc-`-nøgle (også fra andre plugins, også med `_`, højst 17 tegn uden `wc-`), undtagen `pending`, `failed`, `cancelled`, `refunded`, `checkout-draft` og `trash`; andre registrerede statusser vises som "Not supported" med årsag; en tællende status, der ikke længere er registreret, vises som "ikke registreret" og kan beholdes eller fravælges, men ikke tilføjes igen. Listen gemmes som nøglen `counted_order_statuses` i `merchboard_settings` (standard `processing`, `completed`), uden `wc-`, uden dubletter og sorteret; en tom liste afvises, og en uændret liste skriver intet. En ændring auditeres som `settings_updated` atomisk med optionen og gælder fra næste synkronisering af en ordre, også eksisterende ordrer; intet genberegnes, og historikken ændres aldrig. Afvisningslisten håndhæves også ved læsning, og ugyldige eller tomme gemte værdier giver standarden. Settings-siden har fået udvidelsespunktet `merchboard_settings_sections`. Danske oversættelser.
- **Salgsregistrering fra WooCommerce (fase 18, trin 5, ADR-038):** ordrer i status `processing` eller `completed` med produkter koblet til en creator registreres i sales ledgeren ved gemning, refund, annullering, sletning af refunds og permanent sletning af ordren. Fejl stopper aldrig WooCommerce, men sætter ordremetaen `_merchboard_ledger_failure` (fejlkode, tidspunkter og antal, ingen persondata) og logges; den ryddes efter en vellykket synkronisering og slettes ved opt-in-uninstall. Runtime-verificeret med WooCommerce 10.3.8 i både HPOS og legacy.
- **Schema-version 5 (fase 18, ADR-037/038):** nye append-only tabeller `{prefix}merchboard_sales` (ledger med `UNIQUE(event_key)`, `KEY(creator_id, occurred_at)`, `KEY(order_id)`, `KEY(refund_id)`) og `{prefix}merchboard_sales_cycles` (`UNIQUE(order_id, cycle, event)`). Migration 4 → 5 kører automatisk ved aktivering eller opdatering. `SalesRepository` gemmer og læser rækker under en navngiven lås pr. ordre og i én transaktion; der registreres endnu intet salg.
- **WooCommerce-modul (fase 17, trin 2, ADR-030):** modulet indlæses kun med et aktivt WooCommerce ≥ 10.3 (header `WC requires at least: 10.3`). Med en ældre version forbliver modulet slået fra, og brugere, der må aktivere plugins, ser en neutral notice; uden WooCommerce sker intet. Creator-rollen får ingen produktrettigheder.
- **Produktkoblinger (fase 17, trin 3-4, ADR-030/032):** `ProductCreatorLink` er den eneste skrivevej for `_merchboard_creator_id` på parent-produkter: `edit_product` pr. produkt, en eksisterende aktiv creator ved nye koblinger, ingen varianter, papirkurv eller auto-draft, og en eksisterende kobling erstattes kun ved eksplicit overtagelse. Audit-actions `product_creator_linked`/`product_creator_unlinked` skrives i samme transaktion. Afsnittet "Products" på creatorens redigeringsside lister, søger (navn, varenummer og direkte produktkategori), kobler, overtager og fjerner produkter. WooCommerce-produktskærmen viser og redigerer ikke relationen.
- **Creator i produktlisten (fase 17, trin 5, ADR-032):** skrivebeskyttet kolonne "Creator" og et creator-filter i WooCommerce' produktliste, kun for `merchboard_manage_creators`.
- **Beskyttet produktmeta (fase 17, trin 6, ADR-034):** deny-by-default på metadata-laget for `_merchboard_creator_id` og `_merchboard_creator_link_source`; kun en eksplicit, engangs-`ProtectedMetaWrite` for ét produkt kan skrive. Tydelige afvisninger ved WooCommerce REST (400), variant-generering og CSV-import; "Duplicate" kopierer ikke koblingen.
- **Schema-version 4 (fase 17, ADR-033):** ny tabel `{prefix}merchboard_creator_categories` (`term_id` PRIMARY KEY, `creator_id` KEY, `created_at` UTC, `created_by` nullable) til creator ↔ produktkategori-mappingen. Migration 3 → 4 kører automatisk ved aktivering eller opdatering. Nye audit-actions `creator_category_linked` og `creator_category_unlinked`.
- **Kategori-afstemning (fase 17, trin 8, ADR-033):** `CreatorCategoryReconciler` kobler produkter til creators ud fra deres direkte, mappede produktkategorier. Tilstandsbaseret og idempotent; manuelle koblinger røres aldrig, tvetydige produkter får ingen kobling, og inaktive creators får ingen nye. Kaldes automatisk via Action Scheduler (se nedenfor). Product meta `_merchboard_creator_link_source` (`admin` | `category`) skrives nu sammen med `_merchboard_creator_id`.
- **Fuld kategori-afstemning (fase 17, trin 9, ADR-033):** `CategoryReconciliationBatch` afstemmer alle relevante produkter i batches med ét `run_id` pr. kørsel, en tørkørsel uden bivirkninger og audit-actionen `category_sync_completed`, når alle batches er færdige.
- **Automatisk kategori-afstemning (fase 17, trin 9b, ADR-033):** kategoriændringer på produkter, gendannelse fra papirkurven, sletning af mappede kategorier, mapping-ændringer og genaktivering af creators sætter deduplikerede Action Scheduler-jobs i kø (gruppe `merchboard`). Jobs kører i batches på 100 og gentages højst 3 gange ved fejl. Nyt domæne-event `merchboard_creator_status_changed`. Uden Action Scheduler sættes intet i kø (logges).
- **Mapping-UI (fase 17, trin 10, ADR-033):** sektionen "Produktkategori" på creatorens redigeringsside. En kategori mappes efter en tørkørsel og eksplicit bekræftelse. En mapping fjernes efter en tørkørsel med det obligatoriske valg "Fjern kategoristyrede tilknytninger" eller "Behold som manuelle tilknytninger" (massekonvertering til manuelle tilknytninger med samme creator). Mappings til slettede kategorier vises og kan ryddes op med samme valg. Danske oversættelser.
- **Kolonnen "Produktkategorier" i Creators-listen (fase 17, trin 11):** skrivebeskyttet visning af creatorens mappede produktkategorier; mappings til slettede kategorier vises som "Slettet kategori". Creators-listen har fået udvidelsespunkterne `merchboard_creators_list_columns`, `merchboard_creators_list_items` og `merchboard_creators_list_column`.
- **Oprindelse og "Keep as manual" i creatorens produktafsnit (fase 17, trin 12a, ADR-033):** hvert tilknyttet produkt viser "Manuel" eller "Produktkategori" (med de mappede kategorier og afstemningens vurdering), og en kategori-tilknytning kan beholdes som manuel efter eksplicit bekræftelse. Creatoren bevares; kun oprindelsen ændres, med audit.
- **Remove afvises for kategori-styrede produkttilknytninger (fase 17, trin 13a, ADR-033 pkt. 5):** `ProductCreatorLink::unlink()` afviser med `category_managed`, og produktafsnittet tilbyder i stedet "Keep as manual". Manuelle tilknytninger fjernes som før.
- **Værktøj til fuld kategori-afstemning (fase 17, ADR-033):** "Kategoriafstemning" på Creators-listen (kun `merchboard_manage_creators`, intet nyt menupunkt): tørkørsel med tællinger, forventede ændringer og "Kræver opmærksomhed" (tvetydige produkter, inaktive creators og produkter, der ikke kunne vurderes), eksplicit bekræftelse og asynkron kørsel via Action Scheduler. En forældet tørkørsel starter intet. Nye udvidelsespunkter på Creators-siden: `merchboard_creators_list_tools` og `merchboard_creators_view_<action>`. Danske oversættelser.
- **Manuelle afvigelser i "Kræver opmærksomhed" (fase 17, ADR-033):** kategoriafstemningens tørkørsel viser manuelle tilknytninger, hvis produktkategorier peger på en anden (aktiv eller inaktiv) creator eller på flere creators. Kun diagnostisk: den manuelle tilknytning vinder og ændres ikke. Danske oversættelser.
- **Audit-log-labels (fase 17, trin 14):** produkt- og kategori-hændelser vises læsbart i Merch Dashboard → Audit log: produkter og produktkategorier med navn og ID, oprindelse, årsag, valg, hvem der udløste en afstemning, forkortet kørsels-id og afstemningens optællinger på én linje. Danske oversættelser.
- **Sletning af beskyttet produktmeta ved opt-in-uninstall (ADR-036):** vagten tillader kun sletning af de to nøgler, og kun når WordPress afinstallerer Merch Dashboard med opt-in til sletning af data. `Uninstaller` sletter dem via WordPress' meta-API før tabellerne og opt-in.

### Changed
- **Overview (fase 20):** nøgletalskortene "Revenue", "Orders" og "Products sold" med "Sales data coming soon" er erstattet af rigtige tal fra `/me/sales` ("Net sales (excl. VAT)", "Units", "Orders").
- **`SalesRepository::write()` (fase 20):** invaliderer salgscachen for creators med indsatte rækker efter COMMIT.
- **Audit-loggen viser lister i indstillinger (fase 18, trin 6):** `previous_value` og `value` vises som "a, b", når værdien er en flad liste af skalarer, og en tom liste som "—". Indlejrede og associative arrays vises fortsat som "…"; al anden visning er uændret.
- **Vagten for beskyttet produktmeta er en core-service (ADR-035):** den registreres i `Plugin::register_hooks()` og beskytter nøglerne også uden (eller med en for gammel) WooCommerce. `ProtectedMetaEntryPoints` bliver i WooCommerce-modulet.
- `CreatorCategoryService::unlink()` kræver nu et eksplicit valg (`remove_links` eller `keep_as_manual`, ADR-033 pkt. 10). Valget auditeres i `creator_category_unlinked`.
- Manuelle produktkoblinger gemmer nu oprindelsen `admin`. Kilden `backfill` (ADR-031) er fjernet; ældre koblinger uden oprindelse behandles som manuelle.
- **Tooling:** `composer analyse` kører PHPStan med `--memory-limit=2G`. Grænsen gælder pr. parallel worker, og 1G var for lidt på CI's runner med få kerner.

### Fixed
- **Testforurening (fase 19, trin 4):** en unit-test for ordre-read-modellen definerede WooCommerce-funktioner globalt i den fælles testproces, så `UninstallerTest` kunne fejle afhængigt af den tilfældige testrækkefølge. Rettet uden ændring af produktionskode.
- Integrationstesten for migration 4 sammenligner kolonnetyper uden heltals-display-bredde, så den består på både MariaDB og MySQL 8.0.19+ (CI).

### Verifikation
- **Products og Orders i Merch Dashboard Local (fase 19, trin 8, 2026-10-06):** 42 runtime-checks med syntetiske data og HPOS (adgang, sektioner, produkter, ordrelinjer, privacy, paginering og dashboard-shellen over HTTP). Ikke testet: browser-UI (intet browserværktøj) og legacy-ordrelager.
- **Sales ledger i Merch Dashboard Local (fase 18, trin 8, 2026-10-01):** regression af Q4-scenarierne (refunds, sletning af refunds via admin, papirkurv, gendannelse, permanent sletning, forældet refund-cache, fejlmarkør og teknisk log) og et samspilsscenarie mellem backfill og live-hooks på `00fb066`, bestået i HPOS og legacy. Ingen ændring af adfærden.
- **Uninstall-verifikation (ADR-036):** integrationstests for alle uninstall-scenarier (opt-in/opt-out, manglende eller forkert `WP_UNINSTALL_PLUGIN`, skrivninger i vinduet, idempotens og logning uden værdier). Ingen ændring af uninstall-adfærden.

## [0.1.0] - 2026-09-25

Første udgave (MVP): admin opretter og administrerer creators, og en creator logger ind, ser sit overview og redigerer sin profil i creator-dashboardet. Dansk oversættelse følger med. Kræver PHP ≥ 8.2 og WordPress ≥ 6.7. WooCommerce er valgfrit. Punkterne nedenfor er samlet over faserne 1-16 og MVP-checkpointet.

### Added
- Projektfundament: Composer (PSR-4 `MerchBoard\`), PHPCS/WPCS, PHPStan level 8, `@wordpress/scripts` (ESLint/Prettier), WordPress Playground-dev-miljø med WooCommerce og GitHub Actions CI.
- Plugin-entry `merchboard.php` (kun header).
- Dokumentation: arkitektur, security- og rollemodel, datamodel, API-, frontend-, admin- og WooCommerce-design, roadmap (20 faser), ADR 001-016 og AI-udviklingsregler.
- Project Isolation omfatter eksterne services og connectors. Dinero og Google Drive er eksplicit fravalgt (ADR-016).
- **Plugin bootstrap (fase 2):** versionstjek (PHP ≥ 8.2, WP ≥ 6.7) med admin notice i stedet for fatal error, egen PSR-4-autoloader, `Plugin` med modul-boot på `plugins_loaded` (filter `merchboard_modules`, action `merchboard_loaded`), `Container`, `Module`-interface, WooCommerce HPOS-kompatibilitetserklæring og `uninstall.php`-skelet.
- PHPUnit 11 + Brain Monkey med 38 unit-tests. CI kører PHPUnit og et PHP 7.2-syntakstjek.
- **Databasefundament (fase 3):** tabel `merchboard_creators`, versionerede migrationer (`merchboard_db_version`, ADR-019), `Installer` (activation hook og versionstjek ved opdateringer), `Creator`/`CreatorStatus`/`CreatorRepository` og `CreatorsModule` (ADR-020). 113 unit-tests.
- **Logging og fejlhåndtering (fase 4):** `Logger`-abstraktion med WooCommerce-logger (source `merchboard`) og `error_log()`-fallback, tærskel fra `WP_DEBUG`/filter `merchboard_log_level`, sanitization af context (`LogContext`) og `DatabaseException::context()`. Migrationsfejl logges via Logger. ADR-021.
- **Roller og capabilities (fase 5):** rolle `merchboard_creator` (`read` + `merchboard_access_dashboard`), admin-capabilities `merchboard_manage`, `merchboard_manage_creators` og `merchboard_manage_settings`, samt `Capabilities` og `RoleManager` med idempotent sync ved aktivering og opdatering (ADR-022).
- **Creator-administration og audit trail (fase 6):** admin-side "Merch Dashboard → Creators" (liste, opret/rediger, aktivér/deaktivér, tilknyt/frakobl WordPress-bruger), `CreatorService`, `Transaction`, `AuditTrail`/`AuditRepository`/`AuditAction` (ADR-023).
- **Release-pipeline (fase 16):** Et tag `vX.Y.Z` kører alle CI-tjek og bygger derefter plugin-ZIP'en med `bin/package.mjs`. Resultatet er en draft-release på GitHub med ZIP, SHA-256 og release notes fra CHANGELOG. `npm run package -- --tag` afviser tags, der ikke matcher versionen eller `HEAD`, og pakken afvises også, hvis header, `MERCHBOARD_VERSION` og `package.json` ikke har samme SemVer-version. Procedure for versions-bump, release og staging (`devpage.ccmerch.dk`, manuel upload). ADR-028.
- **Distributionspakke (MVP-checkpoint):** `npm run package` bygger den installérbare `dist/merchboard-mvp-<version>.zip` med `bin/package.mjs`. Pakken bygges fra en allowlist med rodmappen `merchboard/` (`merchboard.php`, `uninstall.php`, `src/`, `templates/`, `languages/`, `assets/js/`, `build/`) og uden `vendor/`, kilder, tests eller konfiguration. Den er deterministisk og fejler ved manglende eller forbudte filer og ved runtime-filer, der afviger fra `HEAD`. `.distignore` og `export-ignore` er bragt i sync med allowlisten.
- **Dashboard overview → MVP (fase 15):** Overview-sektion (velkomst, status, profil-resumé og KPI-kort med "Salgsdata kommer snart"), sektion-registry `DashboardSections` med filter `merchboard_dashboard_sections` og capability pr. sektion, samt dansk oversættelse af alle UI-strenge (`languages/`, `load_plugin_textdomain()`, `wp_set_script_translations()`, `npm run i18n` med `wp-cli/i18n-command`).
- **REST-fundament, `/me` og Profile (fase 14):** `Core\Http\RestController`, `GET /merchboard/v1/me` (`display_name`, `status`), `GET`/`PATCH /merchboard/v1/me/profile` (display name, bio, sociale links med faste platforme), migration 3 `merchboard_creatormeta`, `CreatorProfileService` med validering, whitelist og audit `creator_updated`, samt Profile-sektion i dashboardet (formular, validering og toast). Creator-navnet i topbaren kommer fra `GET /me`.
- **Dashboard frontend-fundament (fase 13):** Creator-dashboardet er en React-app i TypeScript (strict, ADR-027), bygget med `@wordpress/scripts` (`npm run build`/`start`/`typecheck`/`test:js`). Den har layout (topbar, sidebar, mobil-nav, skip link), en router med deep links og query-fallback, bootstrap-data med runtime-validering, API-klient til `merchboard/v1` med typed resultat, `useApi`, kernekomponenter og design tokens. `DashboardAssets` registrerer bygget, og shellen udskriver kun bundlen og dens WordPress-dependencies med CSP-nonce. Jest-, typetjek-, stylelint- og build-trin i CI.
- **Creator dashboard: route og sikker shell (fase 12):** `Modules/Dashboard` med konfigurerbar route (default `/creator-dashboard/`, deep links), `ShellController` med auth-gate via `CreatorContext` (login-redirect, 403 "access not active", shell uden theme og JavaScript) og security headers inkl. CSP. Dashboard-slug under Merch Dashboard → Settings (validering, audit). `Deactivator` og ugyldiggørelse af rewrite rules. For rene creators (ADR-026): login-redirect inkl. WooCommerce, blokering af wp-admin, skjult admin bar og Application Passwords slået fra.
- **Audit log (fase 11):** Merch Dashboard → Audit log (read-only, `WP_List_Table`, filtre på hændelse, creator og periode, læsbar context, slettede brugere og creators håndteres, link til WooCommerce' tekniske logs), `AuditRepository::paginate()` med `AuditFilter`, ny capability `merchboard_view_logs` til administrator og seneste aktivitet på Overview.
- **Admin-shell, Overview og brugervalg (fase 10):** topmenu "Merch Dashboard" med `merchboard_manage` og Overview (creator-tal og systemstatus), søgbart brugervalg (login-felt, AJAX-forslag via `admin-ajax.php`, ingen e-mail), profil-link i creator-formularen og infoboks på brugerprofilen. Første JavaScript-fil `assets/js/creator-user-search.js` (vanilla, ingen build).
- **Lifecycle og permanent sletning (fase 9):** Merch Dashboard → Settings med opt-in "Delete all Merch Dashboard data when the plugin is deleted" (default fra, `merchboard_manage_settings`, nonce, skrevet bekræftelse `DELETE`, audit `settings_updated`). `uninstall.php` sletter kun ved opt-in og kun pluginets egne tabeller, options, rolle og capabilities for det aktuelle site (`Uninstaller`, `SchemaEraser`, ADR-025).
- **Creator access og brugerrelation (fase 8):** `Modules/Access` med `CreatorContext` (adgang = capability `merchboard_access_dashboard` + aktiv, koblet creator-record), reaktion på `deleted_user` (creator bevares, frakobles og deaktiveres, systemaudit med actor NULL, én transaktion), `AuditTrail::record_system()` og `CreatorRepository::release_user()`.
- **Database-hardening og MySQL/MariaDB-verifikation (fase 7):** integrationstests mod rigtig WordPress og database (`npm run test:integration`, portabel MariaDB 10.11.19 lokalt, CI-job mod runnerens MySQL), `TransactionSupport` (engine-tjek) og admin-notice ved ikke-transaktionelle tabeller (ADR-024).

### Changed
- **Navngivning (ADR-018):** Den midlertidige konvention `ccmerch` / `CCMerch\` / `ccmerch-dashboard` / "CC Merch Dashboard" er erstattet af `merchboard` / `MerchBoard\` / `merchboard` / "Merch Dashboard" i al kode, konfiguration og dokumentation. Hovedfilen hedder nu `merchboard.php`. Ingen datamigration, fordi der ikke fandtes persistent data. Punkterne ovenfor bruger allerede de nye navne.
- **Arkitektur:** Runtime bruger pluginets egen autoloader i stedet for Composers `vendor/autoload.php`. Den distribuerede plugin indeholder ikke `vendor/` (ADR-017, erstatter en del af ADR-004).
- Activation/deactivation-hooks og text domain-loading er flyttet fra fase 2 til fase 3, 4 og 15, hvor de får en funktion.
- `bin/composer.mjs` aktiverer manglende PHP-extensions via en projekt-lokal ini (`PHP_INI_SCAN_DIR`), så de også gælder for Composer-scripts.

### Fixed (MVP-checkpoint)
- Creator-dashboardet startede ikke ("The dashboard could not be started."), når webstedets titel var tom. En tom `site_name` i bootstrap-data accepteres nu, fordi den kun bruges til visning. Feltet skal stadig findes og være en streng, og sidetitlen udelader ` · ` uden titel.

### Fixed (fase 14)
- En udløbet nonce (`rest_cookie_invalid_nonce`) vises nu som udløbet session i stedet for "ingen adgang".

### Fixed (fase 13)
- `docs/FRONTEND.md` var blevet ødelagt af fase 12's docs-script (filens start indsat midt i rewrite-afsnittet) og er skrevet om.

### Fixed (fase 12)
- `CreatorRepository::find_by_user_id()` kaster nu `DatabaseException`, når forespørgslen fejler, i stedet for at returnere "ingen creator". Adgangen var i forvejen nægtet, men fejlen blev ikke logget.

### Changed (fase 3)
- Statusmodel for creators er `active`/`inactive` (tidligere planlagt `active`/`suspended`/`archived`). `user_id` er nullable (tidligere planlagt `NOT NULL`). `merchboard_creatormeta` flyttet til fase 14. ADR-020.
- Uninstall bevarer eksplicit alle data. Opt-in-sletning kommer i fase 9.
- `bin/composer.mjs` fjerner andre ini-filer i sin egen `.tools/php-ini/`-mappe, så extensions ikke indlæses to gange (en forældet lokal ini fra før ADR-018 gav "Module already loaded"-advarsler).

### Changed (fase 4)
- ADR-021 erstatter ADR-012: ingen `merchboard_logs`-tabel. Audit trail planlagt som `merchboard_audit_log` i fase 6.
- Migrationsfejl skrives ikke længere direkte med `error_log()`, men via Logger med renset diagnostik. Admin-noticen henviser til loggen uden detaljer.

### Changed (fase 5)
- Udskudt fra den oprindelige fase 5-plan: `merchboard_view_logs` → fase 11, meta-capabilities via `map_meta_cap` → fase 8, rolle-oprydning ved uninstall → fase 9.
- Creator-rollens display name er "Merch Dashboard Creator" (tidligere planlagt "Creator").

### Changed (fase 6)
- Creator-admin-UI flyttet frem fra fase 10. `deleted_user`-hook flyttet fra fase 6 til fase 8.
- Tilknytning af en bruger giver rollen `merchboard_creator` som ekstra rolle, og frakobling fjerner den.
- PHPCS: `EscapeOutput.ExceptionNotEscaped` er slået fra projekt-bredt (exception-beskeder renderes aldrig; dokumenteret i regelsættet).

### Changed (fase 11)
- `RoleManager::VERSION` 1 → 2: administratorer får `merchboard_view_logs` ved næste request. Uninstall fjerner den også (via `Capabilities::all()`).

### Changed (fase 10)
- Topmenuens slug er nu `merchboard` (Overview), og menu-capability er `merchboard_manage` (før `merchboard_manage_creators`). Creators (`merchboard-creators`) og Settings (`merchboard-settings`) er uændrede URL'er som submenuer.
- Creator-formularens brugerfelt er ændret fra en select med op til 500 brugere (`user_id`) til et login-felt (`user`: login, `#id` eller tomt). `UserAccounts::choices()` er erstattet af `search()`, `find_by_login()` og `login()`.

### Changed (fase 9)
- `uninstall.php` sletter ikke længere "intet": ved eksplicit opt-in sletter den Merch Dashboards egne data. Uden opt-in bevares alt som hidtil.
- Nyt audit-action `settings_updated`.
- Roadmap-fase 9 er omdøbt til "Lifecycle, settings og permanent sletning". Overview er flyttet til fase 10, og settings-tabs kommer, når de får indhold.

### Changed (fase 8)
- Roadmap-fase 8 er omdøbt til "Creator access og brugerrelation". Login-redirect, wp-admin-blokering, admin bar og application passwords er flyttet til fase 12, og meta-capabilities til fase 14.
- Audit-context for `creator_user_unlinked`/`creator_deactivated` kan nu indeholde `reason: user_deleted`. Ingen ny action og ingen schemaændring.

### Changed (fase 7)
- **Migrationslås:** atomisk `INSERT IGNORE` i stedet for `add_option()` (som ikke er en lås på MySQL/MariaDB), compare-and-swap ved overtagelse af forladte låse og ejer-token ved frigivelse. Låsens værdi er nu `<tid>:<token>`. Ældre værdier (kun tid) læses stadig.
- **Fail closed:** `Transaction` skriver intet, hvis en plugin-tabel ligger på en engine uden transaktioner. Tidligere ville ændringen bestå uden audit-record (ADR-023's konsekvens er ændret af ADR-024).
- **Audit-context** gemmes som ASCII-JSON, og ugyldig UTF-8 eller ikke-endelige tal erstattes i stedet for at gemme en tom context.
- Roadmap-fase 7 er omdøbt fra "Integrationstest-infrastruktur" til "Database-hardening og MySQL/MariaDB-verifikation". Integrationstestene bruger en egen let bootstrap i stedet for `wp-phpunit`.

### Database
- **Fase 9:** ny option `merchboard_settings` (autoload, array). Ingen schemaændring.
- **Schema-version 1:** ny tabel `{prefix}merchboard_creators` (`id`, `user_id` nullable UNIQUE, `display_name`, `status`, `created_at`, `updated_at`; UTC). Nye options `merchboard_db_version` (autoload) og `merchboard_db_migration_lock` (midlertidig). Transient `merchboard_db_migration_failed`.
- **Roller (fase 5):** rolle `merchboard_creator` og administrator-capabilities i WordPress' `wp_user_roles`. Ny option `merchboard_roles_version` (autoload). Ingen schemaændring.
- **Schema-version 2 (fase 6):** ny tabel `{prefix}merchboard_audit_log` (`id`, `created_at`, `action`, `actor_user_id` nullable, `creator_id` nullable, `context` JSON). Migration 1 → 2 kører automatisk ved aktivering eller opdatering.
- **Schema-version 3 (fase 14):** ny tabel `{prefix}merchboard_creatormeta` (standard WordPress meta-tabel: `meta_id`, `merchboard_creator_id`, `meta_key`, `meta_value`) til whitelistede profilfelter (bio, sociale links).
