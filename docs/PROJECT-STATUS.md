# Project status

**Sidst opdateret:** 2026-09-25 · **Seneste færdige fase:** 16 (Release-pipeline og staging ✅) · **Næste fase:** 17 (WooCommerce foundation) · **Schemaversion:** 3 · **Version:** 0.1.0 (tagget `v0.1.0`, draft-release, ikke udgivet) · **Naming:** `Merch Dashboard` / `merchboard` / `MerchBoard\` (ADR-018)

## Completed

### Fase 1 – Project foundation ✅

- [x] Analyse af udgangspunkt (tomt repo). Se [reports/2026-09-24-initial-analysis.md](reports/2026-09-24-initial-analysis.md).
- [x] Repo-grundfiler: `.gitignore`, `.gitattributes` (LF), `.editorconfig`, `.nvmrc`.
- [x] Plugin-entry `merchboard.php` (kun header og ABSPATH-guard).
- [x] Composer (projekt-lokalt via `bin/composer.mjs`), PSR-4-autoload `MerchBoard\` → `src/`.
- [x] PHPCS + WordPress Coding Standards 3.4, PHPStan 2.2 level 8 med WordPress-stubs.
- [x] npm + `@wordpress/scripts` 35 (ESLint, Prettier, stylelint; build fra fase 13).
- [x] Lokal WordPress via WordPress Playground (`npm run wp:start`), WooCommerce via blueprint. Verificeret: WP 7.1.2, PHP 8.2, WooCommerce og Merch Dashboard er aktive.
- [x] GitHub Actions CI: PHP-syntaks 8.2, PHPCS, PHPStan og ESLint.
- [x] Dokumentation: arkitektur, moduler, database, API, frontend/design system, admin, WooCommerce, security, roller, test, udvikling, ADR 001-016 (ADR-017 tilføjet i fase 2), roadmap, feature-specs og AI-guidelines.
- [x] Project Isolation udvidet til eksterne services og connectors. **Dinero og Google Drive er ikke en del af projektets nuværende eller planlagte integrationsarkitektur** og må ikke bruges (ADR-016).

### Fase 2 – Plugin bootstrap ✅

- [x] Hovedfil: ABSPATH-guard, dobbelt-load-guard, `MERCHBOARD_VERSION`, versionstjek, autoloader og `Plugin`. Ingen logik i øvrigt.
- [x] `MerchBoard\Core\Requirements`: PHP ≥ 8.2 og WP ≥ 6.7. Ellers en escaped admin notice (kun for `activate_plugins`) og ingen fatal error. PHP 7.2-syntaks, tjekket med PHP 7.4 lokalt og 7.2 i CI.
- [x] `MerchBoard\Core\Autoloader`: egen PSR-4-autoloader med validering af klassenavne (ADR-017). `vendor/` bruges ikke ved runtime.
- [x] `MerchBoard\Plugin`: `plugins_loaded` → modul-boot via `merchboard_modules` (validering, idempotens) → `merchboard_loaded`. HPOS-erklæring på `before_woocommerce_init`.
- [x] `MerchBoard\Core\Container` (lazy, typesikker, cirkel-detektion) og `MerchBoard\Core\Module`.
- [x] `uninstall.php`-skelet (sletter intet, der er ingen data) og `.distignore`.
- [x] PHPUnit 11 + Brain Monkey: 38 unit-tests. `npm test` / `composer test`. CI: PHPUnit og PHP 7.2-syntaksjob.
- [x] Smoke test i Playground (WP 7.1.2, PHP 8.2, WooCommerce 11.1.2): aktiveret, deaktiveret og genaktiveret uden fatals, notices eller `debug.log`. Forside, wp-admin og REST svarer 200. WooCommerce markerer ikke pluginet som HPOS-inkompatibelt.

**Bevidst ikke i fase 2** (se [ARCHITECTURE.md §5](ARCHITECTURE.md#5-boot-flow)): activation hook (fase 3), deactivation hook (fase 4), text domain-loading (fase 15), roller/capabilities (fase 5), database (fase 3), admin-menuer og frontend (fase 9 og 12).

### Fase 3 – Database-fundament og creator-tabel ✅

- [x] Tabel `{prefix}merchboard_creators` (schema-version 1): `id`, `user_id` (nullable, UNIQUE), `display_name`, `status`, `created_at`, `updated_at` (UTC). Ingen kundedata. Ingen FK.
- [x] Migrationssystem (ADR-019): `Tables` (whitelist), `Schema::migrations()`, `Migration`, `Migrator` (option `merchboard_db_version`, gemt efter hver succes, `add_option`-lås).
- [x] `Installer`: activation hook (idempotent) og versionstjek på `plugins_loaded` før moduler. Fejl-backoff (5 min) og admin notice.
- [x] Creator-domænemodel (ADR-020): `Creator` (readonly), `CreatorStatus` (`active`/`inactive`), `CreatorRepository` (`find`, `find_by_user_id`, `create`; prepared queries, ID-validering, generiske fejl), `CreatorsModule`.
- [x] Uninstall-politik: data bevares. Sletning bliver opt-in i fase 9. Deaktivering rører aldrig data.
- [x] 113 unit-tests (209 assertions). Lint og PHPStan level 8 er grønne.
- [x] Playground-runtime (SQLite, WP 7.1.2, PHP 8.2, WooCommerce): schema installeret ved aktivering, repository create/find, UNIQUE afviser duplikat og tillader flere NULL, UTC-tidsstempler, idempotent genkørsel, opgradering 0 → 1, data bevaret ved deaktivering/genaktivering, ingen fatals/notices, REST og wp-admin svarer normalt.

**Bevidst ikke i fase 3:** creator-rolle/capabilities (5), CreatorService, update og lister (6), adgang/login (8), admin-UI (10), `/me`-API (14), `merchboard_creatormeta` (14), WooCommerce-data og ledger (17-18), slet-data-rutine (9).

**MySQL/MariaDB:** MariaDB 10.11.19 verificeret i fase 7. MySQL afventer CI-resultatet. Se [DATABASE.md](DATABASE.md#mysqlmariadb-vs-playground-sqlite).

### Fase 4 – Logging og fejlhåndtering ✅

- [x] `Logger`-interface + `PluginLogger` i containeren (injected, ingen singleton/statiske kald). Niveauer: debug, info, warning, error (1:1 WooCommerce/PSR-3).
- [x] Backend: WooCommerce' logger (source `merchboard`) når WooCommerce er aktivt **og** initialiseret, ellers `error_log()`. WooCommerce er ikke et krav, og en fejlende WooCommerce-logger falder tilbage uden tab (ADR-021).
- [x] Tærskel: `debug` ved `WP_DEBUG`, ellers `warning`. Filter `merchboard_log_level`. Pluginet ændrer aldrig debug-indstillinger.
- [x] `LogContext`: maskering af følsomme nøgler, exceptions uden fremmede beskeder/traces, kontroltegn fjernet, længde- og dybdegrænser.
- [x] Migrationsfejl: `Migrator` lægger renset diagnostik i `DatabaseException::context()`, og `Installer` logger én gang som `error`. Semantikken er uændret (version gemmes ikke, 5-minutters pause, generisk admin notice).
- [x] Den eneste `error_log()`-forekomst i `Installer` er erstattet. `error_log()` findes nu kun i `ErrorLogWriter` (backenden).
- [x] 165 unit-tests (294 assertions). Lint og PHPStan level 8 er grønne.
- [x] Playground med **og** uden WooCommerce: logger virker, følsomme værdier er maskeret, fremtvunget migrationsfejl er logget, versionen er ikke opdateret, pausen er sat, låsen er frigivet, admin notice er generisk (kun for admins), ingen PHP-notices, deaktivering/genaktivering er OK, og REST og wp-admin svarer 200.
- [x] Fundet og rettet via Playground: WooCommerce-loggeren kaldt før `woocommerce_init` udløste en `_load_textdomain_just_in_time`-notice, og SQLite-driverens `last_error` indeholdt HTML/backtrace.

**Ændret plan:** ingen log-tabel, channels eller retention-cron (ADR-021 erstatter ADR-012). Audit trail → fase 6, audit-visning → fase 11, deactivation hook → fase 12.

### Fase 5 – Roller og capabilities ✅

- [x] `Capabilities`: konstanter og definitioner (single source of truth). Pluginet ejer `merchboard_*`.
- [x] Rolle `merchboard_creator` ("Merch Dashboard Creator") med **præcis** `read` + `merchboard_access_dashboard`.
- [x] Admin-capabilities `merchboard_manage`, `merchboard_manage_creators` og `merchboard_manage_settings` på `administrator`. Administratorens øvrige capabilities er uændrede.
- [x] `RoleManager`: deklarativ, idempotent sync via role-API. Creator-rollen holdes på præcis sit sæt (eskalering fjernes og logges), andre roller og brugere røres aldrig, og der er egen version `merchboard_roles_version` (ADR-022).
- [x] Integreret i `Installer`: aktivering og versionstjek på `plugins_loaded`. Deaktivering og uninstall bevarer rolle og caps.
- [x] 204 unit-tests (391 assertions). Lint og PHPStan level 8 er grønne.
- [x] Playground med **og** uden WooCommerce (identiske resultater): rolle og caps oprettet, administratoren fik kun de 3 plugin-caps (intet fjernet eller ændret), andre roller uændrede, rigtig `user_can()` (admin: plugin-admin ja og dashboard nej; creator og customer+creator: kun dashboard; subscriber: intet; ikke-definerede caps: ingen), eskaleringsforsøg fjernet og logget, anden sync ændrer intet, deaktivering/genaktivering bevarer rolle og caps, ingen fatals/notices, og forside, wp-admin og REST svarer 200.

**Bevidst ikke i fase 5:** creator-adgang (capability + aktiv record), meta-caps og wp-admin-blokering (fase 8), `merchboard_view_logs` (11), rolle-oprydning ved uninstall (9), admin-UI, REST, frontend og `/me`.

### Fase 6 – Creator-administration og audit trail ✅

- [x] Migration 2: `merchboard_audit_log` (append-only, actor nullable, UTC, minimal JSON-context). Schemaversion 2.
- [x] `AuditAction` (6 actions, lukket sæt), `AuditTrail` (server-side actor/tid, renset context) og `AuditRepository` (kun append/for_creator).
- [x] `Transaction`: ægte databasetransaktion. Creator-ændring og audit committes sammen eller slet ikke (ADR-023).
- [x] `CreatorRepository`: rename, set_status, link_user, unlink_user, paginate og linked_user_ids. Ingen delete.
- [x] `CreatorService`: capability-tjek, validering (navn, bruger findes, ikke koblet andetsteds), én audit-record pr. semantisk ændring, rolle `merchboard_creator` tildeles/fjernes efter commit.
- [x] Admin: "Merch Dashboard → Creators" (`WP_List_Table`, statusfilter, sortering), opret/rediger (default inaktiv), aktivér/deaktivér-links, tilknyt/frakobl bruger. `admin-post.php` med capability → nonce (bundet til ID) → input → PRG med whitelistede notices.
- [x] 278 unit-tests (661 assertions). Lint og PHPStan level 8 er grønne.
- [x] Playground via **det rigtige admin-UI**, med og uden WooCommerce: fuldt flow (opret, tilknyt, omdøb, deaktivér, aktivér, frakobl), 6 audit-records med actor 1 og UTC-tid, følsom testmarkør ikke i audit, rolle tildelt som ekstra rolle og fjernet igen, ugyldig/forfalsket/fremmed nonce → 403, subscriber og tilknyttet creator-bruger → 403, ukendt bruger/status/tomt navn → pæne fejl, deaktivering/genaktivering bevarer data, **ægte rollback i SQLite** (probe), ingen fatals/notices, og REST og wp-admin svarer 200.
- [x] Fundet og rettet via Playground: enum i `selected()` gav fatal på formularen (regressionstest tilføjet).

**Bevidst ikke i fase 6:** audit-visning og `merchboard_view_logs` (11), meta-capabilities, creator-adgang og `deleted_user`-hook (8), permanent sletning (9), dashboard, REST, `/me`, WooCommerce-data.

### Fase 7 – Database-hardening og MySQL/MariaDB-verifikation ✅

- [x] Revision af migrationer, `Migrator`, `Installer`, repositories, audit og `Transaction` mod MySQL/MariaDB-semantik. Schemaet var korrekt og uændret: ingen ny migration, schemaversion stadig 2.
- [x] **Migrationslås** omskrevet: `add_option()` var ikke en lås på MySQL/MariaDB (demonstreret). Nu atomisk `INSERT IGNORE`, compare-and-swap ved overtagelse og ejer-token ved frigivelse.
- [x] **Fail closed** uden transaktionel engine: `TransactionSupport` (`information_schema`) plus admin-notice (valgt af projektejeren, ADR-024).
- [x] **Audit-context** som ASCII-JSON. Malformed context fejler ikke længere skrivningen.
- [x] **Integrationstests** (`npm run test:integration`): rigtig WordPress 7.1.2 og **MariaDB 10.11.19** (portabel, SHA-256-verificeret, kun under testkørslen). 53 tests grønne. CI-job `PHP integration (MySQL)` tilføjet.
- [x] 299 unit-tests (715 assertions). Lint og PHPStan level 8 er grønne.
- [x] Playground (SQLite) med **og** uden WooCommerce: fuldt admin-flow uændret, 6 audit-records, 403 for uautoriserede og forkerte nonces, rollback-probe, engine-tjek (`sqlite` → tilgængelig), låsens SQL-semantik, migration 1 → 2 med ny lås, deaktivering/genaktivering bevarer data, og forside, wp-admin og REST svarer 200. Ingen notices fra pluginet. Én WooCommerce-textdomain-notice opstod i blueprintens WooCommerce-installationstrin, før Merch Dashboard blev aktiveret.
- [x] Verificeret med og uden rettelserne: de nye audit-integrationstests fejler med den gamle JSON-kodning.

**Bevidst ikke i fase 7:** REST, dashboard, nye admin-sider, sletning, audit-visning, `deleted_user`-hook og WooCommerce-logik.

### Fase 8 – Creator access og brugerrelation ✅

- [x] `Modules/Access/CreatorContext`: den eneste adgangspolitik. **Adgang = capability `merchboard_access_dashboard` + creator-record koblet til brugeren + `status = active`.** `current()`, `current_user_has_access()`, `for_user()` og `user_has_access()`. Creator'en findes altid ud fra brugerens ID, aldrig ud fra klient-data. Databasefejl logges og giver "ingen adgang".
- [x] `deleted_user`: WP-bruger slettes → creator bevares → `user_id = NULL` → `status = inactive` → audit-records (systemhandling, actor NULL). Én transaktion (fail closed), betinget frigivelse (race mod gen-kobling), kun for brugere der ikke længere findes. Fejl afbryder ikke WordPress' sletning, men logges.
- [x] `AuditTrail::record_system()` (actor NULL). Eksisterende actions genbruges med `reason: user_deleted`. Ingen ny action og ingen schemaændring.
- [x] 327 unit-tests (800 assertions) og 62 integrationstests mod MariaDB 10.11.19 (tre kørsler med forskellige seeds). Lint og PHPStan level 8 er grønne.
- [x] Playground med **og** uden WooCommerce, alle 15 trin: opret bruger og creator, kobl, rolle, capability, adgang, deaktivér (ingen adgang, rolle bevaret), aktivér, frakobl (ingen adgang, kun creator-rollen fjernet), kobl igen og `wp_delete_user()`. Derefter findes creator'en, er `inactive` med `user_id = NULL`, og der er to audit-records med actor NULL. Administrator har ikke creator-adgang, og creator har ingen admin-caps. Fase 6-UI-flowet er uændret, og forside, wp-admin og REST svarer 200. Ingen PHP-notices.

**Bevidst ikke i fase 8:** dashboard, REST, `/me`, login-redirect, wp-admin-blokering, admin bar, application passwords (fase 12), meta-capabilities (fase 14), WooCommerce, audit-visning og sletning.

### Fase 9 – Lifecycle, settings og permanent sletning ✅

- [x] Lifecycle dokumenteret og regressionstestet:

```text
Deaktivering
→ data bevares (tabeller, options, rolle og capabilities uændrede)

