# Roadmap

Overordnet plan. Hver fase er lille nok til én afgrænset Claude Code-prompt, og roadmappet må ændres løbende. Ændringer i rækkefølge eller scope noteres i [PROJECT-STATUS.md](PROJECT-STATUS.md).

**Fælles Definition of Done for alle faser:** `npm run lint` og relevante tests er grønne, security-tjeklisten ([SECURITY.md](SECURITY.md#tjekliste-pr-ændring)) er gennemgået, feature-dokumenter, PROJECT-STATUS og evt. DATABASE/DECISIONS/CHANGELOG er opdateret, ændringerne er committed i små commits og pushed, og CI er grøn.

## Overblik

| Område | Faser | Milepæl |
| --- | --- | --- |
| Foundation | 1-2 | Tooling, docs og plugin-bootstrap |
| Data & core services | 3-4 | Migrationer og creator-tabel (✅ fase 3), logging og fejlhåndtering (✅ fase 4) |
| Authentication & permissions | 5-8 | Roller og capabilities (✅ fase 5), creator-administration (✅ fase 6), database-hardening og integrationstests (✅ fase 7), creator access (✅ fase 8) |
| Admin | 9-11 | Menu, settings, creator-administration, logs |
| Creator Dashboard | 12-15 | Route/shell, frontend-fundament, profil, overview → **MVP** |
| Deployment | 16 | Release-pipeline og staging (✅ fase 16, `v0.1.0`) |
| WooCommerce | 17-19 | Produkt↔creator (✅ fase 17), sales ledger, produkter/ordrer i dashboard |
| Statistics | 20 | Salgsanalyse og grafer |
| Senere | – | Payouts, notifications, integrations, campaigns, support, reporting, hardening og E2E |

---

## Fase 1 – Project foundation ✅

Analyse, dokumentation, dev-tooling (Composer, PHPCS/WPCS, PHPStan, wp-scripts/ESLint/Prettier), Playground-miljø og CI.

## Fase 2 – Plugin bootstrap ✅

- **Mål:** Pluginet booter via et modulsystem uden funktionalitet.
- **Leveret:** `MERCHBOARD_VERSION` og dobbelt-load-guard i hovedfilen, PHP-/WP-versionstjek med admin notice (PHP 7.2-syntaks, CI-tjekket), egen PSR-4-autoloader (ADR-017, erstatter den planlagte Composer-autoload ved runtime), `Plugin`, `Container`, `Module`-interface, HPOS-kompatibilitetserklæring, `uninstall.php`-skelet, `.distignore`, PHPUnit + Brain Monkey (38 unit-tests), PHPStan på `src/`, `uninstall.php` og `tests/`, samt `npm test`/`composer test` i CI.
- **Flyttet til senere faser**, fordi de ikke har en funktion endnu: activation hook (→ fase 3), deactivation hook (→ fase 4), load af text domain (→ fase 15). Se [ARCHITECTURE.md §5](ARCHITECTURE.md#5-boot-flow).
- **Done:** Pluginet aktiveres, deaktiveres og genaktiveres uden notices eller fatals i Playground (WP_DEBUG). Tests og lint er grønne.

## Fase 3 – Database-fundament og creator-tabel ✅

- **Mål:** Versionerede migrationer, creator-schema og et sikkert creator-datalag.
- **Leveret:** activation hook → `Installer`, versionstjek på `plugins_loaded` (før moduler), `Tables` (whitelist), `Schema`/`Migration`/`Migrator` (heltalsversion `merchboard_db_version`, lås og version gemt efter hver succes), migration 1 `merchboard_creators`, `Creator`, `CreatorStatus` (`active`/`inactive`), `CreatorRepository` (find, find_by_user_id, create), `CreatorsModule` og uninstall-politik (bevar data). 113 unit-tests. Runtime-verifikation i Playground/SQLite. ADR-019 og ADR-020.
- **Ændret i forhold til den oprindelige plan:** `merchboard_creatormeta` er flyttet til fase 14 (profilfelter). Entity, enum og et minimalt repository er rykket frem fra fase 6. `slug` og `created_by` er udeladt, indtil der er et behov. Statusmodellen er `active`/`inactive` i stedet for `active`/`suspended`/`archived`. Et separat `Activator`/`Upgrader` er samlet i `Installer`.
- **MySQL/MariaDB:** MariaDB 10.11.19 verificeret i fase 7. MySQL 8.0 verificeres i CI-jobbet `PHP integration (MySQL)` (senest 8.0.46, grøn), og staging kommer i fase 16 (se [DATABASE.md](DATABASE.md#mysqlmariadb-vs-playground-sqlite)).

## Fase 4 – Logging og fejlhåndtering ✅

- **Leveret:** `Logger`-interface (debug/info/warning/error), `PluginLogger` (tærskel fra `WP_DEBUG` + filter `merchboard_log_level`), `LogContext` (maskering af følsomme nøgler, sikre exceptions og fjernelse af kontroltegn), backends `WooCommerceLogWriter` (efter `woocommerce_init`) og `ErrorLogWriter` (fallback), `DatabaseException::context()`, og at `Migrator`/`Installer` logger migrationsfejl én gang i stedet for via `error_log()`. 165 unit-tests. Playground med og uden WooCommerce. ADR-021.
- **Ændret i forhold til den oprindelige plan (ADR-021 erstatter ADR-012):** ingen `merchboard_logs`-tabel, ingen channels og ingen retention-cron. Audit trail flyttes til fase 6 som egen tabel, deactivation hook til fase 12 (der er ingen cron at rydde op).

## Fase 5 – Roller og capabilities ✅

- **Leveret:** `Capabilities` (konstanter og definitioner), rollen `merchboard_creator` (præcis `read` + `merchboard_access_dashboard`), admin-caps `merchboard_manage`, `merchboard_manage_creators` og `merchboard_manage_settings` på `administrator`, `RoleManager` (deklarativ, idempotent sync, eskaleringsværn, egen version `merchboard_roles_version`) integreret i `Installer` (aktivering og versionstjek). 204 unit-tests. Playground med og uden WooCommerce (rigtig `user_can()`). ADR-022.
- **Ændret i forhold til den oprindelige plan:** `merchboard_view_logs` → fase 11. `map_meta_cap` for `merchboard_view_creator`/`merchboard_edit_creator` → fase 8 (kræver creator-record/status). Oprydning ved uninstall → fase 9 (opt-in). Se [USER-ROLES.md](USER-ROLES.md#udskudt-dokumenteret-ændring-i-forhold-til-den-oprindelige-fase-5-plan).

## Fase 6 – Creator-administration og audit trail ✅

- **Leveret:** migration 2 `merchboard_audit_log`, `AuditAction`/`AuditTrail`/`AuditRepository` (append-only), `Transaction`, udvidet `CreatorRepository`, `CreatorService` (authorization, validering, ændring og audit i én transaktion, rolle efter commit), `UserAccounts`, admin-UI (menu "Merch Dashboard" → Creators, `WP_List_Table`, opret/rediger, aktivér/deaktivér, tilknyt/frakobl bruger) via `admin-post.php` med nonces bundet til creator-ID. 278 unit-tests. Playground via det rigtige admin-UI med og uden WooCommerce, inkl. rollback-probe. ADR-023.
- **Ændret i forhold til den oprindelige plan:** Creator-admin-UI'et er flyttet frem fra fase 10. `deleted_user`-hooken er flyttet til fase 8. Topmenuen fra fase 9 oprettes allerede her (kun Creators).

<details><summary>Oprindelig fase 6-plan</summary>


- **Scope:** Audit trail (`merchboard_audit_log`-migration og `AuditLog`-service, ADR-021), udvid `CreatorRepository` (update med `updated_at`, statusskift, frakobling af user, paginerede lister med whitelisted sortering), `CreatorService` (forretningsvalidering, rolle-tildeling, statusskift, events og audit-log) og `deleted_user`-håndtering (user_id → NULL, status → inactive, data bevares). Entity, enum og grundlæggende repository findes fra fase 3.
- **Done:** Unit-tests af service-regler. Ingen UI endnu.

</details>

## Fase 7 – Database-hardening og MySQL/MariaDB-verifikation ✅

- **Scope (udført):** revision af databasefundamentet mod MySQL/MariaDB-semantik. Atomisk migrationslås, fail closed uden transaktionel engine, audit-context som ASCII-JSON, integrationstests mod rigtig WordPress og database (portabel MariaDB lokalt, CI-job mod runnerens MySQL) og dokumentation af forudsætninger (ADR-024). Omdøbt fra "Integrationstest-infrastruktur". `wp-phpunit` er erstattet af en egen let bootstrap.
- **Done:** Integrationstests grønne mod MariaDB 10.11.19 lokalt. CI-jobbets MySQL-resultat er ikke set fra udviklingsmiljøet.

## Fase 8 – Creator access og brugerrelation ✅

- **Scope (udført):** `CreatorContext` (capability **og** aktiv, koblet creator-record) som eneste adgangspolitik og `deleted_user`-reaktion (user_id → NULL, status → inactive, audit som systemhandling, i én transaktion). Se [features/creator-access.md](features/creator-access.md).
- **Done:** Unit- og integrationstests (MariaDB) for adgangsmatrix og brugersletning, og Playground med og uden WooCommerce.
- **Flyttet:** `LoginRedirect`, `AdminAreaGuard`, skjult admin bar og application passwords fra for creators → fase 12 (de har først et mål, når dashboard-routen findes). Meta-capabilities `merchboard_view_creator`/`merchboard_edit_creator` → fase 14 (første ressourcer). Omdøbt fra "Creator access og login-flow".

## Fase 9 – Lifecycle, settings og permanent sletning ✅

- **Scope (udført):** Settings-side (Merch Dashboard → Settings, `merchboard_manage_settings`) med opt-in "Delete all Merch Dashboard data when the plugin is deleted" (default fra, skrevet bekræftelse, audit-logget) og sletterutinen i `uninstall.php` (`Uninstaller`: egne tabeller, options, rollen `merchboard_creator` og de præcise plugin-capabilities; aldrig brugere eller WordPress/WooCommerce-data). Lifecycle-regressionstests for deaktivering. ADR-025.
- **Done:** Unit- og integrationstests (MariaDB), og Playground med og uden WooCommerce (scenarier A-E og settings-UI over HTTP).
- **Flyttet:** Overview (systemstatus) og menu-capability `merchboard_manage` for topmenuen → fase 10. Settings-tabs (General/Access/Advanced) kommer, når de får indhold (Access i fase 12, General i fase 12/15). Omdøbt fra "Admin shell: menu, overview og settings".

## Fase 10 – Admin creator management (rest) og Overview ✅

- **Scope (udført):** Topmenu "Merch Dashboard" med `merchboard_manage` og Overview som første punkt (creator-tal, dinglende links og systemstatus: schema, engine, roller, WooCommerce, sletning ved uninstall). Creators og Settings er submenuer med deres egne capabilities. Søgbart brugervalg (login-felt med AJAX-forslag, ingen e-mail), profil-link i formularen og infoboks på `user-edit.php`. Se [features/admin-creator-management.md](features/admin-creator-management.md).
- **Done:** Unit- og integrationstests (MariaDB) for capability, nonce og validering, og Playground med og uden WooCommerce over HTTP.
- **Ikke gjort:** bulk actions (valgfrit, intet konkret behov). Seneste audit-hændelser på Overview kom med fase 11, og rewrite-status kommer med fase 12.

## Fase 11 – Admin audit-log viewer ✅

- **Scope (udført):** Capability `merchboard_view_logs` (administrator, `RoleManager::VERSION` 2), read-only audit-log-side med `WP_List_Table` (20 pr. side, pagineret i databasen), filtre (hændelse, creator, periode), læsbar context (ingen rå JSON), håndtering af slettede actors og creators, link til WooCommerce → Status → Logs for tekniske logs og seneste aktivitet på Overview. Se [features/admin-audit-log.md](features/admin-audit-log.md).
- **Done:** Kræver `merchboard_view_logs`. Output er escaped. Unit- og integrationstests (MariaDB), og Playground med og uden WooCommerce over HTTP.

## Fase 12 – Creator dashboard: route og sikker shell ✅

- **Scope (udført):** `Modules/Dashboard`: rewrite rule og query vars (slug fra settings, default `/creator-dashboard/`, deep links), rewrite rules ugyldiggøres ved aktivering, i `Deactivator`, ved slug-ændring og når de gemte regler mangler ruten. `ShellController` (auth-gate via `CreatorContext`: login-redirect / 403-side "access not active" / shell), `templates/dashboard/shell.php` og `no-access.php` uden theme og uden JS, security headers inkl. CSP. Dashboard-slug i Settings (validering, audit). Fra fase 8: `LoginRedirect` (inkl. WooCommerce), `AdminAreaGuard`, skjult admin bar og Application Passwords slået fra for **rene creators** (ADR-026).
- **Done:** Unit- og integrationstests (MariaDB) for adgang, login, wp-admin, admin bar, Application Passwords og slug. Deep links virker. Playground med og uden WooCommerce over HTTP.

## Fase 13 – Dashboard frontend-fundament ✅

- **Forudsætning (opfyldt):** TypeScript besluttet (ADR-027).
- **Scope (udført):** `npm run build`/`start`/`typecheck`/`test:js`, entry `assets/src/dashboard/index.tsx` (React + TypeScript strict), `@wordpress/element`/`api-fetch`/`i18n` og typer som dev-deps, `DashboardAssets` (asset.php), bootstrap-data med runtime-validering, design tokens, layout (topbar, sidebar, mobil-nav, skip link), router (History API, pretty og query-fallback), API-klient (`merchboard/v1`, typed `ApiResult`, validering), `useApi`, kernekomponenter (Button, Card, Alert, Skeleton, EmptyState, ErrorState, ApiErrorState, Field), CSP med nonce, ESLint `react/no-danger` og `no-explicit-any`, Jest-setup, typetjek, stylelint og build i CI. Intet `/me`-endpoint (fase 14, besluttet af projektejeren).
- **Done:** Shellen viser et responsivt layout med navigation og empty state. JS-tests, typetjek, lint, PHP-tests og integrationstests er grønne. Playground med og uden WooCommerce (pretty permalinks og fallback) i en rigtig browser uden konsolfejl eller CSP-brud.

## Fase 14 – REST-fundament, `/me` og Profile ✅

- **Scope (udført):** `Core\Http\RestController`-basisklasse, migration 3 for `merchboard_creatormeta` (bio, sociale links), `GET /me` (`display_name`, `status`), `GET /me/profile` og `PATCH /me/profile` (whitelist: `display_name`, `bio`, `social_links` med faste platforme), audit `creator_updated`, Profile-sektion med formular, klient- og servervalidering og toast. Creator-navnet i topbaren kommer fra `GET /me`. Alle security-tests fra [API.md](API.md#test-krav). `GET /me/profile` og skemaerne er besluttet af projektejeren i fase 14.
- **Done:** IDOR-, permission- og valideringstests er grønne (unit, integration mod MariaDB og Jest). Playground med og uden WooCommerce over HTTP (cookie + nonce) og i browser.

## Fase 15 – Dashboard overview → **MVP** ✅

- **Scope (udført):** Overview-sektion (velkomst, creator-info fra `GET /me` og `GET /me/profile`, KPI-kort med empty state "Salgsdata kommer snart" uden API-kald), sektion-registry fuldt koblet (`DashboardSections`, filter `merchboard_dashboard_sections`, capability pr. sektion), dansk oversættelse af UI-strenge (`languages/`, `load_plugin_textdomain()` på `init`, `wp_set_script_translations()`, `npm run i18n` med `wp-cli/i18n-command`). Intet `/me/overview`-endpoint (flyttet til fase 20, besluttet af projektejeren).
- **Done:** MVP: admin kan oprette en creator, creator kan logge ind, se overview og redigere sin profil, og intet andet. Verificeret over HTTP og i Chrome i Playground, både på engelsk (med WooCommerce) og på dansk (uden WooCommerce).

## Fase 16 – Release-pipeline og staging ✅

- **Scope:** GitHub Actions-workflow på tag `v*`: composer `--no-dev`, `npm run build` og zip efter `.distignore`, med zip som release-asset. Versions-bump-procedure. Staging-deploy-procedure (manuel upload eller SFTP, afhængigt af hosting). MySQL-verifikation på staging.
- **Leveret:** `.github/workflows/release.yml` (tag `v*`, alle CI-jobs genbrugt via `workflow_call`, tagget commit skal ligge på `main`, draft-release med ZIP og `.sha256` via `gh`, release notes fra CHANGELOG). `bin/package.mjs --tag` (tag = `v<version>` på `HEAD`, samme version i header, konstant og `package.json`, SemVer). Versions-bump-, release- og staging-procedure i [DEVELOPMENT.md](DEVELOPMENT.md#release). ADR-028.
- **Ændret i forhold til planen:** Ingen `composer --no-dev` (pluginet har ingen `vendor/`, ADR-017) og ingen zip efter `.distignore`: workflowet genbruger `bin/package.mjs` (allowlist). Staging er `devpage.ccmerch.dk` med manuel upload. SFTP er fravalgt. Releasen er en draft, som projektejeren udgiver.
- **Done (2026-09-25):** CI og release-workflowet er grønne for tag `v0.1.0`. Draft-releasen har `merchboard-mvp-0.1.0.zip` og `.sha256` (hash verificeret, byte-identisk med den lokale pakke). ZIP'en er installeret og testet på staging (`devpage.ccmerch.dk`) af projektejeren. Releasen udgives af projektejeren.

## Fase 17 – WooCommerce foundation ✅

- **Scope:** `WooCommerceModule` (betinget indlæsning), `WC requires at least`, produktkoblinger administreret i Merch Dashboard (creatorens redigeringsside, ADR-032), kategori-mapping som vedvarende automatisk kobling (ADR-033), skrivebeskyttet "Creator"-kolonne og -filter i produktlisten og audit-log af koblinger. Intet creator-felt på WooCommerce-produktskærmen.
- **Præciseret før implementering (ADR-030, projektejerens beslutninger D1-D6):**
  - WooCommerce ≥ 10.3 (seneste linje, der kræver WordPress 6.7). Under minimum indlæses modulet ikke, og admin ser en notice.
  - Kolonnen "Creators" i ordrelisten er udskudt, og fasen har ingen ordrelogik.
  - `_merchboard_creator_id` (og fra ADR-033 `_merchboard_creator_link_source`) slettes kun ved opt-in-uninstall.
  - Ingen nye dependencies.
  - Én autoritativ domæneservice (`ProductCreatorLink`). WooCommerce-produktskærmen er ikke en skrivevej og viser ikke relationen. REST, CSV-import og XML-RPC kan ikke ændre nøglerne direkte, quick/bulk edit har intet felt, og duplicate kopierer ikke koblingen.
  - Ingen nye REST-endpoints.
- **Udvidet før implementering (ADR-031, ændret af ADR-033):** kategori-mapping pr. creator og en engangs-backfill. Mapping som creator-meta og backfill som selvstændig skrivevej er erstattet af ADR-033.
- **Ændret før trin 4 (ADR-032):** Produktkoblingen administreres i Merch Dashboard på creatorens redigeringsside (`admin-post.php` + nonce, `merchboard_manage_creators` + `edit_product` pr. produkt, `ProductLinkSource::Admin`), ikke på WooCommerce-produktskærmen. Produktlistens kolonne og filter er skrivebeskyttede. `product_screen` er fjernet i trin 4. Der er intet admin-REST-API, og creator-dashboardet bruges ikke.
- **Ændret efter trin 4 (ADR-033, implementeret i trin 6-13; åbne punkter nedenfor):**
  - Tabellen `merchboard_creator_categories` (migration 4, schema-version 4): én creator pr. `product_cat`, teknisk flere kategorier pr. creator (fase 17's UI tillader én). Ingen creator-meta `product_category_id`.
  - Oprindelse `_merchboard_creator_link_source` (`admin` | `category`) skrives sammen med `_merchboard_creator_id`. `backfill` udfases som kilde.
  - Skriveveje: manuel admin-vej (`source: admin`) og automatisk kategori-afstemning (`source: category`, systemarbejde via Action Scheduler uden capability-tjek for den aktuelle bruger).
  - Manuel kobling vinder altid. Remove afvises for kategori-styrede produkter; "Keep as manual" gør dem manuelle. Tvetydige produkter får ingen kategori-kobling. Kun direkte kategoritilknytning tæller.
  - Engangs-backfillen erstattes af den første afstemning, når en kategori mappes (tørkørsel → bekræftelse).
- **Rækkefølge:**
  - 1-4 ✅: undersøgelse, modul-skelet, `ProductCreatorLink`, produktkoblinger på creatorens redigeringsside.
  - 5 ✅: skrivebeskyttet kolonne og filter i produktlisten.
  - 6 ✅: vagter for skriveveje (REST, CSV-import, duplicate m.fl.) for `_merchboard_creator_id` og `_merchboard_creator_link_source`. Deny-by-default på metadata-laget med en eksplicit, engangs-`ProtectedMetaWrite` (ADR-034).
  - 7 ✅: migration 4 (`merchboard_creator_categories`) og mapping-service (oprettelse/fjernelse, én kategori pr. creator i fase 17, ikke standardkategorien, kun aktive creators, audit `creator_category_linked`/`creator_category_unlinked`).
  - 8 ✅: kategori-afstemning: oprindelsesmeta i `ProductStore`/`ProductRecord`, `ProductLinkSource::Category` (`Backfill` fjernet), intern afstemningsmetode `ProductCreatorLink::apply_category_link()` og tilstandsbaseret `CreatorCategoryReconciler` (én kategori eller ét produkt ad gangen, idempotent, tællinger). Ingen hooks, jobs eller UI.
  - 9 ✅: fuld afstemning i batches (`CategoryReconciliationBatch`): kandidater er parent-produkter i en mappet kategori eller med en gemt oprindelse (keyset-paginering, 100 pr. side), ét `run_id` pr. kørsel, tørkørsel uden skrivninger og audit, `category_sync_completed` efter sidste batch. Ingen hooks, jobs eller UI.
  - 9b ✅: hooks og Action Scheduler (tidligere trin 9; opdelt 2026-09-27): `set_object_terms`, `deleted_term_relationships`, gendannelse/overgang fra auto-draft (`transition_post_status`), `pre_delete_term`/`delete_term`, mapping-events og domæne-eventet `merchboard_creator_status_changed`. Handlerne sætter kun deduplikerede jobs i kø (pr. produkt eller pr. afgrænset kørsel), ingen fuld backfill. Fjernelse af en mapping kræver valget `remove_links`/`keep_as_manual` (det sidste afvises til trin 10-11). Et fejlet job gentages højst 3 gange (1, 5 og 15 min.).
  - 10 ✅: admin-UI for mapping på creatorens redigeringsside med tørkørsel og eksplicit bekræftelse ved oprettelse og valget "Remove category-managed links" / "Keep as manual links" (tørkørsel og bekræftelse) ved fjernelse. "Keep as manual" som massekonvertering i admin-requesten (højst 200 pr. kørsel, mappingen fjernes først, når intet er tilbage), og oprydning af mappings til slettede kategorier med samme valg.
  - 11: kolonnen "Produktkategorier" i Creators-listen ✅ (flyttet fra trin 10; skrivebeskyttet, én samlet hentning pr. side, mappings til slettede kategorier vises som "Slettet kategori"). Resten er trin 12a.
  - 12 (Local-verifikation) ✅ (2026-09-28): integrationstests og verifikation af hele kæden i Merch Dashboard Local (wp-admin, bulk edit, CSV-import, REST, duplicate og kategorisletning). Udført med projektejerens godkendelse mod Merch Dashboard Local (WordPress 7.1.2, WooCommerce 10.3.8) på plugin-versionen `983ceed` med WP-CLI og HTTP på midlertidige, syntetiske objekter, som blev ryddet op igen. Bestået: fixturens klassifikation (en fuld tørkørsel gav 0 ændringer), creator-login og dashboard, produktkoblinger og oprindelse, kategori-mapping og automatisk afstemning via Action Scheduler, manuel-vinder og konflikter, Keep as manual og Remove-blokering, produktlistens kolonne og filter, den klassiske produkteditor (også en gemning), quick og bulk edit, CSV-import, REST, duplicate, kategorisletning, vagten for beskyttet meta, `/me` uden vilkårligt creator-ID, ingen kunde-PII for creators og audit-log-labels. Ikke manuelt verificeret: blok-produkteditorens UI (kun indirekte via WooCommerce REST, som blev verificeret) og "Pluginet virker stadig uden WooCommerce" (ikke kørt i Local; dækket af automatiske tests).
  - 12a: creatorens produktafsnit viser oprindelsen ("Manuel"/"Produktkategori", med de mappede kategorier og om afstemningen vil fjerne eller flytte en kategori-tilknytning) og tilbyder "Keep as manual" pr. produkt med eksplicit bekræftelse ✅. (i commits, CHANGELOG og tidligere noter kaldt "trin 12")
  - 13 (uninstall): uninstall (begge meta-nøgler; tabellen via `Tables::all()`). Oprydningsmekanismen er besluttet i ADR-036: vagtens uninstall-vindue tillader kun sletning af de to nøgler under Merch Dashboards egen opt-in-uninstall (`WP_UNINSTALL_PLUGIN` + opt-in); `Uninstaller` bruger WordPress' meta-API. **Meta-nøglerne ✅ (ADR-036, implementeret 2026-09-26, verificeret 2026-09-27: opt-in, opt-out, manglende/forkert basename, skrivninger i vinduet, et andet plugins uninstall, idempotens, logning uden værdier og at vinduet lukker igen).** Tabellen `merchboard_creator_categories` er med i `Tables::all()` og slettes ved opt-in-uninstall (trin 7).
  - 13a (Remove): Remove afvises for kategori-styrede produkter (ADR-033 pkt. 5) ✅. `ProductCreatorLink::unlink()` afviser med `category_managed`, og produktafsnittet viser ingen Remove på dem, kun "Keep as manual" og en forklaring. Afstemning, fjernelse af mappings og "Keep as manual" er uændrede. (tidligere kaldt "trin 13, Remove")
  - **✅ ADR-035 (implementeret 2026-09-26):** metadata-vagten er en core-service i `MerchBoard\Core\Security` og registreres i `Plugin::register_hooks()` uden om `merchboard_modules`, så nøglerne er beskyttet uanset WooCommerce. `ProtectedMetaEntryPoints` bliver i WooCommerce-modulet.
  - **ADR-033, fuld afstemning ✅ (2026-09-27):** admin-værktøjet "Kategoriafstemning" på Creators-siden (`action=category-sync`, intet nyt menupunkt): tørkørsel (`CategoryReconciliationBatch::preview()`) → eksplicit bekræftelse med fingeraftryk af tørkørslen (en forældet tørkørsel starter intet) → asynkron kørsel via den eksisterende Action Scheduler-kæde (`CategoryReconciliationScheduler::run()` med fuldt scope). "Kræver opmærksomhed" viser tvetydige produkter, produkter hvis eneste mappede creator er inaktiv, og produkter, der ikke kunne vurderes.
  - **ADR-033, manuelle afvigelser ✅ (2026-09-27):** manuelle koblinger, der afviger fra produktets kategorier (anden aktiv creator, anden inaktiv creator eller tvetydige kategorier), vises under "Needs attention". Kun diagnostisk: udfaldet er fortsat `skipped_manual`, og intet skrives.
  - 14: audit-log-labels ✅ (2026-09-27): læsbare detaljer for produkt- og kategori-hændelserne (produkter og kategorier som "Navn (#ID)", koder, "Triggered by", forkortet `run_id`, optællinger som én linje) med samlede opslag pr. side.
  - 15: i18n, docs og slutverifikation ✅ (2026-09-28). Kriterierne:
    - dokumentationen (ROADMAP, PROJECT-STATUS, CHANGELOG, ADMIN, WOOCOMMERCE, TESTING, feature-dokumentet og ADR-statusnoterne) er konsistent med implementeringen og ADR-030 til ADR-036;
    - i18n-kontrollen giver ingen utilsigtet diff (en frisk POT har de samme strenge som `languages/merchboard.pot`, `.po` har ingen tomme eller fuzzy oversættelser, og `languages/*.json` ændres ikke kun pga. tidsstempler);
    - den fulde test-suite er grøn (`npm run lint`, PHPUnit, Jest, integrationstests og `git diff --check`);
    - ændringerne er pushet, og CI er grøn på alle jobs;
    - fase 17 afsluttes først, når trin 12's Local-verifikation er godkendt af projektejeren og bestået (✅ 2026-09-28).
  - **Nummerering:** 12a og 13a er udført under numrene "trin 12" og "trin 13, Remove" (2026-09-27). De oprindelige trin 12 (Local-verifikation) og 13 (uninstall) beholder deres numre.
  - Trin 8-9 kommer før mapping-UI'et (10), fordi tørkørslen ved oprettelse bruger afstemningens klassifikation.
- **Done:** Produkter kan kobles til creators manuelt og via kategori-mapping. Pluginet virker stadig uden WooCommerce. Den første afstemning giver de forventede klasser på fixture-datasættet i Merch Dashboard Local, en ny kørsel skriver intet, og kategoriændringer via wp-admin, bulk edit, CSV-import og REST afstemmes. Manuelle koblinger røres aldrig af afstemningen. **✅ Opfyldt 2026-09-28** (trin 12). "Pluginet virker stadig uden WooCommerce" er dækket af automatiske tests, ikke kørt manuelt i Local.

## Fase 18 – Sales ledger ✅

- **Scope:** migration for `merchboard_sales`, `SalesRecorder` (status-, refund- og delete-hooks), idempotens, reversals, konfigurerbare tællende statusser, backfill via Action Scheduler + WP-CLI-kommando. Forretningsreglerne er besluttet i ADR-037.
- **Ikke i fase 18:** fuld afstemning af ledgeren mod WooCommerce (senere job-type), `/me/orders` (fase 19), `/me/sales` og statistik (fase 20), payouts og revenue split.
- **Rækkefølge:**
  - 1 ✅ (2026-09-28): beslutningsrunde. ADR-037 og opdatering af DATABASE, WOOCOMMERCE, ROADMAP, PROJECT-STATUS og ARCHITECTURE. Kun dokumentation.
  - 2 ✅ (2026-09-28): **teknisk afklaring før implementering**, udført i tre runder: retningsbeslutninger (ADR-038), read-only discovery af WooCommerce 10.3.8's kildekode og verifikation af de sidste risici (`@@in_transaction` og `GET_LOCK()` på projektets MariaDB-testdatabase, hook-rækkefølgen ved sletning af ordrer og refunds, HPOS' gemning efter meta-ændringer). Resultat: ADR-038's supplement (fortegn med `correction`, tilstandsbaseret synkronisering pr. ordre, append-only `merchboard_sales_cycles`, cyklus 1 som brutto + refunds, korrektionstyper, `event_key`, lås + transaktion, `occurred_at`, fejlmarkør, triggere) og et **design-låst** skema i DATABASE.md. Ingen ny dependency er nødvendig. Resterende OPEN (ikke kritiske for skemaet): `@@in_transaction` på MySQL 8.0 (verificeres i CI ved implementeringen) og låsens timeout.
  - 3 ✅ (2026-09-28): migration 5 (`merchboard_sales` og `merchboard_sales_cycles` jf. DATABASE.md) og et repository: indsættelse i én transaktion med `event_key`-dubletter som registreret no-op, læsning af ledger- og cyklustilstand pr. ordre, opslag via `refund_id`, navngiven lås pr. ordre med kontrol af forbindelsen. Accept: unit- og integrationstests på MariaDB og MySQL 8 (CI) for skema og dbDelta-idempotens, `UNIQUE(event_key)` og `UNIQUE(order_id, cycle, event)`, `GET_LOCK()` med en ekstra forbindelse, rollback og transaktioner; begge tabeller er med i `Tables::all()` og opt-in-uninstall.
    - **Status (2026-09-28): implementeret og CI grøn** (commit `602cd50`, MySQL 8.0.46). Migration 5 (schema-version 5) med `merchboard_sales` og `merchboard_sales_cycles` og `SalesRepository` (lagring og læsning, ordrelås, én transaktion pr. skrivning, kontrol af forbindelsen). Integrationstests grønne på MariaDB 10.11.19; CI er grøn på MySQL 8.0.46. `@@in_transaction` bruges ikke: `wpdb` kan genforbinde stille efter en tabt forbindelse og gentage en sætning uden for transaktionen, så hver INSERT i stedet er betinget af låsens `CONNECTION_ID()` (se PROJECT-STATUS). Sales ledger understøtter ikke SQLite/Playground (teknisk begrænsning, se PROJECT-STATUS).
  - 4 ✅ (2026-09-29, commit `f1bf888`, CI grøn): `SalesRecorder` som ren planlægningslogik (ordre-snapshot + ledger-tilstand → rækker og cyklushændelser) uden hooks. Accept: unit-tests for hele livscyklussen (cyklus 1 brutto + refunds, delvis og fuld refund, lukning ved 0, ny cyklus med resterende netto, refund på lukket cyklus, sletning af refund, ændrede og fjernede linjer, gentagne kørsler uden ændring), variant → parent, linjer uden creator, fortegn, valuta, `occurred_at` og undtagelserne med fejlmarkør.
    - **Status (2026-09-29):** den rene planlægningslogik er implementeret som `SalesPlanner` med input-snapshots (`OrderSnapshot`, `OrderLineSnapshot`, `RefundSnapshot`), `SalesAmount` (1/10000-heltal, B1) og resultatet `SalesPlanResult` (rækker eller en fejlkode). Unit-testet (57 tests, bl.a. alle livscyklus-scenarier med idempotens og invariant-kontrol). Ingen database, hooks eller fejlmarkør. To fortolkninger er gjort normative for trin 5 (ADR-038, statusnote 2026-09-29).
  - 5 ✅ (2026-09-29, commit `366714c`, CI grøn inkl. MySQL 8.0.46): synkron registrering fra triggerne i ADR-038 med fejlmarkør og re-entrancy-vagt. Accept: integrationstests for salg, refund (hel og delvis), annullering efter completed, gentagne hooks, papirkurv og gendannelse (ingen ændring), permanent sletning (begge hooks, én lukning), sletning af en refund (én korrektion), en ordre oprettet direkte i tællende status og en fejl, der sætter og siden rydder markøren.
    - **Status (2026-09-29):** `SalesSynchronizer` (vagt mod genindtræden, sletningsvagt, billigt forhåndstjek, ordrelås, læsning uden for transaktionen, planner, fejlmarkør og log med id'er), `WooCommerceOrderSnapshotReader` (CRUD med `edit`-kontekst, refunds via `wc_get_orders()`, creator via `ProductCreatorLink`), `WooCommerceAmount`, `CountedOrderStatuses` (`processing`, `completed`), `WooCommerceSalesFailureMarker` (`_merchboard_ledger_failure`, HPOS-gemning undertrykt), `SalesLedgerHooks` (registreret i `WooCommerceModule`), `SalesConnectionChangedException` og uninstall af markøren. Unit-tests og integrationstests på MariaDB grønne; runtime-verificeret 2026-09-29 i Merch Dashboard Local (WordPress 7.1.2, WooCommerce 10.3.8) med syntetiske data og scriptede WP-CLI-scenarier: 25/25 checks i HPOS og 25/25 i legacy (ADR-038, Q4 og statusnote "trin 5 afsluttet"). Uninstall-forespørgslen er efter verifikationen rettet til `meta_key` + `meta_compare => EXISTS`, fordi legacy ignorerer `meta_query`.
    - **Beslutninger (2026-09-29, ADR-038 statusnote Q1–Q8):** refund-sletning via `deleted_post` og `woocommerce_refund_deleted` (ikke `woocommerce_delete_order_refund`), `SalesConnectionChangedException`, sletningsvagt pr. request, `unexpected` for ugyldige beløb og brøkdelte antal, synkron behandling af andre ordrer under deres egen lås, når låse- og transaktionskonteksten tillader det (ellers fejlmarkør og log på den berørte ordre, ingen retry eller kø), papirkurv uden markør, og uninstall af markøren via CRUD. Integrationstestene bruger en rigtig database med en test-reader; scenarierne med en rigtig WooCommerce verificeres i trin 8.
  - 6 ✅ (2026-09-30, commit `906dc51`, CI grøn inkl. MySQL 8.0.46, run `36754285160`; ADR-038 statusnoter 2026-09-30: statusreglen præciseret og trin 6 afsluttet, runtime-verificeret med HPOS og legacy): indstillingen for tællende statusser (standardværdien, i designet `processing` og `completed`, bekræftes her). Accept: central indstilling med passende capability, nonce, sanitize-whitelist og audit; ændringer gælder kun fremadrettet.
    - **Beslutninger (2026-09-29, ADR-038 statusnote "ROADMAP trin 6", S1–S14):** nøglen `counted_order_statuses` i `merchboard_settings`; service og UI i WooCommerce-modulet via det nye udvidelsespunkt `merchboard_settings_sections`; capability `merchboard_manage_settings` (før nonce); alle registrerede statusser (`wc_get_order_statuses()`) undtagen `pending`, `failed`, `cancelled`, `refunded`, `checkout-draft` og `trash`; tom liste afvises; standard `processing` + `completed` (bekræftet); statusser, der ikke længere er registreret, bevares og markeres; afvisningsliste og fallback til standarden også ved læsning; audit med lister, vist som `a, b`. "Kun fremadrettet" betyder synkroniseringer efter ændringen, også af eksisterende ordrer; ingen backfill. Implementeret og runtime-verificeret (se ovenfor).
  - 7 ✅ (2026-09-30; commit `00fb066`, pushet, CI grøn inkl. MySQL 8.0.46, run `36887796803`; ADR-038 statusnote "ROADMAP trin 7", D1–D6 med præciseringen af D6; runtime-verificeret i Merch Dashboard Local (WordPress 7.1.2, WooCommerce 10.3.8) med syntetiske data i HPOS og legacy): backfill via Action Scheduler og WP-CLI. Accept: dry-run, batching, idempotent genkørsel og et resumé med passende audit; tilskrivning ud fra den aktuelle creator-kobling.
    - **Beslutninger (2026-09-30):** kun ordrer uden ledger-historik (ingen genberegning eller afstemning); `wc_get_orders()` med offset-paginering; dry-run med kun tællinger; separat backfill-indgang med uændret `SalesSync`; `sales_backfill_completed`; `wp merchboard sales-backfill` som trigger for Action Scheduler, én kørsel ad gangen, ingen automatisk retry, eksplicit genoptagelse af et `failed` job, men ingen garanti for genoptagelse efter Action Schedulers oprydning (så startes kørslen forfra).
  - 8 ✅ (2026-10-01; ADR-038 statusnote "ROADMAP trin 8 afsluttet"): verifikation i Merch Dashboard Local med rigtige WooCommerce-ordrer (kræver projektejerens godkendelse). Omfatter de WooCommerce-afhængige scenarier fra trin 5 som scriptede WP-CLI-scenarier: HPOS og legacy, `wc_create_refund()`, sletning af refunds via admin, papirkurv, gendannelse og permanent sletning, forældet refund-cache og hook-rækkefølgen (ADR-038, Q4). Ingen WooCommerce-dev-dependency i test-suiten. De WooCommerce-afhængige scenarier fra trin 5 er udført 2026-09-29 (se trin 5).
    - **Status (2026-10-01):** regressionsrunde på `00fb066` med godkendelse: alle Q4-scenarier og et samspilsscenarie (en ordre registreret af backfillen, derefter live refund, refund-sletning via admin-vejen, papirkurv og gendannelse og permanent sletning) bestod i HPOS og legacy. Afvigelser: refund-sletning via admin er verificeret som `delete()` + `woocommerce_refund_deleted` (som `WC_AJAX::delete_refund()`), ikke via en HTTP-AJAX-anmodning; den forældede refund-cache er en legacy-adfærd, som HPOS-kørslen ikke tester reelt. Testdataene er fjernet; audit- og Action Scheduler-historikken er bevaret.
  - 9 ✅ (2026-10-01, ADR-038 statusnote "fase 18 afsluttet"): i18n, docs og slutverifikation (som fase 17's trin 15: konsistente docs, i18n uden utilsigtet diff, grøn suite, pushet og grøn CI). Docs og i18n er gennemgået og suiten er grøn lokalt; commit, push og grøn CI er afslutningen af trinnet.
- **Done:** Integrationstests for sale, refund (hel og delvis), cancel efter complete og gentagne hooks. **✅ Opfyldt 2026-10-01:** `SalesSynchronizerTest` (salg, statusskift med reversal og ny cyklus, delvis og fuld refund, sletning af en refund, gentagne hooks), sammen med planner-, repository- og backfill-testene og runtime-verifikationen i Merch Dashboard Local (trin 5–8, HPOS og legacy).

## Fase 19 – Products og Orders i dashboard

- **Scope:** `/me/products`, `/me/orders` (uden kunde-PII), sektionerne Products og Orders (responsive tabeller, paginering, empty states).
- **Done:** IDOR-tests (ordre med anden creators produkt). Privacy er bekræftet af virksomheden.

## Fase 20 – Sales & statistics

- **Scope:** `/me/sales` (aggregeringer pr. dag/uge/måned, pr. produkt), caching og invalidering, valg og integration af chart-bibliotek (ADR), Sales-sektion og KPI'er på Overview. Admin: Sales-tab pr. creator.
- **Done:** Queries er målt på realistisk datamængde, og grafer er tilgængelige (tabel-alternativ).

---

## Senere (uprioriteret)

| Område | Indhold |
| --- | --- |
| Payouts | Afregningsperioder, beregning fra ledger, status, eksport og evt. integration med betalingsudbyder |
| Notifications | E-mail (via `wp_mail`) og in-app. Fx "nyt salg" og "udbetaling klar" |
| Integrations | Framework for eksterne tjenester med webhooks og HMAC. Hver konkret tjeneste kræver projektejerens eksplicitte godkendelse og en ADR. Dinero og Google Drive er fravalgt (ADR-016). |
| Campaigns | Tidsbegrænsede kampagner/drops pr. creator |
| Support | Henvendelser fra creators til virksomheden |
| Reporting | Admin-rapporter på tværs af creators og CSV-eksport |
| Security hardening | Fuld CSP med nonces, rate limiting på `/me`-endpoints, GDPR exporter/eraser, security-review før produktion |
| Testing | Playwright E2E mod Playground i CI |
| Teams | Flere brugere pr. creator |
| Impersonation | "Se som creator" for admins med audit |
| Branding | Branded login-side, dark mode og brand-font (self-hosted) |
