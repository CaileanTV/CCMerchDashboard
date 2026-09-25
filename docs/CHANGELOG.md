# Changelog

Større ændringer: features, databaseændringer, arkitekturændringer, breaking changes og større bugfixes. Formatet følger [Keep a Changelog](https://keepachangelog.com/da/1.1.0/), og versionering følger [SemVer](https://semver.org/).

## [Unreleased]

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