Afinstallation (slet plugin) uden eksplicit opt-in
→ data bevares

Afinstallation med eksplicit opt-in ("Delete all Merch Dashboard data when the plugin is deleted")
→ Merch Dashboards egne data slettes
→ WordPress- og WooCommerce-data bevares
```
- [x] Fuld inventory af plugin-ejet state (tabeller, options, transient, rolle, capabilities på roller og brugere). Se [DATABASE.md](DATABASE.md#afinstallation-og-datalivscyklus).
- [x] Merch Dashboard → Settings: opt-in (default fra, `merchboard_manage_settings`, nonce, skrevet `DELETE`, server-side tjek, audit `settings_updated`). Kun præcis `true` aktiverer.
- [x] `uninstall.php` → `Uninstaller`: brugere → roller → tabeller → options → settings sidst. Idempotent, ingen falsk transaktion, opt-in bevares ved fejl. Kun præcise plugin-navne (fundet og rettet: prefix-baseret oprydning ville fjerne fremmede `merchboard_*`-roller og -capabilities). Ingen audit under uninstall.
- [x] Multisite verificeret i WordPress' kildekode: kun det aktuelle sites data. Andre sites røres ikke.
- [x] 358 unit-tests (889 assertions), 69 integrationstests mod MariaDB 10.11.19 (to kørsler). Lint og PHPStan level 8 er grønne.
- [x] Playground med **og** uden WooCommerce, scenarier A-E via WordPress' egen `uninstall_plugin()` (filer slettes aldrig):
  - Deaktivering, genaktivering og uninstall uden opt-in ændrer intet.
  - Opt-in uden bekræftelse afvises.
  - Uninstall med opt-in fjerner tabeller, options, rolle og kun de 3 admin-caps (118 → 115 med WooCommerce, 64 → 61 uden).
  - Brugeren beholder subscriber og custom-rolle, og custom-rollen er urørt.
  - Posts og WooCommerce-produkt, -ordre og -kunde overlever.
  - Gentagen uninstall er sikker.
  - Settings-UI over HTTP: bekræftelse påkrævet, manglende/forfalsket nonce giver 403, creator og subscriber får 403, slå til/fra virker, og der skrives audit.
  - Ingen notices, og forside, wp-admin og REST svarer 200.

**Bevidst ikke i fase 9:** Overview (fase 10), settings-tabs uden indhold, multisite-oprydning på tværs af sites, dashboard, REST, login-redirect og WooCommerce-logik.

### Fase 10 – Admin creator management (rest) og Overview ✅

- [x] Topmenu "Merch Dashboard" (`merchboard`, `merchboard_manage`) i `Modules/Admin`. Creators og Settings er submenuer med egne capabilities. Hver side og handler tjekker selv.
- [x] Overview: creator-tal, dinglende links, schema, migrationsfejl, engine, rolle-sync, WooCommerce og sletning ved uninstall. Ingen opfundne tal, ingen personoplysninger.
- [x] Søgbart brugervalg: login-felt (server-side opslag) og AJAX-forslag (`merchboard_manage_creators` + nonce, kun login og display name, aldrig e-mail, maks. 20, koblede brugere udelades). 500-brugers-grænsen er fjernet.
- [x] Profil-link i formularen (`edit_user`) og infoboks på `user-edit.php`/`profile.php` (`merchboard_manage_creators`).
- [x] 374 unit-tests (961 assertions) og 76 integrationstests mod MariaDB 10.11.19. Lint (PHPCS, PHPStan level 8, ESLint) er grøn.
- [x] Playground over HTTP, med og uden WooCommerce: menu, Overview, direkte adgang (subscriber og creator får 403 overalt, kun-`manage_creators` får Creators 200 og Overview/Settings 403), søgning (nonce, 403 uden, ingen e-mail), gem via login, profilboks og forudfyldning, uændret settings-bekræftelse, ingen notices.

**Bevidst ikke i fase 10:** bulk actions (valgfrit), seneste audit-hændelser (fase 11), dashboard, REST, login-redirect og WooCommerce-logik.

### Fase 11 – Admin audit-log viewer ✅

- [x] Capability `merchboard_view_logs` (administrator, `RoleManager::VERSION` 2, eksisterende sites synkroniseres, fjernes ved opt-in-uninstall).
- [x] Merch Dashboard → Audit log: read-only `WP_List_Table`, 20 pr. side (`COUNT` og `LIMIT/OFFSET` i databasen), nyeste først. Filtre: hændelse, creator og periode (sitets tidszone → UTC). Ugyldige værdier ignoreres.
- [x] Context vises læsbart via whitelist (ingen rå JSON, ingen e-mail). "System" for NULL-actor, "#ID (no longer exists)"/"(not found)" for slettede brugere og creators. Profil- og redigeringslinks kun med de rette capabilities.
- [x] Tekniske logs vises ikke. Link til WooCommerce → Status → Logs eller note om PHP's fejllog. Ingen ny tabel (ADR-021).
- [x] Overview: seneste 5 hændelser kun med `merchboard_view_logs`.
- [x] 397 unit-tests (1056 assertions) og 81 integrationstests mod MariaDB 10.11.19. Lint (PHPCS, PHPStan level 8, ESLint) er grøn.
- [x] Playground over HTTP, med og uden WooCommerce:
  - 27 records, 2 sider, og alle tre filtre virker. Ugyldige filtre ignoreres med notice.
  - Escaping, ingen e-mail, ingen rå JSON, ingen POST-formularer eller bulk actions. Rækker er uændrede efter visning.
  - Subscriber, creator og creator-manager får 403. En rolle med kun `read` + `merchboard_view_logs` får 200 på Audit log og 403 på Overview og Creators (uden WooCommerce; med WooCommerce blokerer WooCommerce' egen wp-admin-regel roller uden `edit_posts`).
  - Ingen notices.

**Bevidst ikke i fase 11:** retention/sletning af records (åbent spørgsmål 10, senere lukket for v0.1.0 af ADR-029), eksport, tekniske logs i admin og alt fra fase 12+.

### Fase 12 – Creator dashboard: route og sikker shell ✅

- [x] Dashboard-URL `/creator-dashboard/` (åbent spørgsmål 8 lukket). Slug'en kan ændres under Merch Dashboard → Settings.
- [x] `Modules/Dashboard`: `DashboardRoute` (rewrite rule øverst, query vars `merchboard_dashboard` og `merchboard_dashboard_path`, deep links, `/?merchboard_dashboard=1` uden pretty permalinks), `ShellController` på `template_redirect` (prioritet 0) og `ShellResponse`.
- [x] Auth-gate kun via `CreatorContext`: logget ud → login med `redirect_to` til siden, uden creator-adgang → 403 "Your access is not active" med log ud-link (aldrig link til wp-admin, afslører ikke hvorfor), creator → shell. Creator'en tages aldrig fra URL'en.
- [x] `templates/dashboard/shell.php` og `no-access.php`: komplette HTML-sider uden theme, `wp_head()` og JavaScript. Alt escaped.
- [x] Headers: `nocache_headers()`, `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy`, `nosniff`, `X-Frame-Options: SAMEORIGIN` og CSP `default-src 'none'` (ingen scripts), `frame-ancestors 'self'`.
- [x] Rewrite rules: gemte regler slettes ved aktivering, deaktivering (ny `Deactivator`) og slug-ændring, så WordPress genopbygger dem ved næste request. Mangler den aktuelle regel (fx efter en plugin-opdatering), sker det samme automatisk.
- [x] Dashboard-slug i Settings: egen formular og nonce, `merchboard_manage_settings`, validering (format, reserverede WordPress/WooCommerce-stier, eksisterende indhold og rewrite-baser), audit `settings_updated` med gammel og ny værdi.
- [x] **Rene creators** (ADR-026: `merchboard_access_dashboard` uden `merchboard_manage`, `edit_posts` og `manage_woocommerce`, også inaktive): `LoginRedirect` (`login_redirect` og `woocommerce_login_redirect`, kun validerede dashboard-deep-links respekteres), `AdminAreaGuard` (`admin_init` prioritet 0 og `admin_page_access_denied`; AJAX, `admin-post.php`, `async-upload.php`, cron, REST og CLI undtaget), skjult admin bar og Application Passwords slået fra (både oprettelse og brug).
- [x] Fundet og rettet: `CreatorRepository::find_by_user_id()` behandlede en fejlet forespørgsel som "ingen creator". Adgangen blev nægtet, men fejlen blev ikke logget. Nu kastes `DatabaseException`, og `CreatorContext` logger den (stadig fail closed).
- [x] 510 unit-tests (1419 assertions) og 91 integrationstests mod MariaDB 10.11.19. Lint (PHPCS, PHPStan level 8 inkl. `templates/`, ESLint) er grøn.
- [x] Playground over HTTP, med og uden WooCommerce:
  - Logget ud: login-redirect med deep link. En ondsindet deep link giver startsiden.
  - Ren creator: login lander på dashboardet (også med `redirect_to` til wp-admin, en ekstern host eller `//evil`), gyldigt deep link respekteres, 200 med escaped navn, ingen script, ingen admin bar, alle headers. wp-admin, `profile.php`, `options-general.php`, `plugins.php` og pluginets Settings → dashboardet. AJAX og admin-post undtaget. Log ud virker.
  - Application Passwords: oprettelse via REST afvist (501 `application_passwords_disabled_for_user`), eksisterende password afvist (401), editor + creator accepteret (200).
  - Inaktiv creator og rolle uden record: 403-side, wp-admin → dashboardet. Subscriber og administrator uden creator: 403 på dashboardet, wp-admin uændret. Editor og shop manager, der er creators: dashboard 200, wp-admin 200 og admin bar.
  - WooCommerce "Min konto"-login sender rene creators til dashboardet og andre til "Min konto".
  - Slug: reserveret, eksisterende side og ugyldig afvist uden audit, forfalsket nonce og creator 403, gyldig ændring audit-logget, ny URL 200, gammel 404, redirects følger med.
  - Ingen notices og ingen secrets i logs.

