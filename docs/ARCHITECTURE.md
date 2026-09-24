# Arkitektur

> Status: **Delvist implementeret.** Bootstrap, autoloader, container og modulkontrakt er implementeret (fase 2, se §5). Resten er design. Afvigelser fra dette dokument kræver en ADR i [DECISIONS.md](DECISIONS.md).

## 1. Overblik

```text
                     ┌─────────────────────────── WordPress ────────────────────────────┐
                     │                                                                  │
 Administrator ──►   │  wp-admin (PHP, WP-mønstre)          Creator Dashboard (React)   │ ◄── Creator
                     │  CC Merch-menu, WooCommerce-          /creator-dashboard/*        │
                     │  kolonner/felter                      standalone HTML-shell       │
                     │        │ admin-post.php + nonces             │ REST (cookie + nonce)
                     │        ▼                                     ▼                    │
                     │  ┌───────────────────── ccmerch-dashboard plugin ─────────────┐  │
                     │  │  Modules: Creators · Access · Admin · Dashboard · Rest ·    │  │
                     │  │           Logging · WooCommerce (valgfri) · …               │  │
                     │  │  Core:    Plugin · Container · Migrator · Capabilities ·    │  │
                     │  │           Settings · Assets                                 │  │
                     │  └──────┬──────────────────┬─────────────────────┬─────────────┘  │
                     │         ▼                  ▼                     ▼                │
                     │  wp_users/usermeta   ccmerch_* tables    WooCommerce (HPOS)       │
                     │  roles/caps/options  (creators, logs,    products, orders         │
                     │                       sales ledger …)                             │
                     └──────────────────────────────────────────────────────────────────┘
```

Grundprincipper, i prioriteret rækkefølge: **security → maintainability → modularity → simplicity → performance → DX → UX.**

1. **Ét plugin, interne moduler.** Ikke flere plugins, og ikke microservices.
2. **Brug WordPress, hvor WordPress er godt nok:** users, roles, capabilities, login, nonces, REST API, Settings API, metadata API, WP-Cron, i18n og `WP_List_Table`.
3. **Egen kode, hvor WordPress ikke passer:** domænedata med relationer og volumen (creators, sales ledger, logs) får custom tables med et repository-lag.
4. **Server-side authorization overalt.** UI'et skjuler, men serveren håndhæver.
5. **Kun abstraktioner med reel værdi.** Ingen DI-framework, ingen ORM, ingen event-bus ud over WordPress hooks.

## 2. Navngivning

