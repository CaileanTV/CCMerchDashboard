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