**Bevidst ikke i fase 12:** frontend-build og React (fase 13), `/me`-API (14), branding, Access-settings (til/fra-knapper), rewrite-status på Overview, meta-capabilities og at logge sessioner ud ved deaktivering.

### Fase 13 – Dashboard frontend-fundament ✅

- [x] TypeScript besluttet (åbent spørgsmål 3 lukket, ADR-027): React via `@wordpress/element`, TypeScript strict, `@wordpress/scripts`, REST i `merchboard/v1`, ingen global state-library. Backend er autoritativ for adgang.
- [x] `tsconfig.json` (strict, `noImplicitAny`, `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`, `noUnused*`, `verbatimModuleSyntax`, `noEmit`), `jest.config.js` (kun `assets/src`), npm scripts `build`, `start`, `typecheck` og `test:js`. `npm run lint` omfatter nu stylelint og `tsc`, og `npm test` omfatter Jest.
- [x] `assets/src/dashboard`: entry og mount, bootstrap-validering, router (pretty og query-fallback, deep links, tilbage og refresh), layout (skip link, topbar, sidebar ≥ lg, mobil-nav med `Esc`), `OverviewSection` (placeholder), "Page not found", ErrorBoundary, API-klient med typed resultat og runtime-validering, `useApi`, komponenter og design tokens. Kun `.merchboard-*`-CSS.
- [x] PHP: `DashboardAssets` (validerer `build/dashboard.asset.php`, registrerer script og style med RTL, bootstrap inline med hex-escaping), `ShellController` renderer React-shellen, 503 uden build, CSP-nonce på alle scripts, 403-siden uden JavaScript. Bootstrap indeholder ingen creator-data.
- [x] Dev-dependencies: `typescript` 6.0.3, `@types/react`/`@types/react-dom` 19.3.0, `@wordpress/element` 8.8.0, `@wordpress/api-fetch` 7.56.0 og `@wordpress/i18n` 6.29.0. Ingen runtime-dependencies og intet ekstra testbibliotek.
- [x] 516 PHP unit-tests (1474 assertions), 90 Jest-tests og 93 integrationstests mod MariaDB 10.11.19. ESLint, stylelint, `tsc`, PHPCS og PHPStan level 8 er grønne. CI kører stylelint, typetjek, Jest og build.
- [x] Playground over HTTP og i en rigtig browser (lokal Chrome via `playwright-core` fra `@wordpress/scripts`, headless), med WooCommerce (pretty permalinks) og uden WooCommerce (plain permalinks, `?merchboard_dashboard=1`):
  - Logget ud: login med deep link. Aktiv creator: 200, React renderer "Overview", sidebar og aktivt menupunkt, deep link → "Page not found" → client-side tilbage til startsiden, tilbage-knap og refresh virker, mobil-menu (`aria-expanded`, `Esc`, fokus), skip link er første tab-stop, log ud virker.
  - Alle 12 script-tags har CSP-noncen. 0 CSP-brud, 0 konsolfejl, 0 JS-fejl, 0 fejlede requests, 0 requests til andre hosts, tom localStorage/sessionStorage. Bootstrap uden creator-navn og uden HTML-escaping. Bundle 7,5 KB, ingen source map.
  - Inaktiv creator, aktiv record uden capability, rolle uden record, subscriber og administrator uden creator: 403 uden scripts.
  - Admin-skærmene (Overview, Creators, Settings, Audit log) svarer 200. Ingen PHP-notices og ingen secrets i logs.
