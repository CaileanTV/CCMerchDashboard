# Project status

**Sidst opdateret:** 2026-09-24 · **Seneste færdige fase:** 2 (Plugin bootstrap) · **Næste fase:** 3 (Database-fundament og creator-tabeller) · **Version:** 0.1.0 (ikke released) · **Naming:** `Merch Dashboard` / `merchboard` / `MerchBoard\` (ADR-018)

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

## In progress

Intet.

## Planned

Se [ROADMAP.md](ROADMAP.md). Næste tre:

1. **Fase 3:** Activation hook, migrationer og creator-tabeller.
2. **Fase 4:** Logging, audit og deactivation hook.
3. **Fase 5:** Roller og capabilities.

## Known bugs

Ingen (der er ingen funktionalitet endnu).

## Technical debt

| Emne | Beskrivelse | Handling |
| --- | --- | --- |
| npm audit | 11 advisories (5 moderate, 6 high) i transitive dev-dependencies af `@wordpress/scripts`. Påvirker ikke det distribuerede plugin. | Tjek ved hver opdatering af `@wordpress/scripts` |
| Versionskrav to steder | `MIN_PHP`/`MIN_WP` i `Requirements` skal holdes synkront med plugin-headeren manuelt | Overvej en unit-test, der læser headeren og sammenligner (lille, kan tages i fase 3) |
| Pre-release WP-versioner | `version_compare( '6.7-RC1', '6.7' )` betragtes som for lav, så en RC af præcis minimumsversionen viser notice | Accepteret. Påvirker kun test af pre-releases. |
| Lokal MySQL | Playground bruger SQLite. Ingen lokal MySQL-verifikation. | CI-integrationstests med MySQL (fase 7) og staging (fase 16) |
| CI-resultat ikke verificeret lokalt | Repo'et er privat, og `gh` er ikke installeret, så CI-status kan ikke læses fra terminalen | Tjek Actions-fanen på GitHub efter første push. Overvej at installere `gh`. |

## Godkendte eksterne services

Kun disse eksterne services er en del af projektet. Alt andet kræver eksplicit godkendelse og en ADR (ADR-016).

| Service | Formål |
| --- | --- |
| GitHub (`CaileanTV/CCMerchDashboard`) | Versionsstyring, backup og CI (GitHub Actions) |
| wordpress.org / Composer / npm registries | Download af WordPress, WooCommerce (kun i Playground) og dev-dependencies |

**Eksplicit fravalgt:** Dinero og Google Drive. Ingen integration, ingen data ind eller ud, ingen backup, og connectors må ikke aktiveres på projektets vegne.

## Åbne spørgsmål

Skal besvares af projektejeren. Fasen, hvor svaret senest er nødvendigt, står i parentes.

1. ✅ **Navn/prefix – lukket (ADR-018):** Produktnavn `Merch Dashboard`, teknisk prefix `merchboard`, PHP-namespace `MerchBoard\`. Låst før fase 3. Den tidligere midlertidige konvention `ccmerch` er migreret i hele repo'et.
2. **Hosting:** Hvilken PHP- og MySQL/MariaDB-version kører produktionen? Findes der et eksisterende WooCommerce-produktionssite, som pluginet skal installeres på? (fase 2 / 16)
3. **TypeScript** til dashboardet: ja/nej? Anbefaling: ja, hvis teamet kender det, fordi API-kontrakter og refaktorering over flere år vinder på det. Ellers JS + JSDoc. (fase 13)
4. **Teams:** Kan en creator have flere brugere (fx manager)? (fase 6. Påvirker kun, om vi bygger det nu.)
5. **Revenue-model:** Én creator pr. produkt, eller collabs med split? Opgøres creator-omsætning før/efter rabat, ekskl./inkl. moms? Hvilke ordrestatusser tæller? (fase 17-18)
6. **Privacy:** Må creators se *nogen* kundedata (fx land)? Default: ingen PII. (fase 19)
7. **UI-sprog:** Dansk, engelsk eller begge i creator-dashboardet? (fase 15)
8. **Dashboard-URL:** `/creator-dashboard/` eller andet (fx `/studio/`)? (fase 12)
9. **Lokal persistent WordPress:** Er Playground (flygtigt) tilstrækkeligt, eller ønskes et dedikeret LocalWP-site? Det skal i så fald udpeges eksplicit (Project Isolation). (fase 7)

## Next recommended steps

1. Tjek at CI er grøn under GitHub → Actions, inkl. de nye jobs `PHPUnit` og `PHP 7.2 syntax`.
2. Besvar åbent spørgsmål 2 (hosting). Navn/prefix er besluttet (ADR-018), så fase 3 kan oprette tabeller og options med `merchboard_`-prefix.
3. Kør fase 3 med prompten: *"Implementér roadmap-fase 3 (Database-fundament og creator-tabeller) jf. docs/ROADMAP.md. Følg docs/AI-DEVELOPMENT.md."*
