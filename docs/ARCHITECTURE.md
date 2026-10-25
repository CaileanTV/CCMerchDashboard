# Arkitektur

> Status: **Delvist implementeret.** Bootstrap, autoloader, container og modulkontrakt (fase 2) samt databasefundament, migrationer og creator-datalag (fase 3) er implementeret. Se §5 og §7. Resten er design. Afvigelser fra dette dokument kræver en ADR i [DECISIONS.md](DECISIONS.md).

## 1. Overblik

```text
                     ┌─────────────────────────── WordPress ────────────────────────────┐
                     │                                                                  │
 Administrator ──►   │  wp-admin (PHP, WP-mønstre)          Creator Dashboard (React)   │ ◄── Creator
                     │  Merch Dashboard-menu, WooCommerce-          /creator-dashboard/*        │
                     │  kolonner/felter                      standalone HTML-shell       │
                     │        │ admin-post.php + nonces             │ REST (cookie + nonce)
                     │        ▼                                     ▼                    │
                     │  ┌───────────────────── merchboard plugin ─────────────┐  │
                     │  │  Modules: Creators · Access · Admin · Dashboard · Rest ·    │  │
                     │  │           Logging · WooCommerce (valgfri) · …               │  │
                     │  │  Core:    Plugin · Container · Migrator · Capabilities ·    │  │
                     │  │           Settings · Assets                                 │  │
                     │  └──────┬──────────────────┬─────────────────────┬─────────────┘  │
                     │         ▼                  ▼                     ▼                │
                     │  wp_users/usermeta   merchboard_* tables    WooCommerce (HPOS)       │
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

Konventionen er låst i ADR-018 (før fase 3). Den tidligere midlertidige konvention `ccmerch`/`CCMerch\` må ikke bruges.

| Element | Værdi |
| --- | --- |
| Produktnavn (UI, plugin-header) | `Merch Dashboard` |
| Plugin-slug / installationsmappe | `merchboard` (git-repo'et hedder `CCMerchDashboard`, men mountes/installeres som `wp-content/plugins/merchboard/`) |
| Hovedfil | `merchboard.php` |
| PHP-namespace (rod) | `MerchBoard\` → `src/` (PSR-4: egen autoloader ved runtime, Composer i tests) |
| Prefix: funktioner, options, hooks og capabilities | `merchboard_` |
| Meta-keys (skjulte) | `_merchboard_` |
| Konstanter | `MERCHBOARD_`. Kun `MERCHBOARD_VERSION` findes nu. Flere (fx `MERCHBOARD_URL` til assets) defineres først, når de bruges. Hovedfilens sti gives til `Plugin` som argument i stedet for en global konstant. |
| Custom tables | `{$wpdb->prefix}merchboard_*` |
| Text domain | `merchboard` |
| REST-namespace | `merchboard/v1` |
| Script-/style-handles | `merchboard-*` |
| CSS-klasser (dashboard) | `merchboard-` (fx `merchboard-card`, `merchboard-card__title`, `merchboard-card--muted`) |
| JS-global til bootstrap-data | `window.merchboardDashboard` |

## 3. Mappestruktur (mål)

```text
merchboard/
├── merchboard.php        # header, konstanter, krav-check, autoload, boot. Ingen logik.
├── uninstall.php                # ✅ fase 9: sletter kun egne data, hvis opt-in "delete data on uninstall" er præcis true (Core/Lifecycle/Uninstaller)
├── src/
│   ├── Plugin.php               # composition root: opretter container og registrerer moduler
│   ├── Core/
│   │   ├── Autoloader.php       # PSR-4-autoloader for MerchBoard\ (ADR-017)           [fase 2]
│   │   ├── Requirements.php     # PHP-/WP-versionstjek + admin notice (PHP 7.2-syntaks) [fase 2]
│   │   ├── Container.php        # minimal lazy service container                    [fase 2]
│   │   ├── Module.php           # interface for moduler                             [fase 2]
│   │   ├── Lifecycle/           # Installer (aktivering + versionstjek) [fase 3], Deactivator (rewrite rules) [fase 12]
│   │   ├── Database/            # Tables, Schema, Migration, Migrator, DatabaseException,
│   │   │                        # Migrations/CreateCreatorsTable [fase 3], CreateAuditLogTable, Transaction [fase 6],
│   │   │                        # TransactionSupport (engine-tjek, fail closed) [fase 7]
│   │   ├── Audit/               # AuditAction, AuditTrail, AuditRepository, AuditEntry [fase 6]
│   │   ├── Security/            # Capabilities, RoleManager [fase 5]; map_meta_cap [fase 14]
│   │   ├── Http/                # RestController-basisklasse (merchboard/v1, require_creator, run, error) [fase 14]
│   │   ├── Settings/            # Settings-service (én option, typed getters, defaults)
│   │   ├── Logging/             # Logger, LogLevel, LogContext, PluginLogger, LogWriter,
│   │   │                        # WooCommerceLogWriter, ErrorLogWriter              [fase 4]
│   │   └── Support/             # Template-renderer (planlagt)
│   └── Modules/
│       ├── Creators/            # CreatorsModule, Creator (entity), CreatorStatus (enum), CreatorRepository [fase 3]
│       │                        # CreatorService, CreatorException, UserAccounts, Admin/ (page, list table, actions) [fase 6]
│       ├── Admin/               # AdminModule, AdminMenu (topmenu), OverviewPage [fase 10]; AuditLogPage, AuditLogListTable, AuditLogFormatter [fase 11]
│       ├── Access/              # AccessModule, CreatorContext [fase 8]; CreatorRestrictions, LoginRedirect, AdminAreaGuard [fase 12]
│       ├── Admin/               # Topmenu, Overview-side, Settings-side, Audit-log-side
│       ├── Dashboard/           # DashboardRoute, ShellController [fase 12]; DashboardAssets (build/dashboard.asset.php) [fase 13]; Rest/MeController (/me, /me/profile) [fase 14]; DashboardSections (registry, filter merchboard_dashboard_sections) [fase 15]
│       └── WooCommerce/         # kun indlæst når WooCommerce er aktiv
├── templates/                   # PHP-templates (dashboard-shell, admin-partials)
├── assets/src/
│   ├── dashboard/               # React + TypeScript-app til creator dashboard [fase 13, se FRONTEND.md#struktur--fase-13]
│   └── (assets/js/)             # små admin-scripts uden build (vanilla JS, fase 10)
├── build/                       # genereret af wp-scripts (gitignored)
├── languages/                   # merchboard.pot, merchboard-da_DK.po/.mo og JS-JSON (fase 15, npm run i18n)
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