- [x] Fundet og rettet: Fase 12's docs-script havde ødelagt FRONTEND.md (`$` + backtick i en `String.replace`-erstatning indsatte filens start midt i rewrite-afsnittet). FRONTEND.md er skrevet om, og doc-scripts bruger nu `split/join`.

**Bevidst ikke i fase 13 (se fase 14 for REST):** `/me`-endpoint og `RestController` (fase 14, projektejerens beslutning), endpoint-funktioner i `api/`, sektion-registry og filter, oversættelser (fase 15), lazy-loading af sektioner, Table/Modal/Toast m.fl. og alle forretningsmoduler.

### Fase 14 – REST-fundament, `/me` og Profile ✅

- [x] Scope-konflikt afklaret med projektejeren før implementering: hele roadmap-fase 14. Skemaer: `GET /me` = `{ display_name, status }`, `GET`/`PATCH /me/profile` = `{ display_name, bio, social_links }` (nyt read-endpoint på samme ressource). Sociale links er faste platforme (website, instagram, tiktok, youtube, x, facebook) med https-URL'er (maks. 200 tegn). Bio er ren tekst (maks. 1000 tegn), og profilændringer audit-logges.
- [x] `Core\Http\RestController` (namespace `merchboard/v1`, `require_creator()` via `CreatorContext`: 401 `merchboard_unauthenticated` og 403 `merchboard_forbidden`, `require_capability()`, `run()`: uventede fejl logges og giver en generisk 500 `merchboard_internal_error`).
- [x] `Modules/Dashboard/Rest/MeController` på `rest_api_init`: tre endpoints, alle med permission callback. Creator'en genopslås i endpointet (ikke memoiseret). Ingen creator-ID i routes eller parametre. Kun whitelistede body-felter læses, aldrig query-parametre.
- [x] Migration 3 (schemaversion 3): `merchboard_creatormeta` i WordPress' meta-layout, `CreatorMetaStore` (metadata API, whitelistede nøgler `bio` og `social_links`, skrivninger verificeres, `wp_slash`), `SocialLinks`, `CreatorProfile`, `CreatorProfileException` (feltkoder) og `CreatorProfileService` (genkontrollerer aktiv creator for nuværende bruger, validerer alt før skrivning, ét `creator_updated` med `changed_fields` i samme transaktion). Tabellen er med i `Tables::all()` og dermed i engine-tjek og opt-in-uninstall.
- [x] Display name følger den eksisterende regel (1-191 tegn, `CreatorRepository::normalize_display_name`). Spørgsmålet i fase 14 sagde fejlagtigt 1-100.
- [x] Frontend: `api/creator.ts` (`getMe`, `getProfile`, `updateProfile` og runtime-validatorer), `types/creator.ts`, `app/creator-context.tsx` (`GET /me` én gang, indhold vises først efter svar, fejl erstatter indholdet, navnet i topbaren), `sections/profile` (formular, klientvalidering med serverens fejlkoder, kun ændrede felter sendes, toast, password-link), `components/Toast`. API-klienten har fået `invalid_request` (400) med sikre feltkoder, og `rest_cookie_invalid_nonce` behandles som udløbet session.
- [x] Bootstrap: sektionen `profile` og `password_url` (`wp_lostpassword_url()`, valideret som samme origin).
- [x] 544 PHP unit-tests (1574 assertions), 124 Jest-tests og 108 integrationstests mod MariaDB 10.11.19. ESLint, stylelint, `tsc`, PHPCS og PHPStan level 8 er grønne.
- [x] Playground over HTTP (cookie + `X-WP-Nonce`) og i Chrome, med WooCommerce (pretty permalinks) og uden WooCommerce (plain permalinks, `?rest_route=`):
  - Routes: kun `/me` og `/me/profile`. Logget ud og uden nonce: 401. Aktiv creator: 200 med præcis de tilladte felter.
  - PATCH gyldig: 200 og audit. Ugyldig: 400 med feltkoder, intet gemt. Ukendt platform: 400 (argument-skema). ID'er og `status` i query eller body ignoreres. POST: 404.
  - Inaktiv creator, aktiv record uden capability, rolle uden record, subscriber og administrator uden creator: 403.
  - Editorens creator er uændret. Audit log viser "Changed: display name, bio, social links".
  - Browser: navn i topbaren, Profile via navigation, klientvalidering, gem → toast og nyt navn, refresh bevarer værdier, mobil, mistet session under redigering → "Your session has expired" med login-link (ingen redirect-loop). 0 konsolfejl, 0 CSP-brud, 0 fremmede requests, tom storage. Inaktiv og subscriber: 403-side uden scripts.
  - Ingen PHP-notices fra pluginet og ingen secrets i logs. WooCommerce-noticen `_load_textdomain_just_in_time` blev set igen, men tidsstemplet ligger før Playground melder "Ready", dvs. under blueprint-opsætningen (WooCommerce-installation og setup-scripts), ikke under vores requests.
