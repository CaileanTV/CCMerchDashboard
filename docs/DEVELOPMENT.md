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
| `npm run build` / `npm run start` | Bygger dashboardet og alert-overlayet til `build/` (produktion) / bygger og overvåger (udvikling) |
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

## Lokal WordPress (LocalWP): Merch Dashboard Local

Projektets **officielle, persistente lokale WordPress/WooCommerce-testmiljø** (udpeget af projektejeren 2026-09-26, lukker åbent spørgsmål 9). Det bruges til WooCommerce-integrationen (fase 17 og frem): produkter, kategorier, varianter, produktmeta, WooCommerce-admin, REST, CSV-import og duplicate mod en rigtig MariaDB. Playground bruges fortsat til hurtige, flygtige smoke-tests med en reproducerbar blueprint.

| | |
| --- | --- |
| Site-navn (LocalWP) | `Merch Dashboard Local` |
| URL | `http://merchboard.local` |
| Site-mappe | `%USERPROFILE%\Local Sites\merch-dashboard-local` (WordPress i `app\public`) |
| Miljø | LocalWP 10.1.2, "Custom": PHP 8.2.29, nginx 1.26.1, MariaDB 10.11.18 (utf8mb4, InnoDB) |
| WordPress | 7.1.3 (observeret 2026-10-07; ved oprettelsen 7.1.2; over pluginets minimum 6.7) |
| WooCommerce | 11.1.2 (observeret 2026-10-07, opdateret uden for projektets verifikationer; ved oprettelsen 10.3.8, ADR-030's referenceversion, installeret med WP-CLI fra wordpress.org) |
| Plugin | Junction `app\public\wp-content\plugins\merchboard` → dette repo. Ingen kopi af koden, og ændringer slår igennem med det samme. `npm run build` er nødvendig for dashboardet |

- **Opsætning (allerede gjort, 2026-09-26):** Sitet blev oprettet fra bunden i LocalWP's brugerflade. Der blev ikke importeret data eller kopieret noget fra andre sites. WooCommerce 10.3.8 og junctionen blev lagt ind via sitets WP-CLI, og begge plugins blev aktiveret. Verificeret: schema 3, rolleversion 2, de tre plugin-tabeller (InnoDB), creator-rollen med præcis `read` + `merchboard_access_dashboard` (ingen produktrettigheder), og `WooCommerceModule` bootet med WooCommerce 10.3.8 uden notice. Ingen fatal errors.
- **WP-CLI:** Brug LocalWP's "Open site shell" for sitet. Den sætter sitets eget miljø (`app\.envrc`, genereret af LocalWP: `PHPRC`/`MYSQL_HOME` for netop dette site, sitets PHP og LocalWP's WP-CLI). Kør kun WP-CLI med `--path` til dette sites `app\public`.
- **Admin-login:** Oprettet af projektejeren ved sitets oprettelse. Credentials ligger aldrig i repoet.
- **Testdata:** Sitet er tomt (ingen creators, produkter, kategorier eller ordrer). Testdata til fase 17 oprettes først, når projektejeren beder om det.
- **Isolation:** Kun dette site hører til projektet. Andre LocalWP-sites og deres databaser, uploads, plugins, themes og credentials er uden for scope og må hverken åbnes, listes, kopieres eller ændres (Project Isolation i [AI-DEVELOPMENT.md](AI-DEVELOPMENT.md)). Staging (`devpage.ccmerch.dk`) og produktion må ikke bruges af AI-agenter, heller ikke som erstatning for dette miljø.

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

## Build og deploy

