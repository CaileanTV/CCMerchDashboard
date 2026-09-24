# Project status

**Sidst opdateret:** 2026-09-24 · **Seneste færdige fase:** 10 (Admin creator management (rest) og Overview) · **Næste fase:** 11 (Admin audit-log viewer) · **Schemaversion:** 2 · **Version:** 0.1.0 (ikke released) · **Naming:** `Merch Dashboard` / `merchboard` / `MerchBoard\` (ADR-018)

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

## In progress

Intet.

## Planned

Se [ROADMAP.md](ROADMAP.md). Næste tre:

1. **Fase 11:** Admin audit-log viewer.
2. **Fase 12:** Creator dashboard: route og sikker shell.
3. **Fase 13:** Dashboard frontend-fundament.

## Known bugs

Ingen (der er ingen funktionalitet endnu).

## Technical debt

| Emne | Beskrivelse | Handling |
| --- | --- | --- |
| npm audit | 11 advisories (5 moderate, 6 high) i transitive dev-dependencies af `@wordpress/scripts`. Påvirker ikke det distribuerede plugin. | Tjek ved hver opdatering af `@wordpress/scripts` |
| Versionskrav to steder | `MIN_PHP`/`MIN_WP` i `Requirements` skal holdes synkront med plugin-headeren manuelt | Overvej en unit-test, der læser headeren og sammenligner (lille, kan tages i fase 3) |
| Pre-release WP-versioner | `version_compare( '6.7-RC1', '6.7' )` betragtes som for lav, så en RC af præcis minimumsversionen viser notice | Accepteret. Påvirker kun test af pre-releases. |
| MySQL-verifikation | MariaDB 10.11.19 er verificeret lokalt (fase 7). **MySQL** er ikke verificeret: CI-jobbet findes, men resultatet er ikke set. Andre MariaDB-versioner og produktionens version er ukendte. | Tjek CI-jobbet `PHP integration (MySQL)` under GitHub → Actions, og staging (fase 16) |
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
| Audit-retention | Ubegrænset opbevaring. Ingen politik for automatisk sletning. | Afklares (åbent spørgsmål 10) |
| Creator-rollen har `read` | Creators kan indtil fase 12 se egen profil i wp-admin som en Subscriber (ingen andre rettigheder). | Blokering af wp-admin for creators i fase 12 (flyttet fra fase 8) |
| Administratorer uden `administrator`-rolle | Sites uden standardrollen får ikke plugin-admin-caps automatisk (warning logges) | Tildel manuelt. Settings-baseret tildeling til andre roller kommer med Access-settings (fase 12). |
| Tekniske logs uden WooCommerce | Kun i `debug.log`/serverlog, ikke i wp-admin | Accepteret (ADR-021). Revurder, hvis sites uden WooCommerce bliver relevante. |
| CI-resultat ikke verificeret lokalt | Repo'et er privat, og `gh` er ikke installeret, så CI-status (inkl. det nye MySQL-integrationsjob) kan ikke læses fra terminalen | Tjek Actions-fanen på GitHub efter push. Overvej at installere `gh`. |

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
3. **TypeScript** til dashboardet: ja/nej? Anbefaling: ja, hvis teamet kender det, fordi API-kontrakter og refaktorering over flere år vinder på det. Ellers JS + JSDoc. (fase 13)
4. **Teams:** Kan en creator have flere brugere (fx manager)? Fase 8 er bygget med én bruger pr. creator (ADR-020). Teams vil kræve `merchboard_creator_members`, og ændringen bliver lokal til `CreatorContext` og repositoriet. (før fase 14)
5. **Revenue-model:** Én creator pr. produkt, eller collabs med split? Opgøres creator-omsætning før/efter rabat, ekskl./inkl. moms? Hvilke ordrestatusser tæller? (fase 17-18)
6. **Privacy:** Må creators se *nogen* kundedata (fx land)? Default: ingen PII. (fase 19)
7. **UI-sprog:** Dansk, engelsk eller begge i creator-dashboardet? (fase 15)
8. **Dashboard-URL:** `/creator-dashboard/` eller andet (fx `/studio/`)? (fase 12)
9. **Lokal persistent WordPress:** Er Playground (flygtigt) tilstrækkeligt, eller ønskes et dedikeret LocalWP-site? Det skal i så fald udpeges eksplicit (Project Isolation). Fase 7 løste behovet for en rigtig database med integrationstestene, så spørgsmålet haster ikke længere. (fase 16)
10. **Audit-retention:** Hvor længe skal audit-records bevares (fx ubegrænset, 5 år, 10 år)? Påvirker GDPR-dokumentation. (fase 11)

## Next recommended steps

1. Tjek at CI er grøn under GitHub → Actions.
2. Tjek især jobbet `PHP integration (MySQL)`. Først når det er grønt, er MySQL verificeret. Den første linje i test-output viser serverversionen.
3. Oplys MySQL/MariaDB-versionen på dev/produktion (åbent spørgsmål 2), så `bin/integration.mjs` kan køre samme version.
4. Kør fase 11 (Admin audit-log viewer) jf. docs/ROADMAP.md.