- [x] Fundet og rettet undervejs:
  - `update_metadata()` fjerner backslashes. Værdier slashes nu først.
  - WordPress' meta-cache overlevede mellem integrationstests, fordi ID'er genbruges efter drop af tabeller. Testbasen tømmer nu cachen.
  - En udløbet nonce gav "ingen adgang" i UI'et. Den behandles nu som en udløbet session.

**Bevidst ikke i fase 14:** avatar-upload, Overview med creator-info (fase 15), sektion-registry og oversættelser (15), lazy-loading og admin-scope REST-endpoints.

### Fase 15 – Dashboard overview → MVP ✅

- [x] Beslutninger før implementering (projektejeren): engelske kildestrenge + dansk oversættelse (åbent spørgsmål 7 lukket), `wp-cli/i18n-command` som værktøj, intet `/me/overview`-endpoint (Overview bruger `/me` og `/me/profile`; `/me/overview` flyttet til fase 20).
- [x] Overview: velkomst med creator-navn og status (badge med tekst), "Din profil" fra `GET /me/profile` (bio, links med `rel="noopener noreferrer"`, åbner i ny fane med skjult tekst, eller empty state med link til Profile) med egne loading/fejl/retry-tilstande, og tre KPI-kort (omsætning, ordrer, solgte produkter) med "Salgsdata kommer snart" uden tal og uden API-kald.
- [x] Sektion-registry: `Modules/Dashboard/DashboardSections` (standard `overview` og `profile`, filter `merchboard_dashboard_sections`, validering af id/label/sti/capability, dubletter og ugyldige poster droppes og logges, startsiden findes altid, capability tjekkes server-side og sendes ikke til browseren). Bootstrap-sektionerne kommer derfra.
- [x] i18n: `Plugin::load_textdomain()` på `init` (prioritet 0), `wp_set_script_translations()` for `merchboard-dashboard`. `languages/`: `merchboard.pot` (221 strenge), `merchboard-da_DK.po`/`.mo` og `merchboard-da_DK-<md5(build/dashboard.js)>.json` (57 JS-strenge). `npm run i18n` (build → make-pot → update-po → make-mo → make-json). Dev-dependencies `wp-cli/wp-cli` ^2.12 og `wp-cli/i18n-command` ^2.6 (indgår ikke i pluginet).
- [x] Dashboardet følger **sitets sprog** (`determine_locale()` på frontend = sitets sprog). Creators kan ikke vælge personligt sprog, fordi wp-admin er blokeret for dem (ADR-026). Admin følger brugerens sprog som normalt i WordPress.
- [x] 564 PHP unit-tests (2351 assertions, inkl. `DashboardSectionsTest` og `TranslationsTest`: alt oversat, pladsholdere bevaret, ingen markup, MO/JSON gyldige og matcher bundlen), 133 Jest-tests (inkl. `overview.test.tsx`) og 111 integrationstests mod MariaDB 10.11.19 (inkl. registry med rigtige capabilities, danske PHP-strenge og plural, danske script-oversættelser i shellen med CSP-nonce). ESLint, stylelint, `tsc`, PHPCS og PHPStan level 8 er grønne.
- [x] Playground (HTTP + Chrome):
  - **Engelsk site med WooCommerce og pretty permalinks:** MVP-flowet: admin opretter creator via admin-formularen, creatoren logger ind og lander på Overview, ser velkomst, status og tom profil med link, redigerer profilen, og bio/links vises på Overview efter refresh. Mobil, 0 konsolfejl, 0 CSP-brud, 0 fremmede requests, tom storage.
  - **Dansk site uden WooCommerce og plain permalinks:** samme flow helt på dansk ("Overblik", "Velkommen, …", "Salgsdata kommer snart", "Profilen er gemt."), 403-siden ("Din adgang er ikke aktiv"), admin-skærme og REST-fejlbeskeder på dansk. Phase 14-regression (REST og profilflow) grøn.
  - Dansk site simuleres ved at skrive `WPLANG` direkte, fordi `sanitize_option()` kun accepterer installerede core-sprogpakker, og Playground ikke har den danske. Ingen PHP-notices og ingen secrets i logs.

