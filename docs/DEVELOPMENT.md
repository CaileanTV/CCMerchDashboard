# Udviklingsmiljø

## Forudsætninger

| Værktøj | Version | Bemærkning |
| --- | --- | --- |
| PHP CLI | ≥ 8.2 (lokalt: 8.5) | Bruges til Composer, PHPCS og PHPStan. Skal have `openssl`, `mbstring`, `curl`, `zip` og `fileinfo`. Se nedenfor. |
| Composer | 2.x | Global installation, **eller** projekt-lokalt i `.tools/composer.phar` (gitignored) |
| Node.js | 24 (se `.nvmrc`) | |
| npm | ≥ 11 | Projektet bruger npm (ikke yarn/pnpm) |
| Git | 2.x | |

Docker er **ikke** påkrævet.

### PHP uden php.ini (Windows/WinGet)

PHP installeret via WinGet har ingen `php.ini`, så `openssl` m.fl. er ikke indlæst. Projektets Composer-wrapper [`bin/composer.mjs`](../bin/composer.mjs) aktiverer de manglende extensions uden at ændre den globale PHP-installation. Den skriver en projekt-lokal ini (`.tools/php-ini/merchboard-dev.ini`, gitignored) og sætter `PHP_INI_SCAN_DIR`, så også de PHP-processer, Composer-scripts starter (PHPUnit, PHPCS, PHPStan), får extensions.

Permanent løsning (valgfri, uden for projektet og foretages af udvikleren selv): kopiér `php.ini-development` til `php.ini` i PHP-mappen, og aktivér `extension_dir = "ext"` samt `openssl`, `mbstring`, `curl`, `zip` og `fileinfo`.

### Projekt-lokal Composer

Hvis Composer ikke er installeret globalt, hentes den med verificeret signatur ind i `.tools/`:

```bash
mkdir -p .tools && cd .tools
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
# Verificér SHA-384 mod https://composer.github.io/installer.sig før næste trin
php composer-setup.php && rm composer-setup.php
```

Kør derefter altid Composer gennem wrapperen: `node bin/composer.mjs <args>` eller `npm run composer -- <args>`. Wrapperen bruger global `composer`, hvis `.tools/composer.phar` ikke findes.

## Første opsætning

```bash
npm ci
node bin/composer.mjs install
```

## Kommandoer

