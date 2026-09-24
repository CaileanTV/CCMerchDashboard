# Project status

**Sidst opdateret:** 2026-09-24 · **Nuværende fase:** 1 (Project foundation) er færdig · **Næste fase:** 2 (Plugin bootstrap) · **Version:** 0.1.0 (ikke released)

## Completed

- [x] Analyse af udgangspunkt (tomt repo). Se [reports/2026-09-24-initial-analysis.md](reports/2026-09-24-initial-analysis.md).
- [x] Repo-grundfiler: `.gitignore`, `.gitattributes` (LF), `.editorconfig`, `.nvmrc`.
- [x] Plugin-entry `ccmerch-dashboard.php` (kun header og ABSPATH-guard).
- [x] Composer (projekt-lokalt via `bin/composer.mjs`), PSR-4-autoload `CCMerch\` → `src/`.
- [x] PHPCS + WordPress Coding Standards 3.4, PHPStan 2.2 level 8 med WordPress-stubs.
- [x] npm + `@wordpress/scripts` 35 (ESLint, Prettier, stylelint; build fra fase 13).
- [x] Lokal WordPress via WordPress Playground (`npm run wp:start`), WooCommerce via blueprint. Verificeret: WP 7.1.2, PHP 8.2, WooCommerce og CC Merch Dashboard er aktive.
- [x] GitHub Actions CI: PHP-syntaks 8.2, PHPCS, PHPStan og ESLint.
- [x] Dokumentation: arkitektur, moduler, database, API, frontend/design system, admin, WooCommerce, security, roller, test, udvikling, ADR 001-016, roadmap, feature-specs og AI-guidelines.
- [x] Project Isolation udvidet til eksterne services og connectors. **Dinero og Google Drive er ikke en del af projektets nuværende eller planlagte integrationsarkitektur** og må ikke bruges (ADR-016).

## In progress

Intet.

## Planned

Se [ROADMAP.md](ROADMAP.md). Næste tre:

1. **Fase 2:** Plugin bootstrap + PHPUnit.
2. **Fase 3:** Migrationer + creator-tabeller.
3. **Fase 4:** Logging og audit.

## Known bugs

Ingen (der er ingen funktionalitet endnu).

## Technical debt

| Emne | Beskrivelse | Handling |
| --- | --- | --- |
| npm audit | 11 advisories (5 moderate, 6 high) i transitive dev-dependencies af `@wordpress/scripts`. Påvirker ikke det distribuerede plugin. | Tjek ved hver opdatering af `@wordpress/scripts` |
| PHPStan-paths | `phpstan.neon.dist` analyserer kun hovedfilen, fordi `src/` ikke findes endnu | Tilføj `src/` og `uninstall.php` i fase 2 |
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

1. **Navn/branding:** Er "CC Merch Dashboard" / prefix `ccmerch` det rigtige produktnavn? Et navneskift er billigt nu og dyrt senere. (fase 2)
2. **Hosting:** Hvilken PHP- og MySQL/MariaDB-version kører produktionen? Findes der et eksisterende WooCommerce-produktionssite, som pluginet skal installeres på? (fase 2 / 16)
3. **TypeScript** til dashboardet: ja/nej? Anbefaling: ja, hvis teamet kender det, fordi API-kontrakter og refaktorering over flere år vinder på det. Ellers JS + JSDoc. (fase 13)
4. **Teams:** Kan en creator have flere brugere (fx manager)? (fase 6. Påvirker kun, om vi bygger det nu.)
5. **Revenue-model:** Én creator pr. produkt, eller collabs med split? Opgøres creator-omsætning før/efter rabat, ekskl./inkl. moms? Hvilke ordrestatusser tæller? (fase 17-18)
6. **Privacy:** Må creators se *nogen* kundedata (fx land)? Default: ingen PII. (fase 19)
7. **UI-sprog:** Dansk, engelsk eller begge i creator-dashboardet? (fase 15)
8. **Dashboard-URL:** `/creator-dashboard/` eller andet (fx `/studio/`)? (fase 12)
9. **Lokal persistent WordPress:** Er Playground (flygtigt) tilstrækkeligt, eller ønskes et dedikeret LocalWP-site? Det skal i så fald udpeges eksplicit (Project Isolation). (fase 7)

## Next recommended steps

1. Tjek at første CI-kørsel er grøn under GitHub → Actions.
2. Besvar åbent spørgsmål 1 og 2 (navn og hosting) før fase 2.
3. Kør fase 2 med prompten: *"Implementér roadmap-fase 2 (Plugin bootstrap) jf. docs/ROADMAP.md. Følg docs/AI-DEVELOPMENT.md."*
