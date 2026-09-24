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

**Nuværende unit-tests (fase 2):** `Container`, `Autoloader` (inkl. afvisning af path traversal i klassenavne), `Requirements` (versionstjek og escaped notice kun for admins) og `Plugin` (hooks, modul-boot, validering af filter-output, idempotens og HPOS-erklæring i separat proces). Kør med `npm test`.

**Legacy-syntaks:** CI-jobbet `php-legacy-syntax` kører `php -l` på PHP 7.2 for hovedfilen, `src/Core/Requirements.php` og `uninstall.php`, så versionstjekket kan vise en notice i stedet for en parse error.

## Security-tests er obligatoriske

For hvert endpoint/handler, der eksponerer creator-data, skal der være tests for mindst (se [API.md](API.md#test-krav)):

1. uautentificeret, 2. uden creator-adgang, 3. suspenderet creator, 4. **anden creators ressource (IDOR)** og 5. ugyldigt input.

For admin-handlers: manglende capability og manglende/ugyldig nonce.

## Konventioner

- `tests/Unit/<Modul>/…Test.php` spejler `src/`. Namespace: `CCMerch\Tests\Unit\…`. Arv fra `CCMerch\Tests\Unit\TestCase` (Brain Monkey setup/teardown). Fixtures uden for autoload ligger i `tests/Fixtures/`.
- PHPCS gælder også tests, men uden krav om docblocks på testklasser/-metoder (beskrivende navne i stedet) og uden prefix-krav (tests definerer `ABSPATH` og efterligner WooCommerce-namespaces).
- Én adfærd pr. test og beskrivende navne (`test_suspended_creator_cannot_access_dashboard`).
- Ingen tests mod eksterne tjenester. Brug fakes.
- En bugfix kommer med en test, der fejler uden fixet.

## Definition of Done (test-delen)

- `npm run lint` er grøn.
- Relevante unit-/integrationstests er skrevet og grønne.
- Manuel verifikation i Playground for UI-ændringer (desktop og mobilbredde).
- CI er grøn efter push.