| Element | Værdi |
| --- | --- |
| Plugin-slug / mappe | `ccmerch-dashboard` |
| Hovedfil | `ccmerch-dashboard.php` |
| PHP-namespace (rod) | `CCMerch\` → `src/` (PSR-4 via Composer) |
| Prefix: funktioner, options, hooks og capabilities | `ccmerch_` |
| Meta-keys (skjulte) | `_ccmerch_` |
| Konstanter | `CCMERCH_`. Kun `CCMERCH_VERSION` findes nu. Flere (fx `CCMERCH_URL` til assets) defineres først, når de bruges. Hovedfilens sti gives til `Plugin` som argument i stedet for en global konstant. |
| Custom tables | `{$wpdb->prefix}ccmerch_*` |
| Text domain | `ccmerch-dashboard` |
| REST-namespace | `ccmerch/v1` |
| Script-/style-handles | `ccmerch-*` |
| CSS-klasser (dashboard) | `ccm-` (fx `ccm-card`, `ccm-card__title`, `ccm-card--muted`) |
| JS-global til bootstrap-data | `window.ccmerchDashboard` |

## 3. Mappestruktur (mål)

```text
ccmerch-dashboard/
├── ccmerch-dashboard.php        # header, konstanter, krav-check, autoload, boot. Ingen logik.
├── uninstall.php                # sletter kun data, hvis "delete data on uninstall" er slået til
├── src/
│   ├── Plugin.php               # composition root: opretter container og registrerer moduler
│   ├── Core/
│   │   ├── Autoloader.php       # PSR-4-autoloader for CCMerch\ (ADR-017)           [fase 2]
│   │   ├── Requirements.php     # PHP-/WP-versionstjek + admin notice (PHP 7.2-syntaks) [fase 2]
│   │   ├── Container.php        # minimal lazy service container                    [fase 2]
│   │   ├── Module.php           # interface for moduler                             [fase 2]
│   │   ├── Lifecycle/           # Activator, Deactivator, Upgrader (version-bump → migrationer)
│   │   ├── Database/            # Migrator + Migrations/NNNN_Name.php + Table-navne
│   │   ├── Security/            # Capabilities (roller/caps/map_meta_cap), Nonce/Request-helpers
│   │   ├── Http/                # RestController-basisklasse, response-/fejl-helpers
│   │   ├── Settings/            # Settings-service (én option, typed getters, defaults)
│   │   ├── Logging/             # Logger, LogLevel, LogRepository
│   │   └── Support/             # Assets (læser build/*.asset.php), Template-renderer
│   └── Modules/
│       ├── Creators/            # Creator (entity), CreatorStatus (enum), CreatorRepository,
│       │                        # CreatorService, Admin/ (sider, list table), Rest/ (admin-endpoints)
│       ├── Access/              # CreatorContext, LoginRedirect, AdminAreaGuard
│       ├── Admin/               # Topmenu, Overview-side, Settings-side, Logs-side
│       ├── Dashboard/           # Route (rewrite), ShellController, DashboardSections-registry, Rest/Me*
│       └── WooCommerce/         # kun indlæst når WooCommerce er aktiv
├── templates/                   # PHP-templates (dashboard-shell, admin-partials)
├── assets/src/
│   ├── dashboard/               # React-app til creator dashboard
│   └── admin/                   # evt. små admin-scripts/styles
├── build/                       # genereret af wp-scripts (gitignored)
├── languages/
├── tests/
│   ├── Unit/                    # PHPUnit + Brain Monkey (uden WordPress)
│   └── Integration/             # WP test suite (med database)
└── docs/
```

**Hvorfor `Modules/<Navn>/` med egne `Admin/`- og `Rest/`-undermapper?** Så et modul kan tilføjes, fjernes eller forstås ved at kigge ét sted. Lagdeling (repository/service/controller) sker *inden i* modulet, ikke som globale `Controllers/`- og `Services/`-mapper, der vokser uden grænser.

## 4. Lag inden i et modul

| Lag | Ansvar | Må kalde |
| --- | --- | --- |
| **Controller** (REST-controller, admin page/handler) | HTTP: auth-check, input-validering/sanitization, kald af service, output/escaping | Service |
| **Service** | Forretningsregler, authorization af domænehandlinger, events (`do_action`) og audit-logging | Repository, andre services |
| **Repository** | Al SQL for en tabel. Returnerer entities, aldrig rå arrays ud af laget | `$wpdb` |
| **Entity / value object** | Uforanderlige domæneobjekter (`Creator`) med typed properties og `to_array()` | – |

DTO'er bruges kun, hvor input/output-formen afviger væsentligt fra entity'en (fx et "create creator"-request). Ellers er entity + `to_array()` nok.

Ingen ORM. Repositories skriver eksplicit SQL med `$wpdb->prepare()`, hvilket er let at audite.

## 5. Boot-flow

**Implementeret i fase 2:**

1. `ccmerch-dashboard.php` (`defined( 'ABSPATH' ) || exit;`):
   - stopper stille, hvis `CCMERCH_VERSION` allerede er defineret (en anden kopi af pluginet er indlæst)
   - definerer `CCMERCH_VERSION`
   - kræver `src/Core/Requirements.php` manuelt og kalder `Requirements::check()`. Er PHP < 8.2 eller WordPress < 6.7, registreres en admin notice (kun synlig for brugere med `activate_plugins`, output escaped), og pluginet stopper **uden fatal error**. Hovedfilen og `Requirements.php` skrives derfor i PHP 7.2-syntaks, og CI tjekker dem med `php -l` på PHP 7.2.
   - registrerer projektets egen PSR-4-autoloader (`CCMerch\` → `src/`, ADR-017). Pluginet bruger ikke `vendor/` ved runtime.
   - opretter `new Plugin( __FILE__ )` og kalder `register_hooks()`. Der er ingen singleton og ingen global variabel.
2. `before_woocommerce_init` → `Plugin::declare_woocommerce_compatibility()` erklærer HPOS-kompatibilitet, hvis WooCommerce' `FeaturesUtil` findes. Ellers sker intet.
3. `plugins_loaded` → `Plugin::boot()` (idempotent):
   - modullisten = `default_modules()` (tom i fase 2) gennem filteret `ccmerch_modules`
   - ugyldige elementer (ikke `Module`, dublet-ID eller filter der ikke returnerer et array) springes over med `_doing_it_wrong()`, så et fejlbehæftet tredjeparts-filter ikke vælter boot
   - `register( $container )` kaldes på hvert modul
   - action `ccmerch_loaded` (med containeren) fyres til sidst

**Fejlhåndtering:** Bootstrappen fanger ikke exceptions fra moduler. En fejl i `register()` er en programmeringsfejl og skal være synlig. WordPress' fatal error handler/recovery mode beskytter sitet.

**Bevidst ikke i fase 2** (tilføjes i den fase, hvor de får en funktion):

| Del | Fase | Begrundelse |
| --- | --- | --- |
| `register_activation_hook` → `Activator` (migrationer) | 3 | Intet at oprette endnu. Tomme hooks tilføjer ingen værdi. |
| `Upgrader` (versionsskift → migrationer, rolle-sync) | 3 / 5 | Ingen schema eller roller endnu |
| `register_deactivation_hook` → `Deactivator` (cron, rewrite flush) | 4 / 12 | Ingen cron eller rewrite rules endnu |
| `load_plugin_textdomain()` på `init` | 15 | Ingen oversættelsesfiler endnu. `Text Domain` og `Domain Path` står allerede i headeren. |
| Core-services i containeren (Settings, Logger …) | 3 → | Registreres af de faser, der bygger dem |

Activation/deactivation-hooks registreres i `Plugin::register_hooks()` (de skal registreres, mens hovedfilen indlæses, ikke på `plugins_loaded`), og deres logik placeres i `src/Core/Lifecycle/`. Hovedfilen forbliver uændret.

## 6. Modulsystem

Se [MODULES.md](MODULES.md). Kort: et modul implementerer `CCMerch\Core\Module` (`id()`, `register( Container $c )`), registrerer sine egne hooks, admin-undermenuer, REST-routes og dashboard-sektioner, og kommunikerer med andre moduler via services i containeren eller WordPress-hooks.

## 7. Datalag

Se [DATABASE.md](DATABASE.md). Kort:

- **WordPress users/roles** = identitet og login.
- **`ccmerch_creators` + `ccmerch_creatormeta`** = creator-domæneobjektet (adskilt fra login-kontoen).
- **WooCommerce** = produkter og ordrer (source of truth). Produkt → creator via product meta.
- **`ccmerch_sales`** = ledger med snapshots af ordrelinjer pr. creator (til statistik og payouts).
- **`ccmerch_logs`** = applikations- og audit-log.
- **Options** = settings og schemaversion.

Schemaændringer sker kun via versionerede migrationer (`src/Core/Database/Migrations/`) og dokumenteres i DATABASE.md og CHANGELOG.md.

## 8. API-arkitektur

Se [API.md](API.md). Kort:

- Kun REST (`ccmerch/v1`). **Ingen admin-ajax.** Klassiske admin-formularer poster til `admin-post.php` med nonce og capability-check.
- Creator-endpoints ligger under `/me/...` og tager **aldrig** et creator-ID fra klienten. Creator'en udledes server-side af den indloggede bruger, hvilket fjerner IDOR på creator-niveau.
- Admin-endpoints (`/admin/...`) kræver admin-capabilities.
- Alle routes har eksplicit `permission_callback` og argument-schema.

## 9. Frontend-arkitektur

Se [FRONTEND.md](FRONTEND.md). Kort: Creator dashboard er en React-app (WordPress' egen React via `@wordpress/element`), bygget med `@wordpress/scripts` og serveret fra en dedikeret route med en minimal HTML-shell, der ikke bruger theme. Den har sit eget lille design system (CSS custom properties) og bruger ikke `@wordpress/components`. Admin bruger klassisk PHP-rendering efter WP-mønstre.

## 10. Security-arkitektur

Se [SECURITY.md](SECURITY.md) og [USER-ROLES.md](USER-ROLES.md). Kernen er:

1. **Authentication:** WordPress' login (ingen egen auth).
2. **Authorization:** capabilities (`current_user_can`) og `CreatorContext` (aktiv creator-record knyttet til brugeren) samt ejerskabs-check i services.
3. **Request-integritet:** nonces (REST: `wp_rest`, admin: `check_admin_referer`).
4. **Input:** schema-validering, sanitization og whitelists.
5. **Output:** escaping ved output (PHP: `esc_*`; React escaper selv, og `dangerouslySetInnerHTML` er forbudt).
6. **SQL:** altid `$wpdb->prepare()`.
7. **Audit:** adgangsændringer logges.

## 11. Logging-arkitektur

- `Logger`-service (PSR-3-lignende API: `debug()`, `info()`, `warning()`, `error()`, `critical()` med `channel` og `context`). Den implementerer ikke `psr/log` for at undgå vendor-konflikter (ADR-004).
- Skriver til `ccmerch_logs`. Minimumsniveau styres i settings (default `warning` i production og `debug` når `wp_get_environment_type() === 'local'`).
- Channel `audit` skrives **altid** uanset niveau: adgang givet/fjernet, creator oprettet/ændret/suspenderet, settings ændret. Hver post har actor (`user_id`) og target.
- Retention: daglig WP-Cron-job sletter logs ældre end N dage (setting, default 90; audit 365).
- Aldrig secrets, passwords, tokens eller fuld PII i context. Brug IDs.
- Kritiske fejl skrives desuden til `error_log()` som fallback, hvis databasen fejler.
- Admin-visning: CC Merch → Logs (`WP_List_Table` med filtre).

## 12. Hooks (udvidelsespunkter)

Egne actions og filters prefixes med `ccmerch_` og dokumenteres i det modul, der udløser dem. Eksempler (planlagt):

| Hook | Type | Formål |
| --- | --- | --- |
| `ccmerch_modules` | filter | Tilføj/fjern moduler |
| `ccmerch_creator_created` / `_updated` / `_status_changed` | action | Domæne-events |
| `ccmerch_dashboard_sections` | filter | Sektioner/navigation i creator dashboard |
| `ccmerch_sale_recorded` | action | Ny linje i sales ledger |

Hooks bruges som event-mekanisme. Der bygges ingen separat event-bus.

## 13. Teststrategi

Se [TESTING.md](TESTING.md).

## 14. Build og deploy

Se [DEVELOPMENT.md](DEVELOPMENT.md#build-og-deploy-plan).

## 15. Ikke-mål (indtil videre)

- Multisite-understøttelse (pluginet aktiveres pr. site, men netværksaktivering testes ikke).
- Eget login-/auth-system, SSO eller OAuth for creators.
- Headless/SPA uden WordPress-cookies.
- Flere creators pr. produkt med revenue split (forberedt i datamodellen, men ikke bygget).
- Impersonation ("se som creator"). Kræver audit-design og kommer senere.
