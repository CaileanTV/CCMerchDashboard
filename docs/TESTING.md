# Teststrategi

> Status: Statisk analyse, lint og **PHP unit-tests** kører lokalt og i CI (fra fase 2). Øvrige niveauer er planlagt.

## Niveauer

| Niveau | Værktøj | Hvad | Hvornår | Kører |
| --- | --- | --- | --- | --- |
| Statisk | PHPCS (WPCS), PHPStan level 8, ESLint, `php -l` (8.2) | Standarder, security-sniffs (escaping, nonce, prepared SQL), typefejl | Nu | Lokalt og i CI |
| PHP unit | PHPUnit + [Brain Monkey](https://github.com/Brain-WP/BrainMonkey) | Domænelogik, services, entities og validering uden WordPress | Fase 2 → | Lokalt og i CI |
| PHP integration | PHPUnit + WordPress test suite (`wp-phpunit/wp-phpunit`, `yoast/phpunit-polyfills`) | Repositories mod rigtig database, migrationer, capabilities, REST-permissions og **IDOR-tests** | Fase 7 → | CI (MySQL-service). Lokalt kun med tilgængelig MySQL. |
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

**Hvad unit-tests ikke kan bevise:** at SQL faktisk virker mod en database. Det dækkes midlertidigt af en Playground-runtime-kontrol (SQLite) og autoritativt af integrationstests mod MySQL i fase 7. Se [DATABASE.md](DATABASE.md#mysqlmariadb-vs-playground-sqlite).

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
