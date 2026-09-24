# Changelog

Større ændringer: features, databaseændringer, arkitekturændringer, breaking changes og større bugfixes. Formatet følger [Keep a Changelog](https://keepachangelog.com/da/1.1.0/), og versionering følger [SemVer](https://semver.org/).

## [Unreleased]

### Added
- Projektfundament: Composer (PSR-4 `CCMerch\`), PHPCS/WPCS, PHPStan level 8, `@wordpress/scripts` (ESLint/Prettier), WordPress Playground-dev-miljø med WooCommerce og GitHub Actions CI.
- Plugin-entry `ccmerch-dashboard.php` (kun header).
- Dokumentation: arkitektur, security- og rollemodel, datamodel, API-, frontend-, admin- og WooCommerce-design, roadmap (20 faser), ADR 001-016 og AI-udviklingsregler.
- Project Isolation omfatter eksterne services og connectors. Dinero og Google Drive er eksplicit fravalgt (ADR-016).
- **Plugin bootstrap (fase 2):** versionstjek (PHP ≥ 8.2, WP ≥ 6.7) med admin notice i stedet for fatal error, egen PSR-4-autoloader, `Plugin` med modul-boot på `plugins_loaded` (filter `ccmerch_modules`, action `ccmerch_loaded`), `Container`, `Module`-interface, WooCommerce HPOS-kompatibilitetserklæring og `uninstall.php`-skelet.
- PHPUnit 11 + Brain Monkey med 38 unit-tests. CI kører PHPUnit og et PHP 7.2-syntakstjek.

### Changed
- **Arkitektur:** Runtime bruger pluginets egen autoloader i stedet for Composers `vendor/autoload.php`. Den distribuerede plugin indeholder ikke `vendor/` (ADR-017, erstatter en del af ADR-004).
- Activation/deactivation-hooks og text domain-loading er flyttet fra fase 2 til fase 3, 4 og 15, hvor de får en funktion.
- `bin/composer.mjs` aktiverer manglende PHP-extensions via en projekt-lokal ini (`PHP_INI_SCAN_DIR`), så de også gælder for Composer-scripts.

### Database
- Ingen ændringer (ingen tabeller endnu).
