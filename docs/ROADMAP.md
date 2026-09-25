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
| WooCommerce | 17-19 | Produkt↔creator, sales ledger, produkter/ordrer i dashboard |
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
- **MySQL/MariaDB:** MariaDB 10.11.19 verificeret i fase 7. MySQL afventer CI-resultatet, og staging kommer i fase 16 (se [DATABASE.md](DATABASE.md#mysqlmariadb-vs-playground-sqlite)).

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

## Fase 17 – WooCommerce foundation

- **Scope:** `WooCommerceModule` (betinget indlæsning), `WC requires at least`, creator-felt på produkt, "Creator"-kolonne og -filter i produktlisten og audit-log af koblinger.
- **Præciseret før implementering (ADR-030, projektejerens beslutninger D1-D6):**
  - WooCommerce ≥ 10.3 (seneste linje, der kræver WordPress 6.7). Under minimum indlæses modulet ikke, og admin ser en notice.
  - Kolonnen "Creators" i ordrelisten er udskudt, og fasen har ingen ordrelogik.
  - `_merchboard_creator_id` slettes kun ved opt-in-uninstall.
  - Ingen nye dependencies.
  - Én autoritativ skrivevej (kun den klassiske produktskærm). REST og CSV-import kan ikke ændre koblingen, quick/bulk edit har intet felt, og duplicate starter uden kobling.
  - Ingen nye REST-endpoints og ingen migration.
- **Done:** Produkter kan kobles til creators. Pluginet virker stadig uden WooCommerce.

## Fase 18 – Sales ledger

- **Scope:** migration for `merchboard_sales`, `SalesRecorder` (status-, refund- og delete-hooks), idempotens, reversals, konfigurerbare tællende statusser, backfill via Action Scheduler + WP-CLI-kommando.
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
