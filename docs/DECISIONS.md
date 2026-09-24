# Architecture Decision Records

Vigtige beslutninger registreres her i kronologisk rækkefølge. En beslutning ændres ikke med tilbagevirkende kraft. En ny ADR markeres i stedet som "Erstatter ADR-NNN", og den gamle får status *Erstattet*.

> **Note om navngivning:** ADR-001 til ADR-017 blev skrevet med den tidligere midlertidige konvention `ccmerch` / `CCMerch\` / `ccmerch-dashboard`. Deres tekniske identifiers er opdateret til den nuværende konvention (`merchboard` / `MerchBoard\`) ved rename-migrationen i ADR-018, så dokumenterne ikke beskriver forældede navne. Beslutningernes indhold er uændret.

Skabelon:

```markdown
# ADR-NNN: Titel
## Date
## Status  (Accepteret | Foreslået | Erstattet af ADR-NNN)
## Context
## Decision
## Alternatives considered
## Reason
## Consequences
```

---

# ADR-001: Repo-roden er plugin-roden

## Date
2026-09-24

## Status
Accepteret

## Context
Repo'et var tomt. Enten indeholder repo'et en hel WordPress-installation, eller også er repo'et selve pluginet.

## Decision
Repo'ets rod er pluginmappen `merchboard/`. WordPress kører udenfor (Playground/LocalWP/hosting) og mounter/symlinker repo'et.

## Alternatives considered
- Hele WordPress (core, themes, andre plugins) i repo'et: Det giver støj, core-opdateringer i git-historikken og risiko for at committe `wp-config.php` med credentials.
- Monorepo med `plugins/merchboard/`: Det er ikke nødvendigt, så længe der kun er ét plugin.

## Reason
WordPress-standard for plugin-udvikling. Enkel CI og release-zip, og ingen secrets i nærheden.

## Consequences
Et custom theme eller et ekstra plugin ville kræve et nyt repo eller en omstrukturering. Dev-filer udelukkes fra releases via `.distignore`.

---

# ADR-002: Ét plugin med interne moduler

## Date
2026-09-24

## Status
Accepteret

## Context
Systemet skal kunne udvides med mange moduler (Payouts, Campaigns …).

## Decision
Ét plugin. Modularitet opnås med interne moduler (`src/Modules/<Navn>/`), der implementerer et minimalt `Module`-interface, og som kan til-/fravælges via filteret `merchboard_modules`.

## Alternatives considered
Separate plugins pr. modul (add-on-arkitektur): fleksibel distribution, men versionskobling, aktiveringsrækkefølge og delt kode giver kompleksitet uden gevinst for en intern løsning.

## Reason
Enkel deploy og versionering, fælles security- og datalag, lav kompleksitet.

## Consequences
Moduler skal overholde reglerne i [MODULES.md](MODULES.md) (ingen direkte SQL mod andre modulers tabeller og ingen cirkulære afhængigheder), så de kan udskilles senere, hvis det bliver nødvendigt.

---

# ADR-003: Minimumsversioner PHP 8.2 og WordPress 6.7

## Date
2026-09-24

## Status
Accepteret. Hosting bekræftet 2026-09-24: dev og produktion kører PHP 8.3. Minimum forbliver 8.2 (bredere kompatibilitet; intet 8.3-feature er nødvendigt).

## Context
Pluginet er internt og kører på en server, virksomheden styrer. PHP 8.1 er end-of-life. PHP 8.2 har security-support til slutningen af 2026, og 8.3 til slutningen af 2027.

## Decision
`Requires PHP: 8.2` og `Requires at least: 6.7`. CI og Playground kører PHP 8.2. Composer låses til platform PHP 8.2.0.

## Alternatives considered
- 8.1: EOL, og mangler readonly classes.
- 8.3: typed class constants, men højere hostingkrav.

## Reason
Moderne sprogfeatures (enums, readonly properties/classes, `never`, first-class callables) og samtidig bred hosting-kompatibilitet.

## Consequences
Minimum hæves til 8.3, når 8.2 når EOL (ultimo 2026), efter bekræftelse af hosting-versionen. Det kræver en ny ADR.

---

# ADR-004: Ingen tredjeparts PHP runtime-dependencies

## Date
2026-09-24

## Status
Accepteret. Delvist erstattet af ADR-017: Sætningen om, at distribueret `vendor/` indeholder Composers autoloader, gælder ikke længere. Pluginet bruger sin egen autoloader og distribueres uden `vendor/`.

## Context
WordPress har ingen dependency-isolation. To plugins, der loader forskellige versioner af samme Composer-pakke (fx `psr/log`), giver fatale fejl eller subtile bugs.

## Decision
Composer bruges kun til PSR-4-autoloading af vores egen kode og til dev-værktøjer. Distribueret `vendor/` indeholder kun Composers autoloader. Hvis en runtime-pakke engang bliver uundgåelig, prefixes den med Strauss eller PHP-Scoper (kræver ny ADR).

## Alternatives considered
Almindelige runtime-dependencies (fx `psr/log`, en DI-container eller en HTTP-klient): bekvemt, men konfliktrisiko.

## Reason
Stabilitet i et delt runtime-miljø og minimal supply chain i produktion. WordPress leverer det meste (HTTP API, cache, cron, i18n).

## Consequences
Logger-interfacet er PSR-3-*lignende* uden at implementere `psr/log`. Containeren er hjemmelavet og minimal.

---

# ADR-005: Creator er en egen entitet i en custom table

## Date
2026-09-24

## Status
Accepteret. Præciseret af ADR-020: `user_id` er nullable (creator kan eksistere uden login), og statusmodellen er `active`/`inactive`.

## Context
En creator skal kobles til en WordPress-bruger (login), men salg, produkter og payouts hænger på creator'en som forretningsenhed.

## Decision
Tabellen `merchboard_creators` (med `user_id UNIQUE`) samt `merchboard_creatormeta` via WordPress metadata API. Adgang til "nuværende creator" sker kun via `CreatorContext`.

## Alternatives considered
- Kun rolle + user meta: simpelt, men blander login-konto og forretningsenhed. Historik knækker ved kontoskift, og teams er umulige uden omskrivning.
- Custom post type: gratis admin-UI, men dårlig UX-match, ingen unikhedsgaranti, misbrug af `post_status` og risiko for datalæk via generiske queries.

## Reason
Klar domænemodel, databasegaranteret unikhed og en stabil nøgle for ledger og payouts. Meta-tabellen giver fleksibilitet uden schemaændringer.

## Consequences
Der skal bygges eget admin-UI (`WP_List_Table` og formularer) og repository. Migrationer skal vedligeholdes. Teams kan senere tilføjes via en members-tabel uden at ændre creator-ID'er.

---

# ADR-006: WordPress-authentication; adgang = rolle + aktiv creator-record

## Date
2026-09-24

## Status
Accepteret

## Context
Creators skal logge ind og kun tilgå dashboardet. Security er prioritet 1.

## Decision
WordPress' login og sessions genbruges fuldt ud. Adgang kræver `merchboard_access_dashboard` (via rollen `merchboard_creator`) **og** en aktiv creator-record. Application Passwords slås fra for creators, og wp-admin blokeres for creators.

## Alternatives considered
- Eget login/token-system (JWT osv.): stor angrebsflade og genopfinder password-reset, rate limiting og 2FA.
- Kun rolle: suspension kræver rolleændring, og en fejlagtig rolle giver adgang.

## Reason
Gennemtestet auth, kompatibilitet med 2FA- og security-plugins, og suspension virker øjeblikkeligt.

## Consequences
Dashboardet er cookie-baseret og kræver same-origin (ingen headless-app på andet domæne uden ny ADR).

---

# ADR-007: Creator-API er `/me`-scoped uden creator-ID'er

## Date
2026-09-24

## Status
Accepteret

## Context
IDOR (Creator A ændrer ID'et i URL'en og ser B's data) er den mest sandsynlige alvorlige fejl i et multi-tenant-dashboard.

## Decision
Creator-endpoints ligger under `/merchboard/v1/me/...` og udleder creator'en server-side. Underressourcer slås op med creator-filter i selve queryen. Manglende ejerskab giver 404.

## Alternatives considered
`/creators/{id}/...` med ejerskabs-tjek. Det virker, men ét glemt tjek er et datalæk.

## Reason
Fjerner hele fejlklassen i designet frem for at stole på disciplin.

## Consequences
Admin-endpoints, der tager ID'er, ligger i et separat `/admin/`-namespace med capability-krav.

---

# ADR-008: Kun REST API; ingen admin-ajax

## Date
2026-09-24

## Status
Accepteret

## Decision
Al JS→server-kommunikation går via REST (`merchboard/v1`). Klassiske admin-formularer bruger `admin-post.php` med nonce.

## Alternatives considered
`admin-ajax.php`: ingen schema og ingen permission callbacks. Det er ældre og langsommere mønstre.

## Reason
Schema-validering, eksplicitte permission callbacks, standard-fejlformat og testbarhed.

## Consequences
Alle routes skal have `permission_callback` og `args` (håndhæves i code review).

---

# ADR-009: Creator dashboard som standalone route uden theme

## Date
2026-09-24

## Status
Accepteret

## Decision
En rewrite rule (`/creator-dashboard/*`) renderer en minimal HTML-shell fra pluginet. Kun vores assets indlæses, og hverken theme eller fuld `wp_head()` bruges.

## Alternatives considered
En WordPress-side med shortcode/block: Theme og CSS-konflikter, redaktører kan ødelægge siden, og det ligner ikke en SaaS-app.

## Reason
Fuld kontrol over design, performance, security headers og CSP.

## Consequences
Theme- og plugin-scripts (fx analytics eller cookie-banner) indlæses ikke automatisk. Hvis cookie-samtykke eller analytics kræves i dashboardet, skal det tilføjes eksplicit.

---

# ADR-010: React (WordPress' egen) + @wordpress/scripts; eget design system

## Date
2026-09-24

## Status
Accepteret (TypeScript er åbent spørgsmål)

## Context
Dashboardet skal understøtte navigation, grafer, tabeller, formularer, modaler og mange fremtidige moduler.

## Decision
React via `@wordpress/element` (externals, så det ikke bundles), `@wordpress/api-fetch` og `@wordpress/i18n`, bygget med `@wordpress/scripts`. Et eget lille komponent- og design system med CSS custom properties. `@wordpress/components` bruges ikke i creator-UI.

## Alternatives considered
PHP + vanilla JS, Interactivity API, Vue/Svelte og Vite. Se [FRONTEND.md](FRONTEND.md#teknologivalg).

## Reason
Kompleksiteten retfærdiggør et komponent-framework. Den WordPress-integrerede toolchain giver mindst mulig build-konfiguration.

## Consequences
Et JS-build er nødvendigt (`build/` genereres i CI til releases). React-versionen følger WordPress core.

---

# ADR-011: WooCommerce som valgfrit modul med sales ledger

## Date
2026-09-24

## Status
Accepteret

## Decision
WooCommerce-integration i et betinget modul, kun HPOS-kompatible CRUD-API'er, produkt→creator via product meta og en uforanderlig `merchboard_sales`-ledger fodret af ordre- og refund-hooks.

## Alternatives considered
Live-queries mod WooCommerce ved hver dashboard-visning: langsomt og uden historisk attribution.

## Reason
Performance, revisionsspor og grundlag for payouts.

## Consequences
Ledgeren skal holdes korrekt (idempotens, reversals, backfill og reconciliation). Se [WOOCOMMERCE.md](WOOCOMMERCE.md).

---

# ADR-012: Egen log-tabel med audit-channel

## Date
2026-09-24

## Status
Accepteret

## Decision
`merchboard_logs` og en `Logger`-service. Channel `audit` logges altid. Retention via WP-Cron.

## Alternatives considered
WooCommerce' logger (skaber afhængighed af WooCommerce), `error_log` (ikke søgbart for admins) og et eksternt log-plugin (ekstern afhængighed).

## Reason
Pluginet skal virke uden WooCommerce, og admins skal kunne se audit-historik i WordPress.

## Consequences
Retention og tabelstørrelse skal holdes øje med.

---

# ADR-013: Lokal udvikling med WordPress Playground (flygtigt site)

## Date
2026-09-24

## Status
Accepteret

## Context
Docker findes ikke på udviklingsmaskinen. Projektet skal være isoleret fra andre lokale WordPress-/LocalWP-sites.

## Decision
`@wp-playground/cli` som dev-dependency. `npm run wp:start` starter et flygtigt site (WP latest, PHP 8.2 og WooCommerce via `blueprint.json`) med repo'et mountet som plugin.

## Alternatives considered
- `@wordpress/env`: kræver Docker.
- LocalWP: kræver manuel opsætning uden for repo'et og et eksplicit valg af site (se Project Isolation). Understøttes som valgfrit alternativ.
- Persistent Playground-site i repo'et: WooCommerce-installation fejler på Windows-host-mounts.

## Reason
Nul global opsætning, reproducerbart og isoleret.

## Consequences
SQLite i stedet for MySQL lokalt. MySQL-specifik adfærd verificeres i CI (integrationstests med MySQL) og på staging. Testdata skal seedes via blueprint/scripts.

---

# ADR-014: Kvalitetsværktøjer: WPCS + PHPStan level 8; PHPCompatibility fravalgt

## Date
2026-09-24

## Status
Accepteret

## Decision
PHPCS med WordPress Coding Standards (inkl. security-sniffs), PHPStan level 8 med WordPress-stubs, ESLint/Prettier via wp-scripts og CI på hvert push. PHPCompatibility fravalgt.

## Alternatives considered
PHPStan på lavere level (lettere, men mindre sikkert på greenfield). PHPCompatibility 9.3.5 (kender ikke PHP 8.x) eller 10.0-alpha (ustabil).

## Reason
Høj statisk sikkerhed er billigst at indføre fra start. PHP-kompatibilitet dækkes af PHPStan `phpVersion: 80200` og `php -l` på 8.2 i CI.

## Consequences
Kode skal være null-sikker fra start. Revurder PHPCompatibility, når 10.x er stabil.

---

# ADR-015: Dokumentation på dansk, kode på engelsk

## Date
2026-09-24

## Status
Accepteret

## Decision
`docs/` skrives på dansk. Kode, kommentarer, commit messages, hooks og UI-kildestrenge skrives på engelsk. UI oversættes til dansk via i18n.

## Reason
Dokumentationen læses af projektejeren. Engelsk kode følger WordPress-konventioner og gør i18n korrekt.

## Consequences
Nye dokumenter følger denne regel.

---

# ADR-016: Eksterne services kræver eksplicit godkendelse; Dinero og Google Drive er fravalgt

## Date
2026-09-24

## Status
Accepteret

## Context
Udviklingsmiljøet kan have connectors til eksterne tjenester (fx Dinero og Google Drive). Hvis de er tilgængelige, er der risiko for, at de bliver brugt som datakilde, lager eller backup, uden at det er en del af projektets arkitektur. Roadmappets "Integrations"-område er bevidst formuleret generisk.

## Decision
- Projektet bruger kun: GitHub-repository'et, projektets egne filer, projektets egne lokale dependencies, det WordPress-miljø, der eksplicit tilhører projektet, og eksterne services, der er eksplicit godkendt som ADR.
- **Dinero og Google Drive er ikke en del af projektets nuværende eller planlagte integrationsarkitektur.** Der må ikke bygges integrationer, læses eller sendes data, gemmes projektdata eller tages backup via dem, og deres connectors må ikke aktiveres eller godkendes på projektets vegne.
- En connector, der er tilgængelig i udviklingsmiljøet, giver ikke tilladelse til at bruge den.
- Enhver fremtidig integration (også under roadmappets "Integrations") kræver projektejerens eksplicitte godkendelse af den konkrete tjeneste og en ny ADR.

## Alternatives considered
Ad hoc-brug af tilgængelige connectors efter behov. Fravalgt: Det bryder Project Isolation og giver uklarhed om, hvor projektdata befinder sig.

## Reason
Security og dataminimering: Projektdata forbliver i repository'et og det projekt-ejede WordPress-miljø. Sporbarhed sikres, fordi hver ekstern afhængighed er en dokumenteret beslutning.

## Consequences
GitHub er projektets eneste backup og versionshistorik. Hvis Dinero (fx til bogføring af payouts) eller en anden tjeneste senere ønskes, skal det besluttes eksplicit og registreres i en ny ADR, der erstatter denne del.

---

# ADR-017: Egen PSR-4-autoloader ved runtime; Composer kun til udvikling

## Date
2026-09-24

## Status
Accepteret. Erstatter den del af ADR-004, der siger, at distribueret `vendor/` indeholder Composers autoloader.

## Context
Fase 2 skulle indføre autoloading. Composers `vendor/autoload.php` indlæser **alle** installerede pakkers `files`-autoloads. I et udviklingsmiljø med dev-dependencies betyder det, at fx Mockerys globale hjælpefunktioner (`mock()`, `spy()`) og Brain Monkeys API indlæses i WordPress, når pluginet kører. Desuden ville pluginet fatale eller kræve et ekstra tjek, hvis `vendor/` mangler (fx ved installation direkte fra git uden `composer install`), og release-builds ville kræve `composer install --no-dev`.

## Decision
- Pluginet registrerer sin egen lille PSR-4-autoloader (`src/Core/Autoloader.php`, `MerchBoard\` → `src/`) i hovedfilen og bruger **ikke** `vendor/autoload.php` ved runtime.
- Autoloaderen afviser klassenavne, der ikke er rene namespace-segmenter (fx `..`, `/`, `:` og null-bytes), fordi klassenavne kan nå autoloadere fra utroværdigt input via `class_exists()` i anden kode.
- Composer bruges fortsat til dev-værktøjer (PHPCS, PHPStan, PHPUnit, Brain Monkey) og til autoloading i tests (`autoload` + `autoload-dev`).
- Den distribuerede plugin-zip indeholder ikke `vendor/` (se `.distignore`).

## Alternatives considered
- Composers autoloader ved runtime (oprindelig plan i ADR-004): dev-pakker lækker ind i WordPress lokalt, `vendor/` kræves, og der skal håndteres "vendor mangler".
- Klassemap/manuelle `require`-kald: skalerer dårligt med mange moduler.

## Reason
Nul runtime-afhængighed af `vendor/`, ingen dev-kode i WordPress-runtime, enklere release og en eksplicit sikkerhedsvalidering af klassenavne. Autoloaderen er ~20 linjer og fuldt unit-testet.

## Consequences
- Hvis en tredjeparts runtime-pakke nogensinde tillades (kræver ny ADR, jf. ADR-004), skal den prefixes (Strauss/PHP-Scoper) og indlæses eksplicit. Den kommer ikke automatisk med via Composer.
- Klasser i `src/` skal følge PSR-4 nøjagtigt (filnavn = klassenavn), da der ikke er et classmap-fallback.

---

# ADR-018: Permanent naming convention – Merch Dashboard / merchboard / MerchBoard\

## Date
2026-09-24

## Status
Accepteret. Låst før roadmap-fase 3.

## Context
Fase 1 og 2 brugte en **midlertidig** konvention (`CC Merch Dashboard`, prefix `ccmerch`, namespace `CCMerch\`, slug og text domain `ccmerch-dashboard`), mens navnet stod som åbent spørgsmål. Fra fase 3 bliver prefixet en del af persistente identifiers (tabelnavne, options, meta-keys, roller og capabilities), hvorefter et navneskift kræver datamigration. Der findes endnu ingen persistent plugin-data, så skiftet er gratis nu.

## Decision
| Element | Værdi |
| --- | --- |
| Produktnavn | `Merch Dashboard` |
| Teknisk prefix (funktioner, hooks, options, capabilities, cron, tabeller) | `merchboard_` (skjulte meta-keys: `_merchboard_`) |
| PHP-namespace | `MerchBoard\` |
| Konstanter | `MERCHBOARD_` |
| Plugin-slug, installationsmappe, text domain og hovedfil | `merchboard` / `merchboard.php` |
| REST-namespace | `merchboard/v1` |
| Script-/style-handles og CSS-klasser | `merchboard-` |
| Composer-/npm-pakkenavn | `merchboard/merchboard` / `merchboard` |

Den tidligere konvention `ccmerch` er migreret i hele repo'et og må ikke bruges i ny kode, konfiguration eller dokumentation. Undtagelser: GitHub-repo'et og den lokale mappe hedder fortsat `CCMerchDashboard` (repo-navn, ikke et plugin-identifier), `Author`-feltet i plugin-headeren er firmanavnet og ikke produktnavnet, og den fastfrosne analyserapport i `docs/reports/` bevarer sin oprindelige ordlyd.

## Alternatives considered
- Beholde `ccmerch`: afvist af projektejeren.
- Kort CSS-prefix (fx `mb-`): kolliderer med udbredte utility-klasser (Bootstrap `mb-*` = margin-bottom). Det fulde prefix er entydigt.
- Slug `merch-dashboard`: afviger fra prefixet og giver to navne at huske. Ét token (`merchboard`) overalt er enklest.

## Reason
Ét entydigt token på tværs af PHP, WordPress, REST, CSS og pakker. Låst, før data gør et skift dyrt.

## Consequences
- Ingen datamigration var nødvendig (ingen persistent data før fase 3).
- Plugin-basename er nu `merchboard/merchboard.php`. Et eventuelt tidligere aktiveret `ccmerch-dashboard` i et lokalt miljø skal aktiveres på ny (Playground bygges alligevel forfra ved hver start).
- Et fremtidigt navneskift efter fase 3 kræver en ny ADR og en datamigration.

---

# ADR-019: Databaseversionering og migrationer

## Date
2026-09-24

## Status
Accepteret (fase 3)

## Context
Fase 3 opretter den første tabel. Schemaet skal kunne installeres fra bunden, opgraderes ved plugin-opdateringer uden manuel handling (opdateringer udløser ikke activation-hooken) og udvides i senere faser. WordPress' `dbDelta()` kan oprette tabeller og tilføje kolonner/indexes, men kan ikke omdøbe eller droppe kolonner, flytte data eller køre engangsopgaver i rækkefølge.

## Decision
- **Schemaversion** er et heltal i option `merchboard_db_version` (autoload). `0`/manglende betyder ikke installeret.
- **Migrationer** er klasser (`Migration::up( wpdb )`), registreret i `Schema::migrations()` med den version, de giver, som nøgle. Højeste nøgle er forventet version. En released migration ændres eller omnummereres aldrig.
- **`Migrator`** kører manglende migrationer i stigende rækkefølge og gemmer versionen **efter hver succesfuld migration**. Ved fejl stopper kørslen, og versionen forbliver på sidste succes. Migrationer skal være idempotente. `dbDelta()` bruges inde i migrationer til tabel-DDL.
- **Lås:** option `merchboard_db_migration_lock` oprettet atomisk med `add_option()`. Låse ældre end 5 minutter overtages.
- **Triggere:** `register_activation_hook` (altid, idempotent) og et versionstjek på `plugins_loaded` før modulerne registreres (én autoloaded option-læsning). Efter en fejl pauses automatiske forsøg i 5 minutter (transient), og administratorer ser en generisk notice. Detaljer går til serverens error log, indtil Logger findes (fase 4).
- **Tidsstempler:** `DATETIME` i UTC, sat af applikationen med `current_time( 'mysql', true )`. Ingen `CURRENT_TIMESTAMP`/`ON UPDATE`-defaults i schemaet.
- Ingen fremmednøgler, ingen `down()`-migrationer (rollback via backup) og ingen migrationsbiblioteker.

## Alternatives considered
- **Kun `dbDelta()` på hver aktivering/versionsskift** (uden migrationsliste): simpelt, men uden rækkefølge og uden mulighed for datamigrationer eller kolonneomdøbninger. Det bliver et problem, så snart schemaet skal ændres (fx `user_id` → members-tabel).
- **Plugin-versionen som schemaversion:** kobler releases og schemaændringer. En release uden schemaændring ville trigge unødvendige kørsler.
- **Migrationsbibliotek** (fx Phinx): runtime-dependency (ADR-004), ukendt for WordPress-kontekst og overkill.
- **Semver-streng som schemaversion:** heltal er entydigt og trivielt sammenligneligt.
- **Tidsstempler via MySQL-defaults:** afhænger af sessionens tidszone, understøttes forskelligt af SQLite og håndteres dårligt af `dbDelta()`.

## Reason
WordPress-nativt (options, `dbDelta`, `add_option`-lås), lille nok til at forstå på fem minutter, og tilstrækkeligt til fremtidige schema- og dataændringer.

## Consequences
- Hver schemaændring = ny migrationsklasse + en linje i `Schema::migrations()` + DATABASE.md + CHANGELOG.
- Migrationer skal skrives idempotent og verificere deres resultat.
- MySQL-adfærd for `dbDelta()` skal verificeres i CI/staging (fase 7/16). Playground (SQLite) er ikke autoritativ.

---

# ADR-020: Creator↔WordPress-bruger-relation og minimal statusmodel

## Date
2026-09-24

## Status
Accepteret (fase 3). Præciserer ADR-005.

## Context
ADR-005 planlagde `user_id NOT NULL UNIQUE` og statusserne `active`/`suspended`/`archived`. Ved implementering af tabellen skulle det afgøres, om en creator kan eksistere uden login, hvad der sker, når brugeren slettes, og hvor mange tilstande der reelt er brug for nu.

## Decision
- `merchboard_creators.id` er creator'ens identitet og den eneste reference for fremtidig domænedata.
- `user_id` er **nullable** og **UNIQUE**: højst én creator pr. WordPress-bruger (databasegaranteret), mens creators kan eksistere uden login. Mange `NULL` er tilladt.
- Ingen fremmednøgle til `wp_users`. Sletning af en bruger (fase 6) sætter `user_id = NULL` og `status = inactive`. Creator-data slettes aldrig automatisk.
- En bruger flyttes til en anden creator ved først at frakoble den gamle (`user_id = NULL`).
- Statusmodel: PHP backed enum `CreatorStatus` med **`active`** og **`inactive`**. Ukendte værdier afvises ved læsning. Nye tilstande tilføjes, når en fase har et konkret behov (kolonnen er `VARCHAR`, så det kræver ingen schemaændring).
- Udeladt indtil behov: `slug`, `created_by` og `merchboard_creatormeta` (flyttet til fase 14).

## Alternatives considered
- **`user_id NOT NULL`:** tvinger en dinglende reference efter brugersletning eller kræver sletning af creator'en, hvilket er uacceptabelt for salgshistorik.
- **Betinget unikhed ("én *aktiv* creator pr. bruger"):** MySQL har ingen partielle indexes. Det ville kræve genererede kolonner eller applikationslogik uden databasegaranti.
- **Tre eller flere statusser nu:** "suspended" og "archived" har endnu ingen adfærdsforskel i systemet. YAGNI.

## Reason
Databasegaranteret integritet, klar adskillelse mellem login-konto og forretningsenhed, og ingen tilstande uden adfærd.

## Consequences
- Adgangsreglen (fase 8) kræver `status = active` **og** en koblet bruger.
- USER-ROLES, ADMIN og feature-dokumenter bruger `active`/`inactive` og "frakobl bruger" i stedet for "suspendér"/"arkivér".
- En members-tabel (teams) kan senere erstatte `user_id` via en migration uden at ændre creator-ID'er.
