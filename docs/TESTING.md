# Teststrategi

> Status: **Plan.** Statisk analyse og lint kører nu (lokalt og i CI). Tests tilføjes fra fase 2.

## Niveauer

| Niveau | Værktøj | Hvad | Hvornår | Kører |
| --- | --- | --- | --- | --- |
| Statisk | PHPCS (WPCS), PHPStan level 8, ESLint, `php -l` (8.2) | Standarder, security-sniffs (escaping, nonce, prepared SQL), typefejl | Nu | Lokalt og i CI |
| PHP unit | PHPUnit + [Brain Monkey](https://github.com/Brain-WP/BrainMonkey) | Domænelogik, services, entities og validering uden WordPress | Fase 2 → | Lokalt og i CI |
| PHP integration | PHPUnit + WordPress test suite (`wp-phpunit/wp-phpunit`, `yoast/phpunit-polyfills`) | Repositories mod rigtig database, migrationer, capabilities, REST-permissions og **IDOR-tests** | Fase 7 → | CI (MySQL-service). Lokalt kun med tilgængelig MySQL. |
| JS unit | Jest via `wp-scripts test-unit-js` + Testing Library | Hooks, utils og komponenter med logik | Fase 13 → | Lokalt og i CI |
| E2E | Playwright via `wp-scripts test-e2e` mod Playground | Login-flow, adgangskontrol og kritiske dashboard-flows | Efter MVP | CI |
| Manuel | Tjekliste i PR/fase-afslutning | UX, responsivitet og tilgængelighed | Hver fase | Lokalt (`npm run wp:start`) |

**Hvorfor PHPUnit først i fase 2?** Der findes ingen kode at teste i fase 1. En tom testsuite ville kun være ceremoni. Fase 2 installerer PHPUnit sammen med de første klasser og tests.

## Security-tests er obligatoriske

For hvert endpoint/handler, der eksponerer creator-data, skal der være tests for mindst (se [API.md](API.md#test-krav)):

1. uautentificeret, 2. uden creator-adgang, 3. suspenderet creator, 4. **anden creators ressource (IDOR)** og 5. ugyldigt input.

For admin-handlers: manglende capability og manglende/ugyldig nonce.

## Konventioner

- `tests/Unit/<Modul>/…Test.php` spejler `src/`. Namespace: `CCMerch\Tests\Unit\…`.
- Én adfærd pr. test og beskrivende navne (`test_suspended_creator_cannot_access_dashboard`).
- Ingen tests mod eksterne tjenester. Brug fakes.
- En bugfix kommer med en test, der fejler uden fixet.

## Definition of Done (test-delen)

- `npm run lint` er grøn.
- Relevante unit-/integrationstests er skrevet og grønne.
- Manuel verifikation i Playground for UI-ændringer (desktop og mobilbredde).
- CI er grøn efter push.
