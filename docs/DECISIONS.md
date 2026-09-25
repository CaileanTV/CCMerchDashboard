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
Accepteret. TypeScript besluttet i ADR-027.

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
Accepteret. Suppleret af ADR-030 (WooCommerce-minimum 10.3, én autoritativ skrivevej for produkt→creator, oprydning ved opt-in-uninstall).

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
Erstattet af ADR-021 (tekniske logs uden tabel; audit trail som separat tabel)

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

---

# ADR-021: Tekniske logs via Logger-abstraktion med WooCommerce/error_log-backend; audit trail adskilt

## Date
2026-09-24

## Status
Accepteret (fase 4). Erstatter ADR-012.

## Context
ADR-012 planlagde én egen tabel (`merchboard_logs`) til både tekniske logs og audit, med retention-cron. Fase 4 skal etablere et konsekvent og sikkert logging-fundament uden schemaændringer og uden at gøre WooCommerce til en afhængighed. WordPress core har ingen struktureret logger, kun `error_log()`. WooCommerce har en moden logger med admin-visning (WooCommerce → Status → Logs), fil- og database-handlers og egen tærskel.

## Decision
- **Abstraktion:** interface `Logger` (`debug`, `info`, `warning`, `error`) med struktureret `context`. Standardimplementering `PluginLogger` (tærskel, sanitization via `LogContext`) og `LogWriter`-backends. Registreret i containeren og injected. Ingen statiske kald og ingen singleton.
- **Backend:** `WooCommerceLogWriter` bruger `wc_get_logger()` (source `merchboard`), når WooCommerce er aktivt og `woocommerce_init` har kørt. Ellers, eller hvis WooCommerce' logger fejler, bruges `ErrorLogWriter` (`error_log()`), som WordPress dirigerer til `debug.log` ved `WP_DEBUG_LOG` eller til serverens log.
- **Niveauer:** kun de fire nævnte. Værdierne mapper 1:1 til WooCommerce/PSR-3.
- **Tærskel:** `debug` ved `WP_DEBUG`, ellers `warning`. Filteret `merchboard_log_level` kan tilsidesætte. Pluginet ændrer aldrig WordPress' debug-indstillinger.
- **Sikkerhed:** nøglebaseret maskering, exceptions reduceret til klasse og kode (besked kun fra egne exceptions), ingen stack traces, kontroltegn fjernet og længde- og dybdegrænser.
- **Audit trail** er ikke teknisk logging. Den bliver en separat tabel og service, når de første audit-hændelser opstår (fase 6), med admin-visning i fase 11.

## Alternatives considered
- **Egen log-tabel (ADR-012):** kræver schema, retention-cron og admin-UI. Den blander drift-logs med forretningsdata og giver risiko for databasevækst ved fejl-loops.
- **Kun `error_log()`:** ingen admin-visning, når WooCommerce findes, men acceptabelt som fallback.
- **Kun WooCommerce' logger:** gør WooCommerce til en afhængighed. Uacceptabelt.
- **Eksterne logging-biblioteker (Monolog m.fl.):** runtime-dependency (ADR-004).
- **Flere niveauer (notice/critical/…):** ingen konkret brug. Kan tilføjes, hvis der opstår behov.

## Reason
WordPress-nativt, ingen schema og ingen cron. Administratorer får logs i WooCommerce' velkendte UI, når shoppen kører, og pluginet virker fuldt uden WooCommerce.

## Consequences
- Tekniske logs er ikke søgbare i wp-admin uden WooCommerce. Uden WooCommerce skal serverens log eller `debug.log` bruges.
- Med WooCommerce-backenden gælder også WooCommerce' egen log-tærskel og retention.
- Poster logget før `woocommerce_init` (fx migrationer lige efter en plugin-opdatering) går til `error_log()`.
- Roadmap: fase 4 leverer logging uden tabel og cron. Audit-tabellen flyttes til fase 6, audit-visning til fase 11, og deactivation hook til fase 12 (rewrite rules).

---

# ADR-022: Deklarativ rolle- og capability-synkronisering med egen version

## Date
2026-09-24

## Status
Accepteret (fase 5)

## Context
Roller og capabilities lagres af WordPress i `wp_user_roles` og overlever deaktivering, opdatering og (efter datapolitikken) uninstall. Definitionerne vil ændre sig i senere faser (nye capabilities, forældede fjernes), og plugin-opdateringer udløser ikke activation-hooken. Samtidig må pluginet ikke nulstille administratorers rettigheder, ikke røre andre plugins' capabilities, og creator-rollen må aldrig kunne bære administrative rettigheder.

## Decision
- **Single source of truth:** `Capabilities` (konstanter og definitioner). Pluginet ejer alle capabilities med prefix `merchboard_`.
- **Deklarativ sync** (`RoleManager`) via role-API'et, aldrig direkte option-manipulation:
  - `merchboard_creator` holdes på **præcis** det definerede sæt. Uventede capabilities fjernes og logges (`warning`).
  - `administrator` får pluginets admin-capabilities tilføjet, og forældede `merchboard_*` fjernes. Øvrige capabilities røres aldrig.
  - Andre roller og brugere røres aldrig.
