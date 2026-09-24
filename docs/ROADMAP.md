# Roadmap

Overordnet plan. Hver fase er lille nok til én afgrænset Claude Code-prompt, og roadmappet må ændres løbende. Ændringer i rækkefølge eller scope noteres i [PROJECT-STATUS.md](PROJECT-STATUS.md).

**Fælles Definition of Done for alle faser:** `npm run lint` og relevante tests er grønne, security-tjeklisten ([SECURITY.md](SECURITY.md#tjekliste-pr-ændring)) er gennemgået, feature-dokumenter, PROJECT-STATUS og evt. DATABASE/DECISIONS/CHANGELOG er opdateret, ændringerne er committed i små commits og pushed, og CI er grøn.

## Overblik

| Område | Faser | Milepæl |
| --- | --- | --- |
| Foundation | 1-2 | Tooling, docs og plugin-bootstrap |
| Data & core services | 3-4 | Migrationer, creator-tabeller, logging |
| Authentication & permissions | 5-8 | Roller, creator-domæne, testinfrastruktur, adgangsflow |
| Admin | 9-11 | Menu, settings, creator-administration, logs |
| Creator Dashboard | 12-15 | Route/shell, frontend-fundament, profil, overview → **MVP** |
| Deployment | 16 | Release-pipeline og staging |
| WooCommerce | 17-19 | Produkt↔creator, sales ledger, produkter/ordrer i dashboard |
| Statistics | 20 | Salgsanalyse og grafer |
| Senere | – | Payouts, notifications, integrations, campaigns, support, reporting, hardening og E2E |

---

## Fase 1 – Project foundation ✅

Analyse, dokumentation, dev-tooling (Composer, PHPCS/WPCS, PHPStan, wp-scripts/ESLint/Prettier), Playground-miljø og CI.

## Fase 2 – Plugin bootstrap ✅

- **Mål:** Pluginet booter via et modulsystem uden funktionalitet.
- **Leveret:** `CCMERCH_VERSION` og dobbelt-load-guard i hovedfilen, PHP-/WP-versionstjek med admin notice (PHP 7.2-syntaks, CI-tjekket), egen PSR-4-autoloader (ADR-017, erstatter den planlagte Composer-autoload ved runtime), `Plugin`, `Container`, `Module`-interface, HPOS-kompatibilitetserklæring, `uninstall.php`-skelet, `.distignore`, PHPUnit + Brain Monkey (38 unit-tests), PHPStan på `src/`, `uninstall.php` og `tests/`, samt `npm test`/`composer test` i CI.
- **Flyttet til senere faser**, fordi de ikke har en funktion endnu: activation hook (→ fase 3), deactivation hook (→ fase 4), load af text domain (→ fase 15). Se [ARCHITECTURE.md §5](ARCHITECTURE.md#5-boot-flow).
- **Done:** Pluginet aktiveres, deaktiveres og genaktiveres uden notices eller fatals i Playground (WP_DEBUG). Tests og lint er grønne.

## Fase 3 – Database-fundament og creator-tabeller

- **Mål:** Versionerede migrationer og creator-schema.
- **Scope:** `register_activation_hook` + `Activator` (i `Plugin::register_hooks()`), `Tables`, `Migrator` (lås, versions-option), `Upgrader` (kører ved versionsskift), migration `0001` for `ccmerch_creators` + `ccmerch_creatormeta`, registrering af meta-type. Unit-tests af Migrator-logik.
- **Done:** Tabeller oprettes ved aktivering og ved opgradering. [DATABASE.md](DATABASE.md) og CHANGELOG er opdateret.

## Fase 4 – Logging og audit trail

- **Scope:** migration for `ccmerch_logs`, `Logger` (levels, channels, audit altid), `LogRepository`, retention-cron, `register_deactivation_hook` + `Deactivator` (fjerner cron), log-niveau fra settings/miljø.
- **Done:** Logger kan bruges fra services. Retention er testet.

## Fase 5 – Roller og capabilities

- **Scope:** `Capabilities`: rollen `ccmerch_creator`, admin-caps på `administrator`, sync ved aktivering og versionsskift, `map_meta_cap` for `ccmerch_view_creator`/`ccmerch_edit_creator` og oprydning ved uninstall.
- **Done:** Caps er synlige i Playground. Unit-tests for meta-cap-mapping.

## Fase 6 – Creator-domæne

- **Scope:** `Creator` (readonly entity), `CreatorStatus` (enum), `CreatorRepository` (CRUD, find_by_user, paginering), `CreatorService` (validering, rolle-tildeling, statusskift, events og audit-log), `delete_user`-håndtering.
- **Done:** Unit-tests af service-regler. Ingen UI endnu.

## Fase 7 – Integrationstest-infrastruktur

- **Scope:** WordPress test suite (`wp-phpunit`, `yoast/phpunit-polyfills`), `tests/Integration`-bootstrap, CI-job med MySQL-service og de første integrationstests for repository og migrationer.
- **Done:** Integrationstests kører grønt i CI. Lokal kørsel er dokumenteret (kræver MySQL).

## Fase 8 – Creator access og login-flow

- **Scope:** `CreatorContext`, `LoginRedirect` (inkl. WooCommerce), `AdminAreaGuard`, skjult admin bar, application passwords slået fra for creators. Se [features/creator-access.md](features/creator-access.md).
- **Done:** Integrationstests for alle adgangskombinationer og manuel test i Playground.

## Fase 9 – Admin shell: menu, overview og settings

- **Scope:** Topmenu "CC Merch", Overview (systemstatus: WooCommerce, migrationer, rewrite), Settings-side (Settings API, tabs General/Access/Advanced, én option med whitelist-sanitization).
- **Done:** Settings gemmes, valideres og audit-logges. Capability-tests.

## Fase 10 – Admin creator management

- **Scope:** `CreatorsListTable`, opret/rediger/statusskift via `admin-post`, link på `user-edit.php`. Se [features/admin-creator-management.md](features/admin-creator-management.md).
- **Done:** Integrationstests for nonce, capability og validering. Manuel UX-test.

## Fase 11 – Admin logs viewer

- **Scope:** Logs-side med `WP_List_Table`, filtre (level, channel, periode) og visning af context.
- **Done:** Kræver `ccmerch_view_logs`. Output er escaped.

## Fase 12 – Creator dashboard: route og sikker shell

- **Scope:** rewrite rule og query var (slug fra settings, flush ved ændring, ved aktivering og i `Deactivator`), `ShellController` (auth-gate: login-redirect / 403-side / shell), `templates/dashboard/shell.php` uden theme, security headers og en placeholder-side uden JS.
- **Done:** Integrationstests for adgang. Deep links virker.

## Fase 13 – Dashboard frontend-fundament

- **Forudsætning:** TypeScript-beslutning truffet (åbent spørgsmål).
- **Scope:** `npm run build`/`start`, entry `assets/src/dashboard`, `@wordpress/element`/`api-fetch`/`i18n` som dev-deps, `Assets`-service (asset.php), bootstrap-data, design tokens, layout (sidebar/topbar/mobil-nav), router, `useApi`, kernekomponenter (Button, Card, Alert, Skeleton, EmptyState, ErrorState, Field), ESLint `react/no-danger`, Jest-setup, `build` i CI.
- **Done:** Shellen viser et responsivt tomt layout med navigation. JS-tests og lint er grønne.

## Fase 14 – REST-fundament, `/me` og Profile

- **Scope:** `RestController`-basisklasse, `GET /me`, `PATCH /me/profile` (whitelist), Profile-sektion med formular, validering og toast. Alle security-tests fra [API.md](API.md#test-krav).
- **Done:** IDOR- og permission-tests er grønne.

## Fase 15 – Dashboard overview → **MVP**

- **Scope:** Overview-sektion (velkomst, creator-info, KPI-kort med empty state "Salgsdata kommer snart"), sektion-registry fuldt koblet, dansk oversættelse af UI-strenge (`languages/`, `load_plugin_textdomain()` på `init`, `wp_set_script_translations()`).
- **Done:** MVP: admin kan oprette en creator, creator kan logge ind, se overview og redigere sin profil, og intet andet.

## Fase 16 – Release-pipeline og staging

- **Scope:** GitHub Actions-workflow på tag `v*`: composer `--no-dev`, `npm run build` og zip efter `.distignore`, med zip som release-asset. Versions-bump-procedure. Staging-deploy-procedure (manuel upload eller SFTP, afhængigt af hosting). MySQL-verifikation på staging.
- **Done:** v0.x-zip installeres på staging.

## Fase 17 – WooCommerce foundation

- **Scope:** `WooCommerceModule` (betinget indlæsning), `WC requires at least`, creator-felt på produkt, "Creator"-kolonne og -filter i produktlisten og audit-log af koblinger.
- **Done:** Produkter kan kobles til creators. Pluginet virker stadig uden WooCommerce.

## Fase 18 – Sales ledger

- **Scope:** migration for `ccmerch_sales`, `SalesRecorder` (status-, refund- og delete-hooks), idempotens, reversals, konfigurerbare tællende statusser, backfill via Action Scheduler + WP-CLI-kommando.
- **Done:** Integrationstests for sale, refund (hel og delvis), cancel efter complete og gentagne hooks.

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