1. `merchboard.php` (`defined( 'ABSPATH' ) || exit;`):
   - stopper stille, hvis `MERCHBOARD_VERSION` allerede er defineret (en anden kopi af pluginet er indlæst)
   - definerer `MERCHBOARD_VERSION`
   - kræver `src/Core/Requirements.php` manuelt og kalder `Requirements::check()`. Er PHP < 8.2 eller WordPress < 6.7, registreres en admin notice (kun synlig for brugere med `activate_plugins`, output escaped), og pluginet stopper **uden fatal error**. Hovedfilen og `Requirements.php` skrives derfor i PHP 7.2-syntaks, og CI tjekker dem med `php -l` på PHP 7.2.
   - registrerer projektets egen PSR-4-autoloader (`MerchBoard\` → `src/`, ADR-017). Pluginet bruger ikke `vendor/` ved runtime.
   - opretter `new Plugin( __FILE__ )` og kalder `register_hooks()`. Der er ingen singleton og ingen global variabel.
2. `before_woocommerce_init` → `Plugin::declare_woocommerce_compatibility()` erklærer HPOS-kompatibilitet, hvis WooCommerce' `FeaturesUtil` findes. Ellers sker intet.
3. `plugins_loaded` → `Plugin::boot()` (idempotent):
   - **(fase 3)** `Installer::maybe_upgrade()` kører manglende database-migrationer, *før* modulerne registreres, så moduler altid ser det aktuelle schema. Koster én autoloaded option-læsning, når intet mangler. Registrerer også admin notice ved migrationsfejl.
   - modullisten = `default_modules()` (fra fase 3: `CreatorsModule`) gennem filteret `merchboard_modules`
   - ugyldige elementer (ikke `Module`, dublet-ID eller filter der ikke returnerer et array) springes over med `_doing_it_wrong()`, så et fejlbehæftet tredjeparts-filter ikke vælter boot
   - `register( $container )` kaldes på hvert modul
   - action `merchboard_loaded` (med containeren) fyres til sidst

**Fejlhåndtering:** Bootstrappen fanger ikke exceptions fra moduler. En fejl i `register()` er en programmeringsfejl og skal være synlig. WordPress' fatal error handler/recovery mode beskytter sitet.

**Implementeret i fase 3:**

- `register_activation_hook` registreres i `Plugin::register_hooks()`, mens hovedfilen indlæses (ikke på `plugins_loaded`), og kalder `Plugin::activate()` → `Installer::install()`. Det er idempotent og kan køres flere gange.
- Core-services i containeren, registreret i `Plugin`-konstruktøren (lazy): `wpdb` (global `$wpdb`), `Migrator` (med `Schema::migrations()`), `Logger` (fase 4), `RoleManager` (fase 5) og `Installer`.
- **(fase 5)** `Installer::install()` (aktivering) og `maybe_upgrade()` (versionstjek på `plugins_loaded`) synkroniserer også roller og capabilities via `RoleManager` (egen version `merchboard_roles_version`, ADR-022). Uafhængigt af migrationer. Se [USER-ROLES.md](USER-ROLES.md#installation-og-synkronisering-adr-022).
- Detaljer om schemaversion, migrationer, lås og fejlhåndtering: [DATABASE.md](DATABASE.md#schemaversion-og-migrationer--adr-019).

**Endnu ikke implementeret** (tilføjes i den fase, hvor de får en funktion):

| Del | Fase | Begrundelse |
| --- | --- | --- |
| ~~`load_plugin_textdomain()` på `init`~~ | ✅ 15 | `Plugin::load_textdomain()` på `init` (prioritet 0), filer i `languages/`. |
| Øvrige core-services (Settings, Capabilities, Audit …) | 5 → | Registreres af de faser, der bygger dem. `Logger` findes fra fase 4. |

Hovedfilen forbliver uændret, når hooks tilføjes. De registreres i `Plugin`, og logikken placeres i `src/Core/Lifecycle/`.

**Lifecycle (✅ fase 9, ADR-025):**

```text
Deaktivering
→ data bevares (tabeller, options, rolle og capabilities uændrede)

Afinstallation (slet plugin) uden eksplicit opt-in
→ data bevares

Afinstallation med eksplicit opt-in ("Delete all Merch Dashboard data when the plugin is deleted")
→ Merch Dashboards egne data slettes, inkl. product meta `_merchboard_creator_id` (fra fase 17, ADR-030) og `_merchboard_creator_link_source` (ADR-033)
→ alle andre WordPress- og WooCommerce-data bevares
```

`uninstall.php` (PHP 7.2-parsebar) → `Requirements` → autoloader → `Core\Lifecycle\Uninstaller` (+ `Core\Database\SchemaEraser`). Settings ligger i `Core\Settings\Settings` (option-adgang uden hooks) og ændres via `Modules\Settings\SettingsService`.

## 6. Modulsystem

Se [MODULES.md](MODULES.md). Kort: et modul implementerer `MerchBoard\Core\Module` (`id()`, `register( Container $c )`), registrerer sine egne hooks, admin-undermenuer, REST-routes og dashboard-sektioner, og kommunikerer med andre moduler via services i containeren eller WordPress-hooks.

## 7. Datalag

Se [DATABASE.md](DATABASE.md). Kort:

- **WordPress users/roles** = identitet og login.
- **`merchboard_creators`** (✅ fase 3) = creator-domæneobjektet, adskilt fra login-kontoen. Creator-ID er den primære reference for al domænedata, og `user_id` er kun en nullable, unik relation til login-laget (ADR-020). `merchboard_creatormeta` kommer i fase 14.
- **WooCommerce** = produkter og ordrer (source of truth). Produkt → creator via product meta `_merchboard_creator_id` (sat manuelt af admin eller automatisk via kategori-mapping, ADR-033).
- **`merchboard_sales`** = append-only ledger med snapshots af ordrelinjer pr. creator (til statistik og payouts), og **`merchboard_sales_cycles`** = append-only salgscyklusser pr. ordre (fase 18, design-låst, ADR-038).
- **Tekniske logs** = WooCommerce-logger eller `error_log()` (fase 4, ADR-021). Ingen tabel.
- **`merchboard_audit_log`** (✅ fase 6, ADR-023) = append-only audit trail (`Core/Audit`: `AuditAction`, `AuditTrail`, `AuditRepository`). Creator-ændringer og audit skrives i én databasetransaktion (`Core/Database/Transaction`).
- **Options** = settings og schemaversion (`merchboard_db_version`).

Schemaændringer sker kun via versionerede migrationer (`src/Core/Database/Migrations/`, registreret i `Schema::migrations()`) og dokumenteres i DATABASE.md og CHANGELOG.md (ADR-019). Databaseadgang sker kun via repositories, som ikke udfører authorization. Det sker i laget over (fase 6+).

## 8. API-arkitektur

Se [API.md](API.md). Kort:

- Kun REST (`merchboard/v1`). **Ingen admin-ajax.** Klassiske admin-formularer poster til `admin-post.php` med nonce og capability-check.
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

> Status: **Implementeret (fase 4, ADR-021).** Tekniske logs. Audit trail er et separat system (fase 6, vist i admin fra fase 11, se nederst).

### Abstraktion

| Del | Rolle |
| --- | --- |
| `MerchBoard\Core\Logging\Logger` (interface) | `debug()`, `info()`, `warning()`, `error()` med `( string $message, array $context = [] )`. PSR-3-*lignende*, men implementerer ikke `psr/log` (ADR-004). |
| `LogLevel` (enum) | `debug` < `info` < `warning` < `error`. Værdierne er identiske med WooCommerce/PSR-3-navnene, så mappingen er 1:1. Ingen `notice`/`critical`/`emergency`, fordi der ikke er brug for dem. |
| `PluginLogger` | Filtrerer på tærskel, renser besked og context (`LogContext`) og sender til en `LogWriter`. Fanger ingen exceptions. |
| `LogContext` | Sanitization (se [SECURITY.md](SECURITY.md#logging)) |
| `LogWriter` (interface) | Backend-kontrakt |
| `WooCommerceLogWriter` | Primær backend, når WooCommerce er klar. Ellers fallback. |
| `ErrorLogWriter` | Fallback: én linje pr. post via `error_log()` |

Loggeren registreres i containeren som `Logger::class` (`PluginLogger::for_wordpress()`) og **injectes** i de services, der skal logge (fase 4: `Installer`). Der er ingen statiske kald, ingen singleton og ingen global state.

### Backend og fallback

| Situation | Backend | Hvor ses posterne |
| --- | --- | --- |
| WooCommerce aktivt **og** `woocommerce_init` har kørt | `wc_get_logger()->log( level, message, context + [ 'source' => 'merchboard' ] )` | WooCommerce → Status → Logs, source `merchboard` (fil- eller database-handler efter WooCommerce' indstilling) |
| WooCommerce ikke aktivt, eller før `woocommerce_init` (fx migration på `plugins_loaded` efter en opdatering) | `error_log( '[merchboard] LEVEL: message {json}' )` | `wp-content/debug.log` når `WP_DEBUG_LOG` er slået til, ellers serverens PHP error log |
| WooCommerce' logger kaster en exception | Posten skrives til fallback med `log_backend_failure` (exception-klassen) i context | Som ovenfor |

Tilgængeligheden tjekkes ved **hver** skrivning, ikke ved boot, så valget altid er korrekt. WooCommerce er aldrig et krav. WooCommerce' logger bruges først efter `woocommerce_init`, fordi et tidligere kald kan få WooCommerce til at indlæse sine oversættelser for tidligt (verificeret i Playground: det gav en `_load_textdomain_just_in_time`-notice).

### Niveauer og debug

- **Tærskel:** `debug`, når `WP_DEBUG` er `true`, ellers `warning`. Production-logs indeholder dermed kun problemer. `info` (fx "Database schema migrated.") skrives kun ved debug eller opt-in.
- **Opt-in/override:** filteret `merchboard_log_level` (`'debug'|'info'|'warning'|'error'`). Ugyldige værdier ignoreres.
- Pluginet **læser** kun `WP_DEBUG` og ændrer aldrig `WP_DEBUG`, `WP_DEBUG_LOG` eller andre debug-indstillinger. Hvor `error_log()` skriver hen, styres af WordPress (`WP_DEBUG_LOG`) eller serveren.
- Med WooCommerce-backenden gælder WooCommerce' egen log-tærskel desuden (WooCommerce → Indstillinger → Avanceret → Logs). Debug-poster kan derfor blive filtreret fra af WooCommerce, selv om pluginet sender dem.
- Intet skrives til browseren: ingen `echo`, `var_dump`, `print_r` eller stack traces.

### Konventioner for kaldere

- Beskeden er en kort, **konstant** engelsk sætning. Variable data hører til i `context` (ID'er, versioner, klassenavne).
- Log der, hvor fejlen **håndteres**, eller hvor unik diagnostik findes, og kun én gang. Log ikke og kast videre i flere lag. Eksempel: `Migrator` lægger diagnostik i `DatabaseException::context()` (schemaversion, migrationsklasse, generisk årsag og en renset, afkortet DDL-fejl), og `Installer`, som håndterer fejlen, logger den én gang.
- Catch kun exceptions, som koden faktisk kan håndtere. Der er tre catch-blokke i pluginet: `Migrator` beriger og kaster videre, `Installer` håndterer (backoff, notice og log), og `WooCommerceLogWriter` bruger fallback. Øvrige exceptions propagerer (WordPress' fatal error handler/recovery mode).
- Admin-meddelelser er **menneskelige og generiske**, mens tekniske detaljer kun står i loggen.

### Audit trail (ikke teknisk logging)

"Hvem ændrede hvad" (creator oprettet/deaktiveret, adgang ændret, settings ændret) er forretningsdata med andre krav (søgbar i wp-admin, lang opbevaring og altid registreret uanset log-niveau). Implementeret i fase 6 som egen tabel og service (`merchboard_audit_log`, ADR-023). Vises read-only i admin fra fase 11 (Merch Dashboard → Audit log, `merchboard_view_logs`). Se [DATABASE.md](DATABASE.md#merchboard_audit_log--schema-version-2-adr-023).

| | **Business audit trail** | **Teknisk logging** |
| --- | --- | --- |
| Formål | Hvem ændrede hvad, hvornår (creators, brugerrelationer, settings) | Fejl og diagnostik til drift |
| Lager | Tabellen `merchboard_audit_log` (ADR-023), append-only | WooCommerce-logger (source `merchboard`) eller PHP `error_log` (ADR-021). Ingen tabel. |
| Visning | Merch Dashboard → Audit log (`merchboard_view_logs`, fase 11) | WooCommerce → Status → Logs (WooCommerce' egen adgang) eller serverens `debug.log` |
| Indhold | Handling, actor, creator og minimal context. Ingen stack traces, ingen tekniske fejl. | Tekniske beskeder og renset context. Aldrig forretningshistorik. |

## 12. Hooks (udvidelsespunkter)

Egne actions og filters prefixes med `merchboard_` og dokumenteres i det modul, der udløser dem. Eksempler (planlagt):

| Hook | Type | Formål |
| --- | --- | --- |
| `merchboard_modules` | filter | Tilføj/fjern moduler |
| `merchboard_creator_created` / `_updated` / `_status_changed` | action | Domæne-events |
| `merchboard_dashboard_sections` | filter | Sektioner/navigation i creator dashboard |
| `merchboard_settings_sections` | action | Ekstra sektioner på Merch Dashboard → Settings (kaldes efter capability-tjekket; sektionerne har egne formularer, nonces og capability-tjek). ✅ Fase 18, trin 6 ("Sales ledger" fra WooCommerce-modulet); runtime-verificeret i Merch Dashboard Local med HPOS og legacy, ADR-038 statusnote "trin 6 afsluttet". |
| `merchboard_sale_recorded` | action | Ny linje i sales ledger. **Udskudt:** ikke en del af fase 18; timing og den offentlige kontrakt vurderes i et senere API-/event-design (ADR-038). |

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
- ~~Impersonation ("se som creator")~~: ikke længere et ikke-mål; bygget i trin 27.2 med audit-design (ADR-075).