- **Egen version** `merchboard_roles_version` (heltal, `RoleManager::VERSION`) i stedet for en database-migration. Sync køres ved aktivering (altid) og når versionen er bagud (`Installer::maybe_upgrade()`, én autoloaded option-læsning). Uafhængig af schema-migrationer.
- **Deaktivering og uninstall** bevarer rolle og capabilities. Fjernelse kun med opt-in-sletning i fase 9.
- Meta-capabilities (`map_meta_cap`) kommer i fase 8 sammen med `CreatorContext`.

## Alternatives considered
- **Rolle-ændringer som database-migrationer (ADR-019):** migrationer er imperative og beregnet til schema. Roller er deklarativ konfiguration, hvor den ønskede slut-tilstand er det interessante. En migration pr. capability-ændring giver mange små trin og ingen løbende håndhævelse.
- **Kun `add_role()` ved aktivering:** `add_role()` gør intet, hvis rollen findes, så nye capabilities rulles ikke ud ved opdatering, og manuelle eskaleringer bliver aldrig rettet.
- **Kun additiv sync af creator-rollen (respektér manuelle tilføjelser):** fleksibelt for administratorer, men åbner for utilsigtet privilege escalation via rolle-editorer. Security prioriteres. Har et site brug for andre rettigheder, bruges en anden rolle.
- **`manage_options` som admin-check:** kan ikke gives til fx en shop manager uden fulde admin-rettigheder.

## Reason
Forudsigelig slut-tilstand, idempotens, sikker håndtering af opdateringer og et indbygget værn mod eskalering. Brug af WordPress' egne APIs.

## Consequences
- Ændringer i `Capabilities` kræver, at `RoleManager::VERSION` hæves.
- Manuelle ændringer af `merchboard_creator` overskrives ved næste sync (dokumenteret).
- Sites uden rollen `administrator` skal tildele plugin-capabilities manuelt (warning logges). Settings-baseret tildeling til andre roller kommer i fase 9.

---

# ADR-023: Audit trail som append-only forretningsdata med transaktionel skrivning

## Date
2026-09-24

## Status
Accepteret (fase 6)

## Context
Creator-administration skal registrere hvem der ændrede hvad (ADR-021 udskilte audit fra tekniske logs). Registreringen skal være konsistent med ændringen, respektere privacy og ikke kunne manipuleres fra requesten.

## Decision
- **Tabel** `merchboard_audit_log` (`action`, `actor_user_id` nullable, `creator_id` nullable, `context` JSON, `created_at` UTC) via migration 2.
- **Lukket action-sæt** (`AuditAction`-enum), **én record pr. semantisk ændring**: `creator_created`, `creator_updated` (kun feltnavne), `creator_activated`/`creator_deactivated` (tidligere status) og `creator_user_linked`/`creator_user_unlinked` (bruger-ID).
- **Actor og tid bestemmes server-side** i `AuditTrail` (`get_current_user_id()`, `current_time( 'mysql', true )`). **NULL-actor** for systemhandlinger, så der aldrig opfindes et bruger-ID.
- **Privacy:** ingen display names eller andre værdier i context, kun feltnavne, statusser og ID'er. Context renses desuden af `LogContext`. Ingen IP/user agent.
- **Append-only:** repositoriet har kun append og læsning. Sletning sker kun via opt-in "slet alle data" (fase 9).
- **Konsistens:** `CreatorService` skriver creator-ændring og audit-record(s) i én **rigtig databasetransaktion** (`Transaction`: `START TRANSACTION`/`COMMIT`/`ROLLBACK` via `$wpdb`). Fejler audit-skrivningen, rulles ændringen tilbage, og fejlen propagerer og logges af handleren. Rolleændringer (`wp_usermeta`) sker **efter** commit, så en rollback aldrig efterlader en rolle-cache ude af sync. Fejler rolleændringen, fejler adgangen sikkert, fordi adgang kræver både rolle og aktiv record.

## Alternatives considered
- **Audit via Logger/WooCommerce-log:** tekniske logs har anden retention og tærskel og er ikke forretningsdata (ADR-021).
- **Én `creator_changed`-action med diff:** kompakt, men sværere at filtrere og fristende at gemme værdier. Én action pr. semantisk ændring er tydeligere.
- **Gemme gamle/nye display names:** nyttigt for historik, men personnavne i en uforanderlig log komplicerer GDPR (sletning). Kan tilføjes senere med en ny ADR.
- **Audit først, ændring bagefter (uden transaktion) eller "best effort":** kan efterlade audit for ændringer, der aldrig skete, eller omvendt. En pseudo-transaktion ville give falsk sikkerhed.
- **WordPress hooks som event-bus til audit:** løsere kobling, men gør konsistens med transaktionen svær. Kan tilføjes senere ovenpå.

## Reason
Pålidelig, manipulationssikker og privacy-venlig historik med en ærlig konsistensgaranti. Rollback er verificeret i SQLite/Playground.

