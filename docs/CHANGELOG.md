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

### Changed
- **Navngivning (ADR-018):** Den midlertidige konvention `ccmerch` / `CCMerch\` / `ccmerch-dashboard` / "CC Merch Dashboard" er erstattet af `merchboard` / `MerchBoard\` / `merchboard` / "Merch Dashboard" i al kode, konfiguration og dokumentation. Hovedfilen hedder nu `merchboard.php`. Ingen datamigration, fordi der ikke fandtes persistent data. Punkterne ovenfor bruger allerede de nye navne.
- **Arkitektur:** Runtime bruger pluginets egen autoloader i stedet for Composers `vendor/autoload.php`. Den distribuerede plugin indeholder ikke `vendor/` (ADR-017, erstatter en del af ADR-004).
- Activation/deactivation-hooks og text domain-loading er flyttet fra fase 2 til fase 3, 4 og 15, hvor de får en funktion.
- `bin/composer.mjs` aktiverer manglende PHP-extensions via en projekt-lokal ini (`PHP_INI_SCAN_DIR`), så de også gælder for Composer-scripts.

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

### Database
- **Schema-version 1:** ny tabel `{prefix}merchboard_creators` (`id`, `user_id` nullable UNIQUE, `display_name`, `status`, `created_at`, `updated_at`; UTC). Nye options `merchboard_db_version` (autoload) og `merchboard_db_migration_lock` (midlertidig). Transient `merchboard_db_migration_failed`.
- **Roller (fase 5):** rolle `merchboard_creator` og administrator-capabilities i WordPress' `wp_user_roles`. Ny option `merchboard_roles_version` (autoload). Ingen schemaændring.
