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

### Changed
- **Navngivning (ADR-018):** Den midlertidige konvention `ccmerch` / `CCMerch\` / `ccmerch-dashboard` / "CC Merch Dashboard" er erstattet af `merchboard` / `MerchBoard\` / `merchboard` / "Merch Dashboard" i al kode, konfiguration og dokumentation. Hovedfilen hedder nu `merchboard.php`. Ingen datamigration, fordi der ikke fandtes persistent data. Punkterne ovenfor bruger allerede de nye navne.
- **Arkitektur:** Runtime bruger pluginets egen autoloader i stedet for Composers `vendor/autoload.php`. Den distribuerede plugin indeholder ikke `vendor/` (ADR-017, erstatter en del af ADR-004).
- Activation/deactivation-hooks og text domain-loading er flyttet fra fase 2 til fase 3, 4 og 15, hvor de får en funktion.
- `bin/composer.mjs` aktiverer manglende PHP-extensions via en projekt-lokal ini (`PHP_INI_SCAN_DIR`), så de også gælder for Composer-scripts.

### Changed (fase 3)
- Statusmodel for creators er `active`/`inactive` (tidligere planlagt `active`/`suspended`/`archived`). `user_id` er nullable (tidligere planlagt `NOT NULL`). `merchboard_creatormeta` flyttet til fase 14. ADR-020.
- Uninstall bevarer eksplicit alle data. Opt-in-sletning kommer i fase 9.
- `bin/composer.mjs` fjerner andre ini-filer i sin egen `.tools/php-ini/`-mappe, så extensions ikke indlæses to gange (en forældet lokal ini fra før ADR-018 gav "Module already loaded"-advarsler).

### Database
- **Schema-version 1:** ny tabel `{prefix}merchboard_creators` (`id`, `user_id` nullable UNIQUE, `display_name`, `status`, `created_at`, `updated_at`; UTC). Nye options `merchboard_db_version` (autoload) og `merchboard_db_migration_lock` (midlertidig). Transient `merchboard_db_migration_failed`.