**Bevidst ikke i fase 15:** `/me/overview` og rigtige nøgletal (fase 20), ikoner i navigationen (intet ikonsæt endnu), lazy-loading af sektioner, oversættelse af WordPress' egne strenge (core-sprogpakke) og alt fra fase 16.

### MVP-checkpoint – distributionspakke ✅

- [x] `npm run package` → `dist/merchboard-mvp-0.1.0.zip` via `bin/package.mjs` (allowlist, deterministisk, fejler ved manglende eller forbudte filer og ved afvigelse fra `HEAD`). Se [DEVELOPMENT.md](DEVELOPMENT.md#distributionspakke).
- [x] Konflikt løst (projektejerens valg: allowlist): DEVELOPMENT.md manglede `assets/js/`, som bruges ved runtime, og `.distignore` udelukkede ikke fase 13's `assets/src/`, `tsconfig.json` og `jest.config.js`. Begge er nu i sync med allowlisten, ligesom `export-ignore` i `.gitattributes`. `vendor/` er bekræftet unødvendig (ADR-017).
- [x] Pakken er valideret: 91 filer i `merchboard/`, ingen forbudte filer, ingen secrets eller source maps, `php -l` af alle PHP-filer, PHP `ZipArchive` `CHECKCONS`, .NET-udpakning byte-identisk og Playground med den udpakkede pakke som plugin (MVP-flowet på dansk uden fejl). Manuel WordPress-installation udføres af projektejeren.

### Fase 16 – Release-pipeline og staging ✅ (2026-09-25)

- [x] `.github/workflows/release.yml` på tag `v*`: alle CI-jobs genbrugt via `workflow_call` (`ci.yml` er kun udvidet med triggeren), tagget commit skal ligge på `main`, `npm run package -- --tag`, uafhængig `unzip -t`-kontrol, `.sha256`, release notes fra CHANGELOG og **draft-release** via `gh release create --draft --verify-tag` (projektejerens valg). Permissions: `{}` som standard, `contents: write` kun i release-jobbet. Ingen tredjeparts actions og ingen secrets.
- [x] `bin/package.mjs`: `--tag vX.Y.Z` skal matche versionen og pege på `HEAD`, og header, `MERCHBOARD_VERSION` og `package.json` skal have samme SemVer-version. Uden `--tag` virker lokal pakning som før.
- [x] Procedurer for versions-bump, release og staging-deploy med MySQL-verifikation i [DEVELOPMENT.md](DEVELOPMENT.md#release). ADR-028.
- [x] Staging er `https://devpage.ccmerch.dk` med manuel upload (projektejerens valg). SFTP er fravalgt.
- [x] Afvigelser fra roadmap-planen: ingen `composer --no-dev` (ingen `vendor/`, ADR-017) og ingen zip efter `.distignore`. `bin/package.mjs` genbruges.
- [x] Verificeret lokalt: versions- og tag-regler i en midlertidig klon (gyldigt annoteret og letvægts-tag, forkert version, manglende tag, tag på anden commit, tag uden værdi, afvigende `package.json`, ikke-SemVer, ændret runtime-fil), workflowets shell-trin (ZIP-kontrol, udtræk af release notes inkl. `0.1.1` ≠ `0.1.10` og manglende eller tom sektion, main-kontrol) og YAML-parsing af begge workflows.
- [x] **GitHub:** CI #22 på `main` (`b4d04f2`) er grøn i alle jobs, inkl. `PHP integration (MySQL)` mod MySQL 8.0.46 (111 tests). Annoteret tag `v0.1.0` → `b4d04f2`. Release-workflowet (første kørsel) er grønt: alle genbrugte CI-jobs og "Package and draft release".
- [x] **Draft-release** "Merch Dashboard 0.1.0" med `merchboard-mvp-0.1.0.zip` (160801 bytes) og `merchboard-mvp-0.1.0.zip.sha256`. Hash verificeret med `sha256sum -c` (`bf540a86…0676`). ZIP'en er byte-identisk med den lokalt byggede pakke fra tagget (rodmappe `merchboard/`, 91 filer, version 0.1.0). Releasen er stadig en draft, og udgivelse sker ved projektejeren.
- [x] **Staging:** Release-ZIP'en er manuelt installeret og testet på `devpage.ccmerch.dk` af projektejeren. Testen er bestået. MySQL/MariaDB-versionen på staging er ikke noteret (åbent spørgsmål 2).
- [x] Audit-retention for v0.1.0 er afklaret: ubegrænset (ADR-029).

## In progress

- **Lokalt testmiljø (2026-09-26):** LocalWP-sitet Merch Dashboard Local er oprettet, verificeret og udpeget som projektets lokale WordPress/WooCommerce-testmiljø (åbent spørgsmål 9 er lukket). Der er endnu ingen testdata.

- **Fase 17 – WooCommerce foundation:**
  - **Beslutninger:** D1-D6 er lukket i ADR-030 (D1 rettet fra 10.4 til 10.3, fordi 10.4 kræver WordPress 6.8).
  - **Udført:** Trin 1 (undersøgelse af skriveveje i WooCommerce 10.3.8), trin 2 (modul-skelet, WooCommerce ≥ 10.3) trin 3 (`ProductCreatorLink`, den autoritative domæneservice) og trin 4 (produktkoblinger på creatorens redigeringsside) er færdige.
  - **ADR-031 (2026-09-26, ændret af ADR-033):**
    - `_merchboard_creator_id` er den eneste ejerskabsrelation.
    - Kategori-mapping pr. creator og engangs-backfill med tørkørsel. **Erstattet af ADR-033:** mappingen ligger ikke i creator-meta, og backfill er ikke længere en selvstændig skrivevej.
  - **Testdata:** Syntetisk fixture-datasæt i Merch Dashboard Local (3 creators, 4 kategorier, 10 produkter). Klassifikationen er verificeret med en tørkørsel, der intet skrev.
  - **ADR-032 (2026-09-26):**
    - Produktkoblingen administreres i Merch Dashboard på creatorens redigeringsside (`admin-post.php`, `merchboard_manage_creators` + `edit_product`, `source: admin`).
    - WooCommerce-produktskærmen er ikke en skrivevej og viser ikke relationen.
    - Produktlistens kolonne og filter er skrivebeskyttede.
    - `product_screen` er forældet og fjernes i trin 4.
  - **Trin 4:**
    - Afsnittet "Products" på creatorens redigeringsside (WooCommerce-modulet via `merchboard_creators_edit_sections`). Det viser koblede produkter og giver søgning på navn og varenummer via WooCommerce' egen søgning og på produktkategori (kun direkte tilknytning, opretter ingen kobling), valg af flere produkter, et eksplicit afkrydsningsfelt til overtagelse og resultat pr. produkt.
    - Skrivning sker kun gennem `ProductCreatorLink` (`source: admin`); `product_screen` er fjernet.
    - Verificeret mod WooCommerce 10.3.8 i Merch Dashboard Local: tilknytning, samme creator, afvist overtagelse, overtagelse, variant og fjernelse. Audit kommer fra servicen, og produkteditoren har ingen creator-UI. Koblingerne blev fjernet igen bagefter.
  - **ADR-033 (2026-09-26, kun dokumenteret, implementering ikke startet):** Kategori-mapping som vedvarende automatisk kobling.
    - Tabellen `merchboard_creator_categories` (migration 4): én creator pr. kategori, teknisk flere kategorier pr. creator.
    - Oprindelse `_merchboard_creator_link_source` (`admin` | `category`); `backfill` udfases.
    - Tilstandsbaseret afstemning via Action Scheduler, udløst af term- og produkt-hooks. Kører som systemarbejde gennem en intern metode i `ProductCreatorLink`.
    - Manuel kobling vinder altid. Remove afvises for kategori-styrede produkter, og "Keep as manual" tilføjes. Tvetydige produkter får ingen kategori-kobling.
    - Ændrer ADR-030 D6, ADR-031 og ADR-032 pkt. 1 og 3. Roadmappen er omlagt til trin 5-15.
    - **Kode, der endnu afspejler den gamle model:** `ProductLinkSource::Backfill` og kommentarerne i `ProductLinkSource`, `ProductCreatorLink` og `WooCommerceModule` (engangs-backfill, `product_category_id`) opdateres i trin 8.
  - **Trin 5 (2026-09-26):** Skrivebeskyttet kolonne "Creator" og creator-filter i WooCommerce' produktliste (`ProductListCreators`, kun for `merchboard_manage_creators`; læser kun `_merchboard_creator_id`; ingen kategorier, ingen skrivning, ingen audit). `CreatorRepository::all()` er tilføjet til filterets creator-liste. Verificeret skrivebeskyttet mod WooCommerce 10.3.8 i Merch Dashboard Local (kolonne, filter, kombination med kategorifilter og paginering, varianter og uden capability); data var uændret bagefter.
  - **Trin 6 (2026-09-26):** Vagter for skriveveje: `ProtectedProductMeta` afviser deny-by-default alle generiske skrivninger af `_merchboard_creator_id` og `_merchboard_creator_link_source` via metadata-API'ets pre-hooks; kun en eksplicit, scoped, engangs-`ProtectedMetaWrite` for ét produkt (brugt af `ProductCreatorLink`, senere af ADR-033's afstemning) slipper igennem. `ProtectedMetaEntryPoints` giver tydelige afvisninger i REST, `variations/generate` og CSV-import og holder felterne ude af duplicate. Verificeret mod WooCommerce 10.3.8 i Merch Dashboard Local i en transaktion, der blev rullet tilbage; data var identiske bagefter.
  - **ADR-034 (2026-09-26):** Dokumenterer trin 6's beskyttelse af `_merchboard_creator_id` og `_merchboard_creator_link_source` og ændrer ADR-030 D6 (rå meta-API og WP-CLI er nu blokeret; direkte SQL og kode, der omgår hooks, er stadig udenfor). **Uafklaret:** (A) beskyttelse, når WooCommerce er deaktiveret eller for gammelt (vagten ligger i WooCommerce-modulet); (B) uninstall-mekanismen for de to nøgler, som skal besluttes før trin 13.
  - **Næste:** Trin 7 (migration 4 og mapping-service).

## Planned

Se [ROADMAP.md](ROADMAP.md). Næste tre:

1. **Fase 17:** WooCommerce foundation.
2. **Fase 18:** Sales ledger.
3. **Fase 19:** Products og Orders i dashboard.

## Known bugs

Ingen (der er ingen funktionalitet endnu).

## Technical debt

| Emne | Beskrivelse | Handling |
| --- | --- | --- |
| npm audit | 14 advisories (8 moderate, 6 high) i dev-dependencies (`@wordpress/scripts`, `@wp-playground/cli` og deres transitive pakker). Ingen i fase 13's nye pakker. Påvirker ikke det distribuerede plugin. | Tjek ved hver opdatering af `@wordpress/scripts`/`@wp-playground/cli` |
| Versionskrav to steder | `MIN_PHP`/`MIN_WP` i `Requirements` skal holdes synkront med plugin-headeren manuelt | Overvej en unit-test, der læser headeren og sammenligner (lille, kan tages i fase 3) |
| Pre-release WP-versioner | `version_compare( '6.7-RC1', '6.7' )` betragtes som for lav, så en RC af præcis minimumsversionen viser notice | Accepteret. Påvirker kun test af pre-releases. |
| MySQL-verifikation | MariaDB 10.11.19 er verificeret lokalt (fase 7), og **MySQL 8.0.46** i CI (`PHP integration (MySQL)`, 111 tests, grøn i CI #22 og i release-workflowet for `v0.1.0`). Staging- og produktionsversionen er ikke noteret. | Notér versionen fra staging/produktion (åbent spørgsmål 2) |
| Aktiveringslog i Playground | Info-posten fra aktivering i Playgrounds blueprint-trin kan ikke observeres (trinnet kører før `woocommerce_init` og uden for `debug.log`-omdirigeringen). Dækket af unit-tests. | Verificér ved rigtig admin-aktivering på staging (fase 16) |
| Dinglende `user_id` efter fejlet frigivelse | Løst i fase 8 for normal sletning. Kun hvis frigivelsen fejler (databasefejl, MyISAM), peger creator'en på en slettet bruger. Fejlen logges, linket giver ingen adgang, og admin kan frakoble manuelt. Der er intet automatisk genforsøg. | Accepteret. Overvej et oprydnings-tjek i Overview (fase 9). |
| WooCommerce og roller uden `edit_posts` | Med WooCommerce aktiv sendes brugere uden `edit_posts`, `manage_woocommerce` eller `view_admin_dashboard` fra wp-admin til "Min konto" (WooCommerce' egen regel). En rolle med kun `merchboard_manage_creators` kan dermed ikke nå siden. | Dokumenteret. Rolle-tildeling via settings i fase 12. |
| Multisite: uninstall | Afinstallation sletter kun det aktuelle sites data (også ved opt-in). Andre sites i et netværk beholder deres tabeller, options og roller. Ingen risiko for fremmede data, men heller ingen fuld oprydning. | Multisite er ikke understøttet. Kræver en beslutning, hvis det bliver relevant. |
| Udgåede capabilities | Uninstall fjerner kun de præcise navne i `Capabilities::all()`. Udgår en capability senere, skal den fortsat stå på oprydningslisten. | Husk ved ændring af `Capabilities` (ADR-025) |
| Multisite: bruger fjernet fra et site | WordPress fyrer `deleted_user` også, når en bruger kun fjernes fra et site. Brugeren findes stadig, så linket bevares. Uden rolle på sitet er der ingen adgang. | Multisite er ikke understøttet. Revurder ved behov. |
| Samtidig kobling og sletning | Kobles en bruger i samme øjeblik, som den slettes, kan linket pege på en slettet bruger. Det giver ingen adgang. | Accepteret (meget sjældent) |
| Transaktioner uden InnoDB | Fail closed (ADR-024): creator-ændringer afvises på MyISAM. Rollback verificeret på MariaDB og SQLite. | Accepteret krav. Dokumenteret i DATABASE.md. |
| Genforbindelse midt i en transaktion | Mister `wpdb` forbindelsen midt i en transaktion, genforbinder den og fortsætter uden transaktion. Meget sjældent. | Accepteret. Revurder ved problemer på staging. |
| Emoji på legacy `utf8mb3`-tabeller | `wpdb` afviser display names med 4-byte-tegn. Admin ser den tekniske fejl i stedet for en valideringsbesked. Ingen datakorruption. | Tabellerne oprettes som `utf8mb4` på moderne sites. Valideringsbesked kan tilføjes senere (ikke gjort i fase 10). |
| Persistent object cache og migrationslås | Låsen arbejder direkte på databasen og rydder kun sin cache-nøgle. Samspil med fx Redis er ikke testet. | Staging (fase 16), hvis produktionen bruger object cache |
| Creator med kun `merchboard_view_logs` | En ren creator, der også har fået `merchboard_view_logs` (men ikke `merchboard_manage`), sendes væk fra wp-admin og kan ikke se Audit log (ADR-026). | Giv også `merchboard_manage`, eller revurder reglen, hvis behovet opstår |
| Sessioner ved deaktivering | En deaktiveret creator er stadig logget ind i WordPress. Dashboardet giver 403 fra næste request, men sessionen afsluttes ikke. | Accepteret. Valgfri "log alle sessioner ud" er en fremtidig forbedring. |
| Standard-slug og eksisterende side | Slug-valideringen gælder kun ændringer i Settings. Findes en side med slug `creator-dashboard` allerede ved aktivering, vinder dashboardet (reglen ligger øverst), og siden kan ikke nås på sin URL. | Vælg en anden slug i Settings. Overvej en advarsel på Overview. |
| Hosting-cache | Dashboardet sender `no-cache`-headers. En aggressiv side-cache (CDN, Varnish) skal stadig bypasse `/creator-dashboard/*` og indloggede brugere. | Tjek ved staging (fase 16) |
| Administratorer uden `administrator`-rolle | Sites uden standardrollen får ikke plugin-admin-caps automatisk (warning logges) | Tildel manuelt. Settings-baseret tildeling til andre roller kommer med Access-settings (fase 12). |
| React-version | WordPress 6.7 leverer React 18.3, nyere WordPress React 19, og typerne og Jest bruger React 19. Koden bruger kun fælles API'er. | Test på WordPress 6.7 ved staging (fase 16) eller hæv minimumsversionen |
| Sporadisk WooCommerce-notice i Playground | Én `_load_textdomain_just_in_time`-notice for `woocommerce` blev set én gang ved opstart af et frisk Playground-site. Den kunne ikke genskabes ved en ny kørsel. Pluginet kalder ingen WooCommerce-oversættelser. | Hold øje ved næste fase |
| Profilfelter ved permanent sletning af creator | Der findes ingen hard delete af creators. Opt-in-uninstall dropper `merchboard_creatormeta` sammen med de øvrige tabeller. | Revurder, hvis hard delete indføres |
| Oversættelser skal holdes ajour | `merchboard-da_DK.po` skal opdateres, når UI-strenge ændres (`npm run i18n`, derefter oversæt nye strenge). `TranslationsTest` fejler, hvis en streng mangler. JSON-filnavnet afhænger af stien `build/dashboard.js`. | Kør `npm run i18n` ved strengændringer |
| Creators uden personligt sprog | Dashboardet følger sitets sprog. En creator på et engelsk site kan ikke vælge dansk (wp-admin er blokeret). | Revurder, hvis et flersproget behov opstår (fx sprogvalg i Profile) |
| Metadata-læsefejl | `get_metadata()` giver ingen fejlinformation. En fejlet læsning af bio/links vises som tomme felter. Skrivninger verificeres og fejler lukket. | Accepteret (profilfelter er ikke adgangsstyrende) |
| Tekniske logs uden WooCommerce | Kun i `debug.log`/serverlog, ikke i wp-admin | Accepteret (ADR-021). Revurder, hvis sites uden WooCommerce bliver relevante. |

## Godkendte eksterne services

Kun disse eksterne services er en del af projektet. Alt andet kræver eksplicit godkendelse og en ADR (ADR-016).

| Service | Formål |
| --- | --- |
| GitHub (`CaileanTV/CCMerchDashboard`) | Versionsstyring, backup og CI (GitHub Actions) |
| wordpress.org / Composer / npm registries | Download af WordPress, WooCommerce (kun i Playground), WordPress core til integrationstests og dev-dependencies |
| archive.mariadb.org | Download af den portable MariaDB til lokale integrationstests (fastlåst version og SHA-256, kun dev). Godkendt af projektejeren 2026-09-24 (ADR-024). |

**Eksplicit fravalgt:** Dinero og Google Drive. Ingen integration, ingen data ind eller ud, ingen backup, og connectors må ikke aktiveres på projektets vegne.

## Åbne spørgsmål

Skal besvares af projektejeren. Fasen, hvor svaret senest er nødvendigt, står i parentes.

1. ✅ **Navn/prefix – lukket (ADR-018):** Produktnavn `Merch Dashboard`, teknisk prefix `merchboard`, PHP-namespace `MerchBoard\`. Låst før fase 3. Den tidligere midlertidige konvention `ccmerch` er migreret i hele repo'et.
2. **Hosting – delvist besvaret:** Dev og produktion kører **PHP 8.3** (minimum forbliver 8.2, ADR-003). Åbent: Hvilken **MySQL/MariaDB-version** og hvilket charset/collation? (Fase 7 verificerede MariaDB 10.11.19 lokalt. Den lokale version bør matche produktionen, når den kendes.) Findes der et eksisterende WooCommerce-produktionssite, som pluginet skal installeres på? (fase 7 / 16)
3. ✅ **TypeScript – lukket (fase 13, ADR-027):** Ja. Dashboardet skrives i TypeScript (strict) med React og `@wordpress/scripts`.
4. **Teams:** Kan en creator have flere brugere (fx manager)? Fase 8 er bygget med én bruger pr. creator (ADR-020). Teams vil kræve `merchboard_creator_members`, og ændringen bliver lokal til `CreatorContext` og repositoriet. (før fase 14)
5. **Revenue-model:** Én creator pr. produkt, eller collabs med split? Opgøres creator-omsætning før/efter rabat, ekskl./inkl. moms? Hvilke ordrestatusser tæller? (fase 17-18)
6. **Privacy:** Må creators se *nogen* kundedata (fx land)? Default: ingen PII. (fase 19)
7. ✅ **UI-sprog – lukket (fase 15):** Engelske kildestrenge og dansk oversættelse via WordPress i18n. Dashboardet vises på sitets sprog, admin på brugerens.
8. ✅ **Dashboard-URL – lukket (fase 12):** `/creator-dashboard/`, og slug'en kan ændres under Merch Dashboard → Settings.
9. ✅ **Lokal persistent WordPress – lukket (2026-09-26):** Projektejeren har udpeget LocalWP-sitet **Merch Dashboard Local** (`http://merchboard.local`: WordPress 7.1.2, PHP 8.2.29, MariaDB 10.11.18, WooCommerce 10.3.8, pluginet via junction til repoet) som projektets officielle lokale WordPress/WooCommerce-testmiljø. Playground bruges fortsat til flygtige smoke-tests. Andre LocalWP-sites er uden for scope, og staging og produktion må ikke bruges af AI-agenter. Se [DEVELOPMENT.md](DEVELOPMENT.md#lokal-wordpress-localwp-merch-dashboard-local).
10. ✅ **Audit-retention – lukket for v0.1.0 (ADR-029):** Audit-records bevares ubegrænset i v0.1.0, uden automatisk retention eller sletning. Det er ikke en produktionsbeslutning: en retention-politik for produktion (periode og GDPR-begrundelse) skal besluttes før produktionsbrug og registreres i en ny ADR.

## Next recommended steps

1. Udgiv draft-releasen "Merch Dashboard 0.1.0" på GitHub, når projektejeren er klar (ikke gjort).
2. Notér MySQL/MariaDB-version og charset fra staging (Værktøjer → Webstedets tilstand → Info → Database) i åbent spørgsmål 2.
3. Før produktionsbrug: beslut en retention-politik for audit-records (ADR-029, ny ADR). v0.1.0 bevarer dem ubegrænset.
4. Fase 17 (WooCommerce foundation) jf. docs/ROADMAP.md.