- **Build:** `npm run build` genererer `build/` (JS/CSS + `*.asset.php` med dependencies og versionshash). PHP kræver intet build-trin.
- **Distribution:** Den installérbare ZIP bygges med `npm run package` (se [Distributionspakke](#distributionspakke)). Et tag `vX.Y.Z` bygger den samme ZIP i GitHub Actions og lægger den på en draft-release (✅ fase 16, se [Release](#release)). **Ingen `vendor/`**: pluginet har sin egen autoloader (ADR-017), så der skal ikke køres `composer install` til en release.
- **Versionering:** SemVer. Versionen står tre steder og holdes synkron: plugin-headeren, konstanten `MERCHBOARD_VERSION` og `package.json`. `bin/package.mjs` afviser pakken, hvis de afviger.
- **Miljøer:** local (Playground), staging og production. **Staging er `https://devpage.ccmerch.dk`** (udpeget af projektejeren 2026-09-25). Deploy dertil sker ved manuel upload af release-ZIP'en (se [Staging](#staging)). Andre sites er off-limits (Project Isolation).

## Distributionspakke

**Byg:** `npm run package` → `dist/merchboard-mvp-<version>.zip` (`dist/` er gitignored). Versionen læses fra plugin-headeren, som skal stemme med `MERCHBOARD_VERSION` og `package.json` og være SemVer. Med `npm run package -- --tag vX.Y.Z` (release) skal tagget desuden være `v` + versionen og pege på `HEAD`. Kommandoen kører `npm run build` og derefter `bin/package.mjs` (Node, ingen dependencies).

**Indhold (allowlist), i én rodmappe `merchboard/`:**

| Sti | Hvorfor |
| --- | --- |
| `merchboard.php`, `uninstall.php` | Plugin-entry og opt-in-afinstallation |
| `src/` | Al PHP-kode inkl. egen autoloader (ADR-017) |
| `templates/` | Dashboard-shell, 403-side og alert-overlayets side |
| `assets/js/` | Admin-scripts uden build (brugersøgning i creator-formularen) |
| `assets/designer/` | Produktdesignerens gennemsigtige fil og markørfiler, som Printful og Gelato henter, når et grundprodukt importeres fra kataloget (trin 30.15) |
| `build/` | Dashboardets bundle: `dashboard.js`, `dashboard.css`, `dashboard-rtl.css` og `dashboard.asset.php`, alert-overlayets: `overlay.js`, `overlay.css`, `overlay-rtl.css` og `overlay.asset.php`, og admin-chatvinduets (trin 25.8): `alerts-admin.js` og `alerts-admin.asset.php`. Uden source maps |
| `languages/` | `merchboard.pot`, `merchboard-da_DK.po`/`.mo` og JS-oversættelsen (`merchboard-da_DK-<md5(build/dashboard.js)>.json`) |

**Aldrig med:** `vendor/` (ingen runtime-dependencies), `node_modules/`, `assets/src/` (TypeScript-kilder og Jest-tests), `tests/`, `docs/`, `bin/`, `.git*`, `.github/`, `.tools/`, konfiguration (`composer.*`, `package*.json`, `tsconfig.json`, `jest.config.js`, lint/test-konfiguration), caches, logs, databaser, `.env` og credentials. `.distignore` og `export-ignore` i `.gitattributes` er holdt i sync med allowlisten.

**Sikkerhedsnet i scriptet:** Det afbryder, hvis runtime-filerne afviger fra `HEAD` (ændrede eller utrackede filer i `src/`, `templates/`, `languages/`, `assets/js/`, `assets/designer/` eller hovedfilerne), hvis en påkrævet runtime-fil mangler (fx bundle, MO eller JS-oversættelse), eller hvis en forbudt fil slipper med (fx `.map`, `.ts`, `vendor/`, `tests/`, `.env`, `composer.json`).

**Reproducerbar:** Samme `HEAD` og samme build giver byte-identisk ZIP (sorterede entries, tidsstempel fra `HEAD`-committen, faste rettigheder). Scriptet udskriver sti, version, filantal, størrelse og SHA-256.

**Validering:** Scriptet læser ZIP'en tilbage (CRC og indhold pr. fil, alt under `merchboard/`, gyldig plugin-header). Ved MVP-checkpointet blev pakken desuden åbnet med PHP `ZipArchive` (`CHECKCONS`) og pakket ud med .NET `Expand-Archive`, alle filer var byte-identiske med kilden, og den udpakkede mappe blev kørt i Playground som eneste plugin-kopi (MVP-flow uden fejl).

**Manuel installation:** WordPress → Plugins → Tilføj nyt → Upload plugin → vælg ZIP'en → Installér → Aktivér. Kræver PHP ≥ 8.2 og WordPress ≥ 6.7. WooCommerce er valgfrit. Efter aktivering: Merch Dashboard i admin-menuen. Creator-dashboardet ligger på `/creator-dashboard/` (eller `/?merchboard_dashboard=1` uden pretty permalinks).

## Release

Workflow: [.github/workflows/release.yml](../.github/workflows/release.yml) (✅ fase 16, ADR-028). Et tag giver en **draft-release**. Projektejeren udgiver den selv efter test på staging.

### 1. Versions-bump (commit på `main`)

1. Vælg version efter SemVer: patch for rettelser, minor for nye features. Så længe versionen er `0.x`, kan en minor også indeholde breaking changes.
2. Sæt versionen tre steder: `Version:` i headeren i `merchboard.php`, `MERCHBOARD_VERSION` i samme fil og `version` i `package.json` og `package-lock.json` (`npm version X.Y.Z --no-git-tag-version`).
3. Kør `npm run i18n`, så `Project-Id-Version` i `languages/` får den nye version.
4. Flyt punkterne under `## [Unreleased]` i [CHANGELOG.md](CHANGELOG.md) til en ny sektion `## [X.Y.Z] - ÅÅÅÅ-MM-DD`. Workflowet bruger sektionen som release notes og fejler, hvis den mangler eller er tom.
5. `npm run lint`, `npm test` og `npm run package` lokalt. Commit som `chore(release): vX.Y.Z`, push, og vent på grøn CI. CI kører hele integrationssuiten mod MySQL, og den er porten for et release; den fulde suite køres ikke længere lokalt (projektejerens beslutning 2026-11-04).

### 2. Tag

```sh
git tag -a vX.Y.Z -m "Merch Dashboard X.Y.Z"
git push origin vX.Y.Z
```

Tagget skal være `v` + præcis pluginversionen og sættes på en commit, der ligger på `main`.

### 3. Hvad workflowet gør

| Trin | Fejler, hvis |
| --- | --- |
| Alle CI-jobs (`ci.yml` genbruges via `workflow_call`): PHP-lint, PHPCS, PHPStan, PHPUnit, integration mod MySQL, PHP 7.2-syntaks, ESLint, stylelint, `tsc`, Jest og build | Et hvilket som helst tjek fejler. Så køres pakketrinnet slet ikke |
| Tagget commit ligger på `main` | Tagget peger på en commit uden for `main` |
| `npm ci` og `npm run package -- --tag vX.Y.Z` | Tag ≠ `v<version>`, tagget peger ikke på den byggede commit, versionerne i header, konstant og `package.json` afviger eller er ikke SemVer, runtime-filer afviger fra commit'en, påkrævede filer mangler, forbudte filer er med, eller ZIP'en læses forkert tilbage |
| Uafhængig kontrol med `unzip -t`: alt under `merchboard/`, centrale runtime-filer findes, `.sha256` skrives | ZIP'en er korrupt eller forkert struktureret |
| Release notes fra `CHANGELOG.md` | Sektionen `## [X.Y.Z]` mangler eller er tom |
| `gh release create --draft --verify-tag` med ZIP og `.sha256` | En release med samme tag findes allerede. Intet overskrives |

Permissions: `{}` som standard, `contents: read` til tjek-jobbene og `contents: write` kun til det sidste job, som opretter releasen. Der bruges kun GitHubs egne actions og `gh` CLI. Der er ingen secrets ud over den automatiske `GITHUB_TOKEN`.

**Fejler workflowet**, oprettes ingen release. Ret fejlen på `main`, og lav en ny patch-version. Et pushet tag flyttes ikke. Det slettes kun, hvis projektejeren beslutter det.

### 4. Udgiv

Hent ZIP'en fra draft-releasen, og tjek SHA-256 mod `.sha256`. Installér den på staging efter tjeklisten nedenfor. Er alt i orden, trykker projektejeren **Publish release** på GitHub.

## Staging

Staging er `https://devpage.ccmerch.dk` (projektejerens site). Deploy sker manuelt. Der er ingen SFTP eller automatisk deploy, fordi det ville kræve hosting-credentials i GitHub og en ADR. AI-agenter har ingen adgang til sitet. Tjeklisten udføres af projektejeren.

1. **Før:** Tag backup via hostingen (filer + database). Notér den installerede version af Merch Dashboard.
2. **Deploy:** Plugins → Tilføj nyt → Upload plugin → vælg release-ZIP'en → Installér → "Erstat nuværende med uploadet". Aktivér, hvis pluginet ikke var aktivt.
3. **Aktivering:** Ingen fejl eller notices. Merch Dashboard → Overview viser systemstatus uden advarsler, og der er ingen admin-notice om ikke-transaktionelle tabeller. Med WooCommerce: aktiveringen er logget under WooCommerce → Status → Logs (source `merchboard`).
4. **MySQL-verifikation:** Værktøjer → Webstedets tilstand → Info → Database: notér servertype og -version samt charset/collation i [PROJECT-STATUS.md](PROJECT-STATUS.md) (åbent spørgsmål 2). Pluginets tabeller skal bruge InnoDB (ADR-024). Ellers vises en admin-notice.
5. **MVP-flow:** Opret eller redigér en creator som admin. Log ind som creator. `/creator-dashboard/` viser Overview og Profile på sitets sprog, profilen kan gemmes, og audit-loggen viser ændringen.
6. **Hosting:** `/creator-dashboard/*` og indloggede brugere må ikke caches af en side-cache/CDN. Notér WordPress-versionen, fordi den afgør React-versionen.
7. **Resultat:** Notér version, dato og fund i PROJECT-STATUS.md. Er noget galt: installér den tidligere ZIP igen, eller gendan backuppen.

## Release 1.7.6: printfiler til leverandørerne, Fyld/Tilpas og " - " i produktnavne (fase 39 og 40)

**Versions-bump og CHANGELOG-sektion og tagget `v1.7.6` (begge på `d6644e2`) 2026-11-09** efter projektejerens ønske ("Gør 1.7.6 klar nu, gerne bump med det samme") og OK ("Hvis CI er grøn, må du gerne tagge"). CI var grøn på alle fire jobs, også hele integrationssuiten. Testpakken kørte på staging. Draft-releasens ZIP er byte-identisk med den lokale pakke og testpakken (SHA-256 `3fcf018243df84f1fdd12450591ea05c67d9e7b9f960ec61a0497227d5fa3b0b`).
- Fase 39 og 40 (ADR-084 statusnoter, ADR-102, ADR-096 statusnote) er testet med unit-, integrations- og Jest-tests og reviewet uafhængigt (fase 40). Printfil-rettelsen har en test gennem WordPress' egen rute-matching.
- CI skal være grøn med hele integrationssuiten på den taggede commit.

**Ingen schemaændring:** schema-version 48 som i 1.7.5.

1. **Backup:** tag backup af filer og database via hostingen. Notér den installerede version.
2. **Opdatér** med ZIP'en ("Erstat nuværende med uploadet"). Overview skal vise version 1.7.6 og schema-version 48 uden advarsler.
3. **Navne:** åbn en side i admin. Jobbet `merchboard_designer_names` (Værktøjer → Skemalagte handlinger) sætter " - " ind i de eksisterende produkters navne, 50 ad gangen. Tjek et produkt med en tilføjelse i butikken: "Creator Grundprodukt - Tilføjelse". Navne uden tilføjelse er uændrede.
4. **Printful-mockups:** send et testdesign på et Printful-grundprodukt til godkendelse. Mockups fra Printful kommer (før 1.7.6 fejlede de og gav browserens billeder).
5. **Fyld/Tilpas:** vælg et billede i designeren, og tryk "Fyld printfladen" og "Tilpas til printfladen".
6. **Resultat:** notér version og dato i [PROJECT-STATUS.md](PROJECT-STATUS.md).

**Tilbagerulning:** installér 1.7.5-ZIP'en igen. Navnene beholder " - "; i 1.7.5 viser designeren så tilføjelsen som "- Tilføjelse", indtil den gemmes igen. Printfilerne virker igen ikke.

## Release 1.7.5: go-live uden overkategori og kategoriforslag før go-live (fase 38)

**Versions-bump og CHANGELOG-sektion (`ced3293`), "Godkend alle" med én mail (`4131217`) og tagget `v1.7.5` (på `4131217`) 2026-11-08** efter projektejerens fejlmelding ("Vi skal have lavet en hurtigt opdatering 1.7.5 da jeg har fundet en kritisk fejl") og OK ("Jeg siger OK her nu - kører den på live"; projektejeren installerer den på live). CI var grøn på alle fire jobs, også hele integrationssuiten. Draft-releasens ZIP er byte-identisk med den lokale pakke og testpakken (SHA-256 `69be6f603db84e5ca84aa591946e46b64b75ca271f618b82b879c7cbf028b19b`).
- Fase 38 (ADR-101) er testet med unit-, integrations- og Jest-tests og reviewet uafhængigt.
- CI skal være grøn med hele integrationssuiten på den taggede commit.

**Ingen schemaændring:** schema-version 48 som i 1.7.4. Forslag godkendt før go-live har status `accepted` i den eksisterende kolonne.

1. **Backup:** tag backup af filer og database via hostingen. Notér den installerede version.
2. **Opdatér** med ZIP'en ("Erstat nuværende med uploadet"). Overview skal vise version 1.7.5 og schema-version 48 uden advarsler.
3. **Go-live uden overkategori:** sæt "Overkategori for creators" til "— Ingen (øverste niveau) —" under Indstillinger → Aftale. Godkend en testcreators go-live: kategorien oprettes på øverste niveau.
4. **Kategoriforslag før go-live:** lad en testcreator, der ikke er live, foreslå fx en beskrivelse og et banner. Godkend dem: de står under "Godkendt, venter på go-live", og creatoren ser "Godkendt – vises, når din shop går live." Godkend go-live: værdierne står på den nye kategori.
5. **Banner:** profilsiden siger, at banneret vises i sin helhed og ikke beskæres.
6. **Én mail:** lad en testcreator foreslå flere felter, og tryk "Godkend alle". Creatoren får én mail, der nævner alle felterne.
7. **Resultat:** notér version og dato i [PROJECT-STATUS.md](PROJECT-STATUS.md).

**Tilbagerulning:** installér 1.7.4-ZIP'en igen. Forslag med status `accepted` ignoreres af 1.7.4 (de vises hverken som ventende eller godkendte), og go-live kræver igen en overkategori.

## Release 1.7.4: udskæring og produkttags (fase 37)

**Versions-bump og CHANGELOG-sektion (`361e218`), review-rettelse (`874da79`) og tagget `v1.7.4` (på `874da79`) 2026-11-08.** Testpakken blev bygget efter projektejerens ønske ("vil du så ikke lave en plugin fil klar til mig, så jeg kan køre test på stg") og testet på staging; tagget efter projektejerens OK ("Vi er klar til update"). CI var grøn på alle fire jobs, også hele integrationssuiten. Draft-releasens ZIP er byte-identisk med den lokale pakke og med testpakken fra staging (SHA-256 `921b1650c251a65e8d345609fd8246158abfe82090b8fdfc2f104c4e167de6c1`).
- Fase 37 (ADR-099 og ADR-100) er testet med unit-, integrations- og Jest-tests, og mockup-sammensætningen er set i en headless Chrome med projektejerens `Mask.png`.
- CI skal være grøn med hele integrationssuiten på den taggede commit.

**Schema-version 48:**
- Migration 48 tilføjer `tag_ids` (JSON, NULL) til `merchboard_designer_bases`. Eksisterende grundprodukter har ingen tags; intet andet ændres.
- Udskæringen ligger i sidernes lag og kræver ingen migration.

Testpakken og den senere draft-release testes på staging af projektejeren. AI-agenter har ingen adgang til produktion.

1. **Backup:** tag backup af filer og database via hostingen. Notér den installerede version.
2. **Opdatér** med ZIP'en ("Erstat nuværende med uploadet"). Overview skal vise version 1.7.4 og schema-version 48 uden advarsler.
3. **Udskæring:** upload `Mask.png` som "Udskæring" på hoodie-grundproduktets forside (samme størrelse som de andre lag), og gem. Grundproduktets version må ikke stige. Åbn et hoodie-design: snorene ligger foran designet på lærredet og i galleriet i popuppen ved "Send til godkendelse". Printfilen (efter godkendelse) har ingen udskæring.
4. **Produkttags:** sæt flueben ved et produkttag på grundproduktet, og gem. Produkterne i butikken fra grundproduktet får tagget i baggrunden (Action Scheduler: `merchboard_designer_tags`), og funktionen på produktsiden virker. Et tag, du selv har sat på et produkt, bliver. Fjern fluebenet igen: kun grundproduktets tag forsvinder.
5. **Resultat:** notér version og dato i [PROJECT-STATUS.md](PROJECT-STATUS.md).

**Tilbagerulning:** installér 1.7.3-ZIP'en igen. Kolonnen `tag_ids` og produktmeta'en `_merchboard_designer_tags` bliver liggende uden at blive brugt; tags på produkterne bliver, hvor de er. Udskæringslaget ignoreres af 1.7.3.

## Release 1.7.3: "Send til godkendelse" som popup (fase 36)

**Versions-bump og CHANGELOG-sektion og tagget `v1.7.3` (begge på `4853677`) 2026-11-08** efter projektejerens OK på Local ("Dette er OK - så du må gerne commit og bumpe til 1.7.3"). CI var grøn på alle fire jobs, også hele integrationssuiten. Draft-releasens ZIP er byte-identisk med den lokale pakke (SHA-256 `fa2c2c7fcb6a87be372fba491451e05acf9fdb2198c795c2d44970ea0ed67a98`).
- Fase 36 er reviewet uafhængigt (rettelser i statusnoten i ADR-098) og testet af projektejeren på Local.
- CI skal være grøn med hele integrationssuiten på den taggede commit.

**Ingen schemaændring og ingen serverændring:** schema-version 47 som i 1.7.2. Kun dashboardet (JavaScript og CSS) og tekster ændres.

Tagget giver en draft-release. Projektejeren tester den på staging og udgiver den. AI-agenter har ingen adgang til produktion.

1. **Backup:** tag backup af filer og database via hostingen. Notér den installerede version.
2. **Opdatér** med release-ZIP'en ("Erstat nuværende med uploadet"). Overview skal vise version 1.7.3 og schema-version 47 uden advarsler.
3. **Designeren:** åbn et design med flere farver. Produktfanen må ikke skære noget af og har "Design pr. farve". "Send til godkendelse" åbner popuppen med rækkefølge og galleri. Med fortjeneste 0 spørger "Send" først.
4. **Resultat:** notér version og dato i [PROJECT-STATUS.md](PROJECT-STATUS.md).

**Tilbagerulning:** installér 1.7.2-ZIP'en igen. Intet er gemt anderledes.

## Release 1.7.2: tekster på tilmeldingssiden (ROADMAP 35.7)

**Versions-bump og CHANGELOG-sektion og tagget `v1.7.2` (begge på `b329416`) 2026-11-08** efter projektejerens ønske om at bumpe direkte, så den fulde CI kører på bump-commit'en. CI var grøn på alle fire jobs, også hele integrationssuiten. Draft-releasens ZIP er byte-identisk med den lokale pakke (SHA-256 `3c8fc76967da24f1e1547082424d4d1e51bc6ed7c79b61d1c9602f53c0483dda`).
- Kun tekster på tilmeldingssiden ændres; testet med en integrationstest, der viser formularen, og set på Local.
- CI skal være grøn med hele integrationssuiten på den taggede commit.

**Ingen schemaændring:** schema-version 47 som i 1.7.1. Data og kategorier røres ikke.

Tagget giver en draft-release. Projektejeren tester den på staging og udgiver den. AI-agenter har ingen adgang til produktion.

1. **Backup:** tag backup af filer og database via hostingen. Notér den installerede version.
2. **Opdatér** med release-ZIP'en ("Erstat nuværende med uploadet"). Overview skal vise version 1.7.2 og schema-version 47 uden advarsler.
3. **Tilmelding:** åbn tilmeldingssiden. Overskriften skal være "Opret dig som creator eller forening/community", navnefeltet "Creatornavn/Navn på forening/community", og spørgsmålet "Hvad beskriver dig/jer bedst?" med "Content creator" valgt.
4. **Resultat:** notér version og dato i [PROJECT-STATUS.md](PROJECT-STATUS.md).

**Tilbagerulning:** installér 1.7.1-ZIP'en igen.

## Release 1.7.1: "Vises på forsiden" ved tilmeldingen (fase 35)

**Versions-bump og CHANGELOG-sektion (`14b17b6`) og tagget `v1.7.1` (på `cbcd446`) 2026-11-08** efter projektejerens go ("Når den er grøn i CI - så er vi klar"). CI var grøn på alle fire jobs, også hele integrationssuiten. Draft-releasens ZIP er byte-identisk med den lokale pakke (SHA-256 `55a13d695484edb8415cf3e6ef52ae2c28ac296c751738ce7dcfc93d2a068df0`).
- Tagget sidder på `cbcd446`, ikke på bump-commit'en: CI fandt to tests, der efterlod produkter (`d3b4db1`, `cbcd446`). Rettelserne ændrer kun tests; pluginets kode er den samme som i `14b17b6`.
- Projektejeren valgte versionen 1.7.1 (en lille feature uden schemaændring).
- Fase 35 er reviewet uafhængigt (rettelser i `56abb83`) og runtime-testet på Local (ROADMAP 35.5).
- CI skal være grøn med hele integrationssuiten på den taggede commit.

**Ingen schemaændring:** schema-version 47 som i 1.7.0. Valget gemmes som creator meta `front_group`. Eksisterende creators og kategorier røres ikke.

Tagget giver en draft-release. Projektejeren tester den på staging og udgiver den. AI-agenter har ingen adgang til produktion.

1. **Backup:** tag backup af filer og database via hostingen. Notér den installerede version.
2. **Opdatér** med release-ZIP'en ("Erstat nuværende med uploadet"). Overview skal vise version 1.7.1 og schema-version 47 uden advarsler.
3. **Tilmelding:** åbn tilmeldingssiden. "Vises på forsiden" skal vise "Content creator" (valgt) og "Forening & Community".
4. **Go-live:** tilmeld en testcreator som "Forening & Community", og anmod om go-live. På fanen Go-live skal "Vises på forsiden som: Forening & Community" stå, og listen skal være forudvalgt. Godkend, og kontrollér, at bannerpluginets felt på den nye kategori viser "Forening & community".
5. **Resultat:** notér version og dato i [PROJECT-STATUS.md](PROJECT-STATUS.md).

**Tilbagerulning:** installér 1.7.0-ZIP'en igen. Creator meta'en `front_group` bliver liggende uden at blive brugt; værdien på kategorierne bliver, hvor den er.

## Release 1.7.0: farvedesigns, produktbillede og "Kopiér design" (fase 34)

**Versions-bump og CHANGELOG-sektion (`7a442ca`) og tagget `v1.7.0` (på `7a442ca`) 2026-11-07** efter projektejerens go ("Jeg er klar til at release 1.7.0"). CI var grøn på alle fire jobs, også hele integrationssuiten. Draft-releasens ZIP er byte-identisk med den lokale pakke (SHA-256 `7617379912c102b312da5c5cfd6f350a23f1e8020b298c9424c61da726555b66`).
- Det er en minor, fordi den indeholder nye features (ADR-096).
- Fase 34 er reviewet uafhængigt (rettelser i `30557c1`) og runtime-testet på Local (ROADMAP 34.13). Projektejerens fund af nedbruddet ved valg af et element er rettet i `5232652`.
- CI skal være grøn med hele integrationssuiten på den taggede commit.

**Schema-version 47:**
- Migration 47 tilføjer `primary_image` til `merchboard_designer_versions` (NULL for alle eksisterende versioner, som beholder deres billeder) og gør `merchboard_designer_provider_tasks.side` 48 tegn lang.
- Der er ingen manuelle trin i databasen. Eksisterende designs er uændrede; farvedesigns findes kun i nye gem.

Tagget giver en draft-release. Projektejeren tester den på staging og udgiver den. AI-agenter har ingen adgang til produktion.

1. **Backup:** tag backup af filer og database via hostingen. Notér den installerede version.
2. **Opdatér** med release-ZIP'en ("Erstat nuværende med uploadet"). Overview skal vise version 1.7.0 og schema-version 47 uden advarsler.
3. **Eksisterende produkter:** åbn et designer-produkt i butikken. Billeder, priser og variationer skal være som før, og ordresiden skal vise printfilerne som før.
4. **Farvedesign:** giv i designeren én farve "+ Nyt design" med en ekstra side, og kontrollér "Pris pr. farve". Send det, godkend det, og kontrollér i butikken, at farvens variationer har deres egen pris og billeder, og at en ordrelinje i den farve viser farvens printfiler.
5. **Leverandørmockups:** på et grundprodukt fra en leverandør skal mockups af et farvedesign vise farvens eget design. Gelato og Prodigi er kun testet mod falske svar.
6. **Produktbillede:** vælg et billede i designeren, og skift det bagefter på det live produkt uden ny godkendelse.
7. **Kopiér design:** kopiér et produkt i butikken og et, der venter. Kopien åbner som en ny kladde.
8. **Resultat:** notér version og dato i [PROJECT-STATUS.md](PROJECT-STATUS.md).

**Tilbagerulning:** installér 1.6.0-ZIP'en igen. Kolonnen `primary_image` bliver liggende uden at blive brugt. Produkter med farvedesigns kan ikke vises korrekt i 1.6.0 (priserne pr. farve gemmes i en ny form, og 1.6.0 kender kun det fælles design), så tag dem ud af butikken før en tilbagerulning.

## Release 1.6.0: selvoprettelse, aftalepapir, gå live og baggrundsfarve (fase 32 og 33)

**Versions-bump og CHANGELOG-sektion (`df39b94`) og tagget `v1.6.0` (på `ac61857`) 2026-11-07** efter projektejerens ønske ("Nu er vi klar til den kan bumpe når den er færdig og klar"). CI på bump-committen fandt to fejl i tests (en fast schema-version og et produkt, som en anden test efterlod); de er rettet i `ac61857`, som kun ændrer tests. Draft-releasens ZIP er byte-identisk med den lokale pakke (SHA-256 `cdd65e76a0d6a9d41eb773e6cfeadd5f465d6a5c827260f8d2e85fffd8946b65").
- Det er en minor, fordi den indeholder nye features.
- Fase 32 og 33 er reviewet uafhængigt og runtime-testet på Local (ROADMAP 32.7 og 33.5). Rettelserne efter reviewene er committet (`0b37ef1`, `a537a23`). Fejlen med farvefotos, der forsvandt, er rettet i `e3c5eea` og testet på Local med projektejerens JN-mockups.
- CI er grøn med hele integrationssuiten på den taggede commit.

**Schema-version 46:**
- Migration 45 tilføjer `awaiting_live` og `agreement_required` til `merchboard_creators` (0 for alle eksisterende creators, så de er live og accepterer intet) og opretter tabellerne til aftalepapiret, oprettelser og go-live-anmodninger. Aftaletabellerne bevares ved afinstallation.
- Migration 46 tilføjer `background_color` til grundprodukterne (0 som standard) og giver grundprodukter med nul-datoer fra 1.5.0 tidspunktet for opdateringen.
- Der er ingen manuelle trin i databasen.

Tagget giver en draft-release. Projektejeren tester den på staging og udgiver den. AI-agenter har ingen adgang til produktion.

1. **Backup:** tag backup af filer og database via hostingen. Notér den installerede version.
2. **Opdatér** med release-ZIP'en ("Erstat nuværende med uploadet"). Overview skal vise version 1.6.0 og schema-version 46 uden advarsler.
3. **Eksisterende creators:** åbn dashboardet som en eksisterende creator. Der må ikke stå "Du er ikke live endnu" eller en aftale, der skal accepteres, og produkter, kategori og rabatkode skal være som før.
4. **Selvoprettelse er slået fra** som standard. Før den slås til under Indstillinger → Aftale:
   - projektejeren godkender aftaleteksten (et udkast, ikke juridisk gennemgået, ADR-092);
   - firmaoplysningerne (navn, adresse, CVR, e-mail) er udfyldt;
   - aftalen er udgivet;
   - overkategorien for creators er valgt.
5. **Mail:** en ny oprettelse får "Bekræft din e-mail og vælg en adgangskode", og linket virker i 24 timer.
6. **Prisvarsel:** en prisstigning på et grundprodukt med designs venter 14 dage. "Ændre pris nu" sætter den med det samme efter en advarsel.
7. **Baggrundsfarve:** sæt fluebenet "Baggrundsfarve (AOP)" på et all-over-grundprodukt. Prøv derefter fanen "Baggrund" med en testcreator.
8. **Resultat:** notér version og dato i [PROJECT-STATUS.md](PROJECT-STATUS.md).

**Tilbagerulning:** installér 1.5.0-ZIP'en igen. De nye kolonner og tabeller bliver liggende uden at blive brugt. Selvoprettede creators, der ikke er live, behandles i 1.5.0 som almindelige aktive creators; deaktivér dem derfor før en tilbagerulning. Baggrundsfarver i designs ignoreres af 1.5.0 og forsvinder ved næste gem.

## Release 1.5.0: produktdesigner og alert-designer 2.0 (fase 30 og 31)

**Versions-bump og CHANGELOG-sektion (`701691b`) og tagget `v1.5.0` (på `701691b`) 2026-11-04** efter projektejerens ønske samme dag ("lav review og sidste test, så vi kan bumpe version og få på live"). Draft-releasens ZIP er byte-identisk med den lokale pakke (SHA-256 `7f205284177b80623a7d05b8a4c0ca3405c1860467025ac37388bd3735e86fb4`).
- Det er en minor, fordi den indeholder nye features.
- Fase 30 og 31 er reviewet og runtime-testet på Local og stg.ccmerch.dk (ROADMAP 30.15). Rettelserne efter 30.15 (salgsmails inkl. moms og ADR-090) er reviewet uafhængigt, rettet (`8e3c556`) og runtime-testet på Local.
- CI er grøn med hele integrationssuiten på den taggede commit (den fulde lokale kørsel er droppet, projektejerens beslutning 2026-11-04).

**Schema-version 44:**
- Migration 35–39 og 41–44 opretter og udvider produktdesignerens tabeller (43 med digitaliseringsgebyrerne), og migration 40 opretter tabellen til sletteanmodninger. Migration 36 tilføjer kolonner til `merchboard_sales_tax`, som er tomme for alle eksisterende rækker. Eksisterende salg, bilag og udbetalinger ændres ikke.
- Der er ingen manuelle trin i databasen.
- Produktdesigneren, Printful, Gelato, Prodigi, Printify og oplæsning af alerts er slået fra som standard. Legacy-produkter berøres ikke (legacy-værnet, ADR-080 pkt. 7).
- Eksisterende alerts får layoutet "Fri" og ser ud som før (ADR-088).

Tagget giver en draft-release. Projektejeren tester den på staging og udgiver den. AI-agenter har ingen adgang til produktion.

1. **Backup:** tag backup af filer og database via hostingen. Notér den installerede version.
2. **Opdatér** med release-ZIP'en ("Erstat nuværende med uploadet"). Overview skal vise version 1.5.0 og schema-version 44 uden advarsler.
3. **nginx:** kører sitet på nginx, så tilføj `location ^~ /wp-content/uploads/merchboard-designer/ { deny all; }`. `.htaccess` i mappen virker kun på Apache (SECURITY.md).
4. **PHP-udvidelsen GD** skal være aktiv, før grundprodukter importeres fra et katalog.
5. **Alerts:** åbn en creators alert-side og testalerten. Alerten skal se ud som før opdateringen.
6. **Salgsmail:** en testordre giver creatoren "Nyt salg" med beløb inkl. moms.
7. **Ukendt saldo (ADR-090):** på Udbetalinger fører "N ledger-rækker kan ikke beregnes" til creatorens side. Tjek linjerne og deres udbetaling under "Ordrelinjer uden komplet prisbillede", vælg momsstatus, sæt fluebenet og tryk "Udfyld prisbilleder". Saldoen skal derefter være kendt. Det gælder fx Kenny's Garage & Værksted.
8. **Produktdesigneren** slås til under Indstillinger, når projektejeren vil, først med en testcreator.
9. **Resultat:** notér version og dato i [PROJECT-STATUS.md](PROJECT-STATUS.md).

**Tilbagerulning:** installér 1.4.0-ZIP'en igen. De nye tabeller og kolonner bliver liggende uden at blive brugt, og 1.4.0 virker med schema-version 44. Prisbilleder udfyldt med ADR-090 bevares. Salg af designer-produkter kan 1.4.0 ikke beregne udbetaling for. Alerts, der er gemt med den nye designer, kan se anderledes ud i 1.4.0; tag derfor backup før opdateringen.

## Release 1.4.0: notifikationer (fase 29)

**Versions-bump og CHANGELOG-sektion (`17e3f3b`) og tagget `v1.4.0` (på `17e3f3b`) 2026-10-28.** Projektejeren bad samme dag om at starte bump og release, når den fulde suite og CI var grønne. Draft-releasens ZIP er byte-identisk med den lokale pakke (SHA-256 `1df06c7687782629832f8a53bfa804f34fb2942490b864420d120ef4d6ac1802`).
- Det er en minor, fordi den indeholder nye features.
- Fase 29 er reviewet og runtime-testet på Local med "Test Creator Cailean" (ROADMAP 29.7).
- Den fulde integrationssuite er grøn lokalt på `adcf44d` (1088 tests), og CI er grøn.

**Schema-version 34:**
- Migration 34 opretter `merchboard_notifications`.
- Der er ingen manuelle trin.
- Det daglige saldo-tjek planlægges ved den første sideindlæsning i wp-admin. Den første kørsel noterer kun beløbene, så ingen creator får en "saldo klar"-mail ved udgivelsen.

Tagget giver en draft-release. Projektejeren tester den på staging og udgiver den. AI-agenter har ingen adgang til staging eller produktion.

1. **Backup:** tag backup af filer og database via hostingen.
2. **Opdatér** med release-ZIP'en ("Erstat nuværende med uploadet"). Overview skal vise version 1.4.0 og schema-version 34 uden advarsler.
3. **Mail:** WordPress skal kunne sende mail (fx et SMTP-plugin). Send en testmail fra Merch Dashboard → Notifikationer, og tjek design og afsender. Afsender, farver og logo kommer fra WooCommerce → Indstillinger → E-mails.
4. **Valg:** gennemgå kontakterne på siden Notifikationer. "Creator har nulstillet sin rabatkode" og "Creator har annulleret en udbetaling" er slået fra som standard. Administrator-mails går til sitets admin-e-mail.
5. **Stikprøve:**
   - En testordre med en creators produkt giver creatoren "Nyt salg" (mail og klokken) uden kundens data.
   - Klokken øverst i dashboardet viser en rød boble, og et klik viser teksten.
   - Under Profil viser kortet "E-mailnotifikationer" de mails, creatoren kan slå fra.
6. **Resultat:** notér version og dato i [PROJECT-STATUS.md](PROJECT-STATUS.md).

**Tilbagerulning:** installér 1.3.1-ZIP'en igen. Tabellen `merchboard_notifications` bliver liggende uden at blive brugt. 1.3.1 tjekker kun, at schemaet ikke er ældre end sit eget, så den virker med schema-version 34.

## Release 1.3.1: rettelse af foreslåede billeder i Profile

**Versions-bump og CHANGELOG-sektion (`73ad6c5`) og tagget `v1.3.1` (på `6a3d585`) 2026-10-26** efter projektejerens ønske samme dag.
- Det er en patch, fordi den kun retter én fejl.
- Tagget står på commit'en efter bumpet: CI på `73ad6c5` fejlede på en ustabil integrationstest (et statisk flag fra "Se som creator" smittede mellem tests i tilfældig rækkefølge), som `6a3d585` retter i testbasen. Pluginets kode er den samme.
- Den fulde integrationssuite er grøn lokalt (`906cb8e`, 1066 tests), og draft-releasens ZIP er byte-identisk med den lokale pakke (SHA-256 `9bb40365c65e505d16760e3af7b3b09cc0aebaea25fafe16d851b5d8190ce3fd`). Projektejeren fandt fejlen på ccmerch.dk: et foreslået billede blev blokeret af dashboardets CSP (`blob:`), og det vises nu som en `data:`-adresse. Der er ingen schemaændring (33) og ingen manuelle trin.

1. **Backup:** tag backup af filer og database via hostingen.
2. **Opdatér** med release-ZIP'en ("Erstat nuværende med uploadet"). Overview skal vise version 1.3.1 og schema-version 33 uden advarsler.
3. **Stikprøve:** en creator med et ventende billedforslag skal se billedet under Profil → "Foreslået". Konsollen må ikke vise `blocked:csp`.
4. **nginx-reglen** fra "Release 1.3.0" pkt. 5 skal blive stående. Den har ikke noget med fejlen at gøre.

**Tilbagerulning:** installér 1.3.0-ZIP'en igen. Der er ingen data at gendanne.

## Release 1.3.0: creatorens kategori og rabatkode (fase 28)

**Versions-bump, CHANGELOG-sektion og tagget `v1.3.0` (på `1083f18`) 2026-10-26** efter projektejerens ønske samme dag. Draft-releasens ZIP er byte-identisk med den lokale pakke (SHA-256 `a0d66b56708f6d8f6434860281ba3d3e8b815382944666699fc363fc3df53c36`). Det er en minor, fordi den indeholder nye features. Fase 28 er reviewet og runtime-testet på Local; se ROADMAP 28.6.

**Schema-version 33:**
- Migration 32 opretter `merchboard_category_proposals`.
- Migration 33 gør creatorernes `bio` og `social_links` til ventende forslag og sletter de gamle værdier.
- Der er ingen manuelle trin.

Tagget giver en draft-release. Projektejeren tester den på staging og udgiver den. AI-agenter har ingen adgang til staging eller produktion.

1. **Backup:** tag backup af filer og database via hostingen. Notér den installerede version.
2. **Opdatér** med release-ZIP'en ("Erstat nuværende med uploadet"). Overview skal vise schema-version 33 uden advarsler.
3. **Forudsætning:** pluginet CCMerch Kategoribannere skal være aktivt. Uden det kan forslag til banner, mobilbanner, link og sociale links hverken laves eller godkendes; navn, beskrivelse og logo virker.
4. **Stikprøve:**
   - Admin-siden "Category proposals" viser creatorernes tidligere bioer og links som ventende forslag. Godkend eller afvis dem.
   - En creator foreslår en ændring i Profile, og ændringen vises først i butikken, når den er godkendt.
   - "Opret rabatkode" på en creators side giver en kode, hvis rabat i kurven er creatorens fortjeneste på egne varer.
   - Eksisterende, manuelt registrerede koder virker som før, indtil der oprettes en ny kode. Siden viser en advarsel ved dem.
5. **nginx:** kører sitet på nginx, så tilføj `location ^~ /wp-content/uploads/merchboard-proposals/ { deny all; }`. `.htaccess` i mappen virker kun på Apache (SECURITY.md).
6. **Resultat:** notér version og dato i [PROJECT-STATUS.md](PROJECT-STATUS.md).

**Tilbagerulning:** gendan backuppen. 1.2.0-koden kender ikke forslagstabellen, og bioerne er flyttet til forslag. Installér derfor ikke 1.2.0 oven på schema-version 33.

## Release 1.2.0: oprydning, "Se som creator" og sletning af creators (fase 27)

**Versions-bump, CHANGELOG-sektionen og tagget `v1.2.0` (på `1af0371`) sat 2026-10-25** efter projektejerens valg samme dag (minor: nye features; fase 27 er reviewet og runtime-testet på Local). Schema-version **31**: migration 30 sletter importværktøjernes optioner, og migration 31 tilføjer `deleted_at` til creators. Ingen import eller manuelle trin. Tagget giver en draft-release; projektejeren tester den på staging og udgiver den. AI-agenter har ingen adgang til staging eller produktion.

1. **Backup:** filer og database via hostingen. Notér den installerede version (1.1.0).
2. **Opdatér** med release-ZIP'en ("Erstat nuværende med uploadet"). Overview viser schema-version 31 uden advarsler.
3. **Stikprøve:** Alerts har ikke fanen "Import", menuen har ikke "Import fra Product Vendors", og en creators gamle OBS-link (`/ccmerch-alert/creator-overlay/{id}/`) virker stadig. "Se som creator" og "Slet" findes på Creators-listen.
4. **Resultat:** notér version og dato i [PROJECT-STATUS.md](PROJECT-STATUS.md).

**Tilbagerulning:** gendan backuppen. 1.1.0-koden kender ikke slettede creators, så den bør ikke installeres oven på schema-version 31, når en creator er slettet.

## Release 1.1.0: alerts og migrering fra Product Vendors (fase 25 og 26, trin 25.10.3)

**Versions-bump, CHANGELOG-sektionen og tagget `v1.1.0` sat 2026-10-25** efter projektejerens godkendelse samme dag (fase 25 og 26 er reviewet og runtime-testet; alert-API og webhooks endeligt godkendt efter SparkyBot-tjekket). Version **1.1.0** (projektejerens valg 2026-10-23: minor, nye features uden breaking changes). Tagget giver en draft-release; projektejeren tester den på staging og udgiver den. Produktionen udføres af projektejeren; AI-agenter har ingen adgang til staging eller produktion.

### Før tagget (efter 25.13)

1. 25.13 er afsluttet: slutreview og runtime-test uden åbne fund, eller fundene er rettet på `main` med grøn CI.
2. Versions-bump til 1.1.0 og CHANGELOG-sektionen efter [Release](#release), trin 1. Schema-versionen er **29** (migration 18–29 kører ved opdatering fra 1.0.1).
3. Tag `v1.1.0` efter [Release](#release), trin 2. Draft-releasen testes på staging (devpage) efter [Staging](#staging) og proceduren nedenfor, før den udgives.

### Produktionsprocedure (alerts og MerchAlert)

*Kørt i produktion 2026-10-25; importen er fjernet i trin 27.1. Historisk.*

Rækkefølgen er låst (ADR-065): **backup → deaktivér MerchAlert → opdatér → import → slå alerts til.**

1. **Backup:** filer og database via hostingen. Notér de installerede versioner af Merch Dashboard (1.0.1) og MerchAlert.
2. **Cache:** side-cache/CDN må ikke cache `/merchboard-alerts/*`, `/ccmerch-alert/*` (de gamle OBS-URL'er) eller REST-feedet `wp-json/merchboard/v1/*`. Overlays henter nye alerts med long-poll.
3. **Deaktivér MerchAlert** under Plugins. **Slet det ikke:** MerchAlerts afinstallation sletter dets tabeller, som importen læser.
4. **Opdatér Merch Dashboard** med release-ZIP'en ("Erstat nuværende med uploadet"). Migration 18–29 kører; Overview skal vise schema-version 29 uden advarsler. Alerts er **slået fra** efter opdateringen (kill switch, ADR-065 pkt. 6).
5. **Import:** Merch Dashboard → Alerts → **Import** → "Tørkør". Gennemgå rapporten: hver MerchAlert-creator skal matche den rigtige creator via sin kategori; "Springes over" kræver en beslutning (ret kategorien under Creators, og tørkør igen). Sæt kryds ved backup, og tryk "Importér". "Seneste import" viser antal creators, alerts og chatbeskeder.
6. **Efter importen, før alerts slås til:**
   - Stikprøve: en creators overlay i OBS med den **gamle** URL (`/ccmerch-alert/creator-overlay/{id}/`) viser en testalert; designet, lydene og fonten er som før.
   - Det globale overlay er importeret **slået fra**; slå det til under Alerts → Globalt overlay, hvis det bruges.
   - De importerede alerts står i creatorernes Historik og i Ordreloggen, men vises ikke som nye køb i åbne overlays eller alert-API'en (schema-version 29, 25.13).
   - **Chatbot:** Twitch-logins flyttes ikke. Under Alerts → Chatbot: gem Twitch-appens Client ID og Secret, og log bot-kontoen ind. Creators tilføjer botten til deres kanal igen fra dashboardet (Alerts → Chatbot). Skabelon og til/fra er flyttet.
   - Settings → Alerts: tjek lydgrænserne og "Brugernavn i checkout" (importeret fra MerchAlert).
7. **Slå alerts til:** Settings → Alerts → "Købs-alerts": sæt kryds i "Registrér købs-alerts for creators", og gem. Ordrer betalt, mens alerts var slået fra, får ingen alerts bagefter. Læg en testordre (eller brug "Afspil seneste ordre" på det globale overlay), og tjek overlay, lyd og chatbesked.
8. **Resultat:** notér version, dato, importens tal og eventuelle fund i [PROJECT-STATUS.md](PROJECT-STATUS.md).

### Produktionsprocedure (migrering fra Product Vendors, fase 26)

*Kørt i produktion 2026-10-25; værktøjet er fjernet i trin 27.1. Historisk.*

Efter alerts-proceduren ovenfor, eller i et separat vindue. Kræver backup og at WooCommerce Product Vendors er **aktivt**, til migreringen er færdig (den læser gennem Product Vendors' eget API, ADR-074).

1. **Backup:** filer og database via hostingen.
2. **Tørkørsel:** Merch Dashboard → Import fra Product Vendors → Tørkørsel. Gennemgå hver vendor: brugeren (vælg en anden, hvis forslaget er forkert), produktkategorien og dens konsekvens ("kobler også N andre produkter"), produkter, der bliver stående, og vendors, der springes over. Opdatér tørkørslen efter hvert valg.
3. **Flyt vendors:** sæt kryds ved backup, og tryk "Flyt vendors". Stikprøve af creators: bruger, produkter, kategori og afregningsstart (= migreringens tidspunkt for nye creators).
4. **Roller:** når creators er kontrolleret, "Fjern Product Vendors-rollerne" for de flyttede brugere. Kommission, udbetalinger og betalingsoplysninger i Product Vendors flyttes ikke; Product Vendors kan deaktiveres bagefter.
5. **Resultat:** notér tallene fra "Seneste migrering" i PROJECT-STATUS.md.

**Tilbagerulning:** slå alerts fra (Settings → Alerts), og aktivér MerchAlert igen; dets data er urørt, og mens det er aktivt, sender Merch Dashboard ingen alerts (MerchAlert-vagten). Ved fejl i selve opdateringen: installér 1.0.1-ZIP'en igen, eller gendan backuppen. **MerchAlert afinstalleres** først, når 1.1.0 har kørt stabilt, og importen er verificeret; derefter kan den ikke gentages.

## Git-workflow

Se [AI-DEVELOPMENT.md](AI-DEVELOPMENT.md#git-og-github). Kort fortalt: små commits i Conventional Commits-format (`feat:`, `fix:`, `docs:`, `chore:`, `refactor:`, `test:`, `ci:`), push efter validering, og aldrig force-push eller omskrivning af historik.

## Oversættelser

Kildestrenge er engelske med text domain `merchboard`. Dansk (`da_DK`) følger med i `languages/` (✅ fase 15).

1. Ret eller tilføj strenge i koden (`__()`, `_n()`, `sprintf` med `/* translators: */`-kommentar).
2. `npm run i18n`: bygger dashboardet, genererer `merchboard.pot` (fra `merchboard.php`, `uninstall.php`, `src/`, `templates/` og `build/`), opdaterer `merchboard-da_DK.po`, kompilerer `.mo` og skriver `merchboard-da_DK-<md5(build/dashboard.js)>.json` til JavaScript.
3. Oversæt nye strenge i `merchboard-da_DK.po`, og kør `npm run i18n` igen (eller `node bin/composer.mjs i18n:mo i18n:json`).
4. `npm test` kører `TranslationsTest`: alle strenge oversat, pladsholdere bevaret, ingen markup tilføjet, og JSON'en matcher bundlen.

Værktøj: Composer dev-dependencies `wp-cli/wp-cli` og `wp-cli/i18n-command` (via `node bin/composer.mjs`). De indgår ikke i det distribuerede plugin. Med PHP 8.5 lokalt viser `gettext` en harmløs deprecation-besked.