## Consequences
- Transaktionsgarantien forudsætter en transaktionel storage engine (InnoDB på MySQL/MariaDB, som er WordPress' default). MyISAM ville ikke rulle tilbage. Fejlen logges så, men ændringen består. MySQL-verifikation sker i fase 7/16. *(Ændret af ADR-024: uden transaktionel engine skrives intet.)*
- `Transaction` er ikke re-entrant. Fremtidige use cases må ikke indlejre transaktioner.
- Nye auditerbare hændelser kræver nye `AuditAction`-cases (ingen fri tekst).
- Audit-retention er et åbent spørgsmål. *(Afklaret for v0.1.0 af ADR-029: ubegrænset opbevaring. En produktionspolitik besluttes før produktion.)*

---

# ADR-024: Database-hardening: fail closed uden transaktioner, atomisk migrationslås og integrationstests mod rigtig database

## Date
2026-09-24

## Status
Accepteret (fase 7). Ændrer konsekvensen om MyISAM i ADR-023.

## Context
Fase 3 og 6 var kun kørt mod SQLite (Playground) og unit-test-doubles. En gennemgang mod MySQL/MariaDB-semantik viste tre reelle huller:

1. **Migrationslåsen** brugte `add_option()`. På MySQL/MariaDB kører den `INSERT … ON DUPLICATE KEY UPDATE`. En request med forældet options-cache overskriver derfor en andens lås og får "succes", så to requests kan migrere samtidig. Overtagelse af en forladt lås (`delete_option` + `add_option`) havde samme kapløb, og frigivelsen slettede låsen uanset ejer.
2. **Transaktioner** virker kun på transaktionelle engines. Med MyISAM ville en creator-ændring bestå, mens audit-recorden fejlede (eller omvendt).
3. **Audit-context** blev gemt som UTF-8-JSON. På en legacy `utf8mb3`-tabel ville 4-byte-tegn få audit-skrivningen og dermed forretningsændringen til at fejle. Ukodbar context blev gemt som tom.

Desuden fandtes ingen reproducerbar måde at køre koden mod en rigtig database på. Der er ingen Docker, MySQL eller MariaDB på udviklingsmaskinen.

## Decision
- **Migrationslås** på options-rækken direkte: `INSERT IGNORE` (atomisk), compare-and-swap-`UPDATE` på den forældede værdi ved overtagelse, og `DELETE` kun med egen token. Samme mønster som WordPress' `WP_Upgrader::create_lock()`, plus ejer-token.
- **Fail closed:** `TransactionSupport` spørger `information_schema` (`TABLES` join `ENGINES.TRANSACTIONS`), om alle plugin-tabeller er transaktionelle. Er de ikke det, afviser `Transaction::run()` at starte, og intet skrives. Fejlen logges med tabel og engine, og admin-siden viser en notice. Der angives **ingen** engine i `CREATE TABLE` (som WordPress core). Server-default er InnoDB. SQLite er altid transaktionel. Valgt af projektejeren 2026-09-24.
- **Audit-context** gemmes som ASCII-JSON med erstatning af ugyldig UTF-8 og ikke-endelige tal.
- **Integrationstests** mod rigtig WordPress og database med en egen let PHPUnit 11-bootstrap (`tests/Integration`) og `roots/wordpress-no-content` (Composer, wordpress.org). Lokalt en **portabel MariaDB** (officiel ZIP fra archive.mariadb.org, fastlåst version og SHA-256, kun dev, kun under testkørslen, ingen service). I CI den MySQL-server, som GitHubs Ubuntu-runner leverer. Begge dele er valgt af projektejeren 2026-09-24. archive.mariadb.org er dermed en godkendt downloadkilde for dev-værktøj.

## Alternatives considered
- **Hardcode `ENGINE=InnoDB`:** dækker kun nye tabeller, ikke eksisterende eller konverterede. Tjekket er nødvendigt alligevel. Fravalgt af projektejeren.
- **Kun advarsel ved MyISAM (best effort):** tillader ændringer uden audit-record, som ADR-023 netop skal forhindre. Fravalgt.
- **`wp-phpunit` + `yoast/phpunit-polyfills`** (oprindelig roadmap-plan): vi har ikke brug for `WP_UnitTestCase`-factories endnu, projektet kører allerede PHPUnit 11, og harnessen er lille og uden ekstra dependencies. Kan genovervejes, når REST-tests (fase 14) har brug for factories.
- **Docker/LocalWP:** ikke til stede, og et LocalWP-site kræver eksplicit udpegning (Project Isolation).
- **`GET_LOCK()`:** MySQL-specifikt, ikke i SQLite-oversættelsen, og låsen følger forbindelsen, ikke requesten.

## Reason
Konsistensgarantien i ADR-023 bliver ægte på alle engines, i stedet for at afhænge af serverkonfigurationen. Migrationer kan ikke længere køre dobbelt. Databaseadfærden er bevist mod en rigtig server (MariaDB 10.11.19) med reproducerbare tests, som alle kan køre.

## Consequences
- Et site med MyISAM-tabeller kan ikke ændre creators, før tabellerne konverteres til InnoDB. Det er et bevidst krav og dokumenteret i DATABASE.md.
- Hver creator-skrivning koster én `information_schema`-forespørgsel pr. request, indtil et positivt resultat er cachet.
- MySQL er først verificeret, når CI-jobbet er set grønt. Staging (fase 16) er stadig autoritativt for produktionsmiljøet.
- Nye plugin-tabeller er automatisk omfattet af engine-tjekket (`Tables::all()`).
- MariaDB-versionen i `bin/integration.mjs` skal holdes ajour og helst matche produktionen, når versionen kendes (åbent spørgsmål 2).

---

# ADR-025: Permanent sletning ved uninstall kræver eksplicit opt-in og sletter kun præcist plugin-ejede data

## Date
2026-09-24

## Status
Accepteret (fase 9). Suppleret af ADR-030: pluginets egen product meta `_merchboard_creator_id` slettes også ved opt-in-uninstall. Anden WooCommerce-data røres fortsat ikke.

## Context
Uninstall bevarede indtil nu alle data (fase 3-8), fordi creators og audit er forretningsdata. Pluginet skal kunne fjernes helt, men permanent datatab må aldrig ske ved et uheld. Det må heller ikke ramme WordPress-, WooCommerce- eller andre plugins' data, eller andre sites i et multisite-netværk. `DROP TABLE` kan ikke indgå i en transaktion.

## Decision
- **Opt-in:** Settingen `merchboard_settings['delete_data_on_uninstall']` er default fra og tæller kun som præcis `true`. Den ændres kun via `SettingsService`, som kræver `merchboard_manage_settings` og, for at slå den til, det skrevne ord `DELETE` (server-side). Ændringen audit-logges (`settings_updated`, nyt action i det lukkede sæt), og audit og option skrives i én transaktion (audit først, option sidst). Formularen går via `admin-post.php` som creator-administrationen, ikke via Settings API (som kræver `manage_options`).
- **Uninstall:** `uninstall.php` → `Uninstaller` gør intet uden opt-in. Med opt-in, for det aktuelle site: fjern `merchboard_creator` og direkte plugin-capabilities fra brugere (brugere og deres øvrige roller bevares) → fjern plugin-capabilities fra alle roller og derefter rollen → drop tabellerne i `Tables::all()` efter præcist navn → slet plugin-options og transient → slet `merchboard_settings` **sidst**.
- **Ejerskab ved oprydning = præcise navne**, ikke prefix: `merchboard_creator` og `Capabilities::all()`. ADR-022's prefix-regel bruges fortsat ved sync af pluginets egne roller, men uninstall må ikke fjerne en fremmed `merchboard_*`-rolle eller -capability. En capability, der udgår af `Capabilities`, skal fortsat listes til oprydning.
- **Konsistens uden falsk transaktion:** Hvert trin er idempotent. Fejler et tabel-drop, stopper forløbet før options slettes, så opt-in'en bevares og en senere uninstall fortsætter. Uninstall skriver ingen audit-records.
- **Multisite:** kun det aktuelle site (WordPress kører uninstall én gang uden `switch_to_blog`). Ingen netværks-oprydning.

## Alternatives considered
- **Slet altid ved uninstall** (WordPress-konvention for mange plugins): uacceptabelt for forretningsdata.
- **Kun afkrydsningsfelt uden skrevet bekræftelse:** for let at aktivere ved et uheld.
- **Prefix-baseret oprydning** (`merchboard_*`): fjerner også fremmede roller og capabilities med samme prefix (reproduceret i en integrationstest). Fravalgt.
- **Transaktion om hele sletningen:** DDL committer implicit, så garantien ville være falsk.
- **Iterere alle sites på multisite:** kræver netværks-UI og -opt-in og er ikke understøttet endnu.
- **Settings API:** kræver `manage_options` (eller filter), og pluginet har sin egen capability.

## Reason
Permanent sletning bliver et bevidst, sporbart og autoriseret valg, som kun rammer pluginets egne data, er sikkert at gentage og kan genoptages efter en fejl.

## Consequences
- Et site kan fjerne Merch Dashboard helt uden efterladte tabeller, options, roller eller capabilities.
- Uden opt-in er uninstall uændret (intet slettes).
- Tekniske log-poster i WooCommerce-log eller `debug.log` slettes ikke, fordi de ejes af WooCommerce/serveren.
- Nye plugin-tabeller og -capabilities er automatisk omfattet via `Tables::all()` og `Capabilities::all()`. Nye options skal tilføjes i `Uninstaller`.

---

# ADR-026: Creator-dashboardets indgang og restriktioner for rene creators

## Date
2026-09-24

## Status
Accepteret (fase 12). Projektejeren har besluttet URL, slug og blokeringens omfang.

## Context
Fase 12 skal give creators en indgang (`/creator-dashboard/`), sende dem dertil efter login og holde dem ude af wp-admin, admin bar og Application Passwords. Den oprindelige formulering ("creators uden `merchboard_manage`") ville også ramme editors og shop managers, der samtidig er creators, og tage wp-admin fra dem, selvom de har brug for det i deres arbejde. Samtidig skal inaktive creators og brugere, der kun har creator-rollen uden record, også holdes ude af wp-admin, selvom de ikke har dashboard-adgang. Dashboard-URL'en var et åbent spørgsmål (8).

## Decision
- **URL:** `/creator-dashboard/` (åbent spørgsmål 8). Slug'en kan ændres **nu** under Merch Dashboard → Settings (`merchboard_settings['dashboard_slug']`, `merchboard_manage_settings`, egen nonce). Den valideres (format, reserverede WordPress/WooCommerce-stier, eksisterende indhold og rewrite-baser) og audit-logges (`settings_updated` med gammel og ny værdi).
- **Rewrite rules:** De gemte regler slettes (`delete_option( 'rewrite_rules' )`) ved aktivering, deaktivering (`Deactivator`) og slug-ændring, og når de gemte regler mangler den aktuelle regel. WordPress genopbygger dem ved næste request.
- **Dashboard-adgang** afgøres uændret og alene af `CreatorContext` (fire betingelser, fail closed).
- **Ren creator** (`CreatorRestrictions::applies_to()`): `merchboard_access_dashboard` **og ingen** af `merchboard_manage`, `edit_posts` eller `manage_woocommerce`. Det omfatter alle med creator-rollen, også inaktive creators og rollen uden record. For rene creators, og kun for dem:
  - login-redirect til dashboardet (WordPress og WooCommerce). Kun validerede dashboard-deep-links på samme site respekteres;
  - wp-admin redirectes til dashboardet (`admin_init` og `admin_page_access_denied`, prioritet 0). AJAX, `admin-post.php`, `async-upload.php`, cron, REST og CLI undtages;
  - admin bar skjules;
  - Application Passwords er slået fra (oprettelse og brug).
- Restriktionerne er altid slået til. Der er ingen til/fra-knapper, før der er et konkret behov.
- `edit_posts` og `manage_woocommerce` er fremmede capabilities. De tjekkes kun og tildeles aldrig. De står som konstanter i `Capabilities`, så der ikke er capability-strenge andre steder.

## Alternatives considered
- **Alle creators uden `merchboard_manage`** (oprindelig tekst): ville fjerne wp-admin fra editors og shop managers, der også er creators. Fravalgt af projektejeren.
- **Kun brugere med aktiv creator-adgang:** inaktive creators og rollen uden record ville beholde wp-admin (profil) og Application Passwords. Fravalgt.
- **Fast slug (kun konstant/filter):** enklere, men projektejeren ønskede den konfigurerbar nu.
- **`flush_rewrite_rules()` ved ændring:** genopbygger med den aktuelle requests regler, altså den gamle slug eller et plugin, der er ved at blive deaktiveret. Fravalgt til fordel for at slette de gemte regler.
- **Overstyre WooCommerce' egen wp-admin-regel generelt:** unødvendigt indgreb. Vores guard kører før og gælder kun rene creators.

## Reason
Rene creators får én forudsigelig indgang og ingen adgang til WordPress' egne flader. Personale, der også er creators, mister intet. Reglen er et rent capability-tjek uden databaseopslag, så den kan ikke fejle åben.

## Consequences
- En ren creator, der også har `merchboard_view_logs` uden `merchboard_manage`, kan ikke nå Audit log. Giv også `merchboard_manage`.
- En deaktiveret creator forbliver logget ind, men dashboardet giver 403 fra næste request.
- Findes en side med standard-slug'en allerede ved aktivering, vinder dashboardet. Vælg en anden slug i Settings.
- Fase 13 skal udvide dashboardets CSP med `script-src` for egne handles.

---

# ADR-027: Creator-dashboardet skrives i TypeScript (strict) med React og @wordpress/scripts

## Date
2026-09-25

## Status
Accepteret (fase 13). Besluttet af projektejeren (lukker åbent spørgsmål 3). Supplerer ADR-010.

## Context
Dashboardet vokser over mange faser (profil, produkter, ordrer, salg) og er bygget på REST-kontrakter. JavaScript med JSDoc giver svag kontrol med kontrakter og refaktorering. Beslutningen skulle træffes før den første frontend-kode (fase 13), så der ikke skal migreres senere.

## Decision
- Al creator-dashboard-kode er TypeScript (`.ts`/`.tsx`) med React via `@wordpress/element`.
- Build, lint og test via den eksisterende `@wordpress/scripts` (Babel transpilerer TypeScript). Ingen Vite, Next.js, separat bundler, dev-server eller egen webpack-konfiguration.
- `tsc` kun til typetjek (`npm run typecheck`, `noEmit`) med `strict`, `noImplicitAny`, `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes` m.fl. `any` er forbudt (ESLint).
- Data over en trust-grænse (bootstrap-data, REST-svar) er `unknown` og valideres med små lokale runtime-checks. Intet schema-bibliotek.
- Ingen global state-library. Almindelig React-state og egne hooks. Et bibliotek kræver en ny ADR.
- TypeScript er compile-time typesikkerhed, **ikke** sikkerhed. Backend er autoritativ for login, creator-identitet, status, capabilities og data.
- Nye dev-dependencies: `typescript`, `@types/react`, `@types/react-dom`, `@wordpress/element`, `@wordpress/api-fetch` og `@wordpress/i18n`. Pakkerne leveres ved runtime af WordPress (externals). Der er ingen runtime-dependencies og intet testbibliotek ud over Jest fra `@wordpress/scripts`.
- Admin-JavaScript fra tidligere faser (`assets/js/creator-user-search.js`, vanilla uden build) forbliver JavaScript.

## Alternatives considered
- **JavaScript + JSDoc:** ingen build-ændring, men svag håndhævelse. Fravalgt.
- **Vite/Next.js eller egen webpack-konfiguration:** mister WordPress' dependency extraction og `*.asset.php`, og kræver mere vedligehold. Fravalgt (jf. ADR-010).
- **Runtime schema-bibliotek (fx Zod):** ekstra bundle og dependency for få, simple kontrakter. Revurderes, hvis kontrakterne vokser.
- **Testing Library:** fravalgt indtil videre. `createRoot` + `act` fra React dækker behovet uden ny dependency.

## Reason
Typesikre kontrakter og sikker refaktorering over mange faser, uden at forlade WordPress' toolchain eller tilføje runtime-vægt.

## Consequences
- `npm run lint` omfatter typetjek. CI kører ESLint, stylelint, `tsc`, Jest og build.
- WordPress' React-version afgør runtime (React 18.3 på WordPress 6.7). Koden bruger kun API'er, der findes i React 18 og 19.
- Typerne for REST-svar skal matche backendens faktiske kontrakt og må ikke opfinde felter.

---

# ADR-028: Release via tag, genbrug af bin/package.mjs og draft-release; staging med manuel upload

## Date
2026-09-25

## Status
Accepteret (fase 16). Draft-release og staging (`devpage.ccmerch.dk`, manuel upload) er besluttet af projektejeren.

## Context
Roadmap-fase 16 planlagde et workflow på tag `v*` med `composer --no-dev`, `npm run build` og zip efter `.distignore`. Siden er to ting ændret. Pluginet distribueres uden `vendor/` (ADR-017), så Composer er overflødigt i en release. MVP-checkpointet indførte `bin/package.mjs` som den autoritative, deterministiske pakke fra en allowlist, med sikkerhedsnet mod forbudte og manglende filer. En release må ikke kunne udgives med en anden version eller commit end tagget, og en fejl må ikke nå ud til brugere automatisk.

## Decision
- `.github/workflows/release.yml` kører på tags `v*`. Det genbruger alle CI-jobs fra `ci.yml` (`workflow_call`) og pakker kun, hvis de er grønne.
- Pakken bygges med `npm run package -- --tag vX.Y.Z`, altså samme script som lokalt. Ingen `composer install` og ingen zip efter `.distignore`. Scriptet kræver, at tagget er `v` + pluginversionen og peger på den byggede commit. Header, `MERCHBOARD_VERSION` og `package.json` skal have samme version, og versionen skal være SemVer.
- Tagget commit skal ligge på `main`. ZIP'en kontrolleres uafhængigt med `unzip -t`, og der publiceres en `.sha256` sammen med den.
- Release notes er sektionen `## [X.Y.Z]` i `CHANGELOG.md`. Mangler den, fejler workflowet.
- Releasen oprettes som **draft** med `gh release create --draft --verify-tag` (GitHub CLI på runneren). Projektejeren udgiver den efter test på staging.
- Permissions er `{}` som standard, `contents: read` til tjek og `contents: write` kun til release-jobbet. Kun GitHubs egne actions, ingen secrets ud over `GITHUB_TOKEN`.
- Staging er `https://devpage.ccmerch.dk`. Deploy sker ved manuel upload efter tjeklisten i DEVELOPMENT.md, inkl. MySQL-verifikation.

## Alternatives considered
- **Zip efter `.distignore` i workflowet (roadmap-planen):** en parallel pakkeløsning, der kan glide fra den validerede allowlist. Fravalgt.
- **Tredjeparts release-actions (fx softprops/action-gh-release):** ekstra supply chain med skriveadgang til repo'et. `gh` er forinstalleret og vedligeholdt af GitHub. Fravalgt.
- **Udgiv releasen straks:** en pakke, der er grøn i CI men fejler på et rigtigt site, ville nå ud. Fravalgt af projektejeren.
- **SFTP-deploy fra CI:** kræver hosting-credentials i GitHub og en ny ekstern integration (ADR-016). Fravalgt for nu.

## Reason
Én pakkeproces, samme sikkerhedsnet lokalt og i CI, mindst mulige rettigheder og et menneskeligt godkendelsestrin før udgivelse.

## Consequences
- En release kræver en release-commit (versions-bump, `npm run i18n`, CHANGELOG-sektion) og et tag på `main`. Proceduren står i DEVELOPMENT.md.
- ZIP-navnet er fortsat `merchboard-mvp-<version>.zip`. Rodmappen er `merchboard/`, så WordPress opdaterer det installerede plugin uanset filnavnet.
- Et fejlet workflow opretter ingen release. Et pushet tag flyttes ikke. Rettelser udgives som ny patch-version.
- Workflowet kan kun verificeres på GitHub. Den første kørsel skal kontrolleres under Actions.

---

# ADR-029: Ubegrænset audit-retention i v0.1.0; produktionspolitik besluttes før produktion

## Date
2026-09-25

## Status
Accepteret (fase 16). Besluttet af projektejeren. Supplerer ADR-023 og lukker åbent spørgsmål 10 for v0.1.0. Gælder v0.1.0 og staging. Det er **ikke** en permanent beslutning om retention i produktion.

## Context
ADR-023 gjorde audit trail til append-only forretningsdata og lod retention stå åben. Åbent spørgsmål 10 (hvor længe skal audit-records bevares, fx ubegrænset, 5 eller 10 år) skulle afklares senest i fase 16, før den første release (`v0.1.0`). Den første release installeres på staging, ikke i produktion. Valget påvirker GDPR-dokumentationen.

## Decision
> Audit records are retained indefinitely in v0.1.0. No automatic retention/deletion policy is implemented. A production retention policy must be decided before production use.

- I v0.1.0 bevares audit-records ubegrænset. Der er ingen automatisk sletning, ingen WP-Cron og ingen retention-indstilling.
- Audit-loggens adfærd er uændret. Records slettes kun ved opt-in "slet alle data ved afinstallation" (ADR-025).
- Før pluginet tages i brug i produktion, skal en retention-politik besluttes (periode, evt. indstilling, GDPR-begrundelse) og registreres i en ny ADR. Implementeringen er en separat opgave.

## Alternatives considered
- **Fast periode nu (fx 5 eller 10 år) med automatisk sletning:** kræver WP-Cron, en indstilling og tests, og en produktbeslutning om perioden. Ikke nødvendigt for staging. Udskudt til før produktion.
- **Retention-indstilling i dage (planlagt i ADMIN.md, Settings → Advanced):** samme som ovenfor. Udskudt.

## Reason
v0.1.0 skal verificeres på staging. Den nuværende, testede adfærd er tilstrækkelig dertil, og den mister ingen data. Produktionspolitikken træffes bedst, når kravene til produktion og GDPR kendes.

## Consequences
- Audit-tabellen vokser uden grænse i v0.1.0. Det er acceptabelt på staging (få records).
- Produktionsbrug er blokeret, indtil der er besluttet en retention-politik. Beslutningen står under "Next recommended steps" i PROJECT-STATUS.md.
- Indføres automatisk sletning senere, skal den respektere append-only-princippet (ADR-023). Sletning sker kun efter politikken og aldrig via admin-UI for enkelte records.

---

# ADR-030: WooCommerce-modulet: minimum 10.3, én autoritativ skrivevej for produkt→creator og oprydning ved opt-in-uninstall

## Date
2026-09-25

## Status
Accepteret (fase 17, før implementering). Besluttet af projektejeren (D1-D6). Supplerer ADR-011 (WooCommerce-modul) og ADR-025 (uninstall). **Rettet 2026-09-25 (før implementering):** D1 blev først besluttet som 10.4 ud fra antagelsen, at 10.4 kræver WordPress 6.7. WooCommerce 10.4.0's egen pakke kræver WordPress 6.8, så projektejeren har valgt 10.3 (se D1 og Alternatives considered).

## Context
Fase 17 kobler WooCommerce-produkter til creators via product meta `_merchboard_creator_id` (ADR-011). Før implementeringen skulle det afklares:
- hvilken WooCommerce-version modulet kræver (WOOCOMMERCE.md udskød det til fase 17),
- hvad der sker med en for gammel WooCommerce,
- om kolonnen "Creators" i ordrelisten (ADMIN.md) hører til fasen,
- om koblingen slettes ved opt-in-uninstall. ADR-025 og DATABASE.md sagde, at WooCommerce-meta aldrig slettes, men meta-nøglen ejes af pluginet,
- hvordan WooCommerce' mange skriveveje (produktskærm, REST, CSV-import, quick/bulk edit, duplicate) holdes under samme validering og audit.

## Decision
- **D1 – minimum:** `WC requires at least: 10.3` i plugin-headeren. Det er Merch Dashboards eget minimum for WooCommerce-modulet. WooCommerce 10.3.x er den seneste linje, der kræver WordPress 6.7, som er pluginets generelle minimum (ADR-003, uændret). Verificeret mod WooCommerce's egne plugin-headers og `readme.txt` på wordpress.org: 9.9.0-10.3.8 kræver WordPress 6.7, og 10.4.0 og senere kræver 6.8. Runtime-tjekket og headeren bruger samme værdi.
- **D2 – under minimum:** Er WooCommerce aktivt, men ældre end 10.3, indlæses `WooCommerceModule` ikke. Admin får en neutral requirements-notice efter det eksisterende `Requirements`-mønster. Der er ingen fatal og ingen delvis integration. Resten af pluginet virker. Uden WooCommerce vises ingen notice.
- **D3 – ordreliste:** Kolonnen "Creators" i ordrelisten er udskudt. Fase 17 indeholder ingen logik for ordrer eller ordrelisten.
- **D4 – livscyklus for koblingen:** Koblingen bevares ved deaktivering af pluginet, deaktivering af WooCommerce og opdateringer. Ved **opt-in-uninstall** (ADR-025) slettes `_merchboard_creator_id` fra de produkter og varianter, hvor nøglen findes, på det aktuelle site. Kun den præcise nøgle slettes, via WordPress' meta-API (virker også uden WooCommerce), idempotent og før tabellerne droppes. Anden WooCommerce- og produkt-meta røres aldrig.
- **D5 – test:** Ingen nye runtime- eller dev-dependencies. Testene bruger den eksisterende infrastruktur (Brain Monkey, WooCommerce-fixtures, integrationstests og Playground med WooCommerce fra wordpress.org). Viser implementeringen, at en dependency er nødvendig, stoppes der, og behovet forelægges projektejeren.
- **D6 – skriveveje:**
  - Koblingen har **én autoritativ domæneservice**. Alle understøttede ændringer bruger dens validering (creator findes og er aktiv, variant → parent), capability-tjek og audit i samme transaktion (ADR-023/024).
  - I fase 17 er **kun den almindelige WooCommerce-produktskærm** en understøttet skrivevej.
  - WooCommerce REST og CSV-import må ikke kunne ændre nøglen. Ændringer afvises tydeligt via de hooks, som undersøgelsen i fase 17 fastslår i den faktiske WooCommerce-version.
  - Quick/bulk edit får intet Creator-felt.
  - **Duplicate product** starter uden `_merchboard_creator_id`.
  - Der oprettes ingen nye offentlige REST-endpoints.
  - **Grænse for sikkerhedsmodellen (besluttet efter undersøgelsen i trin 1):** WordPress' rå meta-API (`update_post_meta()`/`add_post_meta()` fra vilkårlig PHP-kode) og `wp post meta update` via WP-CLI kræver kode- eller shell-adgang. De er **ikke-understøttede** bypasses på kode- eller administrationsniveau og blokeres ikke. Der laves ingen globale meta-hooks for at forsøge.
- **Ingen produktrettigheder til creators:** Fase 17 giver ikke `merchboard_creator` nogen WooCommerce-produktcapabilities (`edit_product`, `edit_products`, `publish_products`, `delete_products` m.fl.) og åbner ingen produktadgang i wp-admin eller dashboardet. `Capabilities::EDIT_PRODUCT` er kun en reference til at autorisere en bruger, der i forvejen må redigere produktet. Creators' egen produktadministration er udskudt til en senere fase og skal designes som en separat, begrænset funktion.

## Alternatives considered
- **Ingen minimumsversion eller kun `class_exists`:** ukendt API-adfærd på gamle versioner. Fravalgt.
- **Minimum 10.4 (første udgave af D1):** WooCommerce 10.4 kræver WordPress 6.8 og ville give modulet et højere, implicit WordPress-krav end pluginets generelle minimum 6.7. Det kunne kun løses ved at hæve ADR-003. Fravalgt.
- **Indlæse modulet på en for gammel WooCommerce med advarsel:** risiko for delvis funktion. Fravalgt.
- **Bevare meta ved uninstall (DATABASE.md før ADR-030):** efterlader plugin-ejede data i produkterne, selv om ejeren har valgt fuld sletning. Fravalgt.
- **Slette meta med SQL eller prefix-match:** i strid med ADR-025's præcise ejerskab og WordPress' meta-cache. Fravalgt.
- **REST og CSV-import som understøttede skriveveje gennem servicen:** mere at sikre og teste nu. Kan genovervejes senere med en ny beslutning.
- **Duplicate beholder koblingen:** en kopi ville få en creator uden et eksplicit, auditeret valg. Fravalgt.

## Reason
Forudsigelig kompatibilitet, ingen halvt fungerende integration og en kobling, der altid er valideret, autoriseret og auditeret, fordi den har én skrivevej. Fuld oprydning ved opt-in, uden at røre data, som pluginet ikke ejer.

## Consequences
- ADR-025's grænse "WooCommerce-data røres ikke" har nu én præcis undtagelse: pluginets egen meta-nøgle `_merchboard_creator_id`. Inventory'et i DATABASE.md er opdateret.
- `Uninstaller` skal have et trin til meta-nøglen, og nye plugin-ejede meta-nøgler skal fremover tilføjes eksplicit.
- WooCommerce' nye blok-baserede produkteditor bruger ikke de klassiske hooks til produktfelter. Understøttet skrivevej er den klassiske produktskærm.
- Koblinger lavet før uninstall med opt-in er væk efter geninstallation. Audit-tabellen er også væk.