| Kommando | Formål |
| --- | --- |
| `npm run lint` | Alle checks: ESLint, stylelint, TypeScript (`tsc`), PHPCS og PHPStan |
| `npm run lint:js` | ESLint (WordPress-regler + Prettier) |
| `npm run lint:css` | stylelint (bruges, når der er CSS/SCSS) |
| `npm run lint:php` | PHPCS med WordPress Coding Standards |
| `npm run analyse:php` | PHPStan level 8 med WordPress-stubs |
| `npm test` | PHPUnit unit-tests (Brain Monkey) og Jest |
| `npm run test:php` / `npm run test:js` | Kun PHPUnit / kun Jest (dashboardet) |
| `npm run typecheck` | TypeScript strict-typetjek af `assets/src` (`tsc --noEmit`) |
| `npm run build` / `npm run start` | Bygger dashboardet til `build/` (produktion) / bygger og overvåger (udvikling) |
| `npm run i18n` | Bygger dashboardet og regenererer `languages/`: POT (PHP, templates og `build/`), opdaterer PO, kompilerer MO og JS-JSON (`wp-cli/i18n-command`) |
| `npm run package` | Bygger dashboardet og den installérbare plugin-ZIP `dist/merchboard-<udgave>-<version>.zip` (se [Distributionspakke](#distributionspakke)) |
| `npm run test:integration` | Integrationstests mod rigtig WordPress og MariaDB/MySQL (se [TESTING.md](TESTING.md#integrationstests-fase-7)) |
| `node bin/composer.mjs check` | PHPCS + PHPStan + PHPUnit |
| `npm run format` | Prettier-formattering af JS/JSON/Markdown m.m. |
| `node bin/composer.mjs lint:fix` | Autofix af PHPCS-fejl (phpcbf) |
| `npm run wp:start` | Starter lokal WordPress på <http://127.0.0.1:9400> |

Dashboardet skal bygges (`npm run build`), før Playground kan vise det. Ellers svarer `/creator-dashboard/` med 503.

## Lokal WordPress (WordPress Playground)

`npm run wp:start` starter WordPress via [`@wp-playground/cli`](https://wordpress.github.io/wordpress-playground/) (PHP-WASM + SQLite) og mounter repo'et som pluginet `wp-content/plugins/merchboard`. [`blueprint.json`](../blueprint.json) installerer og aktiverer WooCommerce og aktiverer vores plugin. `WP_DEBUG` og `WP_DEBUG_LOG` er slået til, og `WP_ENVIRONMENT_TYPE` er sat til `local`.

- Login: `admin` / `password`. Brugeren logges automatisk ind i browseren.
- PHP 8.2 bruges bevidst, fordi det er pluginets minimumsversion.
- **Sitet er flygtigt**: WordPress og data bygges forfra ved hver start. Det er reproducerbart og isoleret. Testdata skal derfor oprettes via blueprinten (eller senere seed-scripts), ikke manuelt.
- Kodeændringer i repo'et slår igennem med det samme, fordi mappen er mountet.

**Logs:** Med WooCommerce ligger pluginets tekniske logs under WooCommerce → Status → Logs (source `merchboard`). Uden WooCommerce ligger de i `wp-content/debug.log` (Playground sætter `WP_DEBUG_LOG`). Debug-poster skrives kun, når `WP_DEBUG` er slået til (se [ARCHITECTURE.md §11](ARCHITECTURE.md#11-logging-arkitektur)).

**Kendte begrænsninger**

- SQLite i stedet for MySQL. Custom tables oprettet med `dbDelta()` virker via SQLite-integrationen. SQL testes mod en rigtig database med `npm run test:integration` (MariaDB lokalt, MySQL i CI) og endeligt på staging.
- Persistent site-data på Windows-hosts virker ikke pålideligt: WooCommerce-installationen fejler med "Operation not permitted" ved mappe-rename på en host-mount. Derfor bruges et flygtigt site.
- Første start downloader WordPress og WooCommerce (kræver netværk og tager 1-3 minutter).

**LocalWP (valgfrit):** Et persistent MySQL-site kan bruges ved at lægge et symlink/junction fra et **dedikeret** LocalWP-sites `wp-content/plugins/merchboard` til dette repo. Det skal besluttes eksplicit af projektejeren; se Project Isolation i [AI-DEVELOPMENT.md](AI-DEVELOPMENT.md).

## Installerede dependencies

Kun udviklingsværktøjer. Pluginet har **ingen** tredjeparts runtime-dependencies (se ADR-004 i [DECISIONS.md](DECISIONS.md)).

### Composer (`require-dev`)

| Pakke | Version | Hvorfor | Hvor |
| --- | --- | --- | --- |
| `wp-coding-standards/wpcs` | 3.4.1 | WordPress Coding Standards: formattering samt security-sniffs for escaping, nonce, sanitization og prepared SQL | `phpcs.xml.dist`, `composer lint` |
| ↳ `squizlabs/php_codesniffer` | 3.13.6 | PHPCS-motoren (transitiv) | |
| ↳ `phpcsstandards/phpcsutils`, `phpcsextra` | 1.2.3 / 1.5.1 | Kræves af WPCS (transitiv) | |
| ↳ `dealerdirect/phpcodesniffer-composer-installer` | 1.2.1 | Registrerer standards i PHPCS automatisk (transitiv) | |
| `phpunit/phpunit` | 11.5.56 | Unit-tests. 11.x er den nyeste serie, der understøtter PHP 8.2 (platform-låsen). | `phpunit.xml.dist`, `composer test` |
| `brain/monkey` | 2.7.0 | Mocker WordPress-funktioner og -hooks, så unit-tests kører uden WordPress | `tests/Unit/TestCase.php` |
| ↳ `mockery/mockery` | 1.6.15 | Mock-bibliotek bag Brain Monkey (transitiv) | |
| `phpstan/phpstan` | 2.2.15 | Statisk analyse, som fanger typefejl og null-fejl før runtime | `phpstan.neon.dist`, `composer analyse` |
| `szepeviktor/phpstan-wordpress` | 2.0.4 | WordPress-stubs og -regler til PHPStan (inkl. `php-stubs/wordpress-stubs` 7.1.0) | automatisk via extension-installer |
| `phpstan/extension-installer` | 1.4.3 | Indlæser PHPStan-extensions automatisk | |
| `roots/wordpress-no-content` | 7.1.2 (fastlåst) | WordPress core til integrationstestene. Hentes fra downloads.wordpress.org. Kun dev, aldrig i det distribuerede plugin. | `tests/Integration/config.php` |
| `wp-cli/wp-cli` + `wp-cli/i18n-command` | v2.12.0 / v2.7.0 | Oversættelser (fase 15): `wp i18n make-pot`, `update-po`, `make-mo` og `make-json`. WordPress' officielle i18n-værktøj. Indgår ikke i pluginet | `composer i18n`, `npm run i18n` |

**Bevidst fravalgt:** `phpcompatibility/phpcompatibility-wp`. Den eneste stabile version (PHPCompatibility 9.3.5 fra 2019) kender ikke PHP 8.x, og 10.x er stadig alpha. PHPStan (`phpVersion: 80200`) og CI (`php -l` på PHP 8.2) dækker behovet. Kan genovervejes, når PHPCompatibility 10 er stabil. `composer.json` har `config.platform.php = 8.2.0`, så der aldrig låses dependencies, der kræver nyere PHP end minimum.

### MariaDB til integrationstests (ikke en pakke)

`bin/integration.mjs` henter den officielle portable Windows-ZIP af **MariaDB 10.11.19** (LTS) fra archive.mariadb.org, verificerer SHA-256 og pakker den ud i `.tools/mariadb/` (gitignored). Serveren startes kun under `npm run test:integration` med en frisk, midlertidig datamappe. Downloadkilden er godkendt af projektejeren til dette formål (ADR-024). Versionen hæves ved at ændre version og checksum i scriptet. På andre platforme end Windows, og i CI, angives en dedikeret testdatabase med `MERCHBOARD_TEST_DB_*`.

### npm (`devDependencies`)

| Pakke | Version | Hvorfor | Hvor |
| --- | --- | --- | --- |
| `@wordpress/scripts` | 35.0.0 | Samlet WordPress-toolchain: webpack-build med dependency extraction (bruger WordPress' egen React), ESLint, Prettier, stylelint og Jest. Erstatter en separat Vite/ESLint/Prettier-opsætning. | `eslint.config.cjs`, npm scripts |
| `@wp-playground/cli` | 3.1.55 | Isoleret lokal WordPress uden Docker eller LocalWP | `npm run wp:start`, `blueprint.json` |
| `typescript` | 6.0.3 | Typetjek af dashboardet (ADR-027). Samme version som `typescript-eslint` i `@wordpress/scripts` | `tsconfig.json`, `npm run typecheck` |
| `@types/react`, `@types/react-dom` | 19.3.0 | React-typer (peer af `@wordpress/element`) | TypeScript |
| `@wordpress/element` | 8.8.0 | React-API til dashboardet. Runtime leveres af WordPress (external) | `assets/src/dashboard` |
| `@wordpress/api-fetch` | 7.56.0 | REST-klient (cookie + nonce). Runtime leveres af WordPress | `api/client.ts` |
| `@wordpress/i18n` | 6.29.0 | Oversættelige strenge. Runtime leveres af WordPress | Komponenter |

Runtime-JS-pakker som `@wordpress/element`, `@wordpress/api-fetch` og `@wordpress/i18n` leveres af WordPress selv og bliver externals ved build. De er dev-dependencies (typer, editor-support og tests) fra fase 13.

### Kendte advisories

`npm audit` rapporterer 14 advisories (8 moderate, 6 high) i `@wordpress/scripts`, `@wp-playground/cli` og deres transitive dependencies (markdownlint-cli, webpack-dev-server, copy-webpack-plugin, express m.fl.). Ingen i fase 13's nye pakker. De bruges kun lokalt/i CI og indgår aldrig i det distribuerede plugin. `npm audit fix --force` ville nedgradere `@wordpress/scripts` og er derfor ikke kørt. Følges op ved opdateringer af `@wordpress/scripts` (se teknisk gæld i [PROJECT-STATUS.md](PROJECT-STATUS.md)).

## Konfigurationsfiler

| Fil | Formål |
| --- | --- |
| `.editorconfig` | Tabs, LF og UTF-8 (WordPress-standard); spaces i JSON/YAML |
| `.gitattributes` | LF-normalisering; `export-ignore` for dev-filer |
| `.gitignore` | vendor, node_modules, build, `.tools`, caches og secrets (`.env*`, `auth.json`) |
| `.nvmrc` | Node 24 |
| `phpcs.xml.dist` | WordPress-standarden; prefixes `merchboard`/`MerchBoard`; text domain `merchboard`; PSR-4-filnavne tilladt i `src/` og `tests/` |
| `phpstan.neon.dist` | Level 8 og PHP 8.2. Analyserer hovedfilen, `uninstall.php`, `src/` og `tests/`. |
| `phpunit.xml.dist` | Unit-testsuite (`tests/Unit`), tilfældig rækkefølge, fejler på warnings, deprecations og notices |
| `.distignore` | Filer udelukket fra release-zip (alt undtagen runtime-filer) |
| `eslint.config.cjs` | WordPress ESLint flat config + projekt-overrides |
| `.prettierignore` | Udelukker genererede filer og lockfiles |
| `blueprint.json` | Playground-opsætning |
| `.github/workflows/ci.yml` | CI: PHP-syntaks (8.2), PHPCS, PHPStan og ESLint ved push og PR |

Lokale overrides (fx `phpcs.xml` eller `phpstan.neon` uden `.dist`) kan oprettes uden at blive committed, hvis de tilføjes i `.gitignore`.

## Build og deploy (plan)

- **Build:** `npm run build` genererer `build/` (JS/CSS + `*.asset.php` med dependencies og versionshash). PHP kræver intet build-trin.
- **Distribution:** Den installérbare ZIP bygges med `npm run package` (✅ MVP-checkpoint, se [Distributionspakke](#distributionspakke)). En automatisk release på git-tag (`v*`) i CI er planlagt i roadmap-fase 16 og skal genbruge `bin/package.mjs`. **Ingen `vendor/`**: pluginet har sin egen autoloader (ADR-017), så der skal ikke køres `composer install` til en release.
- **Versionering:** SemVer. Versionen står tre steder og holdes synkron: plugin-headeren, konstanten `MERCHBOARD_VERSION` og `package.json`.
- **Miljøer:** local (Playground), staging (MySQL og en kopi af WooCommerce **uden** kunde-PII) og production.

## Distributionspakke

**Byg:** `npm run package` → `dist/merchboard-mvp-<version>.zip` (`dist/` er gitignored). Versionen læses fra plugin-headeren, som skal stemme med `MERCHBOARD_VERSION`. Kommandoen kører `npm run build` og derefter `bin/package.mjs` (Node, ingen dependencies).

**Indhold (allowlist), i én rodmappe `merchboard/`:**

| Sti | Hvorfor |
| --- | --- |
| `merchboard.php`, `uninstall.php` | Plugin-entry og opt-in-afinstallation |
| `src/` | Al PHP-kode inkl. egen autoloader (ADR-017) |
| `templates/` | Dashboard-shell og 403-side |
| `assets/js/` | Admin-scripts uden build (brugersøgning i creator-formularen) |
| `build/` | Dashboardets bundle: `dashboard.js`, `dashboard.css`, `dashboard-rtl.css` og `dashboard.asset.php`. Uden source maps |
| `languages/` | `merchboard.pot`, `merchboard-da_DK.po`/`.mo` og JS-oversættelsen (`merchboard-da_DK-<md5(build/dashboard.js)>.json`) |

**Aldrig med:** `vendor/` (ingen runtime-dependencies), `node_modules/`, `assets/src/` (TypeScript-kilder og Jest-tests), `tests/`, `docs/`, `bin/`, `.git*`, `.github/`, `.tools/`, konfiguration (`composer.*`, `package*.json`, `tsconfig.json`, `jest.config.js`, lint/test-konfiguration), caches, logs, databaser, `.env` og credentials. `.distignore` og `export-ignore` i `.gitattributes` er holdt i sync med allowlisten.

**Sikkerhedsnet i scriptet:** Det afbryder, hvis runtime-filerne afviger fra `HEAD` (ændrede eller utrackede filer i `src/`, `templates/`, `languages/`, `assets/js/` eller hovedfilerne), hvis en påkrævet runtime-fil mangler (fx bundle, MO eller JS-oversættelse), eller hvis en forbudt fil slipper med (fx `.map`, `.ts`, `vendor/`, `tests/`, `.env`, `composer.json`).

**Reproducerbar:** Samme `HEAD` og samme build giver byte-identisk ZIP (sorterede entries, tidsstempel fra `HEAD`-committen, faste rettigheder). Scriptet udskriver sti, version, filantal, størrelse og SHA-256.

**Validering:** Scriptet læser ZIP'en tilbage (CRC og indhold pr. fil, alt under `merchboard/`, gyldig plugin-header). Ved MVP-checkpointet blev pakken desuden åbnet med PHP `ZipArchive` (`CHECKCONS`) og pakket ud med .NET `Expand-Archive`, alle filer var byte-identiske med kilden, og den udpakkede mappe blev kørt i Playground som eneste plugin-kopi (MVP-flow uden fejl).

**Manuel installation:** WordPress → Plugins → Tilføj nyt → Upload plugin → vælg ZIP'en → Installér → Aktivér. Kræver PHP ≥ 8.2 og WordPress ≥ 6.7. WooCommerce er valgfrit. Efter aktivering: Merch Dashboard i admin-menuen. Creator-dashboardet ligger på `/creator-dashboard/` (eller `/?merchboard_dashboard=1` uden pretty permalinks).

## Git-workflow

Se [AI-DEVELOPMENT.md](AI-DEVELOPMENT.md#git-og-github). Kort fortalt: små commits i Conventional Commits-format (`feat:`, `fix:`, `docs:`, `chore:`, `refactor:`, `test:`, `ci:`), push efter validering, og aldrig force-push eller omskrivning af historik.

## Oversættelser

Kildestrenge er engelske med text domain `merchboard`. Dansk (`da_DK`) følger med i `languages/` (✅ fase 15).

1. Ret eller tilføj strenge i koden (`__()`, `_n()`, `sprintf` med `/* translators: */`-kommentar).
2. `npm run i18n`: bygger dashboardet, genererer `merchboard.pot` (fra `merchboard.php`, `uninstall.php`, `src/`, `templates/` og `build/`), opdaterer `merchboard-da_DK.po`, kompilerer `.mo` og skriver `merchboard-da_DK-<md5(build/dashboard.js)>.json` til JavaScript.
3. Oversæt nye strenge i `merchboard-da_DK.po`, og kør `npm run i18n` igen (eller `node bin/composer.mjs i18n:mo i18n:json`).
4. `npm test` kører `TranslationsTest`: alle strenge oversat, pladsholdere bevaret, ingen markup tilføjet, og JSON'en matcher bundlen.

Værktøj: Composer dev-dependencies `wp-cli/wp-cli` og `wp-cli/i18n-command` (via `node bin/composer.mjs`). De indgår ikke i det distribuerede plugin. Med PHP 8.5 lokalt viser `gettext` en harmløs deprecation-besked.
