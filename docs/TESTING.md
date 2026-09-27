# Teststrategi

> Status: Statisk analyse, lint og **PHP unit-tests** kører lokalt og i CI (fra fase 2). **PHP-integrationstests** mod en rigtig database kører lokalt mod MariaDB og i CI mod MySQL (fra fase 7). Øvrige niveauer er planlagt.

## Niveauer

| Niveau | Værktøj | Hvad | Hvornår | Kører |
| --- | --- | --- | --- | --- |
| Statisk | PHPCS (WPCS), PHPStan level 8, ESLint, `php -l` (8.2) | Standarder, security-sniffs (escaping, nonce, prepared SQL), typefejl | Nu | Lokalt og i CI |
| PHP unit | PHPUnit + [Brain Monkey](https://github.com/Brain-WP/BrainMonkey) | Domænelogik, services, entities og validering uden WordPress | Fase 2 → | Lokalt og i CI |
| PHP integration | PHPUnit 11 + rigtig WordPress (`roots/wordpress-no-content`) + rigtig MySQL/MariaDB | Migrationer, lås, transaktioner, repositories, charset og services mod rigtig database. Senere capabilities, REST-permissions og **IDOR-tests** | Fase 7 → | Lokalt (portabel MariaDB) og i CI (runnerens MySQL) |
| Typer | `tsc` (strict, `npm run typecheck`) | Hele dashboard-koden inkl. tests. `any` forbudt (ESLint) | Fase 13 → | Lokalt og i CI |
| JS unit | Jest via `wp-scripts test-unit-js` (jsdom), React via `createRoot` + `act` (intet Testing Library) | Bootstrap-validering, router, API-klient, hooks, komponenter og app | Fase 13 → | Lokalt og i CI |
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

**Fase 10 (admin-shell, brugervalg og profil):**

- Unit: topmenu og Overview kræver `merchboard_manage`, mens Creators og Settings er submenuer med egne capabilities. Overview afviser fem typer uautoriserede brugere, viser rigtige tal og status uden navne eller e-mail, viser advarsler (dinglende link, afventende schema, MyISAM, afventende rolle-sync, sletning slået til, fejlet migration) og viser kun links med de rette capabilities. Brugerfeltet dækker login, `#id`, tomt felt, ukendt login, e-mail og label afvist, og at et dinglende link bevares uændret. Formularen viser ingen brugerliste og ingen e-mail, forudfylder kun eksisterende brugere og viser kun profil-link med `edit_user`. Søge-endpointet dækker capability, nonce, minimum 2 tegn, at koblede brugere udelades, at kun login og label returneres, og at der ingen `nopriv`-handler er. Profilboksen dækker koblet, ukoblet, escaping og at den er skjult uden capability.
- Integration (MariaDB, rigtig WordPress): søgning på login og display name, men aldrig e-mail (heller ikke med `@`), wildcards og SQL i søgeordet er bogstavelige, grænsen overholdes, login-opslag er eksakte, gem via login og afvisning af ukendt login, WordPress' egne `$menu`/`$submenu` har korrekte capabilities, Overview med rigtige tal og profilboksen med rigtige brugere.

**Fase 11 (audit log):**

- Unit: `paginate` (rigtig side, nyeste først, total, tom side ud over sidste, grænser for side og sidestørrelse, aldrig hele tabellen), filtre som placeholders (præcis SQL og argumenter), `AuditFilter` afviser ugyldige former, og `filter_from_request` ignorerer 11 slags ugyldige værdier (ukendt hændelse, SQL, wildcards, 0 og negative creator-ID'er, overflow-datoer, forkert format, periode i forkert rækkefølge, ikke-skalare værdier). Dertil dage i sitets tidszone som UTC-grænser, labels for alle actions, detaljer uden rå JSON og e-mail (ukendte nøgler renset og afkortet, indlejrede data skjult), slettede brugere og settings-ændringer, 403 for fem typer uautoriserede brugere, menuen med `merchboard_view_logs`, Overview-aktivitet kun med capability'en og det udvidede capability-sæt samt rolle-sync.
- Integration (MariaDB, rigtig `WP_List_Table`): alle slags records (alle actions, System, slettet actor, manglende creator, escaping, ingen e-mail, ingen rå JSON, ingen POST-formular eller bulk actions), 20 pr. side med side 2, filtre på hændelse, creator og periode, at ugyldige filtre ignoreres, at læsning ændrer intet, at creator og subscriber ikke har capability'en, menuen i WordPress' `$submenu`, og at eksisterende sites (rolle-version 1) får capability'en ved næste request.

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

## Frontend-tests (fase 13)

Kør med `npm run test:js` (indgår i `npm test`) og `npm run typecheck` (indgår i `npm run lint`). Konfiguration: `jest.config.js` (wp-scripts-preset, kun `assets/src`) og `tsconfig.json`. Tests ligger i `assets/src/dashboard/test/`, og helpers i `testing/` (`render`/`act`, fixtures).

- **Bootstrap (`parseConfig`):** gyldig config, manglende felter, fremmede hosts, `//`, `javascript:`, `data:`, relative URL'er og credentials i URL'er afvises, ugyldig routing og ugyldige sektioner, og ukendte felter (fx `creator_id`) ignoreres.
- **Routes:** normalisering (traversal, `//`, schemes, `<script>`, encoding og længde afvises), stier og URL'er med pretty permalinks og query-fallback.
- **API-klient:** korrekt `apiFetch`-kald (`parse: false`, method, data, signal), 401/403/404/5xx → fejltype med kun WordPress' fejlkode (ingen beskeder), fejlsvar uden JSON, malformed succes-svar (ugyldig JSON, forkert form, array, ikke et svar) → `invalid_response`, netværksfejl og abort, og kald uden for `merchboard/v1` afvises uden request.
- **`useApi`:** loading → success, fejl og retry, kastet fejl → generisk fejl, abort ved unmount og sene svar ignoreres.
- **`ApiErrorState`:** 401 med login-link og uden redirect, 403 med log ud-link, øvrige fejl med retry, ingen serverdetaljer.
- **Komponenter:** Button (type, busy), Alert (tone som tekst, roller), Card (labelled section), Skeleton (status, skjulte søjler), EmptyState og Field (label, hjælpetekst og fejl via `aria-describedby`/`aria-invalid`).
- **App og mount:** landmarks, ét h1, aktivt menupunkt med rigtige URL'er, deep link → "Page not found" → client-side navigation, tilbage-knap (`popstate`), query-fallback, sektion uden komponent, mobil-nav (`aria-expanded`, `Esc`, fokus), serverværdier som tekst (ingen HTML-injektion), log ud-link, intet i storage eller cookies, mount/unmount og ugyldig bootstrap → generisk fejl uden links.

**PHP (fase 13):** `ShellControllerTest` dækker React-shellen (registrering med manifest-dependencies, RTL, inline bootstrap med hex-escaping og uden creator-data, nonce-filtre kun under rendering, 503 og logning uden build, ugyldige manifester, 403 uden bundle og CSP uden `script-src`). `DashboardAccessTest` (integration) renderer shellen med rigtige WordPress-scripts: kun bundlen og dens dependencies (ikke andre enqueued scripts), nonce på alle `<script>`-tags, filtrene fjernes igen, og 503 uden build. Testene bruger et fixture-build, så de ikke afhænger af `npm run build`.

**Browser (Playground):** Et scratchpad-script kører den lokale Chrome headless via `playwright-core`, som følger med `@wordpress/scripts` (ingen browser-download). Det tjekker rendering, navigation, refresh, mobil-menu, tastatur, CSP-brud, konsolfejl, fejlede og fremmede requests og browser-storage. Playwright-E2E i repo'et er stadig planlagt efter MVP.

## REST og profil (fase 14)

- **PHP unit (`CreatorProfileServiceTest`):** tom men komplet profil, delvis opdatering med én audit-record (`changed_fields`, ingen værdier), omdøbning, uændrede værdier skriver intet, tomme værdier sletter meta, felter uden for whitelisten ignoreres, 15 ugyldige input (tomt/for langt navn, bio for lang eller ikke en streng, links: forkert type, ukendt platform, http, `javascript:`, ingen host, credentials, citationstegn, for lang), servicen afviser en anden brugers creator, en inaktiv creator og logget ud, fejlet meta-skrivning og fejlet audit ruller omdøbning tilbage, korrupte gemte værdier ignoreres, meta-store kun med whitelistede nøgler.
- **Integration (`tests/Integration/Rest/MeEndpointTest.php`, rigtig REST-server via `rest_do_request()`):** kun de tre routes med permission callbacks, præcise svarfelter (ingen e-mail/ID), 401/403 for logget ud, subscriber, administrator uden record, inaktiv creator, rolle uden record og record uden capability på alle tre endpoints (intet skrevet), IDOR (ID'er i query og body, `status` ignoreres, anden creator uændret), PATCH gemmer (også efter tom cache) og auditerer, 400 med feltkoder og intet gemt, deaktivering virker på næste request, databasefejl giver 403 og logges, fejlet audit-skrivning giver 500 og rollback.
- **Jest (`test/creator.test.tsx`):** validatorer for `/me` og `/me/profile` (ukendte felter fjernes, malformede svar afvises), endpoint-funktioner kalder de dokumenterede routes uden ID'er, 400-feltkoder filtreres (ingen servertekst), klientvalidering, app-skeleton → navn (som tekst), 401/403 med link og uden redirect, retry efter 500, malformet `/me`, profilformular (værdier, password-link, kun ændrede felter, toast og topbar-navn, ingen request uden ændringer, klientvalidering blokerer, serverens feltfejl som faste tekster, 401/403/500 ved gem bevarer input, retry når profilen ikke kan indlæses). `test/client.test.ts`: udløbet nonce → `unauthenticated`.
- **Playground:** scratchpad-scripts `check14.sh` (curl med cookie og `X-WP-Nonce` fra `admin-ajax.php?action=rest-nonce`) og `browser14.cjs` (Chrome). Med og uden WooCommerce.

## Overview, sektioner og oversættelser (fase 15)

- **PHP unit:** `DashboardSectionsTest` (standardsektioner, filter tilføjer, capability styrer synlighed, capability sendes ikke, 10 ugyldige poster droppes og logges, startsiden findes altid, ikke-creators ser intet). `TranslationsTest` (alle POT-strenge oversat, pladsholdere bevaret, ingen markup, gyldig MO, JSON til `build/dashboard.js` og identisk med PO). `PluginTest` (textdomain på `init`, sti `languages`). `ShellControllerTest` (script-oversættelser registreret, sektioner fra registry).
- **Integration:** `tests/Integration/Dashboard/SectionsAndTranslationsTest.php` (registry med rigtige capabilities og filter, danske PHP-strenge inkl. plural og sektion-labels, dansk inline script-oversættelse i shellen med CSP-nonce på et dansk site).
- **Jest:** `test/overview.test.tsx` (velkomst og status, profil-resumé som tekst, links med `rel`/`target`, tre KPI-kort uden tal, tom profil → link og client-side navigation, skeleton, 401/403/500 i profil-kortet mens resten vises, malformet profil → fejl og retry, refresh af startsiden).
- **Playground:** scratchpad-scripts `check15.sh` og `browser15.cjs`: MVP-flowet (admin opretter creator over HTTP → creator logger ind → Overview → redigerer profil) på et engelsk site med WooCommerce og et dansk site uden WooCommerce.

## WooCommerce foundation (fase 17)

- **Unit uden WooCommerce (Brain Monkey):** fakes i `tests/Unit/Support/`: `FakeProductStore` (produkter med koblings-, oprindelses- og kategoridata, registrerede skrivninger og en `on_write`-krog, fx til at tjekke `ProtectedMetaWrite` under skrivningen), `FakeJobQueue` (kø, dedup og `drain()` som Action Schedulers runner), `FakeReconciliationCandidates` og `FakeDatabase` (fejl ved læsning/skrivning pr. tabel og storage engine). `WooCommerceProductStore` testes mod en WooCommerce 10.3.8-meta-stand-in (`tests/Fixtures/woocommerce-product.php` med `WcProductStorage`).
- **Separate PHP-processer:** tests, der indlæser globale stand-ins (`woocommerce-product.php`, `wp-term.php`, `wp-post.php`), kører med `#[RunTestsInSeparateProcesses]`, så klasserne ikke lækker til andre tests. Stand-ins er udelukket fra PHPStan, som bruger `tests/Stubs/woocommerce.stub` og WordPress-stubs.
- **Integration (rigtig WordPress og database, uden WooCommerce):** `tests/Integration/Support/PostMetaProductStore.php` implementerer `ProductStore` på `product`-indlæg og post meta, så kæden kører mod den rigtige core-vagt, transaktioner og audit. Kategori-afstemningens jobs lægges i `FakeJobQueue` og køres med `do_action_ref_array()` som Action Schedulers runner. Testene registrerer selv `product_cat` (og posttypen `product`, hvor edit-links testes) og mapper `edit_product` til `edit_posts` via `map_meta_cap`, som WooCommerce gør.
- **Uninstall (ADR-036):** `tests/Integration/Fixtures/uninstall-process.php` kører `uninstall.php` i separate PHP-processer med tilstandene `unloaded`, `loaded-other` og `loaded-none`, så `WP_UNINSTALL_PLUGIN` (manglende, et andet plugins basename, pluginets eget) kan testes uafhængigt af testprocessen.
- **MariaDB og MySQL 8:** lokalt kører integrationstestene mod MariaDB 10.11, i CI mod MySQL 8.0. MySQL 8.0.19+ viser heltalstyper uden display-bredde (`bigint unsigned` i stedet for `bigint(20) unsigned`); kolonnetjek normaliserer typen som `tests/Integration/Database/SchemaTest.php`.
- **Query-count (audit-log, trin 14):** `AuditLogPageTest::test_product_lookups_do_not_grow_with_the_number_of_records` sammenligner antallet af queries ved 3 og 12 produkthændelser. Efter én opvarmning ryddes object cachen (`wp_cache_flush()`) før hver målt rendering, så gentagne opslag, som en varm cache ville besvare, stadig tæller. Verificeret med en midlertidig mutation (ét cachet opslag pr. produkt), som fik testen til at fejle.
- **PHPStan:** `composer analyse` kører med `--memory-limit=2G`. PHPStan giver grænsen videre til hver parallel worker; på en runner med få kerner analyserer hver worker flere filer, og 1G var for lidt (fase 17, CI).
- **Ikke dækket automatisk:** hele kæden mod et rigtigt WooCommerce (wp-admin, quick/bulk edit, CSV-import, REST, duplicate, kategorisletning og Action Schedulers egen behandling). `WooCommerceProductStore` er kun testet mod stand-ins. Kæden er verificeret manuelt i Merch Dashboard Local (trin 12, se nedenfor).
- **Local-verifikation (trin 12, 2026-09-28):** kun mod projektets isolerede LocalWP-site Merch Dashboard Local (WordPress 7.1.2, WooCommerce 10.3.8, pluginet via junction på `983ceed`) og kun med projektejerens godkendelse.
  - **Metode:** WP-CLI i sitets eget miljø (LocalWP's `app/.envrc`, altid med `--path` til sitet) til service-, CRUD-, REST-, CSV-, duplicate-, bulk/quick edit- og guard-tjek, og HTTP mod webserveren til produktlisten, produkteditoren (inkl. en rigtig gemning), creatorens redigeringsside, kategoriafstemningen, audit-loggen, creator-login, dashboardet og `/me`. Action Scheduler-jobs i gruppen `merchboard` blev kørt med Action Schedulers egen behandling (`process_action()`).
  - **Data:** kun midlertidige, syntetiske objekter (produkter, kategorier og en testbruger koblet midlertidigt til en eksisterende creator); ingen nye creators, fordi creators ikke kan slettes. Et snapshot før og efter (creators, mappings, koblingsmeta, produkter, kategorier, relationer, brugere og options) var ens efter oprydningen. Tilbage er kun de append-only audit-records og Action Schedulers historik over færdige jobs. Midlertidige kodeord og sessioner blev aldrig vist og blev slettet eller destrueret bagefter.
  - **Ikke manuelt verificeret:** blok-produkteditorens UI (kun indirekte via WooCommerce REST, som blev verificeret) og drift uden WooCommerce (dækket af automatiske tests, ikke kørt i Local).
