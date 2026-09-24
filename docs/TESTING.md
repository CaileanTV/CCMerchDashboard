# Teststrategi

> Status: Statisk analyse, lint og **PHP unit-tests** kører lokalt og i CI (fra fase 2). **PHP-integrationstests** mod en rigtig database kører lokalt mod MariaDB og i CI mod MySQL (fra fase 7). Øvrige niveauer er planlagt.

## Niveauer

| Niveau | Værktøj | Hvad | Hvornår | Kører |
| --- | --- | --- | --- | --- |
| Statisk | PHPCS (WPCS), PHPStan level 8, ESLint, `php -l` (8.2) | Standarder, security-sniffs (escaping, nonce, prepared SQL), typefejl | Nu | Lokalt og i CI |
| PHP unit | PHPUnit + [Brain Monkey](https://github.com/Brain-WP/BrainMonkey) | Domænelogik, services, entities og validering uden WordPress | Fase 2 → | Lokalt og i CI |
| PHP integration | PHPUnit 11 + rigtig WordPress (`roots/wordpress-no-content`) + rigtig MySQL/MariaDB | Migrationer, lås, transaktioner, repositories, charset og services mod rigtig database. Senere capabilities, REST-permissions og **IDOR-tests** | Fase 7 → | Lokalt (portabel MariaDB) og i CI (runnerens MySQL) |
| JS unit | Jest via `wp-scripts test-unit-js` + Testing Library | Hooks, utils og komponenter med logik | Fase 13 → | Lokalt og i CI |
| E2E | Playwright via `wp-scripts test-e2e` mod Playground | Login-flow, adgangskontrol og kritiske dashboard-flows | Efter MVP | CI |
| Manuel | Tjekliste i PR/fase-afslutning | UX, responsivitet og tilgængelighed | Hver fase | Lokalt (`npm run wp:start`) |

**Unit-tests fra fase 2:** `Container`, `Autoloader` (inkl. afvisning af path traversal i klassenavne), `Requirements` (versionstjek og escaped notice kun for admins) og `Plugin` (hooks, modul-boot, validering af filter-output, idempotens og HPOS-erklæring i separat proces). Kør med `npm test`.

**Unit-tests fra fase 3 (databasefundament):**

- `Tables`: prefix fra `$wpdb->prefix`, og afvisning af alle ikke-registrerede tabelnavne (inkl. SQL injection-forsøg).
- `CreateCreatorsTable`: kolonner, nullable `user_id`, UNIQUE/KEY, `dbDelta`-formatregler, ingen FOREIGN KEY eller CURRENT_TIMESTAMP, verifikation af tabellen og idempotens.
- `Migrator`: version 0 → 1, ingen genkørsel når aktuel, rækkefølge, version gemt efter hver succes, fejl bevarer sidste version og frigiver låsen, fremmed lås respekteres, forladt lås overtages.
- `Installer`: aktivering, idempotens, versionstjek, fejl-backoff, notice kun for administratorer.
- `Creator`/`CreatorStatus`: ID-validering, nullable user, kun `active`/`inactive`.
- `CreatorRepository`: kun prepared queries (`%i`/`%d`), whitelisted tabel, ID-validering før query, typed hydrering med UTC, afvisning af ugyldig status/tidsstempel, insert-formater, creator-ID uafhængigt af user-ID, generiske fejl uden databasedetaljer.
- `Plugin`: activation hook, upgrade før moduler, Creators-modul som standard.

Test-doubles: `tests/Unit/Support/RecordingWpdb` (optager prepare/insert i stedet for at køre SQL, så testene viser *hvordan* databasen kaldes), `InMemoryOptions` (options/transients med `add_option`-semantik) og `RecordingMigration`. `tests/Fixtures/wpdb.php` er en tom `wpdb`-stub, så typehints virker uden WordPress (ekskluderet fra PHPStan).

**Hvad unit-tests ikke kan bevise:** at SQL faktisk virker mod en database. Det dækkes af integrationstestene fra fase 7 (se nedenfor) og af Playground-runtime-kontroller (SQLite). Se [DATABASE.md](DATABASE.md#mysqlmariadb-vs-playground-sqlite).

**Unit-tests fra fase 4 (logging og fejlhåndtering):**

- `PluginLogger`/`LogLevel`: alle fire niveauer, tærskel (dropper under), `WP_DEBUG`-default, filter og ugyldigt filter, niveau-navne = WooCommerce/PSR-3.
- `LogContext`: maskering af 21 følsomme nøgletyper (også indlejret), bevarelse af diagnostiske skalarer, fremmed exception uden besked, egen exception med besked uden stier, objekter, afkortning, dybde, kontroltegn og log-forfalskning.
- Backends: `ErrorLogWriter`-format; `WooCommerceLogWriter` uden WooCommerce (fallback), WooCommerce indlæst men ikke initialiseret (fallback), WooCommerce initialiseret (source `merchboard`), og en WooCommerce-logger der kaster (fallback uden tab). WooCommerce-scenarierne kører i isolerede processer med `tests/Fixtures/woocommerce-logger.php`.
- `Migrator`: fejl-context (version, klasse, årsag, `db_error`), `db_error` afkortet og HTML/backtrace fjernet.
- `Installer`: migrationsfejl logges præcis én gang som `error`, 5-minutters pause bevares, version gemmes ikke, succes logges som `info`, aktuel schema logger intet, holdt lås er kun `debug`, og noticen indeholder ingen databasedetaljer.
- `Plugin`: loggerservice virker uden WooCommerce og bruger WooCommerce' logger, når den findes.

`tests/Unit/Support/RecordingLogger` optager, hvad services beder om at logge, og `RecordingLogWriter` optager, hvad der når backenden.

**Unit-tests fra fase 5 (roller og capabilities):**

- `Capabilities`: creator-rollen har præcis `read` + `merchboard_access_dashboard`, ingen af 14 administrative WordPress-/WooCommerce-capabilities, ingen plugin-admin-caps. Admin-sættet og det samlede sæt er præcis de dokumenterede. Prefix-ejerskab.
- `RoleManager`: oprettelse med præcise caps og display name, administrator beholder alle eksisterende caps og får kun pluginets, idempotens (rollen oprettes én gang, ingen ændringer ved gentagelse), andre roller urørte, eskaleret creator-rolle reduceres og logges, nægtet cap gen-tildeles, forældede `merchboard_*` fjernes fra admin, men fremmede bevares, manglende administrator-rolle, fejl ved oprettelse, versionstjek, samt positive/negative rettigheder for admin, creator, customer+creator og subscriber (inkl. ikke-definerede caps).
- `Installer`/`Plugin`: aktivering synkroniserer roller (også når en migration fejler), `maybe_upgrade` synkroniserer kun ved forældet version, plugin reload rører ikke aktuelle roller.

Test-doubles: `tests/Fixtures/wp-role.php` (minimal `WP_Role`, ekskluderet fra PHPStan) og `tests/Unit/Support/InMemoryRoles` (`get_role`/`add_role`/`remove_role` med WordPress-semantik og en rolle-baseret capability-check).

**Hvad unit-tests ikke kan bevise:** WordPress' faktiske `user_can()`-opløsning. Den er verificeret i Playground med rigtige brugere (se PROJECT-STATUS).

**Unit-tests fra fase 6 (creator-administration og audit):**

- `CreatorService`: create/update/status/link/unlink/relink, audit pr. semantisk ændring med minimal context (display names aldrig i audit), capability-krav for alle operationer (creator, subscriber, plugin-admin uden capability og WP-admin uden capability afvises), ukendt/ugyldig creator, ukendt bruger, bruger koblet andetsteds, ugyldige navne, uændret redigering skriver intet, **fejlet audit-skrivning ruller creator-ændringen tilbage** (ingen rolleændring), fejlet creator-skrivning skriver ingen audit, actor fra serveren (NULL uden bruger), timestamps.
- `CreatorAdminActions`: gyldige flows, 403 for uautoriserede (selv med gyldig nonce), manglende/forfalsket nonce, **nonce bundet til creator-ID** (ID-bytte afvises), ugyldig status/bruger/navn, ikke-skalært input, sanitization, requesten kan ikke sætte actor/tid/ID, teknisk fejl logges én gang og vises neutralt, whitelistede notices.
- `CreatorsAdminPage`: formularer renderes (regression: enum i `selected()` gav fatal i Playground), sikker default `inactive`, nonce bundet til ID, brugere koblet andetsteds tilbydes ikke, escaping af display name, whitelistede notices, 403 uden capability.
- `AuditTrail`/`AuditRepository`: lukket action-sæt, server-side actor/UTC, NULL-actor for system, følsomme nøgler gemmes aldrig, ugyldige ID'er, generisk fejl, `for_creator`, append-only (kun `append`/`for_creator` er offentlige).
- `Transaction`: commit, rollback og videre-kast, start- og commit-fejl, nested afvises.
- `CreatorRepository`: rename/status/link/unlink opdaterer `updated_at`, UNIQUE som sidste net, manglende creator, ugyldige ID'er/navne, `paginate` (filter, sortering, sider, whitelist, max 100), `linked_user_ids`.
- Migration 2 (`CreateAuditLogTable`) og opgradering 1 → 2.

Test-doubles: `FakeDatabase` (in-memory `wpdb`, der udfører pluginets prepared queries med UNIQUE, auto-increment og transaktion med snapshot/rollback, og som kaster på ukendte query-former), `FakeUserAccounts` og `WpDie`. Service, repositories, audit og transaktion testes med deres rigtige implementeringer.

**Unit-tests fra fase 7:** `Migrator`-låsen (atomisk insert i stedet for `add_option`, token i værdien, ikke autoload, fremmed lås respekteres, forladt/ulæselig/ældre lås overtages, compare-and-swap på den forældede værdi, tabt kapløb migrerer ikke, frigivelse sletter aldrig en overtaget lås), `TransactionSupport` (spørger kun `information_schema` om egne tabeller, rapporterer ikke-transaktionelle og manglende tabeller, ukendt engine og fejlet query fejler lukket, små bogstaver i tabelnavne, positivt resultat caches, SQLite spørges ikke), `Transaction` afviser at starte uden transaktionel engine, admin-noticen, samt audit-context som ASCII-JSON, malformed context og manipulerede rækker. Test-doubles: `RecordingWpdb` emulerer lås-rækken med MySQL's affected-rows-semantik, `FakeDatabase` rapporterer storage engines, og `CallbackMigration` lader en test handle midt i en migration.

## Integrationstests (fase 7)

```bash
npm run test:integration          # alle
npm run test:integration -- --filter TransactionTest
```

- **Harness** (`tests/Integration/`): `bootstrap.php` installerer en frisk WordPress 7.1.2 (`roots/wordpress-no-content`, Composer dev-dependency) i en separat proces og indlæser derefter WordPress med pluginet, som et must-use-plugin ville blive det. Hver test starter uden pluginets tabeller og schema-options, så hver test beviser sin egen vej fra et tomt schema. Pluginets tekniske log skrives til `.tools/wp-content/debug.log`. Den første linje i output viser databaseversion, default engine, charset, collation og WordPress-sessionens `sql_mode`.
- **Database lokalt (Windows):** `bin/integration.mjs` henter én gang den officielle portable MariaDB-ZIP (fastlåst version og SHA-256) fra archive.mariadb.org til `.tools/mariadb/`, initialiserer en tom datamappe i `.tools/mariadb-data/` og starter serveren kun under kørslen på 127.0.0.1:3316 (`MERCHBOARD_MARIADB_PORT`). Der installeres ingen Windows-service, og kørslen afbrydes, hvis porten er optaget.
- **Database i CI / egen database:** Sæt `MERCHBOARD_TEST_DB_HOST` (og `_PORT`, `_NAME`, `_USER`, `_PASSWORD`). Databasen oprettes, hvis den mangler, og alle tabeller med prefix `mbtest_` droppes. Brug derfor kun en database, der udelukkende findes til testene. CI-jobbet `PHP integration (MySQL)` bruger den MySQL-server, som GitHubs Ubuntu-runner leverer.
- **Hvad der testes:** schema (kolonner, typer, indexes, charset/collation og engine via `information_schema`) og `dbDelta()`-idempotens, migrationer (0 → 2, 1 → 2 med data, aktuel, gentagen, fejl med rigtig serverfejl, Installer-pause), lås (holdt, forladt, atomisk insert, `add_option`-problemet og 6 samtidige processer), transaktioner (commit, rollback ved audit-fejl via database-trigger, ingen audit ved creator-fejl via UNIQUE-brud/trigger, MyISAM afvises og ignorerer faktisk rollback, neutral admin-fejl med log), repository (typer, duplikat, flere `NULL`, status, manipuleret status, UTC under fremmed tidszone, Unicode byte-præcist, `utf8mb3`-tabel, SQL injection-strenge, sortering, ugyldige ID'er), audit (actor, NULL i stedet for 0, ASCII-JSON på `utf8mb3`, malformed og manipuleret context) og `CreatorService` end-to-end med rigtige brugere og roller (livscyklus, uautoriserede brugere, én bruger pr. creator).

| Engine | Status |
| --- | --- |
| SQLite (Playground) | Runtime-kontroller via det rigtige admin-UI og probes (fase 3-7) |
| MariaDB 10.11.19 | **Verificeret:** alle integrationstests grønne lokalt (fase 7) |
| MySQL | CI-job findes. Resultatet er **ikke set**, og MySQL er derfor ikke verificeret |

**Fase 8 (creator access og brugersletning):**

- Unit: `CreatorContext`-matrix (subscriber, administrator med og uden record, capability uden record, record uden capability, inaktiv, aktiv), udlogget, ukendt/slettet bruger, deaktivering/frakobling virker straks, request-data ignoreres, databasefejl logges og nægter adgang. `release_deleted_user` (frigivelse og deaktivering, record og data bevares, systemaudit uden persondata, kun frakobling for inaktiv, intet for levende, ukoblede og ugyldige brugere, ingen rolleændringer, rollback ved audit-fejl, ingen audit ved skrivefejl, fail closed på MyISAM, betinget frigivelse ved gen-kobling), `DeletedUserHandler` (logger i stedet for at kaste, ignorerer ugyldige argumenter) og `AuditTrail::record_system`.
- Integration (MariaDB, rigtig WordPress): adgangsmatrix med rigtige roller og `user_can()`, fuld livscyklus til og med `wp_delete_user()`, sletning af bruger uden creator, audit-fejl via trigger (rollback, WordPress-sletningen gennemføres, fejlen logges), frigivelsesfejl uden audit, fail closed på MyISAM, frakobling bevarer andre roller, ingen privilege escalation og et genbrugt bruger-ID med dinglende link giver ingen adgang.

**Fase 9 (lifecycle og permanent sletning):**

- Unit: `Settings` (default fra, forfalskede værdier som `"1"`, `"yes"` og `1` aktiverer ikke, korrupt option ignoreres), `SettingsService` (bekræftelse påkrævet for at slå til, ikke for at slå fra, audit, rollback ved fejlet option-skrivning, uændret skriver intet, fire typer uautoriserede brugere), `SettingsActions` (403 uden capability, manglende/forfalsket/fremmed nonce, kun checkbox-værdien `1`, ekstra felter ignoreres, teknisk fejl logges neutralt), `SettingsPage` (tydelig advarsel, bekræftelsesfelt kun når fra, 403, whitelistede notices), `Uninstaller` (uden opt-in eller med forfalsket opt-in røres intet) og `SchemaEraser` (præcise tabelnavne for prefix, stopper og logger ved fejl, opt-in bevares til genforsøg).
- Integration (MariaDB, rigtig `uninstall.php`): deaktivering ændrer intet, uninstall uden opt-in og med forfalsket opt-in bevarer alt, og uninstall med opt-in fjerner kun pluginets data. Brugere med creator-, subscriber- og custom-rolle samt direkte capabilities bevares, og det samme gør administratorens øvrige caps, custom-rolle, posts, post meta, fremmede options og en tabel med lignende navn for et andet site. Der skrives ingen audit under uninstall, gentagen uninstall er sikker, og et fejlet tabel-drop bevarer opt-in'en til genforsøg.

**Legacy-syntaks:** CI-jobbet `php-legacy-syntax` kører `php -l` på PHP 7.2 for hovedfilen, `src/Core/Requirements.php` og `uninstall.php`, så versionstjekket kan vise en notice i stedet for en parse error.

## Security-tests er obligatoriske

For hvert endpoint/handler, der eksponerer creator-data, skal der være tests for mindst (se [API.md](API.md#test-krav)):

1. uautentificeret, 2. uden creator-adgang, 3. inaktiv creator, 4. **anden creators ressource (IDOR)** og 5. ugyldigt input.

For admin-handlers: manglende capability og manglende/ugyldig nonce.

## Konventioner

- `tests/Unit/<Modul>/…Test.php` spejler `src/`. Namespace: `MerchBoard\Tests\Unit\…`. Arv fra `MerchBoard\Tests\Unit\TestCase` (Brain Monkey setup/teardown). Fixtures uden for autoload ligger i `tests/Fixtures/`.
- PHPCS gælder også tests, men uden krav om docblocks på testklasser/-metoder (beskrivende navne i stedet) og uden prefix-krav (tests definerer `ABSPATH` og efterligner WooCommerce-namespaces).
- Én adfærd pr. test og beskrivende navne (`test_inactive_creator_cannot_access_dashboard`).
- Ingen tests mod eksterne tjenester. Brug fakes.
- En bugfix kommer med en test, der fejler uden fixet.

## Definition of Done (test-delen)

- `npm run lint` er grøn.
- Relevante unit-/integrationstests er skrevet og grønne.
- Manuel verifikation i Playground for UI-ændringer (desktop og mobilbredde).
- CI er grøn efter push.
