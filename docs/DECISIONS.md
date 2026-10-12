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
Accepteret. TypeScript besluttet i ADR-027. Suppleret af ADR-043 (2026-10-07): seks dashboard-temaer på semantiske tokens med `data-theme`.

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
Accepteret. Suppleret af ADR-030 (WooCommerce-minimum 10.3, én autoritativ skrivevej for produkt→creator, oprydning ved opt-in-uninstall) og ADR-031 (kategori-mapping pr. creator og engangs-backfill). ADR-031's engangs-backfill er erstattet af ADR-033 (kategori-mapping som vedvarende automatisk kobling). **Suppleret af ADR-035 (2026-09-26):** beskyttelsen af den plugin-ejede produktmeta (`_merchboard_creator_id`, `_merchboard_creator_link_source`, ADR-034) er en core-service, der er aktiv uden WooCommerce og uden om `merchboard_modules`. WooCommerce-integrationen selv (skriveveje, UI, indgangs-vagter og ledger) forbliver i det valgfrie modul.

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
Accepteret (fase 9). Suppleret af ADR-030: pluginets egen product meta `_merchboard_creator_id` slettes også ved opt-in-uninstall. Anden WooCommerce-data røres fortsat ikke. Suppleret af ADR-044 (2026-10-07): user meta-nøglen `merchboard_dashboard_theme` slettes også ved opt-in-uninstall (præcist navn). Suppleret af ADR-059 (2026-10-12): creators (arkiveret), audit-log, payouts og afregningsbilag bevares; ventende payouts afbryder sletningen; reglen "ingen audit ved uninstall" gælder uændret.

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
- **Note 2026-10-12 (ADR-059):** audit-loggen bevares nu også ved opt-in-afinstallation. Opbevaringsperioden er fortsat ikke besluttet (R3).

---

# ADR-030: WooCommerce-modulet: minimum 10.3, én autoritativ skrivevej for produkt→creator og oprydning ved opt-in-uninstall

## Date
2026-09-25

## Status
Accepteret (fase 17, før implementering). Besluttet af projektejeren (D1-D6). Supplerer ADR-011 (WooCommerce-modul) og ADR-025 (uninstall). **D6 er præciseret af ADR-031:** fase 17 har to understøttede skriveveje (den klassiske produktskærm og en eksplicit administrativ engangs-backfill gennem `ProductCreatorLink`). Øvrige begrænsninger i D6 er uændrede. **D6 er ændret af ADR-032:** produktskærmen er ikke længere en skrivevej og viser ikke relationen. Den første skrivevej er Merch Dashboard (wp-admin) → creatorens redigeringsside (`ProductLinkSource::Admin`), og produktlisten får en skrivebeskyttet kolonne og et filter. **D6 er ændret af ADR-033 (2026-09-26):** ud over den manuelle admin-vej findes en godkendt, systembaseret kategori-skrivevej (`source: category`) gennem `ProductCreatorLink` uden capability-tjek for den aktuelle bruger. Engangs-backfill som skrivevej udgår. Uninstall sletter også `_merchboard_creator_link_source`. **Grænsen i D6 er ændret af ADR-034 (2026-09-26):** WordPress' rå meta-API og `wp post meta` via WP-CLI er ikke længere uden for vagterne. `ProtectedProductMeta` afviser generiske skrivninger af `_merchboard_creator_id` og `_merchboard_creator_link_source`, medmindre en eksplicit `ProtectedMetaWrite` for netop det produkt kører. Uden for grænsen er kun direkte SQL, kode der fjerner eller omgår pluginets hooks, og perioder uden indlæst WooCommerce-modul (uafklaret i ADR-034). **D4 er afstemt med vagten i ADR-036 (2026-09-26):** opt-in-uninstall sletter fortsat nøglerne via WordPress' meta-API (ingen direkte SQL). Når vagten er indlæst (fx `wp plugin uninstall --deactivate`), tillader den kun sletningen af de to nøgler, og kun når `WP_UNINSTALL_PLUGIN` er Merch Dashboards basename og opt-in er aktivt. **Rettet 2026-09-25 (før implementering):** D1 blev først besluttet som 10.4 ud fra antagelsen, at 10.4 kræver WordPress 6.7. WooCommerce 10.4.0's egen pakke kræver WordPress 6.8, så projektejeren har valgt 10.3 (se D1 og Alternatives considered).

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

---

# ADR-031: Kategori-mapping pr. creator som input til engangs-backfill; produkt-ejerskab via `_merchboard_creator_id`

## Date
2026-09-26

## Status
Accepteret (fase 17, før implementeringen af `ProductCreatorLink`). Besluttet af projektejeren. Supplerer ADR-011 og ADR-030 og **præciserer ADR-030 D6** (skriveveje). **Ændret af ADR-032:** den første skrivevej i præciseringen nedenfor ("den klassiske WooCommerce-produktskærm") er erstattet af Merch Dashboard (wp-admin) → creatorens redigeringsside. Tvetydige produkter (punkt 7) rettes dér. Backfill og alle øvrige regler er uændrede. **Ændret af ADR-033 (2026-09-26):** mappingen er en vedvarende regel, der automatisk kobler og fjerner kategori-afledte koblinger. Den ligger i tabellen `merchboard_creator_categories`, ikke i creator-meta `product_category_id`. Engangs-backfillen erstattes af den første afstemning, når en kategori mappes. Punkt 2-7, 10, 11, præciseringen af D6 og alternativet "parallel drift med synkronisering" gælder ikke længere som skrevet. Punkt 1, 8 og 9 er uændrede. Teksten nedenfor er bevaret som beslutningshistorik.

## Context
Den eksisterende shop har én WooCommerce-produktkategori (`product_cat`) pr. creator. Produkter tilhører i praksis en creator via den kategori. Reglen findes kun i shoppens data og arbejdsgange, ikke i pluginet. Pluginet har ingen relation mellem creator og kategori (ingen kolonne, creator-meta eller term-meta).

Kategorinavne og slugs kan ikke bruges sikkert til at finde creatoren. Allerede i de syntetiske testdata i Merch Dashboard Local hedder creatoren "Test Creator Alpha" og kategorien "Creator Alpha". Navne og slugs kan desuden ændres.

Fase 18-20 (ledger, `/me/products` og statistik) kræver én entydig ejerskabsrelation. Eksisterende produkter skal derfor have en kobling. Det skal ske uden to sandheder og uden gætteri.

## Decision
1. **Ejerskab:** `_merchboard_creator_id` på parent-produktet er fortsat den **eneste autoritative relation for produkt-ejerskab** (ADR-030).
2. **Kategori-mapping:** Creator-meta `product_category_id` (i `merchboard_creatormeta`, whitelistet i `CreatorMetaStore`) er en separat, administrativ mapping fra en creator til én WooCommerce-kategori. Den må **aldrig** bruges direkte som bevis for produkt-ejerskab eller creator-adgang. Den bruges kun som input til backfill (og til visning i admin).
3. **1:1:** Én kategori pr. creator og én creator pr. kategori. Dubletter afvises med en fejl, og der sker ingen stille omflytning.
4. **Kun eksplicit admin-valg:** Mappingen oprettes, ændres og fjernes kun af en administrator med `merchboard_manage_creators` på creatorens redigeringsside. Der er ingen match på navn, slug, dele af navne eller fuzzy matching (heller ikke som forslag) og ingen automatisk synkronisering.
   - Standardkategorien (`default_product_cat`, fx "Uncategorized") kan ikke vælges.
   - Kun aktive creators kan få en ny mapping.
   - Mappingen oprettes og fjernes med audit (`creator_category_linked`, `creator_category_unlinked`).
5. **Backfill er en eksplicit engangshandling:** gennemsyn af mappings → tørkørsel → bekræftelse → skrivning → resultat med audit. Den er idempotent: en ny kørsel skriver intet til produkter, der allerede er koblet. Klassifikation af hvert parent-produkt i denne rækkefølge:
   - Allerede koblet (`_merchboard_creator_id` findes, uden hensyn til store og små bogstaver) → `already_linked`. Den rapporteres som *matcher mapping*, *afviger* eller *ingen mapping*, men skrives aldrig.
   - Produktets kategorier i mappingen giver 0 creators → `unassigned`, 1 → `creator`, ≥2 → `ambiguous`. Kategorier uden mapping (fx "General Merchandise" eller standardkategorien) ignoreres.
   - Produktstatusser med i kørslen: publish, draft, pending, private og future. Trash og auto-draft er udenfor og rapporteres kun.
   - Kørslen sker i batches, kan genoptages og afsluttes med en resumé-post i audit (`product_backfill_completed`: optællinger og et øjebliksbillede af mappingen).
6. **Ingen overskrivning:** Backfill overskriver eller ændrer aldrig en eksisterende `_merchboard_creator_id`.
7. **Tvetydige produkter:** Produkter med flere mappede creator-kategorier er `ambiguous`. De springes over, rapporteres og rettes manuelt på produktskærmen.
8. **Inaktive creators:** Kan ikke modtage nye produktkoblinger. Backfill springer dem over (`creator_inactive`) og rapporterer dem. Deres mapping bevares.
9. **Varianter:** Får ingen egen creator-relation. Klassifikationen sker på parent-produktet.
10. **Mapping-ændringer påvirker ikke produkter:** En ændret eller fjernet mapping ændrer ingen eksisterende produktkoblinger.
11. **Kategori slettes:** Mappingen fjernes (hook på sletning af `product_cat`-terms) og auditeres med `reason: term_deleted`. Produkternes ejerskab ændres ikke.
12. **Uden for fase 17:** Fremtidig selvbetjening for creators og en mere fleksibel kategorimodel (fx flere kategorier pr. creator) kræver en ny beslutning. Modellen kan udvides uden at ændre ejerskabsrelationen.

**Præcisering af ADR-030 D6:** Fase 17 har præcis **to** understøttede skriveveje for produktets creator-relation:
1. Den klassiske WooCommerce-produktskærm til enkeltprodukter.
2. Den eksplicitte administrative engangs-backfill med tørkørsel og bekræftelse.

Backfill skriver udelukkende gennem `ProductCreatorLink` med samme validering, capability-tjek og audit (`source: backfill`). Den kræver `merchboard_manage_creators` samt `edit_product` for hvert parent-produkt. Alle øvrige begrænsninger i ADR-030 D6 er uændrede: WooCommerce REST, CSV-import, quick/bulk edit og duplicate kan fortsat ikke ændre creator-relationen, og der er ingen nye offentlige REST-endpoints.

## Alternatives considered
- **Kategorien som ejerskab:** Alle WooCommerce-veje (quick/bulk edit, REST, CSV og duplicate) ville kunne ændre ejerskab uden validering og audit. Ejerskab ville være tvetydigt ved flere kategorier og følge omdøbning eller sletning af terms. Fravalgt.
- **Match på navn eller slug:** falske match og brud ved omdøbning. Fravalgt.
- **Parallel drift med synkronisering mellem kategori og meta:** to sandheder, og synkroniseringen ville reelt blive en skrivevej uden om D6. Fravalgt.
- **Mapping som term-meta på `product_cat`:** plugin-data i WooCommerce' terms og en ny undtagelse i uninstall (ADR-025/030). Fravalgt.
- **Egen tabel med unikhed håndhævet af databasen:** stærkeste garanti, men kræver migration 4 (roadmappet siger ingen migration i fase 17). Kan genovervejes, hvis modellen udvides.
- **Mapping kun som input til den enkelte kørsel:** sikkert, men ikke vedligeholdt og ikke synligt i admin. Fravalgt til fordel for en gemt, auditeret mapping.

## Reason
Én auditeret ejerskabsrelation, et sikkert og gennemsigtigt udgangspunkt fra shoppens eksisterende kategorier og ingen gætteri. Kategorierne kan fortsat bruges frit til navigation og markedsføring.

## Consequences
- `CreatorMetaStore` får den tilladte nøgle `product_category_id`. Der er ingen migration, og schema forbliver 3.
- Nye audit-actions: `creator_category_linked`, `creator_category_unlinked` og `product_backfill_completed` (samt produktkoblingens egne actions med `source`).
- Creatorens redigeringsside får et afsnit med kategori-mapping, der kun vises med `WooCommerceModule` aktivt. Creators-listen får en kolonne "Category". Backfill åbnes fra Creators-listen og får ikke sit eget menupunkt.
- Uninstall: mappingen forsvinder med `merchboard_creatormeta` (ADR-025). `_merchboard_creator_id` behandles efter ADR-030, og kategorierne bevares.
- Uoverensstemmelser mellem kategori og ejerskab gøres synlige i tørkørslen, men rettes aldrig automatisk.
- Fase 17 får to nye trin: mapping (trin 7) og backfill (trin 8).

---

# ADR-032: Produkt-creator-koblingen administreres i Merch Dashboard (wp-admin), ikke på WooCommerce-produktskærmen

## Date
2026-09-26

## Status
Accepteret (fase 17, før trin 4). Besluttet af projektejeren. **Ændrer ADR-030 D6 og ADR-031's præcisering af D6** (skriveveje). Supplerer ADR-011. ADR-030 og ADR-031 gælder i øvrigt uændret. **Pkt. 1 og 3 er ændret af ADR-033 (2026-09-26):** skrivevejene er den manuelle admin-vej (`source: admin`, uændret) og den automatiske kategori-afstemning (`source: category`). Engangs-backfill udgår, og `backfill` udfases som kilde. Remove afvises for kategori-styrede produkter, og "Keep as manual" tilføjes.

## Context
ADR-030 D6, præciseret af ADR-031, gjorde den klassiske WooCommerce-produktskærm til den første af to understøttede skriveveje. Planen var et felt "Creator" i fanen *General* og et save-hook. `ProductLinkSource::ProductScreen` (trin 3) blev indført til den vej.

Projektejeren har besluttet, at creator-relationen er en administrativ Merch Dashboard-funktion. Den skal ikke være en del af WooCommerce-produktets synlige redigerings-UI. WooCommerce-produktet skal forblive et almindeligt WooCommerce-produkt.

Projektet har to "dashboards". Kun det ene egner sig til funktionen:
- **Creator-dashboardet** (React, `/creator-dashboard/`) er kun for creators (ADR-026). Dets API er `/me`-scoped uden creator-ID'er (ADR-007). Creators har ingen produktrettigheder (ADR-030), og `ProductCreatorLink` kræver `edit_product`.
  - Admins ville kræve et nyt admin-REST-API og en ny React-adminapp, som arkitekturen ikke har.
  - Creators selv ville være selvbetjening, som er udskudt (ADR-031 punkt 12).
- **Merch Dashboard i wp-admin** har allerede creatorens redigeringsside og mønstret `admin-post.php` + nonce for admin-formularer (ADR-008).

## Decision
1. **Understøttede skriveveje (erstatter listen i ADR-030 D6/ADR-031):** Fase 17 har præcis to:
   1. **Merch Dashboard (wp-admin) → creatorens redigeringsside → produktkoblinger.** Admin ser creatorens koblede produkter og kan tilknytte og fjerne produkter.
      - Formularerne går via `admin-post.php` med nonce.
      - De kræver `merchboard_manage_creators` og `edit_product` for hvert konkret parent-produkt. Det sidste håndhæves af `ProductCreatorLink`.
      - Skrivning sker udelukkende gennem `ProductCreatorLink` med `ProductLinkSource::Admin` (`admin`).
      - Et produkt, der allerede er koblet til en anden creator, overtages ikke implicit. En erstatning kræver et eksplicit admin-valg; detaljerne fastlægges i trin 4.
   2. **Eksplicit administrativ engangs-backfill** (ADR-031, uændret, `source: backfill`).
2. **WooCommerce-produkteditoren er ikke en skrivevej.** Den viser ikke creator-relationen. Der tilføjes intet creator-felt, ingen meta box og intet save-hook for creator-relationen til produktskærmen (hverken den klassiske eller den blok-baserede editor).
3. **`ProductLinkSource`:**
   - `admin` er den nye kilde for admin-vejen.
   - `product_screen` er **forældet og ikke en understøttet skrivevej**. Den må blive i enum'en midlertidigt af hensyn til trin 3's kode og tests, men ingen ny kode må bruge den. Den fjernes i trin 4.
4. **Produktlisten (skrivebeskyttet):** WooCommerce' produktliste får kolonnen "Creator" og et creator-filter.
   - Begge viser og filtrerer efter den aktuelle `_merchboard_creator_id` via den centrale læselogik.
   - De ændrer aldrig relationen og er ingen skrivevej (ingen inline-redigering og ingen bulk-handling).
5. **Uændret:**
   - WooCommerce REST, CSV-import, XML-RPC, quick edit, bulk edit og duplicate kan ikke ændre relationen (ADR-030 D6).
   - Der er ingen nye offentlige REST-endpoints og intet nyt admin-REST-API. Creator-dashboardet og dets `/me`-API bruges ikke til produktadministration.
   - Creators får ingen produktrettigheder, og selvbetjening for creators er uden for fase 17.
   - `_merchboard_creator_id` på parent-produktet er den eneste ejerskabsrelation. `product_category_id` er aldrig bevis for ejerskab (ADR-031).
   - Varianter har ingen egen relation.
   - Tvetydige produkter fra backfill rettes manuelt via admin-vejen (i stedet for "på produktskærmen" i ADR-031 punkt 7).

## Alternatives considered
- **Felt på WooCommerce-produktskærmen (ADR-030/031):** blander Merch Dashboard-data ind i WooCommerce' produkt-UI, kræver et save-hook i produktgemningen og er fravalgt af projektejeren.
- **Admin via creator-dashboardet (nyt admin-REST-API og React-adminapp):** bryder med ADR-007/026 (creator-only, `/me`-scoped) og kræver en ny API- og UI-arkitektur. Fravalgt.
- **Creators kobler selv:** selvbetjening, der kræver produktrettigheder og et separat design (ADR-030/031). Fravalgt i fase 17.
- **Ingen visning i produktlisten:** admins mister overblikket i WooCommerce. En skrivebeskyttet kolonne og et filter giver overblik uden en ny skrivevej. Fravalgt.

## Reason
Relationen er Merch Dashboards forretningsdata. Den administreres dér, hvor creatoren administreres, med den eksisterende admin-arkitektur og uden ny API- eller UI-infrastruktur. WooCommerce-produktet forbliver uændret, og ejerskab har stadig én auditeret domæneservice.

## Consequences
- Trin 4 bygger produktkoblingerne på creatorens redigeringsside, indfører `ProductLinkSource::Admin` og fjerner `product_screen`. Trin 3's kode har ingen hooks på produktskærmen; dens kommentarer, der nævner produktskærmen som kalder, opdateres i trin 4.
- Creatorens redigeringsside får to Merch Dashboard-afsnit i fase 17: produktkoblinger (ADR-032) og kategori-mapping (ADR-031).
- Den skrivebeskyttede kolonne og filteret i produktlisten er en ren læsevej.
- ROADMAP, ADMIN.md og WOOCOMMERCE.md beskriver ikke længere et creator-felt på produktskærmen.

---

# ADR-033: Kategori-mapping som vedvarende automatisk kobling

## Date
2026-09-26

## Status
Accepteret (fase 17, efter trin 4, før implementering). **Implementeringsstatus 2026-09-27:** implementeret i fase 17, trin 6-13 (se PROJECT-STATUS); admin-værktøjet til fuld afstemning og visningen af konflikter som needs attention (tvetydige produkter, inaktive creators og manuelle koblinger, der afviger fra kategorien) er implementeret 2026-09-27; verifikationen i Merch Dashboard Local (roadmappens trin 12) er udført 2026-09-28 (blok-produkteditorens UI og drift uden WooCommerce er ikke kørt manuelt i Local). Beslutningen er uændret. Besluttet af projektejeren. **Ændrer ADR-030 D6, ADR-031 og ADR-032 pkt. 1 og 3** (se "Ændringer af tidligere ADR'er" nedenfor). Supplerer ADR-011 og ADR-025. Dette er en **bevidst arkitekturændring**, ikke en undtagelse fra de tidligere beslutninger. Implementeringen var ikke startet, da beslutningen blev taget (se implementeringsstatus ovenfor). **Suppleret af ADR-034 (2026-09-26):** kategori-afstemningen (pkt. 7) skal skrive gennem `ProtectedMetaWrite::for_category_reconciliation()`; begge meta-nøgler er beskyttet mod generiske skriveveje.

## Context
ADR-031 gjorde creator-kategori-mappingen til et rent input til en engangs-backfill: ingen automatisk synkronisering, og mapping-ændringer og sletning af kategorier påvirkede aldrig produkternes ejerskab. ADR-032 gjorde creatorens redigeringsside til den normale manuelle skrivevej. Trin 4 leverede den, inkl. søgning på produktkategori, som kun hjælper admin med at finde produkter.

Projektejeren har besluttet, at det ikke er nok. Når admin vælger en WooCommerce-produktkategori til en creator, skal kategorien fungere som en **vedvarende automatisk kobling**. Produkter, der ligger direkte i kategorien, kobles automatisk. Nye produkter i kategorien kobles, når de kommer ind, og produkter, der forlader kategorien, mister den kobling, der kom fra kategorien. Admin skal ikke vælge hvert produkt.

Det kræver en model, hvor **creator ↔ kategori** er en vedvarende administrativ relation, mens **produkt → creator** (`_merchboard_creator_id`) fortsat er den eneste effektive ejerskabsrelation. En kategori-afledt kobling skal kunne skelnes fra en manuel.

Undersøgt i kildekoden til WordPress 7.1.2 og WooCommerce 10.3.8 (Merch Dashboard Local):
- Alle kategoriændringer på produkter (wp-admin, quick/bulk edit, CSV-import, REST, WP-CLI og WooCommerce CRUD's `update_terms()`) går gennem `wp_set_object_terms()` (action `set_object_terms`) eller `wp_remove_object_terms()` (action `deleted_term_relationships`).
- `wp_delete_term()` fyrer `pre_delete_term` og kalder derefter en af de to funktioner for hvert produkt i kategorien.
- Action Scheduler 3.9.3 følger med WooCommerce og understøtter unikke asynkrone handlinger (`as_enqueue_async_action( …, $unique = true )`).

## Decision

1. **Datamodel: ny tabel `merchboard_creator_categories`** (ny migration):

   | Kolonne | Type | Noter |
   | --- | --- | --- |
   | `term_id` | `BIGINT UNSIGNED` PRIMARY KEY | `product_cat`-term |
   | `creator_id` | `BIGINT UNSIGNED NOT NULL`, KEY | Creator-ID |
   | `created_at` | `DATETIME NOT NULL` | UTC |
   | `created_by` | `BIGINT UNSIGNED NULL` | WordPress-brugeren, der oprettede mappingen |

   - Én `product_cat` kan kun være mappet til én creator (håndhævet af primærnøglen).
   - En creator kan teknisk have flere kategorier. Fase 17's UI og service begrænser det til én pr. creator.
   - Standardkategorien (`default_product_cat`) kan ikke mappes. Der er ingen match på navn eller slug (uændret fra ADR-031).
   - Relationen ligger **ikke** i creator-meta. Creator-meta `product_category_id` (ADR-031) indføres ikke.
   - Mappingen er **ikke ejerskab** og aldrig bevis for adgang. `_merchboard_creator_id` på parent-produktet er fortsat den eneste autoritative produkt → creator-relation. Al læsning af ejerskab og adgang (produktlisten, `/me` og fase 18-20) bruger kun den.
   - Tabellen slettes ved opt-in-uninstall via den eksisterende mekanisme (`Tables::all()`/`SchemaEraser`). Produktkategorierne selv slettes eller ændres aldrig.

2. **Oprindelse på produktkoblingen:** product meta `_merchboard_creator_link_source` med værdien `admin` eller `category`.
   - Skrives og verificeres **sammen med** `_merchboard_creator_id` (samme skrivning i `ProductStore`, samme transaktion og audit i `ProductCreatorLink`) og fjernes sammen med den.
   - Mangler den på en eksisterende kobling eller har den en ukendt værdi, behandles koblingen som `admin` (manuel).
   - `category` betyder, at koblingen **aktuelt** er afledt af en creator-kategori-mapping.
   - Den er ikke en separat ejerskabsrelation og bruges kun af afstemningen og admin-visningen.
   - `ProductLinkSource::Backfill` (`backfill`) udfases til fordel for `ProductLinkSource::Category` (`category`).

3. **Manuel kobling vinder altid.**
   - Afstemningen fjerner eller overtager aldrig en `admin`-kobling.
   - Eksempel: et produkt er manuelt koblet til Alpha og ligger i Betas kategori. Alpha beholdes, og produktet vises som konflikt (needs attention).
   - Overtager admin eksplicit et produkt via den eksisterende takeover (ADR-032), bliver resultatet en `admin`-kobling.

4. **"Keep as manual":** en eksplicit admin-handling for et kategori-styret produkt.
   - Den ændrer oprindelsen fra `category` til `admin` og beholder samme `_merchboard_creator_id`.
   - Den auditeres og er aldrig implicit.
   - Produktet er derefter immunt over for automatisk fjernelse.

5. **Remove på et kategori-styret produkt afvises** med en forklaring: "Dette produkt styres af en produktkategori. Fjern produktet fra kategorien, eller vælg »Keep as manual«, hvis koblingen skal administreres manuelt." Der er ingen skjult undtagelses- eller ignore-liste.

6. **Automatisk afstemning (vedvarende regel):**
   - **Input pr. parent-produkt:** de **direkte** `product_cat`-termer, hvilke af dem der er mappet, de mappede creators' status, den nuværende kobling og dens oprindelse.
     - Kun direkte kategoritilknytning tæller; der er ingen implicit arv fra underkategorier.
     - Umappede kategorier (fx "General Merchandise" og standardkategorien) ignoreres.
   - **Tilstandsbaseret og idempotent:** resultatet afhænger kun af den aktuelle tilstand, ikke af rækkefølgen eller antallet af hændelser. En afstemning uden ændring skriver intet.
   - **Hooks på term- og produkt-livscyklusniveau**, så wp-admin, quick/bulk edit, CSV-import, REST, WP-CLI og WooCommerce CRUD dækkes:
     - `set_object_terms` og `deleted_term_relationships` (taxonomy `product_cat`);
     - statusovergange for produkter (fx gendannelse fra papirkurven);
     - `pre_delete_term` for mappede kategorier;
     - pluginets egne mapping-handlinger;
     - genaktivering af en creator (nyt domæne-event i Creators-modulet).
   - **Handlerne sætter kun i kø:** de sætter en idempotent afstemning i kø via **Action Scheduler** (asynkron, unik pr. produkt).
     - Handlerne skriver aldrig selv koblinger.
     - Afstemningen sker aldrig midt i WooCommerce' egen produktgemning.
     - Koblingen bliver derfor konsistent med en kort forsinkelse (eventual consistency).
   - **Sikkerhedsnet:** et admin-værktøj til fuld afstemning (tørkørsel → bekræftelse) fanger ændringer, som ingen hook ser (fx direkte SQL).

7. **Autorisation for system-afstemning:**
   - **Systemarbejde:** kategori-afledte ændringer køres via Action Scheduler.
     - De går stadig gennem `ProductCreatorLink` med samme validering: parent-produkt, understøttet status, aktiv creator ved nye koblinger, audit og transaktion.
     - De bruger en **eksplicit intern afstemningsmetode**, der ikke kræver `current_user_can( 'edit_product' )`. Kun afstemningen må kalde den.
   - **Grundlag for autorisationen:** mappingen er oprettet af en administrator med `merchboard_manage_creators`, og selve kategoriændringen er autoriseret af WordPress/WooCommerce.
   - **Bevidst konsekvens:** brugere og veje, der må ændre produktkategorier (fx shop managers, REST-klienter og CSV-import), styrer **indirekte** kategori-afledte koblinger. De kan ikke ændre manuelle koblinger.
   - **Audit:** actor er NULL (system). Den bruger, der udløste kategoriændringen, gemmes som `trigger_user_id` i context, når den er kendt.
   - **Stadig blokeret:** direkte ændring af `_merchboard_creator_id` og `_merchboard_creator_link_source` via REST, CSV-import, XML-RPC, quick/bulk edit og duplicate (ADR-030 D6).
   - **Manuelle ændringer** (link, remove, takeover og "Keep as manual") kræver fortsat `merchboard_manage_creators` og `edit_product` for den aktuelle bruger.

8. **Tvetydighed:**
   - Ligger et produkt direkte i kategorier mappet til **to eller flere forskellige creators**, har det ingen kategori-baseret kobling. En eksisterende `category`-kobling fjernes (`reason: ambiguous`).
   - Der vælges aldrig en creator ud fra rækkefølge eller historik.
   - En manuel kobling må eksistere og vinder.
   - Tvetydighed er en tilstand (needs attention), ikke en selvstændig audit-hændelse.

9. **Scenarier** for et parent-produkt. "Mappet creator" betyder creatoren for en direkte, mappet kategori.

   | Nuværende kobling | Mappede creators | Resultat |
   | --- | --- | --- |
   | `admin` (eller uden oprindelse) | vilkårlig | Ingen ændring. Konflikt vises, hvis den afviger. |
   | ingen | ingen | Ingen kobling |
   | ingen | én aktiv (A) | Kobl til A (`category`) |
   | ingen | én inaktiv (A) | Ingen ny kobling. Vises som `creator_inactive` og afstemmes ved genaktivering. |
   | `category` → A | A (aktiv eller inaktiv) | Ingen ændring. Eksisterende koblinger bevares ved inaktivitet. |
   | `category` → A | ingen | Fjern koblingen |
   | `category` → A | kun B, aktiv | Erstat A → B (`category_moved`) |
   | `category` → A | kun B, inaktiv | Fjern A. Opret ikke B. |
   | ingen eller `category` | to eller flere forskellige | Tvetydig: ingen kategori-kobling (en `category`-kobling fjernes) |

   - **Almindelig kategori sammen med en creator-kategori** (fx Alpha + General Merchandise): creator-kategorien tæller, og produktet kobles til Alpha.
   - **Underkategori:** et produkt, der kun ligger i en underkategori til en mappet kategori, får ingen kobling.
   - **Papirkurv og auto-draft:** ingen aktiv afstemning, og de eksisterende livscyklusregler gælder. En kobling bevares i papirkurven, og `ProductCreatorLink` skriver ikke til produkter i papirkurv eller auto-draft.
   - **Gendannelse fra papirkurven og overgang fra auto-draft:** produktet afstemmes igen.
   - **Varianter** har ingen egen relation og ingen kategorier. Afstemningen sker på parent-produktet.

10. **Oprettelse og fjernelse af en mapping:**
    - **Oprettelse:**
      - En tørkørsel viser konsekvenserne: fx kobles, allerede manuelt koblet til samme creator, konflikt med manuel kobling til en anden creator, tvetydig og creator inaktiv.
      - Efter eksplicit bekræftelse gemmes mappingen, og den første afstemning køres i batches via Action Scheduler.
    - **Fjernelse:**
      - En tørkørsel viser antallet af berørte `category`-koblinger.
      - Admin vælger eksplicit **"Remove category-managed links"** eller **"Keep as manual links"** (oprindelsen ændres til `admin`).
      - Der sker ingen masseændring uden eksplicit bekræftelse.
    - **Hvem og hvor:** kun en administrator med `merchboard_manage_creators` på creatorens redigeringsside. Nye mappings kan kun gives til aktive creators (uændret fra ADR-031).

11. **Sletning af en mappet kategori:**
    - Mappingen fjernes (`reason: term_deleted`).
    - `category`-koblinger, der kun eksisterede på grund af denne mapping, fjernes, fordi produkterne forlader kategorien. De omdannes ikke til manuelle.
    - Manuelle koblinger påvirkes ikke.
    - Dette er bevidst forskelligt fra manuel fjernelse af en mapping (pkt. 10), fordi sletningen sker i WooCommerce uden et valg i Merch Dashboard.

12. **Duplicate:**
    - WooCommerce' duplicate kopierer ikke `_merchboard_creator_id` eller `_merchboard_creator_link_source` (ADR-030 D6, uændret).
    - Kopien arver produktkategorierne og kan derfor få en `category`-kobling gennem den normale afstemning.
    - Der er ingen særregel, der kopierer koblingen direkte.

13. **Audit:** kun faktiske ændringer. Idempotente afstemninger uden ændring skriver intet.

    | Hændelse | Action | Context |
    | --- | --- | --- |
    | Kategori mappet | `creator_category_linked` | `term_id` |
    | Mapping fjernet | `creator_category_unlinked` | `term_id`, `reason` (`admin` eller `term_deleted`) og ved `admin` valget (`remove_links` eller `keep_as_manual`) |
    | Kobling oprettet af afstemningen | `product_creator_linked` | `source: category`, `reason`, `category_ids`, evt. `trigger_user_id` og `run_id` |
    | Kobling fjernet af afstemningen | `product_creator_unlinked` | `source: category`, `reason`, evt. `trigger_user_id` og `run_id` |
    | Flyttet mellem creator-kategorier | `product_creator_unlinked` + `product_creator_linked` i samme transaktion (som ved takeover) | `reason: category_moved`, `previous_creator_id` |
    | "Keep as manual" (enkeltprodukt eller ved fjernelse af mapping) | `product_creator_linked` med samme creator | `source: admin`, `previous_source: category` |
    | Afstemning i batches afsluttet | `category_sync_completed` | `run_id`, anledning og optællinger pr. resultat |

    - Reasons for `source: category`: `category_added`, `category_removed`, `category_moved`, `mapping_created`, `mapping_removed`, `creator_activated`, `term_deleted` og `ambiguous`.
    - Tvetydighed og konflikter auditeres ikke som selvstændige hændelser.

14. **Backfill:**
    - ADR-031's engangs-backfill erstattes konceptuelt af **den første afstemning, når en kategori mappes** (pkt. 10).
    - ADR-031's klassifikation genbruges og udvides med oprindelsen.
    - `product_backfill_completed` erstattes af `category_sync_completed`.
    - Idempotensen følger af den tilstandsbaserede afstemning og Action Schedulers unikke handlinger.

### Ændringer af tidligere ADR'er
- **ADR-030 D6:**
  - Ud over den manuelle skrivevej findes nu en **godkendt, systembaseret kategori-skrivevej** (`source: category`) gennem `ProductCreatorLink` uden capability-tjek for den aktuelle bruger (pkt. 7).
  - REST, CSV-import, XML-RPC, quick/bulk edit og duplicate kan fortsat ikke ændre nøglerne direkte. Kategoriændringer via disse veje kan udløse afstemningen.
  - Uninstall sletter også `_merchboard_creator_link_source`.
- **ADR-031:** følgende er **ændret** som beskrevet ovenfor:
  - Pkt. 2: mappingen ligger ikke i creator-meta `product_category_id`.
  - Pkt. 3: 1:1 bliver én creator pr. kategori, teknisk flere kategorier pr. creator.
  - Pkt. 4: "ingen automatisk synkronisering" gælder ikke længere.
  - Pkt. 5-6: engangs-backfill og "overskriver aldrig" erstattes.
  - Pkt. 7: tvetydige produkter rettes ikke kun manuelt.
  - Pkt. 10: mapping-ændringer påvirker nu produkter.
  - Pkt. 11: kategorisletning kan nu ændre koblinger.
  - Præciseringen af D6 (backfill som skrivevej) og det fravalgte alternativ "parallel drift med synkronisering".

  Uændret: pkt. 1 (ejerskab via `_merchboard_creator_id`), pkt. 8 (inaktive creators får ikke nye koblinger) og pkt. 9 (varianter).
- **ADR-032:**
  - Pkt. 1: skrivevejene er nu den **manuelle admin-vej** (`source: admin`, den normale manuelle vej, uændret) og den **automatiske kategori-afstemning** (`source: category`). Engangs-backfill som selvstændig skrivevej udgår.
  - Pkt. 3: `ProductLinkSource` får `category`, og `backfill` udfases.
  - Remove afvises for kategori-styrede produkter (pkt. 5), og "Keep as manual" tilføjes (pkt. 4).
  - Pkt. 2, 4 og 5 gælder i øvrigt uændret.

## Alternatives considered
- **Mapping i creator-meta `product_category_id` (ADR-031):** entydigheden pr. kategori ville kun blive håndhævet af applikationen, og opslaget fra kategori til creator ved hver produktændring ville ramme et felt uden indeks. Fravalgt.
- **Term-meta på `product_cat`:** plugin-data i WooCommerce' terms og en ny undtagelse i uninstall. Fravalgt (som i ADR-031).
- **Ingen gemt oprindelse (udled den):** når et produkt forlader en kategori, kan en kategori-afledt kobling ikke skelnes fra en manuel, og en legitim manuel kobling kunne blive slettet. Fravalgt.
- **Behold den tidligere creator ved tvetydighed:** resultatet ville afhænge af rækkefølgen af ændringer. Fravalgt.
- **Skjult undtagelsesliste ved manuel fjernelse:** usynlig tilstand, der modarbejder reglen. Fravalgt til fordel for "Keep as manual".
- **Synkron afstemning i hooket eller på `shutdown`:** skrivning midt i WooCommerce' produktgemning (meta-cache, indlejrede transaktioner, midlertidig status) eller uden genforsøg. Fravalgt til fordel for Action Scheduler.
- **Kategorien som ejerskab (ingen `_merchboard_creator_id`):** ingen validering, audit eller manuel overstyring, og ejerskab ville følge omdøbning. Fravalgt (som i ADR-031).
- **Omdan kategori-koblinger til manuelle ved kategorisletning:** ville efterlade koblinger uden grundlag, som ingen har valgt. Fravalgt.

## Reason
Kategorien er shoppens eksisterende arbejdsgang for, hvilken creator et produkt tilhører. En vedvarende regel fjerner manuelt dobbeltarbejde. Ejerskab har stadig én relation, én domæneservice og audit for hver ændring. Oprindelsen gør det muligt at automatisere uden at røre legitime manuelle beslutninger.

## Consequences
- **Migration:** ny migration (schema-version 4) for `merchboard_creator_categories`. Fase 17's "ingen migration" gælder ikke længere.
- **Ny meta-nøgle:** `_merchboard_creator_link_source` er plugin-ejet product meta. Den skal beskyttes af skrivevejsvagterne, udelades ved duplicate og slettes ved opt-in-uninstall sammen med `_merchboard_creator_id`.
- **Kode, der skal ændres:**
  - `ProductLinkSource`: `Admin` og `Category`; `Backfill` fjernes.
  - `ProductRecord`/`ProductStore`: læser og skriver oprindelsen.
  - `ProductCreatorLink`: får den interne afstemningsmetode, afviser Remove på kategori-styrede produkter og får "Keep as manual".
- **Trin 4's UI** viser oprindelsen pr. produkt og tilbyder "Keep as manual". Kategorisøgningen fra trin 4 bevares som søgehjælp.
- **Audit:** nye actions `creator_category_linked`, `creator_category_unlinked` og `category_sync_completed`. `product_backfill_completed` indføres ikke.
- **Creators-modulet** får et domæne-event ved statusændring, så genaktivering kan udløse afstemning.
- **Roadmap:** fase 17's resterende trin omlægges (se ROADMAP.md). Implementeringen var ikke startet, da beslutningen blev taget; den er siden implementeret i fase 17 (se implementeringsstatus under Status og PROJECT-STATUS).

---

# ADR-034: Beskyttelse af produktkoblingens meta mod generiske skriveveje

## Date
2026-09-26

## Status
Accepteret (fase 17, trin 6, implementeret). Besluttet af projektejeren i specifikationen af trin 6. **Ændrer ADR-030 D6** (grænsen for sikkerhedsmodellen). Supplerer ADR-033 (kategori-afstemningen bruger denne mekanisme; implementeret i fase 17) og ADR-025/ADR-030 D4 (uninstall, se pkt. 6). To punkter er **uafklarede** og kræver særskilte beslutninger: beskyttelse uden et indlæst WooCommerce-modul (pkt. 5) og uninstall-mekanismen (pkt. 6). **Uafklaret A er besluttet i ADR-035 (2026-09-26):** vagten er en core-service, der registreres i `Plugin::register_hooks()` uden om `merchboard_modules` (implementeret 2026-09-26). **Uafklaret B er besluttet i ADR-036 (2026-09-26):** uninstall-vindue i vagten (implementeret 2026-09-26). Pkt. 3 er dermed præciseret: `ProtectedMetaWrite` er fortsat den eneste godkendte **skrivekontekst** (tilføje, ændre, omdøbe). Den eneste anden tilladte operation er ADR-036's **sletning** af de to nøgler, og kun når operationen er en sletning, `WP_UNINSTALL_PLUGIN` er Merch Dashboards eget basename, og opt-in til sletning af data er aktivt.

## Context
ADR-030 D6 lukkede WooCommerce' skriveveje (REST, CSV-import, quick/bulk edit og duplicate) for produktkoblingen, men fastslog som grænse, at WordPress' rå meta-API (`update_post_meta()` m.fl. fra vilkårlig PHP-kode) og `wp post meta` via WP-CLI var ikke-understøttede bypasses. Der skulle ikke laves globale meta-hooks.

ADR-033 gjorde koblingen til to meta-nøgler (`_merchboard_creator_id` og `_merchboard_creator_link_source`) og indførte en fremtidig systembaseret skrivevej uden aktuel bruger. Projektejeren har i specifikationen af trin 6 besluttet, at begge nøgler skal være beskyttet deny-by-default mod alle generiske skriveveje, inkl. det rå meta-API, med én eksplicit, afgrænset intern skrivekontekst for de godkendte veje.

Undersøgt i WordPress 7.1.2 og WooCommerce 10.3.8:
- Alle skrivninger gennem metadata-API'et passerer pre-hooks: `add_post_metadata`, `update_post_metadata` og `delete_post_metadata` samt `update_post_metadata_by_mid` og `delete_post_metadata_by_mid`, som WooCommerce CRUD bruger. Et ikke-`null` svar afbryder skrivningen.
- `wp_delete_post()` sletter et indlægs meta via `delete_metadata_by_mid()` mellem `before_delete_post` og `after_delete_post`.
- `Plugin::default_modules()` indlæser kun `WooCommerceModule`, når `WooCommerceSupport::from_environment()->is_supported()` (WooCommerce aktivt og mindst 10.3, ADR-030 D1/D2). Modullisten kan desuden ændres via filteret `merchboard_modules`.
- `uninstall.php` booter ikke pluginet: den registrerer autoloaderen og kører `Uninstaller` direkte. WordPress kræver, at pluginet er deaktiveret før sletning (`delete_plugins()`), så i det normale forløb er pluginets hooks ikke registreret. `wp plugin uninstall --deactivate` deaktiverer og afinstallerer derimod i **samme** WP-CLI-proces, hvor pluginet allerede er indlæst ved opstart.

## Decision

1. **Beskyttede nøgler:** `_merchboard_creator_id` og `_merchboard_creator_link_source` (`ProtectedProductMeta::KEYS`). Nøgler sammenlignes trimmet og uden hensyn til store og små bogstaver, som MySQL sammenligner dem, så ingen anden stavemåde slipper igennem. Beskyttelsen gælder alle indlæg, altså både produkter og varianter; en variant kan derfor ikke få en selvstændig relation.

2. **Deny-by-default på metadata-laget (`ProtectedProductMeta`):** pre-hooks på de fem metadata-hooks ovenfor (prioritet 1000, efter andre plugins) afviser enhver tilføjelse, ændring, omdøbning og sletning af en beskyttet nøgle, uanset kilde:
   - WooCommerce CRUD, WooCommerce REST, CSV-import, quick/bulk edit og XML-RPC;
   - `update_`/`add_`/`delete_post_meta()`, `delete_post_meta_by_key()` og `*_metadata_by_mid()` fra vilkårlig kode;
   - `wp post meta` via WP-CLI.

   Ved skrivninger via meta-ID tjekkes både den gemte og en eventuel ny nøgle, så man hverken kan omdøbe et link væk eller omdøbe anden meta til et link.

   En afvist skrivning fejler (`false`), logges som teknisk advarsel (operation, post-ID og nøgle, aldrig værdier; ADR-021) og skriver ingen audit. Læsning påvirkes aldrig, og anden meta skrives normalt.

3. **Godkendt skrivekontekst (`ProtectedMetaWrite`):** den eneste vej udenom vagten.
   - **Formål:** hver kontekst har et formål (`ProtectedMetaPurpose`): `manual_link` (Merch Dashboards manuelle vej via `ProductCreatorLink`) eller `category_reconciliation` (ADR-033's systemafstemning; bruges siden fase 17, trin 8 af `ProductCreatorLink::apply_category_link()` via `ProtectedMetaWrite::for_category_reconciliation()`).
   - **Oprettelse:** kun via de navngivne fabrikker `for_manual_link( $product_id )` og `for_category_reconciliation( $product_id )`; constructoren er privat.
   - **Produktbinding:** konteksten dækker præcis ét produkt-ID (positivt). Den tillader aldrig skrivninger på andre indlæg, heller ikke produktets varianter, og aldrig sletning af en nøgle fra alle indlæg.
   - **Scope:** den har kun virkning under `ProtectedProductMeta::run( $write, $operation )` og kun for varigheden af det kald.
   - **Oprydning:** konteksten ryddes altid efter kaldet, også når operationen kaster en undtagelse (`finally`).
   - **Engangsbrug:** vagten accepterer hver instans én gang; genbrug giver `LogicException`.
   - **Ingen indlejring:** kører en kontekst allerede, giver endnu et `run()` `LogicException`, og den afviste kontekst giver ingen adgang.
   - **Ingen bruger:** konteksten bærer ingen bruger og tjekker ingen capability. Autorisation sker før oprettelsen: `ProductCreatorLink` tjekker `edit_product`, creatoren og produktet og opretter først derefter konteksten, og kun om selve lagerskrivningen i sin transaktion. En systemkørsel uden aktuel bruger (ADR-033) bruger derfor samme mekanisme.
   - **Ingen global bypass:** der er ingen global boolean, ingen statisk bypass og ingen offentlig metode, der slår vagten fra.

4. **Permanent sletning af et indlæg:** mellem `before_delete_post` og `after_delete_post` må WordPress slette netop det indlægs beskyttede rækker via `delete_metadata_by_mid()`, så der ikke efterlades forældreløs meta. Det åbner ikke for andre skrivninger eller andre indlæg.

5. **Tydelige afvisninger ved indgangene (`ProtectedMetaEntryPoints`):** metadata-vagten afviser stille; derfor afviser disse hooks forsøget tidligt og tydeligt, hvor WooCommerce tillader det:
   - REST create/update/batch af produkter og varianter (`woocommerce_rest_pre_insert_*_object`): `400 merchboard_protected_product_meta`, når objektets beskyttede felter afviger fra de gemte (tilføjelse, ændring, omdøbning via meta-ID eller sletning). Ikke på `/duplicate`, hvor WooCommerce ikke tjekker for fejl.
   - REST `…/variations/generate` (`rest_request_before_callbacks`): `400`, når `meta_data` nævner en beskyttet nøgle.
   - CSV-import (`woocommerce_product_import_process_item_data`): rækken fejler med en besked; andre rækker og anden meta importeres normalt.
   - Duplicate (`woocommerce_duplicate_product_exclude_meta`): kopien og dens varianter starter uden felterne. En `category`-kobling kan derefter opstå via ADR-033's afstemning.

6. **Eksplicitte begrænsninger** (ingen garanti for fuld databaseintegritet):
   - **Direkte SQL** mod `wp_postmeta` (fra kode, databaseværktøjer eller `wp db query`) passerer ikke metadata-API'et og er ikke beskyttet.
   - **Kode, der fjerner eller omgår pluginets hooks**, er ikke beskyttet. Det gælder fx `remove_filter()` på vagtens callbacks, et andet plugin, der afbryder skrivningen før vagten, eller `merchboard_modules`-filteret, der fjerner `WooCommerceModule`.
   - Begge kræver kode-, shell- eller databaseadgang og ligger uden for sikkerhedsmodellen.
   - **Vagten virker kun, når pluginet og `WooCommerceModule` er indlæst i requesten** (se de uafklarede punkter nedenfor).

### Uafklaret A: beskyttelse, når WooCommerce er deaktiveret eller for gammel
- **Nu:** vagten registreres i `WooCommerceModule::register()`. Modulet indlæses kun med et understøttet WooCommerce (ADR-030 D2). Er WooCommerce deaktiveret eller ældre end 10.3, er `ProtectedProductMeta` **ikke aktiv**, og nøglerne kan ændres via metadata-API'et (fx `update_post_meta()` fra kode eller `wp post meta` via WP-CLI).
- **Hvad der stadig beskytter i den situation:**
  - WordPress' egen beskyttelse af `_`-nøgler (custom fields-UI og XML-RPC).
  - Core REST ændrer ikke uregistreret meta.
  - WooCommerce' egne skriveveje findes ikke, når WooCommerce ikke kører.
  - Koblingen bevares (ADR-030 D4), men er ikke beskyttet mod ændringer via kode eller WP-CLI i den periode.
- **Mulig løsning (ikke besluttet, ikke implementeret):** registrere metadata-vagten (pkt. 2-4) uafhængigt af WooCommerce, fx i pluginets core eller i et modul, der altid indlæses. Den afhænger kun af WordPress' metadata-API. Indgangs-vagterne (pkt. 5) bliver i WooCommerce-modulet.
- **Afvejninger:** det kræver en beslutning om modulgrænsen (ADR-011: WooCommerce-koden bor i det valgfrie modul; MODULES.md), og om `merchboard_modules`-filteret fortsat må kunne fjerne beskyttelsen.
- **Status:** besluttet i ADR-035 (mulighed B: core-service, registreret i `Plugin::register_hooks()`, uden om `merchboard_modules`) og implementeret 2026-09-26. Begrænsningen "perioder uden indlæst WooCommerce-modul" gælder ikke længere.

### Uafklaret B: uninstall-mekanismen (før trin 13)
- **Krav:** opt-in-uninstall skal slette `_merchboard_creator_id` og `_merchboard_creator_link_source` fra alle produkter og varianter (ADR-030 D4, ADR-033), idempotent og før tabellerne droppes (ADR-025).
- **Konflikten:** vagten afviser netop sletning af en beskyttet nøgle på tværs af indlæg (`delete_post_meta_by_key()` → `delete_metadata( …, $delete_all = true )`). `ProtectedMetaWrite` dækker bevidst kun ét produkt.
- **I det normale forløb** (deaktivér → slet i wp-admin) er pluginet ikke indlæst, når `uninstall.php` kører, så vagten er ikke registreret og blokerer ikke.
- **Men** `wp plugin uninstall --deactivate` (WP-CLI) deaktiverer og afinstallerer i samme proces, hvor pluginet og dermed vagten allerede er indlæst. Så ville sletningen blive afvist (og logget), og nøglerne ville blive liggende. Det samme gælder enhver kontekst, hvor `Uninstaller` kører med pluginet indlæst (fx tests).
- **Krav til løsningen:** en eksplicit, afgrænset oprydningsmekanisme, der kun kan bruges af `Uninstaller` og kun sletter de to nøgler, uden at åbne en generel bypass.
- **Mulige retninger (ikke besluttet):**
  - (a) et eget formål i `ProtectedMetaPurpose` med en kontekst, der kun tillader sletning af de to nøgler på alle indlæg og kun under `Uninstaller`;
  - (b) at `Uninstaller` sletter pr. produkt med én kontekst pr. produkt;
  - (c) at `Uninstaller` fjerner vagtens hooks før sletningen. (c) svarer til en global bypass i den proces og bør vurderes kritisk.
  - Direkte SQL-sletning er fravalgt af ADR-030 (Alternatives considered).
- **Status:** besluttet i ADR-036 (mulighed D): et uninstall-vindue i vagten, som kun tillader sletning af de to nøgler under dette plugins opt-in-uninstall. Oprettelse, ændring og omdøbning er fortsat blokeret. Implementeret 2026-09-26.

## Ændringer af tidligere ADR'er
- **ADR-030 D6:** Grænsen "WordPress' rå meta-API … og `wp post meta update` via WP-CLI … er ikke-understøttede bypasses … Der laves ingen globale meta-hooks" gælder ikke længere. Disse veje afvises nu af metadata-vagten (pkt. 2). Uden for grænsen er kun direkte SQL og kode, der fjerner eller omgår pluginets hooks (pkt. 6), samt perioder uden indlæst WooCommerce-modul (Uafklaret A). D6's øvrige regler er uændrede.
- **ADR-033:** Den systembaserede kategori-skrivevej (ADR-033 pkt. 7) skal skrive gennem `ProtectedMetaWrite::for_category_reconciliation()` for hvert produkt. Oprindelses-nøglen er beskyttet på samme måde som koblingen.

## Alternatives considered
- **Behold ADR-030's grænse (ingen meta-hooks):** kode og WP-CLI kunne ændre ejerskab uden validering og audit. Fravalgt af projektejeren i trin 6.
- **Kun indgangs-vagter (REST, import, duplicate):** dækker ikke `update_post_meta()` fra kode, WP-CLI eller ukendte WooCommerce-veje. Fravalgt; indgangs-vagterne supplerer kun med tydelige fejl.
- **Tjek af aktuel bruger, capability eller admin-skærm i vagten:** ville blokere systemkørsler uden bruger (ADR-033) og give adgang til alle med rettigheden, ikke kun til de godkendte veje. Fravalgt til fordel for en eksplicit kontekst.
- **Global boolean eller statisk bypass-funktion:** kan glemmes slået til, lække på tværs af operationer og kaldes utilsigtet. Fravalgt.
- **Afvis også læsning eller ændringer af indlæg uden nøglerne:** unødvendigt og ville bryde WooCommerce. Fravalgt.

## Reason
Ejerskab af et produkt er forretningsdata med audit (ADR-023/030). Når nøglerne kun kan ændres gennem en eksplicit, afgrænset og engangs-kontekst, som de godkendte veje først opretter efter deres egen validering, kan ingen generisk vej ændre ejerskab uden validering og audit. Det gælder også dem, der ikke er kendt i dag.

## Consequences
- **Implementeret i trin 6:** `ProtectedProductMeta`, `ProtectedMetaWrite`, `ProtectedMetaPurpose` og `ProtectedMetaEntryPoints`. `ProductCreatorLink` skriver kun inden for en `manual_link`-kontekst.
- **Nye skriveveje** for de beskyttede nøgler skal have et eget formål i `ProtectedMetaPurpose` og en særskilt beslutning.
- **Teknisk log:** afviste forsøg ses som advarsler i den tekniske log (WooCommerce → Status → Logs, source `merchboard`).
- **To opgaver kræver beslutning:** beskyttelse uden indlæst WooCommerce-modul (Uafklaret A) og uninstall-mekanismen før trin 13 (Uafklaret B). *(Senere besluttet: A i ADR-035, B i ADR-036.)*

---

# ADR-035: Beskyttelse af creator-produktmeta uafhængigt af WooCommerce

## Date
2026-09-26

## Status
**Accepteret (2026-09-26): mulighed B, registreret i `Plugin::register_hooks()`.** Besluttet af projektejeren. Lukker ADR-034, Uafklaret A. **Implementeret 2026-09-26** (se Consequences). Supplerer ADR-011 og ADR-034 (statusnoter med krydsreference). Uninstall (ADR-034, Uafklaret B) er **ikke** løst af denne beslutning. Vurderingen af mulighederne nedenfor er bevaret som beslutningsgrundlag.

## Context
ADR-034 beskytter `_merchboard_creator_id` og `_merchboard_creator_link_source` med `ProtectedProductMeta` på WordPress' metadata-lag. Vagten registreres i `WooCommerceModule::register()`.

**Undersøgt i koden (trin 6-stand):**
- **Hvornår modulerne indlæses:** `Plugin::boot()` kører på `plugins_loaded`. Den sender `default_modules()` gennem filteret `merchboard_modules` og kalder `register()` på hvert gyldigt modul. `default_modules()` tilføjer kun `WooCommerceModule`, når `WooCommerceSupport::from_environment()->is_supported()` (WooCommerce aktivt og mindst `Requirements::MIN_WOOCOMMERCE` = 10.3).
- **Konsekvensen i dag:** uden WooCommerce, med en for gammel WooCommerce, eller når et filter på `merchboard_modules` fjerner modulet, er vagten ikke registreret. Nøglerne kan så ændres via `update_post_meta()` fra kode eller via `wp post meta`. Koblingen bevares i den periode (ADR-030 D4), men er ikke beskyttet.
- **Vagtens afhængigheder:** `ProtectedProductMeta`, `ProtectedMetaWrite` og `ProtectedMetaPurpose` bruger kun WordPress' metadata-API, `Core\Logging\Logger` og PHP (`WeakMap`, `LogicException`). Den eneste binding til WooCommerce-modulet er, at nøglerne læses fra konstanterne i `ProductCreatorLink`. Vagten kræver ikke WooCommerce.
- **Hvad der kræver WooCommerce:** `ProtectedMetaEntryPoints` (REST, `variations/generate`, CSV-import, duplicate) bruger kun WooCommerce' hooks og objekter. Uden WooCommerce findes de veje ikke.
- **Core ejer allerede nøglen:** ADR-030 D4 lader core (`Core\Lifecycle\Uninstaller`) slette `_merchboard_creator_id` ved uninstall, også uden WooCommerce. Nøglen er altså allerede plugin-ejet data, som core kender, og ikke kun WooCommerce-integration (ADR-011).
- **Core-services i dag** (`Plugin::register_core_services()`): `wpdb`, `Logger` (`PluginLogger`, WooCommerce-logger eller `error_log`, ADR-021), `Installer`, `RoleManager` m.fl. `Container` tillader ikke, at en allerede oprettet service erstattes.

## Sikkerhedsmål (projektejerens forslag, til vurdering)
1. De to nøgler er beskyttet, uanset om WooCommerce er aktivt.
2. Beskyttelsen afhænger ikke af en WooCommerce-version.
3. `merchboard_modules` kan ikke deaktivere den grundlæggende beskyttelse utilsigtet.
4. `ProtectedMetaWrite` er fortsat den eneste godkendte interne skrivekontekst.
5. Direkte SQL og bevidst fjernelse af sikkerhedshooks er fortsat dokumenterede begrænsninger (ADR-034 pkt. 6).

## Muligheder

| | A. Status quo | B. Core, uden for modulfilteret | C. Neutralt modul i `default_modules()` | D. Neutralt modul, der altid indlæses |
| --- | --- | --- | --- | --- |
| Hvor | `WooCommerceModule` | `Plugin` registrerer vagten som core-service, før eller uafhængigt af modul-boot | Nyt modul (fx `ProductOwnershipModule`), altid i `default_modules()` | Nyt modul, som `Plugin` registrerer uden om `merchboard_modules`, eller et nyt begreb "påkrævede moduler" |
| Uden WooCommerce | **Ikke beskyttet** | Beskyttet | Beskyttet | Beskyttet |
| For gammel WooCommerce | **Ikke beskyttet** | Beskyttet | Beskyttet | Beskyttet |
| `merchboard_modules` kan fjerne beskyttelsen | Ja | Nej | **Ja** (bevidst eller ved en fejl i et filter) | Nej |
| Opfylder mål 1-3 | Nej | Ja | Nej (mål 3) | Ja |
| Ændring i arkitekturen | Ingen | Core får en sikkerhedsservice for plugin-ejet produktmeta. Modulfilteret styrer fortsat kun moduler. | Følger det eksisterende modulmønster; ingen nye begreber | Nyt begreb i modularkitekturen (moduler, filteret ikke må fjerne), eller en undtagelse i `Plugin::boot()` |
| Kompleksitet | Ingen | Lav | Lav | Middel |

B og D giver samme sikkerhed. De adskiller sig i, hvor begrebet bor: core-service (B) eller et særligt modul (D). A og C opfylder ikke det foreslåede mål. A er dog den nuværende, dokumenterede tilstand (ADR-034), og C beskytter uden WooCommerce, men kan fjernes via filteret.

**Fælles for B, C og D, hvis en af dem vælges:**
- **Placering og nøgler:** `ProtectedProductMeta`, `ProtectedMetaWrite` og `ProtectedMetaPurpose` flyttes ud af `Modules\WooCommerce` (fx til `Core\Security` eller modulets namespace). Nøglerne defineres dér, og `ProductCreatorLink::META_KEY`/`SOURCE_META_KEY` henviser til dem (ikke omvendt), så vagten ikke afhænger af WooCommerce-modulet.
- **`ProtectedMetaEntryPoints`** bliver i `WooCommerceModule`, fordi den kun giver mening med WooCommerce.
- **Én instans:** vagten oprettes og registreres præcis én gang. `WooCommerceModule` henter den samme instans fra containeren til `ProductCreatorLink` og må hverken oprette eller registrere en ny.
- **Tidspunkt (under-valg):**
  - Registrering i `Plugin::boot()` (på `plugins_loaded`) dækker alt efter indlæsningen af plugins.
  - Registrering allerede i `Plugin::register_hooks()`, når `merchboard.php` indlæses, dækker også kode, der kører under indlæsningen af plugins, der indlæses efter Merch Dashboard.
  - Kode i plugins, der indlæses før, er uden for begge dele.
  - Valget påvirker ikke de andre punkter.

## Konsekvenser for B/C/D (til vurdering)
- **Dependency injection:** vagten bliver en core-service (B) eller en modul-service (C/D) i `Container` med `Logger` som eneste afhængighed. `ProductCreatorLink` får den stadig via constructoren; kun registreringen flytter.
- **Logging:** uden WooCommerce skriver `PluginLogger` afviste forsøg til PHP's fejllog i stedet for WooCommerce-loggen (ADR-021). ADMIN.md og WOOCOMMERCE.md skal nævne det.
- **Ydelse:** som i dag. De to `_by_mid`-hooks slår rækken op (`get_metadata_by_mid()`) ved hver skrivning via meta-ID; med B, C eller D sker det også uden WooCommerce. Uden WooCommerce er den slags skrivninger sjældne.
- **Tests:**
  - **Integration:** den nuværende test registrerer sin egen instans. Mindst én test skal bevise, at det **bootede** plugin beskytter nøglerne uden WooCommerce. Integrationsmiljøet har intet WooCommerce, så det er det naturlige sted: `update_post_meta()`, `delete_post_meta_by_key()` og skrivninger via meta-ID afvises uden testens egen registrering.
  - **Unit:**
    - `Plugin::boot()` registrerer vagten, også når WooCommerce mangler, er for gammelt (`WooCommerceSupport` med fx 10.2) eller `merchboard_modules` returnerer `array()` (B/D), eller dokumentér, at filteret fjerner den (C).
    - `WooCommerceModule` genbruger instansen og registrerer ikke hooks to gange.
    - Nøglekonstanterne er uændrede.
  - De eksisterende tests af vagten flytter med klasserne.
- **ADR-011** (WooCommerce som valgfrit modul):
  - Ved B eller D får den en statusnote. Beskyttelsen af plugin-ejet produktmeta er core-ansvar (B) eller et påkrævet moduls ansvar (D) og ikke WooCommerce-integration. Integrationen selv (skriveveje, UI, indgangs-vagter og ledger) forbliver i det valgfrie modul.
  - Ved C: MODULES.md beskriver det nye modul. ADR-011 ændres ikke.
- **ADR-034:** ved B eller D lukkes Uafklaret A, og begrænsningen "perioder uden indlæst WooCommerce-modul" udgår. Ved C gælder begrænsningen fortsat for `merchboard_modules`. Ved A består den.

## Uninstall (ADR-034, Uafklaret B)
- **Modulgrænsen ændrer ikke behovet for en særskilt beslutning.** I det normale forløb (deaktivér → slet) er pluginet ikke indlæst, når `uninstall.php` kører, med A, B, C og D. `wp plugin uninstall --deactivate` kører i én proces, hvor pluginet og dermed vagten er indlæst, uanset placering.
- **Et nyt fund, som gælder alle tre retninger i ADR-034:** `uninstall.php` bygger sine egne objekter (`Uninstaller`, `SchemaEraser`, `PluginLogger`) og kender ikke den registrerede vagt-instans i pluginets container. En kontekst eller et formål (retning a/b) har kun virkning på den instans, der ejer hooks. En ny instans i `uninstall.php` kan ikke autorisere noget. Det samme gælder retning c (at fjerne hooks kræver den registrerede instans). Uninstall-mekanismen skal derfor også afgøre, hvordan `Uninstaller` når den registrerede instans, når den findes.
- **Hvad B eller D gør lettere:** hvis vagten bor i core, kan `Core\Lifecycle\Uninstaller` referere til vagtens klasser og formål uden at afhænge af WooCommerce-modulet. Det gør retning (a), et eget uninstall-formål, arkitektonisk renere.
- **Hvad B eller D ikke afgør:** hvilken retning der vælges, og hvordan instansen findes.
- **Status:** uninstall kræver fortsat en særskilt beslutning før trin 13.

## Decision
**Mulighed B.** Beskyttelsen af `_merchboard_creator_id` og `_merchboard_creator_link_source` er en **core-service**, der registreres uden om `merchboard_modules`:

1. **Altid aktiv:** vagten (ADR-034 pkt. 2-4) er aktiv, når pluginet er indlæst, uanset om WooCommerce er installeret, aktiveret eller opfylder minimumsversionen. Den afhænger ikke af en WooCommerce-version.
2. **Registreringstidspunkt:** vagten registreres i `Plugin::register_hooks()`, altså når `merchboard.php` indlæses, og **ikke** først i `Plugin::boot()` (`plugins_loaded`). Den dækker derfor også kode i plugins, der indlæses efter Merch Dashboard. Kode, der kører før `merchboard.php` indlæses, er uden for vagten.
3. **Uden om modulfilteret:** `merchboard_modules` kan ikke deaktivere beskyttelsen. Filteret styrer fortsat modulerne, inkl. WooCommerce-integrationen.
4. **Ikke et modul:** der indføres ikke et særligt eller påkrævet modul, som omgår modulmønstret.
5. **Én instans:** core opretter og registrerer præcis én instans. `WooCommerceModule` genbruger samme instans (fra containeren) til `ProductCreatorLink` og registrerer ingen hooks for vagten. Hooks må ikke registreres dobbelt.
6. **Klasser og nøgler:** `ProtectedProductMeta`, `ProtectedMetaWrite` og `ProtectedMetaPurpose` flyttes til core. Nøglerne defineres dér, og `ProductCreatorLink` henviser til dem, så vagten ikke afhænger af WooCommerce-modulet. *(Implementeret i `MerchBoard\Core\Security`; nøglerne er `ProtectedProductMeta::LINK_KEY` og `::SOURCE_KEY`.)*
7. **Indgangs-vagter:** `ProtectedMetaEntryPoints` forbliver i `WooCommerceModule`, fordi REST, `variations/generate`, CSV-import og duplicate afhænger af WooCommerce.
8. **Logging:** afviste skrivninger logges fortsat via `Logger`. Uden WooCommerce-loggen bruges den eksisterende fallback til PHP's fejllog (ADR-021).
9. **Uændret:**
   - `ProtectedMetaWrite` er fortsat den eneste godkendte interne skrivekontekst (ADR-034 pkt. 3).
   - Direkte SQL og bevidst fjernelse af vagtens hooks er fortsat dokumenterede begrænsninger (ADR-034 pkt. 6).

## Consequences
- **Implementering (2026-09-26):**
  - `ProtectedProductMeta`, `ProtectedMetaWrite` og `ProtectedMetaPurpose` ligger i `MerchBoard\Core\Security`.
  - `Plugin::register_core_services()` opretter den ene instans. `Plugin::register_hooks()` registrerer den, før andre hooks, og `register()` er idempotent.
  - `WooCommerceModule` henter samme instans fra containeren til `ProductCreatorLink` og registrerer kun `ProtectedMetaEntryPoints`.
  - Vagten får loggeren som `Core\Logging\LazyLogger`, der først slår den delte `Logger` op ved første log-kald. Grunden er, at `PluginLogger` læser filteret `merchboard_log_level`, når den oprettes. Oprettet mens `merchboard.php` indlæses, ville tærsklen blive låst for alle services, før temaer og senere plugins kan filtrere den.
  - **Tests:** unit-tests af registrering uden WooCommerce, med for gammel WooCommerce og med en tom `merchboard_modules`. En test viser, at modulet genbruger instansen og hooks registreres én gang. En integrationstest mod det bootede plugin uden WooCommerce.
- **ADR-011:** statusnote. Beskyttelsen af plugin-ejet produktmeta er core-ansvar. WooCommerce-integrationen forbliver i det valgfrie modul.
- **ADR-034:** Uafklaret A er besluttet her. Begrænsningen "perioder uden indlæst WooCommerce-modul" udgår, når beslutningen er implementeret.
- **Uninstall (ADR-034, Uafklaret B):** fortsat uafklaret. Skal besluttes særskilt før trin 13 (se afsnittet om uninstall ovenfor, inkl. spørgsmålet om hvordan `Uninstaller` når den registrerede instans). *(Senere besluttet i ADR-036: uninstall-vindue i vagten uden adgang til instansen.)*

---

# ADR-036: Sletning af beskyttet produktmeta ved opt-in-uninstall

## Date
2026-09-26

## Status
**Accepteret (2026-09-26): mulighed D.** Besluttet af projektejeren. Lukker ADR-034, Uafklaret B, under ADR-035's core-service-model. **Ændrer ADR-034 pkt. 3** med én afgrænset undtagelse, der kun tillader **sletning**. `ProtectedMetaWrite` er fortsat den eneste godkendte **skrivekontekst**. Krydsreference fra ADR-030 D4. **Verificeret 2026-09-27** med integrationstests af scenarierne opt-in, opt-out, manglende/forkert basename, skrivninger i vinduet, et andet plugins uninstall, gentagelse og logning; beslutningen er uændret. **Implementeret 2026-09-26** oven på ADR-035's core-vagt (se Consequences). Vurderingen af de fravalgte muligheder A, B og C er bevaret som beslutningsgrundlag.

## Context
- **Krav:** opt-in-uninstall skal slette `_merchboard_creator_id` og `_merchboard_creator_link_source` fra alle produkter og varianter på det aktuelle site via WordPress' meta-API, idempotent og før tabellerne droppes (ADR-025, ADR-030 D4, ADR-033). Direkte SQL er fravalgt (ADR-030, Alternatives considered). Uninstall skriver ingen audit-records (ADR-025); audit-tabellen droppes i samme kørsel.
- **Vagten:** `ProtectedProductMeta` afviser enhver generisk sletning af de to nøgler, også `delete_post_meta_by_key()` (→ `delete_metadata( …, $delete_all = true )`) (ADR-034). Efter ADR-035 registreres den i `Plugin::register_hooks()`, når `merchboard.php` indlæses, uden om `merchboard_modules`.
- **`ProtectedMetaWrite`** er engangs, bundet til ét produkt og til et formål, og virker kun på den instans, der ejer hooks (ADR-034 pkt. 3).

**Undersøgt i koden og WordPress 7.1.2:**
- **`uninstall.php`** er et separat indgangspunkt. Den tjekker `WP_UNINSTALL_PLUGIN` mod pluginets basename, registrerer autoloaderen og bygger sine egne objekter (`Uninstaller`, `SchemaEraser`, `PluginLogger`). Den booter ikke pluginet og har ingen adgang til pluginets container.
- **Ingen global adgang til instansen:** `merchboard.php` opretter `new Plugin( __FILE__ )` uden at gemme instansen. Containeren gives kun videre via `merchboard_loaded`. Der findes ingen global eller statisk adgang til en registreret service.
- **`uninstall_plugin()`** definerer konstanten `WP_UNINSTALL_PLUGIN` med pluginets basename umiddelbart før `uninstall.php` inkluderes. Konstanten kan ikke fjernes igen i requesten.
- **`Uninstaller::run()`** gør intet uden opt-in (`Settings::delete_data_on_uninstall()`). Opt-in-optionen slettes sidst, så den stadig er sand, når meta slettes (ADR-030: efter roller, før tabeller).
- **Hvornår vagten er indlæst:**
  - **wp-admin** (Plugins → slet) kræver et deaktiveret plugin. Pluginet er ikke indlæst i den request, så vagten er ikke registreret, og sletningen går igennem.
  - **`wp plugin uninstall --deactivate`** deaktiverer og afinstallerer i samme WP-CLI-proces. Pluginet og dermed vagten er indlæst ved opstart, så sletningen ville blive afvist.
- **Alle stavemåder slettes:** `delete_metadata( …, $delete_all = true )` finder rækkerne med `meta_key = %s` under MySQL's kollation uden hensyn til store og små bogstaver.

## Vurderede muligheder

### A. Særskilt uninstall-formål i `ProtectedMetaWrite`
Fx `for_uninstall()`, der kun tillader sletning af de to nøgler på alle indlæg.
- **Oprettelse:** kunne være sikker, fx privat og kun tilgængelig for `Uninstaller`. Men konteksten har kun virkning på den **registrerede** instans, og `uninstall.php` kan ikke nå den (se B).
- **Brud på invarianterne:** konteksten ville bryde `ProtectedMetaWrite`'s produktbinding. Den skulle dække alle indlæg og `delete_all`, altså præcis den operation, som bindingen skal forhindre.
- **Vurdering:** kræver B. Fravalgt som selvstændig løsning.

### B. `Uninstaller` henter den registrerede instans
- **Hvad det kræver:** en global eller statisk adgang til vagten, fx `Plugin::instance()` eller et statisk register på `ProtectedProductMeta`, som `uninstall.php` kan slå op i.
- **Livscyklus:**
  - I wp-admin-forløbet findes ingen instans, og `Uninstaller` skal forgrene sig.
  - I WP-CLI-forløbet findes den.
- **Risiko:** adgangen findes i **alle** requests, ikke kun under uninstall. Enhver kode kan så hente vagten og køre kontekster. Det er en permanent ny angrebsflade for at give adgang i et kort vindue.
- **Vurdering:** fravalgt.

### C. Midlertidig fjernelse af vagtens hooks
- **Hvordan:** `remove_filter()` kræver den registrerede instans (B) eller indgreb i `$wp_filter` (at finde callbacks, hvis objekt er `ProtectedProductMeta`).
- **Risiko:** efter fjernelsen er **alle** operationer ubeskyttede resten af processen, også `add` og `update`, dvs. ændring af ejerskab. Genregistrering efter sletningen kan springes over ved fejl, og indgreb i `$wp_filter` er skrøbeligt.
- **Vurdering:** den generelle bypass, som skal undgås. Fravalgt.

### D. Uninstall-vindue i vagten, som kun tillader sletning (anbefalet)
Vagten genkender selv WordPress' eget uninstall-signal for netop dette plugin og tillader så kun sletning af de to nøgler, når opt-in er sat. Det kræver ingen adgang til instansen, ingen ny global og ingen kontekst fra `uninstall.php`.

## Decision
**Mulighed D**, præcist afgrænset:

1. **Kun sletning:** undtagelsen gælder kun `delete_post_metadata` (inkl. `$delete_all`) og `delete_post_metadata_by_mid` for de to beskyttede nøgler. `add_post_metadata`, `update_post_metadata` og `update_post_metadata_by_mid` (inkl. omdøbning) afvises altid. Ejerskab kan derfor aldrig oprettes eller ændres ad denne vej, kun fjernes.
2. **Kun under dette plugins uninstall:** `defined( 'WP_UNINSTALL_PLUGIN' )` og `WP_UNINSTALL_PLUGIN === plugin_basename( <pluginets hovedfil> )`. Konstanten sættes af WordPress i `uninstall_plugin()`. Basename gives til vagten af `Plugin`, når core-servicen oprettes i `register_hooks()` (ADR-035).
3. **Kun med opt-in:** `Settings::delete_data_on_uninstall()` er sand. Uden opt-in sletter uninstall intet (ADR-025), og vinduet er lukket.
4. **Sletningen:** `Uninstaller` sletter nøglerne med `delete_post_meta_by_key()` for hver nøgle via meta-API'et. Det sker idempotent, efter roller og før tabeller (ADR-030 D4), og uden direkte SQL.
5. **Begge forløb:**
   - **wp-admin:** vagten er ikke indlæst, og sletningen går direkte igennem meta-API'et.
   - **`wp plugin uninstall --deactivate`:** vagten er indlæst, genkender vinduet og tillader kun sletningen.

   Samme kode i `Uninstaller` i begge tilfælde.
6. **Logging og audit:**
   - Vagten logger hver tilladt uninstall-sletning på niveau `info` (operation og nøgle, aldrig værdier; ADR-021).
   - `Uninstaller` logger som i dag ved afslutning.
   - Ingen audit-records (ADR-025); audit-tabellen droppes i samme kørsel.
   - Afviste forsøg logges som i dag (ADR-034).
7. **Testbarhed:** signalet om uninstall gives til vagten som en afhængighed (fx en callable, som i produktion tjekker konstanten), så det kan testes uden at definere en proces-global konstant.
8. **Ingen ny adgangsvej:** der bruges ingen global eller statisk adgang til vagt-instansen, der fjernes ingen hooks, og der bruges ingen direkte SQL. `Uninstaller` bruger fortsat WordPress' meta-API.
9. **Accepteret WordPress-begrænsning:** `WP_UNINSTALL_PLUGIN` kan kun defineres én gang pr. request. Ved uninstall af flere plugins i samme request, hvor Merch Dashboard ikke er det første, returnerer `uninstall.php` tidligt og sletter intet (se "Resterende risici"). Det accepteres; nøglerne slettes ved en senere uninstall af Merch Dashboard alene.
10. **ADR-034 pkt. 3 ændres med én undtagelse:** `ProtectedMetaWrite` forbliver den eneste kontekst, der må **skrive** (tilføje, ændre, omdøbe) de beskyttede nøgler. Den eneste anden tilladte operation er denne afgrænsede **sletning** under dette plugins opt-in-uninstall. Permanent sletning af et indlæg (ADR-034 pkt. 4) er uændret.

## Hvorfor D ikke er en generel bypass
- **Ingen skrivning:** add, update og omdøbning er aldrig tilladt; kun fjernelse.
- **Uden for uninstall:** vinduet findes ikke, fordi WordPress kun definerer `WP_UNINSTALL_PLUGIN` i `uninstall_plugin()`.
- **Kun dette plugin:** vinduet gælder kun, når konstanten er Merch Dashboards basename. Andre plugins' uninstall åbner det ikke.
- **Kun med opt-in:** uden opt-in er vinduet lukket, også under uninstall.
- **Ingen ny adgangsvej:** ingen global, ingen statisk adgang, ingen fjernelse af hooks og ingen offentlig metode.

## Resterende risici (accepteret)
- **Konstanten kan forfalskes:** kode kan selv definere `WP_UNINSTALL_PLUGIN` med pluginets basename. Det kræver kodeadgang og ligger uden for sikkerhedsmodellen, ligesom fjernelse af hooks (ADR-034 pkt. 6). Selv da tillades kun sletning og kun med opt-in.
- **Vinduet varer resten af requesten:** når WordPress har defineret konstanten, kan anden kode i samme request (fx resten af `delete_plugins()` eller andre plugins' uninstall i samme masse-sletning) også slette de to nøgler. Det gælder kun med opt-in, hvor uninstall alligevel sletter dem.
- **Eksisterende begrænsning i WordPress, uafhængig af denne ADR:** `WP_UNINSTALL_PLUGIN` kan kun defineres én gang pr. request. Ved masse-sletning af flere plugins, hvor Merch Dashboard ikke er det første, returnerer `uninstall.php` tidligt (basename-tjekket) og sletter intet. Nøglerne slettes så ikke før en senere uninstall. Denne ADR ændrer det ikke.

## Alternatives considered
A, B og C ovenfor. Desuden sletning pr. produkt med én kontekst pr. produkt; den kræver ligesom A adgang til instansen (B) og giver en kontekst pr. produkt uden sikkerhedsgevinst. Direkte SQL er udelukket (ADR-030).

## Consequences
- **Implementering (2026-09-26):**
  - `ProtectedProductMeta` får pluginfilen fra `Plugin` og bruger to signaler: `WP_UNINSTALL_PLUGIN` og `Settings::delete_data_on_uninstall()`. Kun i tests kan de erstattes, og kun via constructoren.
  - Vinduet tjekkes kun for en **sletning** af en beskyttet nøgle, som ingen `ProtectedMetaWrite` dækker: `filter_delete` (én post eller alle rækker) og `filter_delete_by_mid`. `filter_add`, `filter_update` og `filter_update_by_mid` spørger aldrig vinduet. En tilladt sletning logges på `info` (operation, post og nøgle, aldrig værdien).
  - `Uninstaller` sletter nøglerne med `delete_post_meta_by_key()` efter roller og før tabeller. `uninstall.php` er uændret.
  - **Tests:**
    - Unit: vinduet med injicerede og med rigtige signaler (i en separat proces).
    - Integration mod det bootede plugin (WP-CLI-forløbet).
    - En separat PHP-proces uden pluginet (wp-admin-forløbet).
    - En separat PHP-proces med pluginet indlæst, mens et andet plugin afinstalleres.
- **Oprindelig plan:**
  - Vagten får basename og et uninstall-signal ind ved oprettelsen.
  - `filter_delete` og `filter_delete_by_mid` får undtagelsen.
  - `Uninstaller` får trinnet med `delete_post_meta_by_key()` for de to nøgler.
- **Tests:**
  - **Unit:** sletning tillades kun med signal for dette plugin og opt-in. Add, update og omdøbning afvises også i vinduet. Andet basename og manglende opt-in afvises. Der logges info.
  - **Integration:**
    - `Uninstaller` fjerner begge nøgler i alle stavemåder med vagten registreret (WP-CLI-forløbet), og uden (wp-admin-forløbet).
    - Anden meta og selve produkterne bevares.
- **ADR-034:** statusnote (pkt. 3 og Uafklaret B).
- **ADR-030 D4:** krydsreference.
- **DATABASE.md:** afinstallationsafsnittet opdateres, når beslutningen er accepteret.

---

# ADR-037: Sales ledger-semantik (fase 18)

## Date
2026-09-28

## Status
Accepteret (fase 18, trin 1, før implementering). Besluttet af projektejeren. Supplerer ADR-011 (sales ledger) og ADR-025 (uninstall). Fastlægger forretningsreglerne for `merchboard_sales`. De tekniske detaljer under "Åbne tekniske afklaringer" besluttes i fase 18, trin 2, før migrationen skrives. Besvarer åbent spørgsmål 5 for beløbsgrundlag og tællende statusser; én creator pr. produkt gælder fortsat i v1, og revenue split er fortsat uafklaret. **Suppleret af ADR-038 (2026-09-28):** ledger-invariant og cyklusmodel, live-registrering af ordrer oprettet i tællende status, `occurred_at` ved backfill, fejlmarkør, `item_name`-snapshot, og `merchboard_sale_recorded` udskudt. Listen "Åbne tekniske afklaringer" nedenfor er stillingen efter trin 1; den aktuelle liste står i ADR-038. **Placering og capability for tællende statusser (Consequences) er fastlagt i ADR-038, statusnote "ROADMAP trin 6" (2026-09-29).** **Backfill (pkt. 6) er præciseret i ADR-038, statusnote "ROADMAP trin 7" (2026-09-30):** kun ordrer uden ledger-historik. **Fortegnsreglen (pkt. 3) er suppleret af ADR-038 (2026-09-28):** den nye type `correction` kan være positiv eller negativ og har altid en `correction_reason`; `sale` er positiv, `refund` og `reversal` negative. Historiske rækker ændres eller slettes aldrig.

## Context
ADR-011 besluttede en uforanderlig ledger fodret af ordre- og refund-hooks. WOOCOMMERCE.md og DATABASE.md indeholdt et udkast (nøglen `UNIQUE(order_item_id, refund_id, type)`, `net_amount` efter rabat ekskl. moms og fragt), men beløbsgrundlaget var et åbent spørgsmål (5), udkastets nøgle understøtter ikke en ordre, der tæller igen efter en reversal, og papirkurv, refunds uden ordrelinjer, attribution ved backfill, audit og afstemning var ikke besluttet.

## Decision
1. **Omsætning:** ordrelinjens beløb efter rabatter, ekskl. moms, fragt og gebyrer. Beløbet tages fra WooCommerce CRUD-data; pluginet beregner ikke selv ordrelinjens beløb.
2. **Ledger:** append-only historik. Historiske rækker ændres ikke, når produktets creator-kobling ændres.
3. **Fortegn:** salg har positivt beløb og antal. Refunds og reversals har negativt beløb og antal. Ledgerens saldo er summen af de relevante rækker.
4. **Tællende statusser:** konfigureres centralt i Merch Dashboard. Ændringer kræver passende capability og auditeres. Ændringer gælder kun fremadrettet og genberegner ikke automatisk historikken.
5. **Registrering:** salgs- og refundhændelser registreres synkront fra WooCommerce-hooks. Backfill og større batcharbejde kører asynkront via Action Scheduler. Fejl skal kunne opdages og genbehandles.
6. **Backfill:** både en WP-CLI-kommando og Action Scheduler. Backfill understøtter dry-run, batching, idempotent genkørsel og et resumé. Historiske ordrer tilskrives ud fra den **aktuelle** creator-kobling, fordi historisk ejerskab ikke kan rekonstrueres ud fra de eksisterende data.
7. **Refunds:** refunds, der ikke kan henføres til ordrelinjer, fordeles ikke automatisk på creators. Fragt og gebyrer medregnes ikke.
8. **Papirkurv og permanent sletning:** flytning af en ordre til papirkurven ændrer ikke ledgeren. Permanent sletning håndteres som en ledgerhændelse, der modposterer den resterende saldo, uden at slette historikken.
9. **Audit:** ledgeren er den detaljerede hændelseshistorik. Der oprettes ikke en audit-record for hver ledger-række. Backfill og administrative ændringer (fx af de tællende statusser) får passende audit eller resumé.
10. **Uninstall:** `merchboard_sales` følger de eksisterende opt-in-regler for sletning af plugin-data (ADR-025). Uden opt-in bevares tabellen.
11. **Afstemning:** fuld afstemning af ledgeren mod WooCommerce er ikke en del af fase 18's leverance. Den forbliver en senere job-type.

## Åbne tekniske afklaringer (fase 18, trin 2)
- **Idempotensnøgle:** den konkrete, deterministiske nøgle, som både tillader gentagne salgscyklusser (tællende → ikke-tællende → tællende) og afviser dubletter ved gentagne hooks. `UNIQUE(order_item_id, refund_id, type)` fra det tidligere udkast er ikke tilstrækkelig, fordi den kun tillader én `sale` og én `reversal` pr. ordrelinje.
- **Reversal efter delvis refund:** den præcise beregning, så en reversal kun modposterer den resterende saldo pr. ordrelinje, og intet tælles dobbelt.
- **WooCommerce-hooks og tidsstempler:** de præcise hooks og de tilgængelige, pålidelige tidsstempler for statusændringer, refunds, papirkurv og permanent sletning i WooCommerce 10.3.8 (HPOS og legacy). `occurred_at` skal repræsentere den faktiske forretningshændelse, men der vælges ikke et konkret felt, før det er verificeret.
- **Testharness for WooCommerce-ordrer:** uden nye dependencies. Viser det sig, at en ny dependency er nødvendig, stoppes der, og behovet rapporteres (ADR-030 D5).
- **`merchboard_sale_recorded`:** om og hvordan hooket eksponeres offentligt. Det står kun som et planlagt eksempel i ARCHITECTURE.md og er ikke besluttet.
- **Produktnavn-snapshot:** om ledgeren skal gemme produktnavnet (til visning efter permanent sletning af et produkt). Tilføjes kun med en dokumenteret begrundelse og konsekvenser.
- **Fejlhåndtering ved synkron registrering:** hvordan en fejlet registrering opdages og genbehandles (pkt. 5).

## Alternatives considered
- **Live-forespørgsler mod WooCommerce i stedet for en ledger:** fravalgt i ADR-011.
- **Genberegning af historikken, når de tællende statusser ændres:** fravalgt; ændringer gælder kun fremadrettet (pkt. 4).
- **Asynkron registrering af hver salgs- og refundhændelse:** fravalgt til fordel for synkron registrering; Action Scheduler bruges til backfill og batcharbejde (pkt. 5).
- **Automatisk fordeling af refunds uden ordrelinjer på creators:** fravalgt (pkt. 7).
- **Historisk ejerskab ved backfill:** ikke muligt, fordi koblingens historik ikke er gemt (pkt. 6).
- **En audit-record pr. ledger-række:** fravalgt; ledgeren er selv historikken (pkt. 9).
- **Afstemning mod WooCommerce i fase 18:** udskudt (pkt. 11).

## Reason
Ledgeren skal være en reviderbar, uforanderlig historik (grundlag for statistik og senere payouts), hvor intet tælles dobbelt, konfigurationsændringer ikke omskriver fortiden, og creators kun tilskrives beløb, der kan henføres til deres egne ordrelinjer.

## Consequences
- **DATABASE.md:** `merchboard_sales` er et skemaudkast, indtil trin 2 har fastlagt idempotensnøglen, `occurred_at`'s kilde og spørgsmålet om produktnavn-snapshot. Beløbssemantik og fortegn følger pkt. 1 og 3.
- **WOOCOMMERCE.md:** hændelsestabellen, beløbsreglerne, statusindstillingen, backfill, papirkurv, permanent sletning og afstemning følger denne ADR.
- **ROADMAP.md:** fase 18 er opdelt i små trin med trin 2 som teknisk afklaring før migrationen.
- **Indstillingen for tællende statusser** er en ny, central indstilling. Placering og capability fastlægges i det trin, der bygger den; ændringer auditeres som administrative ændringer.
- **Uninstall:** tabellen kommer automatisk med i opt-in-sletningen via `Tables::all()`, når den findes (ADR-025).
- **Åbent spørgsmål 5** er besvaret for beløbsgrundlag og tællende statusser; revenue split er fortsat åbent.

---

# ADR-038: Sales ledger – invariant og cyklusmodel (fase 18, trin 2)

## Date
2026-09-28

## Status
Accepteret som retning (fase 18, trin 2, før implementering). Besluttet af projektejeren. Supplerer ADR-037. **Supplement accepteret 2026-09-28 (teknisk afklaring og verifikation, se nederst):** de tidligere OPEN-punkter er afklaret, og skemaet i DATABASE.md er **design-låst** (ikke implementeret). Intet af dette er implementeret. **Statusnote 2026-09-28 (ROADMAP trin 3, se nederst):** dublet-semantikken i pkt. 18 er præciseret (en dublet er en idempotent no-op), og vagten mod en fremmed transaktion er OPEN. **Statusnote 2026-09-28 (ROADMAP trin 4–6, se nederst):** designbeslutningerne for recorderen er truffet (B1–B7); fremmed transaktion er lukket som dokumenteret restrisiko uden vagt. **Statusnote 2026-09-29 (ROADMAP trin 4, se nederst):** to fortolkninger om lukning af cyklusser og om cyklus 1 er normative for trin 5. **Statusnote 2026-09-29 (ROADMAP trin 5, se nederst):** beslutninger Q1–Q8 for synkronisering, hooks og fejlmarkør. **Statusnote 2026-09-29 (trin 5 afsluttet, se nederst):** implementeret og runtime-verificeret (Q4). **Statusnote 2026-09-29 (ROADMAP trin 6, se nederst):** beslutninger S1–S14 for indstillingen af tællende statusser. **Statusnote 2026-09-30 (se nederst):** S14's formatregel er præciseret (underscore, `wc-`-nøgler, længdegrænse 17, ikke-understøttede statusser vises). **Statusnote 2026-09-30 (trin 6 afsluttet, se nederst):** implementeret og runtime-verificeret med HPOS og legacy. **Statusnote 2026-09-30 (ROADMAP trin 7, se nederst):** beslutninger D1–D6 for backfill med præciseringen af D6 (ingen garanti for genoptagelse efter Action Schedulers oprydning). **Statusnoter 2026-10-01 (se nederst):** trin 7 afsluttet (commit `00fb066`, pushet, CI grøn inkl. MySQL 8.0.46, run `36887796803`), trin 8 afsluttet (regressionen af Q4-scenarierne og samspillet mellem backfill og live-hooks bestod i HPOS og legacy på `00fb066`) og fase 18 afsluttet (trin 9).

## Context
Trin 2 undersøgte WooCommerce 10.3.8's kildekode (read-only; sitet Merch Dashboard Local kørte ikke, så runtime-indstillinger som HPOS og synkronisering er ikke verificeret). Fundene, som påvirker ledgeren, står i WOOCOMMERCE.md under "Verificeret i WooCommerce 10.3.8": statushooks affyres fra `WC_Order::status_transition()` for både HPOS og legacy, en fuld refund skifter status (ud af tællende status), **før** `woocommerce_refund_created`/`woocommerce_order_refunded` affyres, papirkurven affyrer intet statushook, men gendannelse under HPOS gør (`from = trash`), en ordre oprettet direkte i en status får intet `woocommerce_order_status_changed`, og `woocommerce_new_order` affyres før ordrelinjerne er gemt. En registrering, der kun reagerer på enkelte hændelser, er derfor sårbar over for rækkefølge og gentagelser.

## Decision
1. **Ledger-invariant:** for en ordre i en tællende status skal ledgerens aktuelle balance for hver ordrelinje med creator svare til WooCommerce' aktuelle netto for linjen efter registrerede refunds. For en ordre, der ikke er i en tællende status, skal balancen være 0. `net_amount` er fortsat netto efter rabatter, ekskl. moms, fragt og gebyrer (ADR-037). Historiske rækker ændres eller slettes aldrig for at få balancen til at passe.
2. **Nye salgscyklusser:** når en ordre igen bliver tællende efter, at en tidligere cyklus er lukket, oprettes en ny salgscyklus med **den resterende aktuelle netto-balance**, ikke nødvendigvis det oprindelige ordrebeløb. Eksempel: første cyklus 100, refund 30, cyklussen lukkes med resterende balance 70; bliver ordren tællende igen, starter den nye cyklus med 70.
3. **Lukning ved balance 0:** en cyklus skal kunne markeres som lukket, også når dens balance er 0. Der oprettes ikke en kunstig reversal-række på 0/0 kun for at markere lukningen. Repræsentationen er OPEN (punkt 1).
4. **Ordrer oprettet direkte i tællende status:** skal registreres live, ikke kun via backfill. Hook og timing (fx `woocommerce_after_order_object_save`) er teknisk afklaring; implementeringen skal undgå unødvendige forespørgsler og være HPOS-kompatibel.
5. **Sletning af en refund:** historiske ledger-rækker slettes eller ændres aldrig, og sletningen må ikke bare ignoreres, hvis ledgeren så ikke længere kan forklares ud fra den append-only historik. Den kræver en eksplicit korrektion; korrektionstypen er OPEN (punkt 2).
6. **Ændring af en tællende ordrelinje:** ændres antal eller beløb efter registreringen, korrigeres ledgeren append-only; den gamle række overskrives ikke. Korrektionsmodellen er OPEN (punkt 3).
7. **`occurred_at`:** backfill bruger WooCommerce' relevante historiske datoer. `date_paid` bruges, når den er den relevante, tilgængelige hændelsesdato. `date_completed` bruges ikke universelt som standard. Der skal være en eksplicit, dokumenteret fallback, når `date_paid` mangler eller ikke er relevant; rækkefølgen er OPEN (punkt 6).
8. **Refunds på ikke-tællende ordrer:** en refund på en ordre, der ikke er tællende nu, registreres stadig, hvis den relaterer sig til en tidligere salgscyklus, men den skaber ikke en ny salgsbalance. Håndteringen af en refund, der ikke kan relateres til nogen tidligere salgscyklus, er OPEN (punkt 8).
9. **Vedvarende fejlmarkør:** fejler ledger-behandlingen, registreres en vedvarende markør via WooCommerce CRUD/ordremeta eller en anden allerede tilladt mekanisme. Der indføres ingen skjult, automatisk retry-kø; genbehandling sker eksplicit via senere WP-CLI-, backfill- eller reprocess-funktionalitet. Nøgle, livscyklus og rydning er OPEN (punkt 7).
10. **Snapshot af ordrelinjens navn:** ledgeren gemmer et snapshot af ordrelinjens navn (fx `item_name`), så historikken kan læses, også når produktet senere ændres eller slettes. **Privacy:** WooCommerce' ordrelinjenavne kan i særlige tilfælde indeholde bruger- eller personoplysninger; ledgeren udvides derfor ikke med unødvendige kundefelter eller PII.
11. **`merchboard_sale_recorded`:** implementeres og låses ikke i fase 18. Udskudt til et senere API-/event-design, hvor timing og den offentlige kontrakt vurderes særskilt.

## Åbne punkter (OPEN) – stillingen ved beslutningen; afklaret i supplementet nedenfor
1. **Cyklusstatus:** hvordan en cyklus repræsenteres som åben eller lukket (også ved balance 0) i database og model.
2. **Korrektion ved sletning af en refund:** korrektionstypen/modellen.
3. **Korrektion ved ændring af en tællende ordrelinje:** fx reversal + ny række eller en særskilt korrektionstype.
4. **Deterministisk idempotensnøgle.**
5. **Samtidighed og låsning.**
6. **Fallback-rækkefølge for `occurred_at`**, når `date_paid` ikke findes eller ikke er relevant.
7. **Livscyklus for den vedvarende fejlmarkør:** metadata-nøgle, hvornår den sættes, og hvordan den ryddes.
8. **Refunds uden en identificerbar tidligere salgscyklus:** den præcise håndtering.

Der vælges ikke kolonner, unikke indekser eller korrektionstyper for disse punkter, før de er afklaret.

## Alternatives considered
- **Hændelsesstyret registrering uden invariant** (hver hook skriver sin egen række): sårbar over for WooCommerce' rækkefølge (fuld refund før refund-hooks), gentagne hooks og gendannelse fra papirkurven.
- **En ny cyklus med det oprindelige ordrebeløb:** ville tælle allerede refunderede beløb igen.
- **En reversal-række på 0/0 som lukkemarkør:** fravalgt (pkt. 3).
- **Kun backfill for ordrer oprettet direkte i tællende status:** fravalgt (pkt. 4).
- **At ignorere sletning af refunds eller ændringer af ordrelinjer:** fravalgt, fordi ledgeren så ikke kan forklares ud fra historikken (pkt. 5 og 6).
- **`date_completed` som universel dato ved backfill:** fravalgt; WooCommerce sætter den ved hver overgang til `completed` (pkt. 7).
- **En automatisk retry-kø:** fravalgt (pkt. 9).
- **Et offentligt `merchboard_sale_recorded` i fase 18:** udskudt (pkt. 11).

## Reason
En tilstandsbaseret invariant gør registreringen uafhængig af hooks' rækkefølge og antal, holder ledgeren append-only og forklarlig, og undgår dobbelttælling ved gentagne salgscyklusser og refunds.

## Consequences
- **DATABASE.md:** skemaet forbliver et udkast. `item_name` er besluttet som snapshot; cyklusstatus, korrektionstyper, idempotensnøgle, låsning og fejlmarkør er OPEN.
- **WOOCOMMERCE.md:** invarianten, cyklusserne og de verificerede WooCommerce-fund er beskrevet.
- **ARCHITECTURE.md:** `merchboard_sale_recorded` er markeret som udskudt.
- **ROADMAP.md:** trin 2 afsluttes først, når OPEN-punkterne er afklaret; derefter låses skemaet i trin 3.


## Supplement 2026-09-28: afklaring og verifikation (accepteret)

Besluttet af projektejeren efter en read-only discovery og verifikation af WooCommerce 10.3.8's kildekode (Merch Dashboard Local) og af MariaDB-adfærd i projektets egen integrationstest-database. Intet er implementeret.

### Beslutninger
12. **Fortegn:** `sale` er positiv, `refund` og `reversal` negative, og den nye type `correction` kan være positiv eller negativ afhængigt af, hvad der korrigeres. En `correction` har altid en `correction_reason`. Supplerer ADR-037 pkt. 3.
13. **Tilstandsbaseret synkronisering pr. ordre:** alle triggere kalder den samme synkronisering, som bringer ledgeren i overensstemmelse med invarianten (pkt. 1) med typede rækker. Salgscyklusser gælder hele ordren, fordi "tællende" er en egenskab ved ordren.
14. **Cyklusstatus:** en separat, append-only tabel `merchboard_sales_cycles` med hændelserne `open` og `close` pr. `(order_id, cycle)` og `UNIQUE(order_id, cycle, event)`. Ingen mutabel statuskolonne. En cyklus lukkes med en `close`-række, også ved balance 0 (pkt. 3).
15. **Cyklus 1 og senere cyklusser (bevidst asymmetri):** første gang en ordre bliver tællende, registreres cyklus 1 som den aktuelle **brutto**-salgsbalance, og eksisterende refunds registreres som `refund`-rækker, så balancen bliver WooCommerce' netto. Senere cyklusser starter med den **resterende netto**-balance (pkt. 2), fordi alle refunds da allerede står i tidligere cyklusser. Refunds registreres altid i ordrens seneste eksisterende cyklus; er den lukket, modposteres de med en `correction` (`closed_cycle_refund`), så cyklussen forbliver 0 og ingen ny salgsbalance opstår (pkt. 8).
16. **Korrektioner:** en slettet refund korrigeres med `correction`/`refund_deleted` (spejling af ledgerens egne refund-rækker for refund-ID'et, fordi WooCommerce' data da er væk), og ligger refunden i en lukket cyklus, også med en modpost (`closed_cycle_refund_deleted`). Ændret antal eller beløb på en tællende ordrelinje korrigeres med `correction`/`line_changed` (delta mellem WooCommerce' netto for linjen og cyklussens balance); en fjernet linje med `line_removed`; en ny linje med creator i en åben cyklus får en `sale` i cyklussen.
17. **Idempotens:** kolonnen `event_key` med `UNIQUE`, bygget deterministisk pr. hændelsestype (tabellen i DATABASE.md). Refund-nøgler afhænger ikke af cyklussen. `UNIQUE(order_item_id, refund_id, type)` er forkastet.
18. **Samtidighed:** en navngiven lås pr. ordre med `GET_LOCK()` om hele synkroniseringen og en kort transaktion (`Transaction::run()`) om ledger- og cyklusskrivningerne; `UNIQUE` er sidste sikkerhedsnet. Rækkefølge: (1) tag låsen og notér `CONNECTION_ID()`, (2) læs WooCommerce-ordren via CRUD **uden for** transaktionen, (3) start transaktionen, (4) læs ledger- og cyklustilstanden, (5) beregn, (6) skriv, (7) kontrollér, at forbindelsen er den samme, (8) commit eller rollback, (9) frigiv låsen i `finally`. Timeout, deadlock, en dublet-konflikt eller en skiftet forbindelse giver rollback og fejlmarkør; ingen automatisk retry.
19. **`occurred_at`:** `sale` i cyklus 1: `date_paid`, ellers `date_created` (aldrig `date_completed`); `sale` i senere cyklusser, `reversal`, `correction` og sletning: behandlingstidspunktet; `refund`: refundens `date_created`. Ved senere genbehandling er det behandlingstidspunktet (accepteret); `date_modified` bruges ikke.
20. **Fejlmarkør:** ordremeta `_merchboard_ledger_failure` med `{v, first_at, last_at, code, count}` (ingen beløb, ingen PII). Sættes eller opdateres ved fejl (`count` øges), ryddes efter en vellykket synkronisering, skrives med `update_meta_data()` + `save_meta_data()`. Følger opt-in-uninstall (ADR-025). Ingen separat fejltabel og ingen automatisk retry.
21. **Triggere:** `woocommerce_after_order_object_save` (kun ordrer af typen `shop_order`; en ordre med status `trash` springes over) er den fælles trigger; den må koste mindst én indekseret ledger-forespørgsel for ordrer med mulige creator-linjer, uden unødvendige CRUD-kald eller globale forespørgsler. Sletning af en ordre: `woocommerce_before_delete_order` og `before_delete_post` (prioritet under 10, kun posttypen `shop_order`); lukningen beregnes kun ud fra ledgeren og er idempotent. Sletning af en refund: `woocommerce_refund_deleted`, `woocommerce_delete_order_refund` (ordren findes via ledgerens `refund_id`) og `before_delete_post` for posttypen `shop_order_refund` (ordren via `post_parent`); alle kalder synkroniseringen.
22. **WooCommerce-begrænsning (accepteret):** under HPOS uden synkronisering affyrer WooCommerce 10.3.8 intet hook ved sletning af en refund via REST. Korrektionen sker da ved næste synkronisering af ordren eller ved eksplicit genbehandling eller backfill; ingen skjult kø.
23. **Refunds uden tidligere cyklus:** normal flow: refunds uden ordrelinjer (ADR-037), refund-linjer uden creator og uden ledger-rækker, og refunds på en ordre, der aldrig har haft en cyklus og ikke er tællende, giver ingen rækker; bliver ordren senere tællende, registreres de i cyklus 1 (pkt. 15). Undtagelse (fejlmarkør, ingen rækker): `_refunded_item_id` mangler eller peger på en linje, der hverken findes i WooCommerce eller i ledgeren, en refund, der ville give negativt antal eller en negativ balance for en linje, og andre inkonsistente data.

### Verificerede fund (A–D)
- **A. `@@in_transaction` (MariaDB 10.11.19, projektets integrationstest-database):** variablen findes; 0 uden transaktion, 1 straks efter `START TRANSACTION`/`BEGIN` og efter skrivninger, 0 efter `ROLLBACK`/`COMMIT`. Med `autocommit = 0` er den 0, indtil en sætning faktisk starter en transaktion (fx en `SELECT 1` gør det ikke). MySQL 8.0 er ikke verificeret (OPEN).
- **B. `GET_LOCK()`:** hører til forbindelsen (sessionen), ikke til transaktionen: den committer eller afbryder ikke en åben transaktion (en indsættelse før `GET_LOCK()` blev rullet tilbage), overlever `COMMIT` og `ROLLBACK`, kan tages før `START TRANSACTION` (`@@in_transaction` forbliver 0), er re-entrant i samme session (hver `GET_LOCK()` kræver sin egen `RELEASE_LOCK()`), frigives, når forbindelsen lukkes, og en anden forbindelse får 0 ved timeout 0, mens låsen holdes. `RELEASE_LOCK()` på en lås, der ikke findes, returnerer NULL, så `finally` er sikkert. MariaDB afviste ikke et navn på 65 tegn, men MySQL begrænser navne til 64 tegn; navnet holdes under 64. Projektets `Transaction::run()` og `GET_LOCK()` via `wpdb` bruger samme forbindelse. WordPress' `wpdb::query()` genforbinder ved fejl 2006 og kører sætningen igen i en **ny** session: låsen og en åben transaktion er da tabt uden fejl. Derfor kontrolleres `CONNECTION_ID()` før commit (pkt. 18), og `UNIQUE` sikrer, at en ny synkronisering konvergerer.
- **C. Sletning af en ordre (WooCommerce 10.3.8):** legacy: `woocommerce_before_delete_order($id, $order)` (linjerne findes) → `wp_delete_post()` → `before_delete_post` (WooCommerce sletter linjerne på prioritet 10) → `delete_post` → `woocommerce_delete_order($id)`; en direkte `wp_delete_post()` giver kun `before_delete_post`. HPOS: `woocommerce_before_delete_order` (linjerne findes) → sletning i HPOS-tabellerne og af linjerne → med synkronisering `wp_delete_post()` på den spejlede post (`before_delete_post` med linjerne allerede væk), uden synkronisering slettes placeholder-posten med direkte SQL uden WordPress-hooks → `woocommerce_delete_order($id)`. Ledgerens egne data kan altid læses. Samme ordre kan derfor ramme både WooCommerce- og WordPress-hooket; lukningen beregnes kun fra ledgeren, og den anden kørsel finder ingen åben cyklus (no-op).
- **D. Sletning af en refund:** legacy: `WC_Order_Refund_Data_Store_CPT::delete()` → `wp_delete_post()` (`before_delete_post` med refunden stadig til stede) → `woocommerce_delete_order_refund($id)` (efter sletningen; ingen ordre-ID). Admin-AJAX affyrer derefter `woocommerce_refund_deleted($refund_id, $order_id)` i begge datalagre. HPOS: `OrdersTableRefundDataStore::delete()` fjerner refunden fra HPOS-tabellerne og kalder med synkronisering `wp_delete_post()` (så `before_delete_post` med refunden allerede fjernet), uden synkronisering intet hook. Alle triggere kalder den samme synkronisering, som ser en ledger-refund, der ikke længere findes i WooCommerce, og skriver én korrektion med nøglen `c-rd:…`; gentagne triggere afvises af `UNIQUE`.
- **Fejlmarkøren og re-entrance:** `WC_Data::save_meta_data()` affyrer kun `added_/updated_/deleted_order_meta`. Under HPOS gemmer datalagret dog ordren efter en meta-ændring (`after_meta_change()`, medmindre ordren er ændret i samme sekund), hvilket affyrer `woocommerce_after_order_object_save`. Der findes intet filter for at gøre nøglen "ephemeral". Synkroniseringen skal derfor have en re-entrancy-vagt pr. ordre i requesten, så en markørskrivning ikke udløser en ny synkronisering; et scoped `woocommerce_orders_table_datastore_should_save_after_meta_change`-filter under markørskrivningen kan desuden undgå den ekstra gemning (valg ved implementeringen).

### Resterende OPEN (ikke kritiske for skemaet)
- **`@@in_transaction` på MySQL 8.0:** verificeret på MariaDB 10.11.19 (projektets testdatabase), men ikke på MySQL 8.0, som kun kører i CI. Skal verificeres i CI, når registreringen implementeres. Findes variablen ikke på MySQL 8.0, er vagten mod tredjeparts-transaktioner kun aktiv på MariaDB, eller den erstattes; det dokumenteres som en begrænsning. Påvirker ikke skemaet.
- **Timeout for den navngivne lås** fastlægges ved implementeringen (kort; ingen ventekø). Påvirker ikke skemaet.

### Statusnote 2026-09-28: ROADMAP trin 3 (migration 5 og `SalesRepository`)
Besluttet af projektejeren efter implementeringen af trin 3. Teksten ovenfor er bevaret som historik; noten præciserer den.

- **Dublet = idempotent no-op (præciserer pkt. 18):** pkt. 18's "en dublet-konflikt … giver rollback og fejlmarkør" gælder ikke en almindelig dublet. En række, hvis `event_key` (pkt. 17) eller `(order_id, cycle, event)` (pkt. 14) allerede er gemt, er en allerede registreret hændelse. `SalesRepository::write()` springer den over, og transaktionen fortsætter og committer de øvrige nye rækker og cyklushændelser; resultatet angiver de oversprungne nøgler. Begrundelse: en deterministisk `event_key` gør det sikkert at afspille hooks igen (gentagne og overlappende triggere, fund C og D), så en dublet er forventet og ikke en fejl. Reelle databasefejl, lås-timeout, deadlock og en skiftet forbindelse giver fortsat rollback (og fejlmarkør, når recorder-flowet findes).
- **Timeout og `@@in_transaction` (afklarer to OPEN-punkter ovenfor):** låsens timeout er 5 sekunder. `@@in_transaction` bruges ikke; forbindelsen sikres i stedet med `CONNECTION_ID()` (hver INSERT er betinget af låsens forbindelse, og forbindelse og låsejer kontrolleres før og efter COMMIT), fordi `wpdb` kan genforbinde stille og gentage en sætning uden for transaktionen (fund B).
- **OPEN: fremmed transaktion på samme forbindelse.** `Transaction::run()` sender `START TRANSACTION`/`COMMIT` på den fælles `wpdb`-forbindelse. Der er i dag ingen eksplicit vagt mod, at anden kode allerede har åbnet en transaktion på den forbindelse; `START TRANSACTION` ville så committe den implicit. `Transaction`'s egen vagt mod indlejring dækker kun dens egne transaktioner. `@@in_transaction` genindføres ikke nu alene for at lukke punktet. Implementeringen må ikke antage, at en ekstern transaktion ikke findes. Punktet skal afklares (design og verifikation), før ledgeren skrives fra recorder-flowet.

### Statusnote 2026-09-28: designbeslutninger for recorderen (ROADMAP trin 4–6)
Besluttet af projektejeren efter discovery af WooCommerce 10.3.8 og en midlertidig CI-probe. Noten supplerer pkt. 13–23 og statusnoten ovenfor; den ældre tekst er bevaret som historik. Intet af dette er implementeret.

- **Trinfordeling:** trin 4 er den rene planlægningslogik (ordre-snapshot + ledger-tilstand → rækker og cyklushændelser, uden database og hooks). Trin 5 er synkroniseringen pr. ordre, hooks og fejlmarkøren. Trin 6 er konfigurationen af tællende statusser.
- **B1 – pengearitmetik:** internt heltal i 1/10000 enheder. Ingen float og ingen afhængighed af `bcmath`. Konverteringen til og fra WooCommerce' decimalstrenge er deterministisk og dækkes af unit-tests.
- **B2/B3 – creator-reglen (normativ):**
  - En ordrelinje følges, hvis den har ledger-rækker, eller hvis dens produkt har en gyldig creator nu.
  - Når en linje får sin første ledger-række, gemmes den aktuelle creator som snapshot. Har linjen ledger-historik, bestemmes creator altid af dette snapshot; refunds, reversals, korrektioner og senere cyklusser for linjen bruger det.
  - En linje uden historik kan få sin første creator i den **aktive** cyklus, også når produktet først er koblet, efter ordren blev oprettet. En linje får aldrig en ny creator i en lukket cyklus, og en senere kobling skaber aldrig en retroaktiv række i en lukket cyklus. En refund for en linje uden historik registreres derfor ikke i en lukket cyklus.
  - Eksisterende ledger-rækker ændres aldrig, når produktets creator ændres.
- **B4 – fremmed transaktion (lukker OPEN-punktet i statusnoten ovenfor som dokumenteret restrisiko):** der implementeres ingen database-specifik transaktionsvagt. `@@in_transaction` kan ikke bruges portabelt: på MariaDB 10.11.19 svarer den 0/1 som forventet, men MySQL 8.0.46 svarer "Unknown system variable 'in_transaction'" (midlertidig CI-probe, run 36430252054, lukket uden merge). `information_schema.INNODB_TRX` er afvist: den viste ingen transaktion efter en fremmed INSERT, selv med fulde rettigheder, og kræver på MySQL rettigheden `PROCESS`. WooCommerce 10.3.8's relevante ordreflows har ingen åben transaktion, når vores hooks kører (transaktionen i `PostsToOrdersMigrationController` committes, før hooket affyres, og affyrer ingen hooks undervejs). Et tredjeparts-plugin kan stadig have åbnet en transaktion på samme `wpdb`-forbindelse; så committer vores `START TRANSACTION` den implicit (påvist på MariaDB). Det er en dokumenteret restrisiko, ikke en garanti. Der indføres ingen MariaDB-only-adfærd, og `Transaction::run()` forsøger fortsat ikke at detektere eller håndtere situationen. Forbindelsessikringen (`GET_LOCK()`, `CONNECTION_ID()` og de betingede INSERTs) bevares uændret.
- **B5 – afvist:** ingen samling af synkroniseringer pr. request og ingen kørsel ved `shutdown`. Registreringen forbliver synkron med hook-flowet, med synkronisering pr. ordre under ordrelåsen. Begrundelse: `shutdown` gør ledgeren best-effort ved fatale fejl og afbrudte processer, gør fejlmarkering og genbehandling vanskeligere, ændrer den dokumenterede synkrone hook-model og løser ikke generelt problemet med tredjeparts-transaktioner.
- **B6 – sletning af refunds med et kendt slettet refund-ID (præciserer pkt. 21):**
  - Triggere: `woocommerce_refund_deleted` (refund- og ordre-ID), `woocommerce_delete_order_refund` (refund-ID; ordren findes via ledgerens `refund_id`) og `deleted_post` for posttypen `shop_order_refund` (ordren via `post_parent`). `before_delete_post` bruges ikke som trigger for sletning af refunds; i legacy findes refunden stadig, når det affyres. Sletning af ordrer er uændret (pkt. 21).
  - Synkroniseringen modtager ordre-ID'et og eventuelt et `deleted_refund_id`. Planneren behandler netop det refund-ID som fraværende, uanset hvad WooCommerce' objekt- eller query-cache stadig viser.
  - Begrundelse: i legacy-datalageret kalder refundens `delete()` `wp_delete_post()`, før refund-listens cache slettes, og WordPress affyrer `deleted_post` før `clean_post_cache()`. Når `deleted_post` kører, kan `get_refunds()` og en cachet forespørgsel derfor stadig vise den slettede refund; ved en direkte `wp_delete_post()` rydder WooCommerce aldrig refund-listen. Det kendte slettede refund-ID har derfor forrang for den observerede cache-tilstand.
  - WooCommerce' egne caches manipuleres ikke. Den generelle læsning af refunds er CRUD-baseret.
  - Den accepterede begrænsning består: sletning af en refund via REST under HPOS uden synkronisering har intet hook (pkt. 22).
- **B7 – fejlkoder for `_merchboard_ledger_failure`:** `lock_timeout`, `db_error`, `in_transaction`, `unresolved_refund_item` (pkt. 20) samt `connection_changed`, `negative_balance`, `currency_mismatch` og `unexpected`. `in_transaction` bevares som kode, men der er ingen automatisk detektion (B4).
- **Tællende statusser (midlertidig standard):** trin 5 bruger den faste liste `processing` og `completed`, centraliseret i Sales-laget. Det er ikke en offentlig indstilling, og `wc_get_is_paid_statuses()` bruges ikke som kilde (den kan ændres med et filter). Trin 6 kan erstatte listen med den konfigurerbare løsning fra ADR-037 pkt. 4.

### Statusnote 2026-09-29: ROADMAP trin 4 afsluttet (planlægningslogik)
Bekræftet af projektejeren efter implementeringen af den rene planlægningslogik (`SalesPlanner`). De to fortolkninger nedenfor er normative for trin 5. Teksten ovenfor er bevaret som historik.

1. **En åben cyklus med balance 0 lukkes ikke af sig selv.** En cyklus lukkes ikke alene, fordi dens balance bliver 0; så længe ordren er i en tællende status, kan den forblive åben. En cyklus lukkes ved overgang ud af tællende status eller ved permanent sletning af ordren. Invarianten er uændret: en lukket cyklus summerer til 0. Der oprettes fortsat ingen kunstig reversal på 0/0 (pkt. 3).
2. **Cyklus 1 kan åbnes ved balance 0.** Cyklus 1 åbnes, så snart mindst én linje har en creator, også når den beregnede brutto- eller nettobalance er 0. Formålet er at etablere linjens snapshot (creator, produkt, variant og navn), som kun kan gemmes i en ledger-række, uden en kunstig reversal. Senere cyklusser kræver fortsat en positiv ny balance. En refund for en linje uden historik i en lukket cyklus registreres fortsat ikke (B2/B3).

### Statusnote 2026-09-29: beslutninger for ROADMAP trin 5 (synkronisering, hooks og fejlmarkør)
Besluttet af projektejeren efter en read-only discovery af WooCommerce 10.3.8 (kildekode) og af projektets kode. Supplerer pkt. 20–22 og B4–B7; teksten ovenfor er bevaret som historik. Intet af dette er implementeret.

1. **Q1 – refund-hooks (præciserer B6):** `woocommerce_delete_order_refund` bruges ikke. Sletning af en refund synkroniseres via `deleted_post` for posttypen `shop_order_refund` (ordren via `post_parent`) og `woocommerce_refund_deleted` (refund- og ordre-ID). Begrundelse: i WooCommerce 10.3.8 affyres `woocommerce_delete_order_refund` kun i legacy-datalageret og altid lige efter `wp_delete_post()`, som allerede har affyret `deleted_post`; hooket tilfører intet. `deleted_post` dækker legacy-sletning (også en direkte `wp_delete_post()`) og HPOS med synkronisering; `woocommerce_refund_deleted` dækker sletning via WooCommerce-admin i begge datalagre, også HPOS uden synkronisering. Begrænsningen for REST-sletning under HPOS uden synkronisering består (pkt. 22). B6's liste med tre triggere er historik.
2. **Q2 – skiftet forbindelse:** `SalesRepository` kaster en særskilt `SalesConnectionChangedException`, når forbindelsen eller låsejeren har skiftet, så fejlen klassificeres som `connection_changed` ud fra typen og ikke ud fra en fejlbesked. Ændringen i repositoryet er begrænset til denne fejltype.
3. **Q3 – uninstall af fejlmarkøren:** opt-in-uninstall (ADR-025) sletter `_merchboard_ledger_failure` fra legacy-postmeta med WordPress' meta-API og fra HPOS' ordremeta kun via WooCommerce CRUD. Er WooCommerce deaktiveret under uninstall, kan markører i HPOS' meta-tabel blive liggende; det er accepteret. Ingen direkte SQL mod WooCommerce-tabeller.
4. **Q4 – teststrategi:** de scenarier, der kræver en rigtig WooCommerce (HPOS og legacy, `wc_create_refund()`, sletning af refunds via admin, papirkurv, gendannelse og permanent sletning, forældet refund-cache, hook-rækkefølgen), verificeres i Merch Dashboard Local med scriptede WP-CLI-scenarier (ROADMAP trin 8, kræver projektejerens godkendelse). Integrationssuiten tester synkroniseringen mod en rigtig database med en test-reader i stedet for WooCommerce. Der indføres ingen WooCommerce-dev-dependency og ingen CI-ændring alene for at få WooCommerce i test-suiten.
5. **Q5 – sletningsvagt (implementeringsregel):** synkroniseringen fører pr. request en markering `deleting[order_id]`. Når sletningen af en ordre er startet, behandles alle senere kald for den ordre i samme request som en slettet ordre (kun ledgeren, pkt. 21) og må ikke genåbne en cyklus. Begrundelse: WooCommerce sletter en ordres refunds under sletningen af ordren; `deleted_post` for de refunds kører, mens ordren endnu kan læses med sin tællende status, og ville ellers åbne en ny cyklus.
6. **Q6 – ugyldige værdier:** en tom eller ugyldig beløbsstreng (også float og eksponent-notation) og et brøkdelt antal giver fejlkoden `unexpected`, og intet skrives. Der gættes ikke på 0, og antal afrundes ikke. Readeren læser beløb, antal og status med konteksten `edit`, så filtre ikke ændrer værdierne.
7. **Q7 – andre ordrer:** udløses en synkronisering for en anden ordre, mens en synkronisering kører, udføres den synkront under sin egen ordrelås. Der er ingen kø, intet retry og ingen udskudt behandling. *(Præciseret samme dag, se "Præcisering af Q7" nedenfor: det gælder kun, når den eksisterende låse- og transaktionskontekst tillader det.)* Den samme ordre springes over af vagten mod genindtræden (pkt. 20 og fund om fejlmarkøren).
8. **Q8 – papirkurv:** en ordre med status `trash` springes over; der synkroniseres intet, og der sættes ingen fejlmarkør (ADR-037: papirkurven har ingen effekt).

**Præcisering af Q7 (2026-09-29, besluttet af projektejeren, løsning a):** Q7 lovede oprindeligt, at en synkronisering af en anden ordre altid udføres synkront under sin egen ordrelås. Det kan den eksisterende kode ikke garantere: `SalesRepository::with_order_lock()` afviser en indlejret lås på samme instans, og `Transaction::run()` afviser en indlejret transaktion. Derfor gælder:

- En synkronisering af en anden ordre udføres synkront under sin egen ordrelås, **når den eksisterende låse- og transaktionskontekst tillader det**, fx når den ydre ordres lås allerede er frigivet (som under skrivningen af fejlmarkøren).
- Afvises et indlejret kald af repositoryets eller transaktionens beskyttelse, håndteres fejlen for den berørte ordre som enhver anden synkroniseringsfejl: fejlmarkør på den ordre og teknisk log uden persondata. Der er ingen automatisk retry og ingen skjult kø; ordren rettes ved næste synkronisering eller ved eksplicit genbehandling.
- En separat `SalesRepository`-instans pr. ordre løser ikke begrænsningen, fordi `Transaction::run()` stadig afviser en transaktion inden i en anden på samme forbindelse. Transaktionsarkitekturen ændres ikke i trin 5.
- Discovery fandt intet WooCommerce 10.3.8-flow, hvor en anden ordre synkroniseres, mens den ydre lås holdes: readeren og planneren gemmer intet i låsens vindue.

### Statusnote 2026-09-29: ROADMAP trin 5 afsluttet (implementering og Q4-verifikation)
Status, ingen nye beslutninger. Trin 5 er implementeret efter pkt. 13–23, B1–B7 og Q1–Q8 (med præciseringen af Q7). Q4's runtime-verifikation er udført i Merch Dashboard Local (WordPress 7.1.2, WooCommerce 10.3.8) med syntetiske data: 25/25 checks i HPOS og 25/25 i legacy. Verificeret: oprettelse direkte i `processing`, statusskift og gentagne gemninger (idempotens), delvis og fuld refund via `wc_create_refund()` (fuld refund lukker uden 0/0-reversal), sletning af refunds via admin-vejen (`woocommerce_refund_deleted`) og i legacy også `deleted_post` uden dubletter, refund-sletning uden hook under HPOS (ingen korrektion før næste gemning, derefter præcis én; pkt. 22), den forældede refund-cache i legacy (`get_refunds()` viste stadig den slettede refund, `wc_get_orders()` ikke; ledgeren fik præcis én `c-rd`), papirkurv, gendannelse og permanent sletning (én reversal og `close`), fejlmarkørens livscyklus uden rekursion, og at refund-objektets egen gemning ikke synkroniseres. Q7-begrænsningen (en indlejret synkronisering af en anden ordre afvises og giver fejlmarkør og log på den ordre, uden retry eller kø) er verificeret i integrationssuiten. Uninstall-oprydningen af markøren er verificeret isoleret; i legacy ignorerer `wc_get_orders()` `meta_query`, så forespørgslen er rettet til `meta_key` + `meta_compare => 'EXISTS'` (Q3 uændret: kun meta-API og WooCommerce CRUD). MySQL 8 verificeres i CI efter push.

### Statusnote 2026-09-29: beslutninger for ROADMAP trin 6 (indstillingen for tællende statusser)
Besluttet af projektejeren efter discovery. Fastlægger placering og capability, som ADR-037 (Consequences) lod stå til dette trin, og erstatter den midlertidige faste liste fra statusnoten 2026-09-28. Den ældre tekst er bevaret som historik. Intet af dette er implementeret.

- **S1 – placering:** nøglen `counted_order_statuses` (S13) i den eksisterende option `merchboard_settings`; ingen separat option. Core `Settings` validerer kun format og kender ikke WooCommerce. Service, validering mod WooCommerce og UI ejes af WooCommerce-modulet. Settings-siden får udvidelsespunktet `merchboard_settings_sections` (action, kaldes efter capability-tjekket; sektionerne tjekker selv capability, har egne formularer og nonces og escaper selv), som Creators-sidens `merchboard_creators_edit_sections`.
- **S2 – capability:** `merchboard_manage_settings`; ingen ny capability. I admin-post-flowet tjekkes capability før nonce, som i `SettingsActions`.
- **S3 – whitelist ved gemning:** alle aktuelt registrerede WooCommerce-statusser (S14), også fra andre plugins, undtagen den faste afvisningsliste `pending`, `failed`, `cancelled`, `refunded`, `checkout-draft` og `trash`. Statusser gemmes som slugs uden `wc-`; dubletter fjernes, og rækkefølgen normaliseres deterministisk, så samme semantiske liste aldrig giver en falsk ændring eller audit.
- **S4 – tom liste:** afvises før audit og option; der skal altid være mindst én gyldig tællende status.
- **S5 – audit:** `settings_updated` med `setting` = `counted_order_statuses`, `previous_value` og `value` som lister (arrays). Audit og option skrives atomisk i det eksisterende transaktionsmønster. Audit-visningen viser flade lister af skalarer som `a, b` (kun for `previous_value` og `value`); indlejrede eller associative arrays vises fortsat som `…`, og al øvrig visning er uændret. Nøglen får labelen "Setting: counted order statuses".
- **S6 – "kun fremadrettet" (ADR-037 pkt. 4):** Ændringen gælder for synkroniseringer efter ændringen – også af eksisterende ordrer, ikke kun nye ordrer. Der sker ingen masse-genberegning og ingen backfill; en ordre vurderes efter den aktuelle liste, næste gang den synkroniseres, og kan så få reversal og lukning eller en ny cyklus med behandlingstidspunktet som `occurred_at` (følger af den tilstandsbaserede synkronisering, ADR-038 pkt. 13). Historiske rækker ændres aldrig. Standardværdien er præcis `['processing', 'completed']`.
- **S7 – statusser, der ikke længere er registreret:** bevares i den gemte liste og vises i UI som "ikke registreret"; de fjernes aldrig stiltiende, så genaktivering af det plugin, der leverer statussen, genopretter adfærden.
- **S8 – afvisningsliste ved læsning:** `CountedOrderStatuses` håndhæver den samme faste afvisningsliste ved læsning som forsvar mod direkte eller manipuleret option-data. WooCommerce-modulet ejer valideringen.
- **S9 – ugyldig eller tom gemt værdi:** er den gemte værdi efter formatvalidering tom eller uden tilladte statusser, falder `CountedOrderStatuses` tilbage til standarden `['processing', 'completed']`. Korrupte option-data kan aldrig gøre alle ordrer ikke-tællende.
- **S10 – gyldige valg ved gemning (præciserer S7):** registrerede statusser minus afvisningslisten plus statusser, der allerede står i den gemte liste, men ikke længere er registreret. De sidste kan bevares eller fravælges, men ikke tilføjes som nye. Ved læsning tæller de, medmindre de står på afvisningslisten.
- **S11 – placering på siden:** sektionen "Sales ledger" står efter dashboard-slug og før "Data on uninstall".
- **S12 – notices:** sektionen viser sine egne notices via egne query-argumenter; `SettingsException` udvides ikke.
- **S13 – nøgle:** præcis `counted_order_statuses`.
- **S14 – registrerede statusser:** kilden er `wc_get_order_statuses()`; dens nøgler har `wc-`-præfiks, mens den gemte værdi og Sales-laget bruger slugs uden. Core validerer kun formatet (små bogstaver, cifre og `-`, som WooCommerce' slugs); WooCommerce-modulet afgør registrering og afvisning.

Uændret: `CountedOrderStatuses` er det eneste sted, hvor en status afgøres som tællende; planneren kender ikke indstillingen (dens API og domænemodel er uændrede); ingen databaseændringer, ingen nye WooCommerce-hooks, ingen backfill eller historisk genberegning, ingen nye dependencies, og HPOS/legacy-arkitekturen er uændret.

### Statusnote 2026-09-30: S14 præciseret (format og længde for status-slugs)
Besluttet af projektejeren efter en skrivebeskyttet gennemgang af WordPress 7.1.2 og WooCommerce 10.3.0. Præciserer S14 (og dermed S3); den oprindelige tekst er bevaret som historik. Alle øvrige S1–S14-regler, herunder afvisningslisten, fallback og "kun fremadrettet", er uændrede.

- **Baggrund:** S14 begrænsede formatet til små bogstaver, cifre og `-` "som WooCommerce' slugs". Det er for snævert: WordPress' `register_post_status()` og `pre_post_status` bruger `sanitize_key()`, som også beholder `_`, og WooCommerce accepterer enhver nøgle i `wc_get_order_statuses()`. En registreret underscore-status blev derfor udeladt uden forklaring, i strid med S3 ("alle aktuelt registrerede statusser"). Store bogstaver kan ikke bruges konsistent: WordPress gør post-statussen til små bogstaver, så en ordre med en sådan status gemmes forskelligt i legacy og HPOS.
- **Format:** en gyldig slug består af `a-z`, `0-9`, `_` og `-` (tegnsættet fra `sanitize_key()`), er ikke tom og starter ikke med `wc-`. Store bogstaver afvises fortsat. Underscores bevares ved lagring, læsning, sammenligning og audit.
- **Registrerede statusser:** `wc_get_order_statuses()` er fortsat kilden. En status regnes kun som registreret, hvis nøglen har præfikset `wc-`; præfikset fjernes, før sluggen valideres og gemmes. Nøgler uden `wc-` tilbydes aldrig (WooCommerce' `set_status()` kan kun sætte statusser, hvis `'wc-' . status` er en nøgle).
- **Længde:** højst 17 tegn uden `wc-`. Både `wp_posts.post_status` (legacy) og `wc_orders.status` (HPOS, WooCommerce 10.3.0 og trunk) er `varchar(20)` og gemmer statussen med `wc-`, så 20 − 3 tegn opfører sig ens i begge lagre.
- **Ikke-understøttede statusser:** registrerede nøgler uden `wc-`, med ugyldigt format eller over længdegrænsen vises særskilt som "Not supported" med årsag. De kan hverken vælges eller tilføjes. Gemte værdier, der er gyldige efter disse regler, bevares.
- **Uændret:** ingen databaseændringer, ingen nye hooks, ingen nye dependencies.

### Statusnote 2026-09-30: ROADMAP trin 6 afsluttet (implementering og runtime-verifikation)
Status, ingen nye beslutninger. Trin 6 er implementeret efter S1–S14 med præciseringen af S14 fra statusnoten 2026-09-30: en gemt status-slug består af `a-z`, `0-9`, `_` og `-` (tegnsættet fra WordPress' `sanitize_key()`), er ikke tom, har intet `wc-`-præfiks og er højst 17 tegn, fordi både `wp_posts.post_status` (legacy) og `wc_orders.status` (HPOS) er `varchar(20)` inkl. `wc-`. Registrerede nøgler kræver `wc-`; store bogstaver afvises; underscore understøttes; registrerede statusser, der ikke kan understøttes, vises med årsag under "Not supported" og kan ikke vælges.

Automatiske tests: PHPUnit 1183, Jest 136/136, integration 223 (MariaDB 10.11.19), `npm run lint` og `git diff --check` grønne. MySQL 8 verificeres i CI efter push.

Runtime-verifikationen er udført i Merch Dashboard Local (WordPress 7.1.2, WooCommerce 10.3.8) med syntetiske data: 41/41 checks i HPOS og 41/41 i legacy. Verificeret: sektionens placering mellem dashboard-slug og "Data on uninstall", standardvalget `processing` + `completed`, at afviste statusser ikke kan vælges, at understøttede registrerede statusser (også en underscore-status) tilbydes, at ikke-understøttede registreringer (store bogstaver, nøgle uden `wc-`, over 17 tegn) vises med årsag og uden afkrydsning, gemning med præcis én `settings_updated` (lister i `previous_value`/`value`), ingen audit ved samme statusmængde i en anden rækkefølge, afvisning af en tom liste og af ikke-tilladte statusser uden ændringer, afvisning af en forfalsket nonce (403), ingen genberegning eller backfill ved en ændring, og at en ordre vurderes efter den nye liste ved sin næste synkronisering (ny tællende status åbner en cyklus, en fjernet status giver reversal og lukning, usynkroniserede ordrer er uændrede). I legacy er underscore-statussen gemt uændret som `wc-<slug>` i `wp_posts`.

Efterkontrol: Action Scheduler-jobbet `merchboard_reconcile_product` for det slettede testprodukt (job 164, oprettet af produktsletningen) kørte ved sitets normale async-behandling og står som `complete` uden fejl eller retry; handleren fandt ikke produktet og skrev intet, og der var ingen nye plugin- eller WooCommerce-dataændringer. Testens append-only auditspor efterlod 8 nye audit-records; Action Scheduler-historikken indeholder de relevante færdige jobs (`complete`); der er ingen andre ventende eller fejlede `merchboard`-jobs. Alle øvrige testdata (testordrer og ledger-rækker, testprodukt, testbruger, midlertidig mu-plugin til teststatusser) er fjernet, den syntetiske creator er inaktiv igen, og `merchboard_settings` er tilbage i sin oprindelige tilstand. Trin 6 er dermed afsluttet.

### Statusnote 2026-09-30: beslutninger for ROADMAP trin 7 (backfill), D1–D6
Besluttet af projektejeren efter en read-only foranalyse og et beslutningsgrundlag for D1–D6. Præciserer ADR-037 pkt. 5, 6, 9 og 11 og ADR-038 pkt. 9 og 22; den ældre tekst er bevaret som historik. Uændret: planneren og domænemodellen, den tilstandsbaserede synkronisering (pkt. 13), lås og transaktion (pkt. 18), `event_key` (pkt. 17), creator-reglen (B2/B3), "kun fremadrettet" (ADR-037 pkt. 4, S6) og afstemning uden for fase 18 (ADR-037 pkt. 11). Ingen migration, ingen nye WooCommerce-hooks, ingen direkte SQL mod WooCommerce' ordrelager og ingen nye dependencies.

- **D1 – hvad backfill må ændre:** backfill registrerer kun ordrer **uden ledger-historik** (ingen cyklus, `latest_cycle() = 0`). Eksisterende ledger-historik ændres aldrig af backfill; det kontrolleres også under ordrelåsen, så en ordre, som en live-hook registrerer imens, ikke røres. En historisk ordre, der tæller nu, kan få sin første historik (cyklus 1 som brutto plus eksisterende refunds, pkt. 15, med `occurred_at` efter pkt. 19); en historisk ordre, der ikke tæller nu, får ingen rækker. Tidligere statusforløb rekonstrueres ikke, eksisterende cyklusser genberegnes ikke, og backfill er ikke afstemning. Eksplicit genbehandling af fejlmarkerede eller udvalgte ordrer med historik er ikke en del af trin 7.
- **D2 – udvælgelse:** WooCommerce CRUD via `wc_get_orders()` med `type = shop_order`, `return = ids`, `orderby = ID` stigende og `limit`/`offset` (begge datalagre; `field_query` findes kun i HPOS, så der er ingen portabel "id større end"-cursor). Der scannes de tællende statusser (med `wc-`-præfiks, så også gemte, ikke-registrerede statusser matcher); listen tages ved kørslens start, og synkroniseringen afgør pr. ordre, hvis statussen er ændret imens. `trash` er altid udelukket. Ingen `--from`/`--to`. Accepteret begrænsning: slettes en ordre under en kørsel, kan offset-pagineringen springe en senere ordre over; en ny kørsel registrerer den (backfill er idempotent).
- **D3 – dry-run:** læser ordrer og ledger-tilstand og bruger samme planner, men skriver ingen ledger-rækker, tager ingen ordrelås, sætter ingen fejlmarkør, skriver ingen log og intet audit. Resuméet er kun tællinger: scannede ordrer, ordrer der ville blive registreret, oversprungne pr. årsag (har historik, tæller ikke, ingen creator, findes ikke, i gang), rækker pr. type (`sale`, `refund`, `reversal`, `correction`) og fejl pr. kode. Ingen beløb.
- **D4 – synkroniseringens API:** den eksisterende live-kontrakt `SalesSync` er uændret. Backfill har en separat indgang (`SalesBackfillSync`: `backfill()` og den skrivebeskyttede `preview()`), som genbruger samme læsning, lås, transaktion, planner, fejlmarkør og log som den live synkronisering; der er ingen parallel ledger-algoritme.
- **D5 – audit:** én ny action, `sales_backfill_completed`, via `record_system` (actor NULL) med `run_id`, evt. `trigger_user_id` og kun tællinger: scannede, pr. udfald, rækker pr. type og fejl samlet og pr. kode. Ingen ordre-ID'er, ingen beløb og intet audit pr. ledger-række. Skrives kun, når sidste side af en kørsel er behandlet; dry-run og stoppede kørsler skriver den ikke.
- **D6 – CLI og Action Scheduler:** `wp merchboard sales-backfill start` sætter en kørsel i kø i Action Scheduler (hook `merchboard_sales_backfill`, gruppe `merchboard`); kørslen sker side for side med cursoren i jobbets payload. `dry-run` kører inline, fordi den intet skriver; `status` og `resume` findes også. Kun én aktiv kørsel ad gangen (et nyt run og en genoptagelse afvises, mens et backfill-job venter eller kører). Ingen automatisk retry: en fejl i én ordre håndteres af synkroniseringen (rollback, fejlmarkør, log), og kørslen fortsætter; en fejl på en hel side markerer jobbet `failed` med uændret cursor; en systemfejl skriver ikke `sales_backfill_completed`. Ventende backfill-jobs annulleres ved deaktivering og uninstall (kun dette hook; andre Merch Dashboard-jobs røres ikke). Intet admin-UI og ingen ny capability (WP-CLI forudsætter shell-adgang). Kommandoens eneste option er `--batch-size` (1–200, standard 50).
- **Præcisering af D6 (besluttet 2026-09-30 efter runtime-verifikationen):** Action Schedulers egen oprydning sletter jobs med status `canceled`, og et annulleret job, der aldrig har kørt, slettes allerede ved næste oprydning (dets `last_attempt_gmt` er tom). En kørsel, der afbrydes ved deaktivering eller uninstall, garanteres derfor **ikke** at kunne genoptages fra sin cursor. Forskellen er:
  - **Eksplicit genoptagelse af et bevaret `failed` job:** en side, der fejler, efterlader sit job som `failed` med den uændrede cursor (Action Scheduler sletter som standard ikke `failed`-jobs). `wp merchboard sales-backfill resume` sætter netop den payload i kø igen og fortsætter kørslen fra den side.
  - **Genstart forfra, når payloaden er fjernet:** er jobbet (fx et annulleret job efter deaktivering) fjernet af Action Schedulers oprydning, findes cursoren ikke længere. Kørslen startes da forfra med `start`; backfillens idempotens (D1, `event_key`) gør det datasikkert. Findes det annullerede job endnu, kan `resume` bruge det, men det er ingen garanti.
  - Der indføres ingen ny persistent cursor-lagring, og ledger-semantikken ændres ikke for at understøtte genoptagelse. Afgørelsen af, om noget kan genoptages, bygger på Action Schedulers bevarede jobs; sletter oprydningen senere (efter opbevaringsperioden) de fuldførte jobs fra en nyere kørsel, kan et ældre `failed` job igen fremstå som genoptageligt. En genoptagelse er idempotent, men skriver et resumé for den ældre kørsel.

### Statusnote 2026-10-01: ROADMAP trin 7 afsluttet (commit og CI)
Status, ingen nye beslutninger. Trin 7 er implementeret efter D1–D6 med præciseringen af D6 og er committet og pushet som `00fb066`; CI-run `36887796803` er grøn på alle jobs (PHPCS og PHPStan, PHPUnit 1253, Jest 136/136, integration 231 på MySQL 8.0.46, PHP 7.2-syntakstjek). Runtime-verifikationen (2026-09-30, Merch Dashboard Local, WordPress 7.1.2, WooCommerce 10.3.8, syntetiske data) bestod i HPOS og legacy: dry-run uden skrivninger, første kørsel over flere sider, historik uberørt, ikke-tællende og creator-løse ordrer uden rækker, `occurred_at` fra `date_paid` og refundens dato, fejlmarkør og teknisk log ved en ordrefejl, en fejlet side genoptaget fra sin cursor, ét run ad gangen, resumé i audit-loggen, idempotent anden kørsel, annullering ved deaktivering med genstart forfra efter Action Schedulers oprydning og ingen efterladte backfill-jobs (legacy 24/24). De dokumenterede begrænsninger gælder uændret: offset-pagineringen kan springe en ordre over, hvis en ordre slettes under en kørsel; en kørsel afbrudt ved deaktivering eller uninstall garanteres ikke at kunne genoptages efter Action Schedulers oprydning; et ældre `failed` job kan fremstå som genoptageligt, når nyere fuldførte jobs er ryddet op; en side, der kører, når pluginet deaktiveres, kan ikke annulleres.

### Statusnote 2026-10-01: ROADMAP trin 8 afsluttet (runtime-regression)
Status, ingen nye beslutninger. Trin 8 er udført som en målrettet regressionsrunde på commit `00fb066` i Merch Dashboard Local (WordPress 7.1.2, WooCommerce 10.3.8) med syntetiske data, i HPOS og legacy, med projektejerens godkendelse. Alle checks bestod i begge datalagre:

- **Q4-scenarierne fra trin 5 (Q4):** ordre oprettet direkte i `processing`; statusskift og gentagne gemninger uden nye rækker; delvis og fuld refund via `wc_create_refund()` (fuld refund lukker uden 0/0-reversal); creator-snapshot (en senere kobling giver første salg i den åbne cyklus, en afkobling ændrer ikke historikken); sletning af refunds via admin-vejen med præcis én `c-rd`, også med en forudindlæst refund-liste, og sletning uden hook, som konvergerer ved næste gemning (balance = WooCommerce' netto); papirkurv uden ændring, gendannelse og gemning uden ændring, permanent sletning med én reversal og `close`; fejlmarkørens livscyklus (lås-timeout, gentaget fejl med uændret `first_at`, kodeskift til `currency_mismatch`, rydning ved succes, sletning under lås uden efterladt markør) med `lock_timeout` og `currency_mismatch` i den tekniske log (kun ID'er og kode); ingen falske markører.
- **Samspil mellem backfill og live-hooks:** en historisk ordre uden ledger-historik blev registreret via `wp merchboard sales-backfill start` og Action Schedulers egen kørsel (cyklus 1 med `date_paid`, creator fra koblingen, resumé `registered 1`); derefter en live delvis refund (`r:…` ved refundens dato), en ny backfill-kørsel uden ændring (`skipped_history`), sletning af refunden via admin-vejen (én `c-rd` med `refund_deleted`, balancen tilbage til ordrens netto), papirkurv og gendannelse uden ændring og permanent sletning med én reversal og `close`. Tidligere rækker blev aldrig ændret, og der opstod ingen fejlmarkør.
- **Afvigelser (dokumenteret, ingen fejl):** (1) sletning af refunds via admin blev verificeret som WooCommerce' `delete()` efterfulgt af `woocommerce_refund_deleted` – præcis det, `WC_AJAX::delete_refund()` gør – og ikke som en HTTP-AJAX-anmodning, som i trin 5; (2) den forældede refund-cache er en legacy-adfærd; HPOS bestod de samme checks, men har ikke den samme cache-adfærd, så HPOS-kørslen tester reelt ikke cachen.
- **Afgrænsning:** Q4-scriptets uninstall-del (fjernelse af markøren site-wide) blev ikke kørt igen; den er verificeret i trin 5 og hører ikke til trin 8. Den syntetiske testcreator fra trin 5 blev genbrugt (creators kan ikke slettes).
- **Oprydning:** alle syntetiske ordrer, refunds, ledger-rækker og produkter er fjernet (0 ordrer, 0 ledger- og cyklusrækker, 0 fejlmarkører), testcreatoren er inaktiv igen, og HPOS er slået til igen. Bevaret som forventet: append-only audit-records (testens oprettelse og fjernelse af koblinger, statusskift og backfill-resuméer) og Action Schedulers historik over færdige jobs. To ventende `merchboard_reconcile_product`-jobs for de slettede testprodukter er harmløse no-ops (handleren finder ikke produktet og skriver intet).

### Statusnote 2026-10-01: fase 18 afsluttet (ROADMAP trin 9)
Status, ingen nye beslutninger. Fase 18's Done-kriterium (integrationstests for salg, hel og delvis refund, annullering efter `completed` og gentagne hooks) er opfyldt af `tests/Integration/WooCommerce/SalesSynchronizerTest.php` (registrering af en ordre oprettet i tællende status, statusskift med reversal, lukning og ny cyklus, delvis og fuld refund med sletning af en refund og forældet cache, gentagne hooks uden nye rækker) sammen med planner-, repository- og backfill-testene og runtime-verifikationen i trin 5–8 (HPOS og legacy). Backfillens idempotens og genoptagelse efter D6 er dækket af `tests/Integration/WooCommerce/SalesBackfillTest.php` og `tests/Unit/Modules/WooCommerce/Sales/SalesBackfillJobsTest.php`. Dokumentationen er gennemgået for konsistens, i18n-kontrollen gav ingen utilsigtet diff, og den fulde suite er grøn; trin 9's ændringer committes, pushes og CI-verificeres som afslutning.

### Statusnote 2026-10-08: moms pr. ledger-række (ADR-048)
Ledgeren (`merchboard_sales`), dens invariant, nøgler, idempotens og tællende statusser er uændrede. Skrivevejen bærer nu også rækkens faktiske WooCommerce-moms og skriver den i den nye append-only-tabel `merchboard_sales_tax` (`event_key` → moms), i samme transaktion og med samme `CONNECTION_ID()`-vagt som rækken, og kun for rækker, der faktisk indsættes. Ukendt moms skrives ikke. Se ADR-048.

---

# ADR-039: Products og Orders i creator-dashboardet (fase 19)

## Date
2026-10-01

## Status
Accepteret (fase 19, trin 1, før implementering). Besluttet af projektejeren på grundlag af beslutningsoversigten P1–P12. Privacy-beslutningen (P5, P6 og øvrige kundeoplysninger) er bekræftet af virksomheden 2026-10-01: virksomhedsbekræftelse modtaget i projektbeslutningen (navn og rolle er ikke angivet). Besvarer åbent spørgsmål 6 (privacy). Supplerer ADR-007 (`/me`-scope), ADR-011 og ADR-037 (ledgeren som datakilde; pkt. 10 om `item_name`) og ADR-026 (dashboardets CSP, som ikke ændres). Ændrer ikke ADR-038. **Præcisering 2026-10-01 (se nederst):** P7's produktpris og moms.

## Context
Fase 19 skal give creators `GET /me/products` og `GET /me/orders` og sektionerne Products og Orders (ROADMAP). Done kræver IDOR-tests, og at privacy er bekræftet af virksomheden. Fase 18 har leveret en append-only sales ledger (`merchboard_sales`, `merchboard_sales_cycles`) med et creator-snapshot pr. række og `KEY(creator_id, occurred_at)`. Produktkoblingen er `_merchboard_creator_id` (ADR-030/031). Dashboardets CSP er `img-src 'self' data:` (`ShellController`). API.md fastlægger lister med `page`/`per_page` (max 100) og `X-WP-Total`/`X-WP-TotalPages`, og FRONTEND.md forbyder HTML fra serveren i dashboardet.

**Teknisk begrænsning i ledgeren:** en permanent sletning af en ordre med en åben cyklus skrives som den samme `reversal` (`v:{order_item_id}:{cycle}`) og `close` som en ordre, der ikke længere tæller (`SalesPlanner`, DATABASE.md's event-key-model). En ordre, der allerede var lukket, før den blev slettet, får ingen ny række. Ledgeren kan derfor ikke skelne permanent slettede ordrer fra almindelige reversal/close-forløb.

## Decision
1. **P1 – datakilde for Orders:** sales ledgeren er den eneste kilde til udvælgelse, creator-filtrering og paginering. Creator'en udledes server-side via `CreatorContext`, og filteret på `creator_id` ligger i selve SQL-queryen (ADR-007). Queryen er den samme under HPOS og legacy.
2. **P2 – rækkeenhed, antal og markeringer:** én række pr. ordrelinje, aggregeret over alle ledger-rækker for `order_id` + `order_item_id` på tværs af cyklusser, kun rækker med creator'ens `creator_id`.
   - **Antal** er linjens saldo i ledgeren: summen af rækkernes `quantity` (ADR-037 pkt. 3). Mens ordren har en åben cyklus, svarer den til WooCommerce' nettoantal (ordrelinjen minus refunds); når ordren ikke tæller, er den 0. Et købt bruttoantal vises ikke, fordi det ikke kan udledes entydigt (en genåbnet cyklus starter med det resterende netto).
   - **Refunderet** (`refunded`): linjen har mindst én `refund`-række (`r:{order_item_id}:{refund_id}`) uden en tilhørende `refund_deleted`-korrektion (`c-rd:{order_item_id}:{refund_id}`). `closed_cycle_refund` og `closed_cycle_refund_deleted` påvirker ikke markeringen. Der skelnes ikke mellem hel og delvis refund. Refunds uden ordrelinje står ikke i ledgeren (ADR-037 pkt. 7) og giver ingen markering.
   - **Ikke tællende** (`counted = false`): ordren har ingen åben cyklus i `merchboard_sales_cycles` (lige mange `open`- og `close`-rækker for `order_id`). Markeringen hører til ordren og gælder alle dens linjer. Den angiver ikke årsagen: ledgeren skelner ikke mellem annullering, fuld refund, andre ikke-tællende statusser og permanent sletning; årsagen vises alene via den live status (P4).
   - Begge markeringer kan gælde samtidig. En linje fjernet fra en tællende ordre (`line_removed`) har antal 0 uden markering.
   - Alt udledes af de eksisterende tabeller uden nye ledger-hændelser, uden migration og uden WooCommerce-opslag. Rå hændelser vises ikke i fase 19.
3. **P3 – felter:**
   - **Products:** navn, status, pris, lagerstatus og billede (produkt-ID kun som nøgle).
   - **Orders:** ordrenummer, dato, status, produktnavn og antal (plus markeringen fra P2). Datoen er `occurred_at` for linjens første salgsrække.
   - **Ingen salgsbeløb i fase 19** (hverken pr. linje eller summer). Beløb, valuta pr. aggregering og nøgletal hører til fase 20 (`/me/sales`).
4. **P4 – status og slettede ordrer (hybrid, løsning A):** ledgeren udvælger og paginerer; WooCommerce bruges kun til at berige de viste ordrer med live status og ordrenummer (højst ét ordreopslag pr. ordre på siden).
   - Findes ordren, vises dens WooCommerce-status og ordrenummer (`get_order_number()`).
   - Ligger ordren i papirkurven, vises den med status **"Trashed"**.
   - Findes ordren ikke længere (permanent slettet), vises den med status **"Deleted"**, og ordre-ID'et vises som fallback for ordrenummeret, fordi WooCommerce-ordrenummeret ikke kan hentes.
   - **Slettede ordrer filtreres ikke ud fra ledgeren** (se begrænsningen ovenfor). Der tilføjes **ingen ny ledger-hændelse** i fase 19, og der laves **ingen HPOS- eller legacy-specifik SQL-join** for at skjule slettede ordrer. `X-WP-Total` og `X-WP-TotalPages` beregnes alene fra ledgeren og forbliver korrekte.
5. **P5 – kundens land (privacy, bekræftet af virksomheden 2026-10-01):** kundens land vises **aldrig** i Orders-listen (hverken fakturerings- eller leveringsland).
6. **P6 – produktnavn i Orders (privacy, bekræftet af virksomheden 2026-10-01):** det aktuelle WooCommerce-produktnavn vises, når produktet findes (varianten, når linjen har en, ellers produktet). Er produktet slettet, må det gemte `item_name` fra salgstidspunktet vises (ADR-037 pkt. 10).
7. **Øvrige kundeoplysninger (privacy, bekræftet af virksomheden 2026-10-01):** ingen øvrige kundeidentificerende oplysninger vises i fase 19, herunder navn, e-mail, telefon, adresse, by, postnummer, kundenoter, IP-adresse, betalingsoplysninger og kunde- eller bruger-ID. Svarene indeholder kun felterne fra P3.
8. **P7 – produkter:** kun produkter med status `publish` og `private`, der er koblet til creator'en (kladder, `pending` og `trash` vises ikke). Prisen sendes som strukturerede data (strenge, aldrig floats, plus valuta); variable produkter som min/max-pris. Kun lagerstatus (`instock`, `outofstock`, `onbackorder`), ikke lagerantal. Der sendes ingen HTML, heller ikke fra `get_price_html()`; klienten formatterer.
9. **P8 – produktbilleder:** kun en thumbnail-URL med samme origin som dashboardet; ellers `null`, og klienten viser en pladsholder. Billedet får produktnavnet som `alt`. **Ingen CSP-udvidelse i fase 19** (`img-src 'self' data:` bevares).
10. **P9 – paginering og sortering:** `page` og `per_page` (standard 20, max 100) med `X-WP-Total` og `X-WP-TotalPages`, som API.md fastlægger. Fast sortering: Orders efter datoen fra P3, nyeste først (derefter `order_item_id` faldende); Products efter navn stigende (derefter ID). Ingen filtre og ingen søgning; andre query-parametre ignoreres.
11. **P10 – IDOR:** kun liste-endpoints (`GET /me/products`, `GET /me/orders`). En ordre med linjer fra flere creators viser kun creator'ens egne linjer, filtreret server-side i queryen. Intet `/me/orders/{id}` i fase 19; et senere detalje-endpoint skal svare 404 ved manglende ejerskab (ADR-007).
12. **P11 – WooCommerce inaktiv:** routes og dashboard-sektioner registreres af WooCommerce-modulet (sektionerne via `merchboard_dashboard_sections`). Uden WooCommerce er der hverken routes eller sektioner. Ledger-data bevares.
13. **P12 – caching:** ingen caching i fase 19 (ingen transients, options eller invalidering). Caching hører til fase 20.

## Åbent designpunkt (ikke del af denne ADR's implementeringsscope)
**Ekstra checkoutfelt identificeret med et ID:** virksomheden har åbnet mulighed for et ekstra checkoutfelt, som eventuelt kan vises i stedet for et navn. Det implementeres **ikke** under denne ADR, og ADR-039 lover ingen funktion for det. Uafklaret:
- om feltet er en del af fase 19 eller en senere fase;
- hvordan admin konfigurerer, hvilket checkoutfelt-ID der bruges;
- hvordan feltets værdi læses fra WooCommerce;
- hvilke validerings- og privacy-regler der gælder;
- om feltet må erstatte produktnavnet eller kun være et ekstra felt;
- hvordan feltet håndteres for gamle ordrer, slettede ordrer og manglende værdier.

Feltet kræver en særskilt beslutning (ny ADR eller dateret statusnote) før implementering.

## Alternatives considered
- **Live `wc_get_orders()` som datakilde (P1):** viser også ordrer, der aldrig har talt, tilskriver anderledes end salgsdataene og kræver forskellige queries for HPOS og legacy. Fravalgt.
- **Én række pr. ordre eller pr. ledger-hændelse (P2):** pr. ordre skjuler linjeniveauet; pr. hændelse er en Sales-visning (fase 20). Fravalgt.
- **Beløb i Orders i fase 19 (P3):** giver to steder at forklare omsætning og multi-valuta, før fase 20 har designet det. Udskudt.
- **Slettede ordrer skjult (P4):** via en SQL-join mod WooCommerces ordretabeller (afhænger af datalageret), efter det live opslag (forkerte totaler og ufulde sider) eller via en ny ledger-hændelse (ændrer ADR-038's skrivevej og virker ikke for allerede slettede ordrer). Fravalgt af projektejeren til fordel for løsning A.
- **Kundens land, altid `item_name` eller altid det aktuelle navn uden fallback (P5/P6):** fravalgt af virksomheden.
- **Alle produktstatusser, lagerantal eller `get_price_html()` (P7):** viser upublicerede planer, mere lagerdata end nødvendigt eller HTML i dashboardet. Fravalgt.
- **Udvidet CSP til uploads- eller CDN-host, eller ingen billeder (P8):** en CSP-ændring kræver et security-review; ingen billeder giver en ringere sektion. Fravalgt i fase 19.
- **Detalje-endpoint og filtre/søgning (P9/P10), caching (P12):** ikke nødvendige for fase 19's leverance. Udskudt.

## Reason
Ledgeren giver samme tilskrivning som salgsdataene, én query uafhængig af datalageret, korrekt paginering og creator-filtrering i SQL, så IDOR er udelukket i designet (ADR-007). WooCommerce bruges kun til berigelse af højst én side. Privacy følger virksomhedens bekræftelse og dataminimering: ingen kundedata, og `item_name` kun som fallback. Ingen HTML og ingen CSP-ændring holder dashboardets sikkerhedsmodel uændret.

## Consequences
- **Database:** ingen migration og intet nyt skema; Orders bruger `KEY(creator_id, occurred_at)`. Viser aggregeringen sig langsom på realistisk datamængde, kræver et nyt indeks en særskilt beslutning. Ingen nye options, meta eller jobs; lifecycle og uninstall er uændrede.
- **Backend:** pagineringshjælpere i `RestController` (første liste-route); en read-model for Orders på ledgeren; produktlisten kræver paginering med offset og total (`WooCommerceProductStore::linked_to()` har i dag kun en grænse). Status, ordrenummer og produktnavn læses live for højst `per_page` ordrer pr. side.
- **Frontend:** `client.ts` skal give total-headers videre (i dag returneres kun det validerede body); nye validatorer, typer, tabeller og paginering; statusserne "Trashed" og "Deleted" og ordre-ID'et som fallback skal kunne vises.
- **Ordrer før ledgeren:** kun ordrer, der er registreret i ledgeren, vises; ældre ordrer kræver, at backfillen er kørt (ADR-038, statusnote "ROADMAP trin 7"). Creator-snapshottet betyder, at en senere omkobling af et produkt ikke flytter tidligere ordrelinjer (ADR-037).
- **Slettede ordrer:** creators ser linjer for ordrer, som butikken har slettet, med status "Deleted" og ordre-ID'et i stedet for ordrenummeret.
- **Billeder:** sites med medier på en anden origin (CDN/offload) viser pladsholdere. Det er en dokumenteret begrænsning.
- **Tests:** integrationstests for begge endpoints efter API.md's krav 1–5, inklusive IDOR med en ordre med flere creators. WooCommerce-opslag læses gennem interfaces med fakes, fordi integrationsmiljøet ikke har WooCommerce. Den rigtige adfærd (HPOS og legacy, papirkurv, permanent sletning, slettede produkter, billeder) verificeres i Merch Dashboard Local med projektejerens godkendelse.
- **Dokumentation:** API.md, FRONTEND.md, WOOCOMMERCE.md ("Dashboard-data og privacy"), SECURITY.md, TESTING.md, PROJECT-STATUS.md (åbent spørgsmål 6) og ROADMAP.md opdateres i de relevante trin.

### Præcisering 2026-10-01: P7 – produktpris og moms
Besluttet af projektejeren før implementering. Præciserer P7's "pris"; de øvrige beslutninger i ADR-039 er uændrede.

1. **Aktiv pris:** `GET /me/products` viser den aktive WooCommerce-pris: `get_price()` for simple produkter og de tilsvarende aktive variationspriser for variable produkter. `regular_price` bruges ikke og sendes ikke som særskilt felt.
2. **Ingen momsberegning:** prisen er den gemte aktive pris, som WooCommerce returnerer. `wc_get_price_to_display()`, `wc_get_price_excluding_tax()` og `wc_get_price_including_tax()` kaldes ikke, og variationspriserne læses uden visningsjustering (`get_variation_prices()` uden `$for_display`).
3. **Momsgrundlag:** butikkens egen konfiguration (`woocommerce_prices_include_tax`). API'et beregner og omregner ikke moms.
4. **Prisobjekt:** `{ "min": "<streng>", "max": "<streng>", "currency": "<ISO 4217>", "tax": "store_setting" }`. Feltet `tax` er maskinlæsbart metadata, så klienten ikke antager, at prisen altid er inkl. eller ekskl. moms. Beløb er altid strenge, aldrig floats, og der sendes ingen HTML.
5. **Simple produkter:** den aktive `get_price()` som både `min` og `max`.
6. **Variable produkter:** `min` og `max` af de aktive variationspriser efter samme princip.
7. **Tom pris:** en tom aktiv pris (for et variabelt produkt: ingen aktive variationspriser) giver `price: null`.
8. **UI:** dashboardet beregner ikke moms og viser ingen lokal "inkl. moms"/"ekskl. moms"-etiket, fordi API'et ikke fastlægger grundlaget.
9. **Tests (trin 2 og 6):** aktiv pris frem for `regular_price` (udsalg), simpel pris, variable min/max, tom pris, `tax: "store_setting"`, og at svaret ikke indeholder floats eller HTML.

---

# ADR-040: Sales & statistics fra sales ledgeren (fase 20)

> **Note 2026-10-10:** visningen af salg er ændret til inkl. moms af ADR-053. Definitionerne nedenfor (nettosalg ekskl. moms fra `net_amount`) gælder fortsat for ledgeren og API'ets eksisterende felter.

## Date
2026-10-06

## Status
Accepteret (fase 20, trin 1, før implementering). Besluttet af projektejeren på grundlag af beslutningsgrundlaget for fase 20. Bygger på ADR-011 (ledgeren som grundlag for statistik), ADR-037 (omsætningsgrundlag, fortegn, ingen genberegning, udskudt `merchboard_sale_recorded`), ADR-038 (event-nøgler og `occurred_at`) og ADR-039 (beløb, nøgletal og caching udskudt til fase 20; produktnavn-fallback P6). Overholder ADR-007 (`/me`-scope), ADR-025 (præcist ejerskab ved uninstall) og ADR-032 (administration i wp-admin). Grafvalget står i ADR-041. Revenue split (åbent spørgsmål 5) er fortsat åbent og ikke en del af denne ADR.

## Context
Fase 20 skal give creators salgsstatistik (`/me/sales`, en Sales-sektion og KPI'er på Overview) og administratorer en Sales-visning pr. creator, med caching og målt ydelse (ROADMAP). Ledgeren `merchboard_sales` er append-only med fortegn pr. række (`sale` positiv; `refund` og `reversal` negative; `correction` begge veje), `currency` pr. række, et creator-snapshot pr. række og `occurred_at` som hændelsens tidspunkt i UTC (ADR-038): første salg på betalingsdatoen (ellers oprettelsesdatoen), salg i en senere cyklus, reversal og korrektion på behandlingstidspunktet og refund på refundens dato. Indekset `creator_occurred (creator_id, occurred_at)` dækker filtrering på creator og periode. Brutto købte enheder kan ikke rekonstrueres entydigt (en genåbnet cyklus starter med det resterende netto, ADR-039 P2).

## Decision
1. **Read-model:** én service, `SalesStatistics`, læser alene `merchboard_sales` og bruges af både `/me/sales`, Overview og admin-sektionen. Ingen WooCommerce-forespørgsler til tallene; kun produktnavne slås op (punkt 4).
2. **Ordrer (`orders`):** antal unikke `order_id` med mindst én `sale`-række i den valgte periode (eller i den enkelte delperiode i serien). Refunds, reversals og korrektioner ændrer ikke antallet; en genåbnet ordre tæller kun igen, hvis der er en ny `sale`-række i perioden. Totalens antal tælles over hele perioden (ikke som summen af delperioderne).
3. **Enheder (`units`):** `SUM(quantity)` over alle ledger-hændelser i perioden. Refunds, reversals og korrektioner rammer deres egen `occurred_at` og kan give negative enheder. Brutto købte enheder rekonstrueres ikke.
4. **Pr. produkt:** grupperes på `product_id` (parent); varianter samles under deres produkt, og der er ingen opdeling på variantniveau i fase 20. Navnet er produktets aktuelle navn, når produktet findes, ellers ledgerens `item_name`-snapshot fra produktets seneste række (som ADR-039 P6; snapshottet kan indeholde variantens suffiks).
5. **Hændelsesdatoer:** ledgerens `occurred_at` bruges uændret. En refund flyttes ikke tilbage til salgsdagen; negative værdier i en periode er gyldige. Historiske perioder ændres kun, når ledgeren selv får rækker med en gammel dato (fx backfill eller en senere refund af et gammelt salg).
6. **Tidszone:** perioder fortolkes i sitets tidszone (`wp_timezone()`). Grænserne for dag, uge og måned beregnes i PHP med `DateTimeImmutable` (sommertid håndteres der) og konverteres til UTC-intervaller; SQL bruger ikke `CONVERT_TZ()` (kræver tidszone-tabeller, som ikke er garanteret). Svaret angiver tidszonen.
7. **Perioder:** `from` og `to` er datoer `YYYY-MM-DD` i sitets tidszone, begge inklusive; internt bruges det halvåbne interval `[from 00:00, to + 1 dag 00:00)`. `interval` er `day`, `week` eller `month`; en uge er en ISO-uge (mandag–søndag), en måned en kalendermåned. Første og sidste delperiode kan være delvise for `week` og `month`; `from` og `to` udvides ikke. Standard uden parametre: i dag minus 29 dage til i dag med `day`. Højst 366 delperioder pr. svar og et inputinterval på højst 5 år.
8. **Valuta:** der aggregeres altid pr. `currency`, uden omregning, og der er aldrig en samlet total på tværs af valutaer (bekræfter reglen i WOOCOMMERCE.md). Overview viser ét KPI-sæt pr. valuta.
9. **Beløb og totaler:** `sales` = summen af `sale`-rækker, `adjustments` = summen af alle andre typer, og `net_sales = sales + adjustments`. Beløb sendes som decimalstrenge (API.md's konvention), aldrig floats.
10. **Caching:** WordPress' object cache (`wp_cache_*`), gruppe `merchboard_sales`, med en generation pr. creator. Nøglen indeholder creator, generation, `from`, `to`, `interval` og tidszone; levetid 15 minutter (sikkerhedsnet for dagsskifte, ændret tidszone og omdøbte produkter). Efter en vellykket `SalesRepository::write()` øges generationen for hver `creator_id` blandt de indsatte rækker; det dækker live-synkronisering, backfill og sletning, fordi de alle skriver gennem `write()`. Invalideringen er et internt kald i repository-laget, ingen offentlig hook: `merchboard_sale_recorded` forbliver udskudt (ADR-037 pkt. 11). Ændring af de tællende statusser kræver ingen invalidering, fordi ledgeren ikke genberegnes (ADR-037 pkt. 4). **Ingen transients:** uden persistent object cache gemmes transients i options-tabellen med dynamiske navne, og ADR-025 kræver, at uninstall kun sletter præcise, plugin-ejede navne (prefix-sletning er bevidst fravalgt). Object cache efterlader intet i databasen; uden persistent object cache gælder cachen kun den enkelte request.
11. **`GET /me/sales`:** read-only `/me`-route (kun med WooCommerce, adgang som de øvrige `/me`-routes, creator kun fra `CreatorContext`) med `from`, `to` og `interval` (standard og grænser fra punkt 7). Svaret er et objekt: den anvendte periode (`from`, `to`), `interval`, `timezone` og `currencies` = en liste pr. valuta med `currency`, `totals` (`net_sales`, `sales`, `adjustments`, `units`, `orders`), en tæt `series` (alle delperioder, også tomme med 0, hver med start og slut) og `products` (højst 100, sorteret efter `net_sales`, med `truncated`). Ingen paginering. En periode uden data giver HTTP 200 med `currencies: []`. Ugyldige formater eller intervaller afvises af argumentvalideringen (400 `rest_invalid_param`); `from > to` eller overskredne grænser giver 400 med en fast `merchboard_`-kode, uden at gentage input.
12. **`/me/overview` udgår:** der oprettes intet separat endpoint. Overview bruger `/me/sales` med standardperioden og viser `totals`, så hver KPI kun har én definition. API.md's planlagte `/me/overview` fjernes i fase 20's docs-trin.
13. **Admin Sales:** en server-renderet sektion "Sales" på creatorens redigeringsside (via `merchboard_creators_edit_sections`, som produktkoblingerne i fase 17), kun for `merchboard_manage_creators`, for aktive og inaktive creators. Den bruger samme `SalesStatistics` med creator-ID'et fra den redigerede creator og viser totaler pr. måned og produktopdeling pr. valuta. Intet admin-REST og ingen kundedata. Ingen global admin-salgsoversigt i fase 20.
14. **Terminologi:** API-felterne hedder `net_sales`, `units` og `orders`. UI: "Net sales" / "Nettosalg (ekskl. moms)", "Units" / "Enheder" og "Orders" / "Ordrer", med forklaringen: "Butikkens nettosalg af dine produkter efter rabatter, ekskl. moms, fragt og gebyrer. Det er ikke din afregning." Overviews nuværende KPI-label "Omsætning" ændres til "Nettosalg". Ingen revenue split og ingen afregning i fase 20.
15. **Ydelse:** `/me/sales` benchmarkes uden cache på både MySQL 8.0 og MariaDB 10.11 med målet p95 < 200 ms for selve read-model-operationen, for et år med daglige delperioder og produktaggregering. Repoet dokumenterer ingen forventet produktionsvolumen; benchmark-trinnet definerer derfor et syntetisk datasæt og dokumenterer det som test-fixture, ikke som en forretningsmæssig forudsætning. Et nyt indeks tilføjes kun, hvis målingen viser et reelt behov, og kræver en særskilt beslutning med migration.

## Alternatives considered
- **Ordrer som "tæller nu" (åben cyklus):** en tilstand, ikke en hændelse; kan ikke tidsfæstes til en periode. Fravalgt.
- **Brutto købte enheder:** kan ikke rekonstrueres entydigt fra ledgeren. Fravalgt.
- **Produkt på variantniveau eller snapshot-navn som nøgle:** lange lister og ustabile nøgler. Fravalgt i fase 20.
- **Refunds henført til salgets periode:** ændrer gamle perioder, er sværere at cache og forklare og strider mod ledgerens hændelsesmodel. Fravalgt.
- **UTC-dage eller fast forskydning i SQL:** forkerte dage for brugeren og ved sommertid; `CONVERT_TZ()` kræver tidszone-tabeller. Fravalgt.
- **Omregning eller samlet total på tværs af valutaer:** kræver kurser (en ekstern kilde) og blander valutaer. Fravalgt.
- **Transients:** dynamiske options-navne i strid med ADR-025's præcise uninstall. Fravalgt.
- **Offentlig `merchboard_sale_recorded` til invalidering:** udskudt af ADR-037 pkt. 11; et internt kald er nok. Fravalgt.
- **Separat `/me/overview`:** to definitioner af samme KPI. Fravalgt.
- **Admin-REST eller global admin-oversigt:** ikke i ROADMAP's fase 20-scope; ADR-032-mønstret er server-renderet. Fravalgt.

## Reason
Én read-model på ledgeren giver samme tal i dashboardet, på Overview og i admin, med deterministiske, testbare definitioner, der følger ledgerens hændelsesmodel. Sitets tidszone og inklusive datoer er det, brugeren forventer; grænser beregnet i PHP er sommertid-sikre og databaseuafhængige. Object cache med generationer giver invalidering pr. creator uden ny offentlig API og uden data i databasen.

## Consequences
- **Database:** intet nyt skema og intet nyt indeks nu; `creator_occurred` dækker filtreringen. Aggregeringen slår rækkerne op og grupperer i en midlertidig tabel; et dækkende indeks overvejes kun efter målingen (punkt 15).
- **Caching:** reel gevinst kræver persistent object cache i produktion; ydelsesmålet gælder derfor uden cache.
- **API.md, FRONTEND.md, ADMIN.md, WOOCOMMERCE.md og TESTING.md** opdateres i fase 20's trin; `/me/overview` fjernes, og Overview-KPI'erne omdøbes.
- **Fortsat åbent:** revenue split (åbent spørgsmål 5), produktionens databaseversion (åbent spørgsmål 2), global admin-salgsoversigt og `merchboard_sale_recorded`.

### Statusnote 2026-10-07: indholdet af admin Sales (ROADMAP trin 7)
Besluttet af projektejeren 2026-10-07 før implementeringen af trin 7. Præciserer pkt. 13; teksten ovenfor er bevaret som historik. Admin-sektionen "Sales" viser **standardperioden fra pkt. 7** (30 kalenderdage inklusive i dag, `day`, sitets tidszone) med nettosalg, enheder og ordrer pr. valuta og den daglige serie som tabel. **Ingen totaler pr. måned og ingen produktopdeling** i trin 7; begge kan tages op senere som en særskilt beslutning. Alt øvrige i pkt. 13 gælder uændret: server-renderet via `merchboard_creators_edit_sections`, kun `merchboard_manage_creators`, aktive og inaktive creators, creator-ID'et fra den redigerede creator, intet admin-REST og ingen kundedata. Sektionen kalder samme `SalesReports`-service (i produktion `CachedSalesReports`) som `GET /me/sales` uden HTTP, så definitionerne (pkt. 2–9), cache-posten og invalideringen (pkt. 10) er de samme som i creator-dashboardet.

---

# ADR-041: Grafer i dashboardet uden tredjeparts-bibliotek (fase 20)

## Date
2026-10-06

## Status
Accepteret (fase 20, trin 1, før implementering). Besluttet af projektejeren. ROADMAP kræver, at valget af grafbibliotek dokumenteres i en ADR. Supplerer ADR-010 (eget designsystem), ADR-004 og ADR-016 (ingen unødvendige afhængigheder og ingen eksterne services) og ADR-026 (dashboardets CSP). Bruges af ADR-040.

## Context
Fase 20 skal vise salg over tid og pr. produkt, og grafer skal være tilgængelige med et tabel-alternativ (ROADMAP's Done-kriterium). FRONTEND.md stillede krav om lille bundle, tilgængelighed og tree-shaking med Chart.js eller en letvægts-SVG-løsning som kandidater. Dashboard-bundlen (`build/dashboard.js`) er i dag cirka 31 KB minificeret (cirka 9 KB gzip) og har ingen runtime-afhængigheder ud over WordPress' egne externals. Shellens CSP tillader kun egne scripts med nonce (`script-src 'self' 'nonce-…'`); en CDN er udelukket (ADR-016).

## Decision
1. **Intet tredjeparts grafbibliotek:** dashboardet bruger egne, små SVG-visualiseringer (fx søjler for nettosalg og enheder pr. periode), skrevet som React-komponenter i det eksisterende designsystem.
2. **Tabel altid:** hver visualisering har en semantisk tabel med de samme tal som data- og tilgængeligheds-alternativ. SVG'en har en tekstlig beskrivelse (`role="img"` med label); tabellen er den primære kilde for skærmlæsere.
3. **Ingen CDN og ingen ny runtime-afhængighed:** intet nyt npm-runtime-bibliotek; alt bygges ind i den eksisterende bundle.
4. **CSP uændret:** SVG og React kræver ingen ændring af CSP'en.
5. **Test:** visualiseringerne er DOM/SVG, der kan testes deterministisk i Jest.

## Alternatives considered
- **Chart.js:** fravalgt. Det ville gøre bundlen flere gange større end hele det nuværende dashboard, tilføje en unødvendig afhængighed med egen vedligeholdelse, og canvas er ikke tilgængeligt, så et tabel-alternativ kræves alligevel. Den begrænsede visualisering (få serier, ingen avanceret interaktion) kan dækkes af projektets eget React-designsystem.
- **Kun tabeller:** opfylder tilgængeligheden, men giver intet overblik over udviklingen. Fravalgt; tabellen beholdes som alternativ.
- **Andre biblioteker (fx D3- eller React-chart-biblioteker):** samme afhængigheds- og størrelsesindvending. Fravalgt.

## Reason
Lille bundle, ingen nye afhængigheder, uændret CSP og fuld tilgængelighed via tabellen, med visualiseringer, der kan testes deterministisk.

## Consequences
- Avancerede interaktioner (zoom, tooltips med animation, mange serier) kræver en ny vurdering og en ny ADR, hvis behovet opstår.
- FRONTEND.md's afsnit om grafer opdateres i fase 20's docs-trin.

---

# ADR-042: Branding – globalt admin-styret logo og login-branding i Merch Dashboard-kontekst (fase 21)

## Date
2026-10-07

## Status
Accepteret (fase 21, trin 2, før implementering). Besluttet af projektejeren efter arkitekturafklaringen (trin 1) og login-undersøgelsen (trin 2), se [reports/2026-10-07-fase-21-branding-themes.md](reports/2026-10-07-fase-21-branding-themes.md) afsnit B, F, G og K. Bekræfter SECURITY.md's regel om SVG-upload. Supplerer ADR-006 (WordPress-authentication) og ADR-026 (dashboard-indgang og login-redirect) uden at ændre dem. Bruges af ADR-043.

## Context
Dashboard-headeren viser i dag kun sitenavnet som tekst, og pluginet rører ikke `wp-login.php`'s udseende. Repoet har ingen logo-assets. Fase 21 skal give Merch Dashboard et logo i headeren og en brandet login-oplevelse.

Login-undersøgelsen læste WordPress 7.1.2's kildekode (repoets dev-dependency `vendor/roots/wordpress-no-content`) og fandt:
- `wp_login_form()` poster altid til `wp-login.php`.
- `login_redirect`, testcookie, secure cookie, fejlvisning, sessionsudløb og admin-e-mail-bekræftelse findes kun i `wp-login.php`, ikke i `wp_signon()`.
- WordPress' login har ingen CSRF-nonce.
- Brute-force-beskyttelse og 2FA ligger hos plugins og hosting (SECURITY.md), ofte som regler på URL'en `/wp-login.php`.
- Logget ud sendes man fra enhver dashboard-URL allerede til `wp_login_url( <dashboard-URL> )` (`ShellController`).
- `DashboardRoute::dashboard_url_for()` validerer en dashboard-URL på samme site.

## Decision

**Logo**

1. **Ét globalt logo**, ikke pr. creator. Det gemmes som attachment-ID i `merchboard_settings['logo_attachment_id']` (heltal ≥ 0; 0 = intet logo). `Settings::sanitize()` gør ugyldige værdier til 0. Ingen URL og ingen kopi af filen gemmes.
2. **Administration:** en sektion "Branding" på Merch Dashboard → Settings med egen formular, nonce og `admin-post`-action (samme mønster som dashboard-slug'en, ADR-026).
   - Kræver capability `merchboard_manage_settings`. Ingen nye capabilities.
   - Ændringer går kun gennem `SettingsService`: `settings_updated`-audit (`setting`, gamle og nye attachment-ID'er) og option i én transaktion.
   - Logoet vælges i WordPress' Media Library (`wp_enqueue_media()` og en lille vanilla JS-fil uden build, som `assets/js/creator-user-search.js`).
   - "Remove logo" sætter værdien til 0 (fallback). Uden JS kan logoet fjernes, men ikke vælges.
3. **Validering ved gem** (server-side; klienten stoles ikke på):
   - attachmentet findes (`post_type = attachment`);
   - MIME-typen er `image/png`, `image/webp` eller `image/jpeg`;
   - dimensionerne fra `wp_get_attachment_metadata()` holder sig inden for grænserne: højde mindst 64 px, største side højst 2000 px, forhold mellem 1:1 og 6:1;
   - URL'en har samme origin som sitet.

   Ellers afvises valget med en notice, og intet gemmes.
4. **Ingen SVG-upload** (bekræfter SECURITY.md: XSS-risiko; en sanitizer ville kræve en runtime-dependency, jf. ADR-004). **Default-logoet er en first-party SVG** i `assets/brand/`, versioneret og reviewet i repoet:
   - ingen `<script>`, event-attributter, `<foreignObject>`, eksterne referencer eller indlejrede fonte;
   - vises kun som `<img>` og som CSS-`background-image`, hvor scripts ikke kører;
   - designes, så det er læseligt på både lys og mørk baggrund.
5. **Én uploadet variant er tilstrækkelig.**
   - **Begrundelse:** et logo med mørkt motiv på transparent baggrund forsvinder på mørke flader, og et lyst forsvinder på lyse. Det løses uden en ekstra variant ved altid at vise det uploadede logo på en lys **logo-plade** (`--merchboard-logo-plate`, padding og radius) på mørke temaer og på mørk login.
   - **Krav til admin:** logoet skal være læseligt på lys baggrund (vises som hjælpetekst i Settings).
   - En separat mørk variant tilføjes ikke. Den kræver en ny beslutning, hvis pladen viser sig utilstrækkelig.
   - Logoer er undtaget fra WCAG's kontrastkrav (SC 1.4.3/1.4.11), men pladen sikrer læselighed.
6. **Fælles opløsning:** én service løser logoet til `{ url, width, height, alt }` og bruges af både dashboard-headeren og login.
   - Den genvaliderer ved hver læsning, fordi attachmentet kan være slettet eller ændret.
   - Fallback-kæde: admin-logo → default-SVG → sitenavnet som tekst.
   - `alt` er sitenavnet, fordi logoet erstatter navnet.
   - Dashboardet får logoet som bootstrap-feltet `logo`. Det er sitekonfiguration og ikke creator-data, i tråd med FRONTEND.md's bootstrap-regel. Klienten validerer det som en URL med samme origin.
7. **Same-origin:** dashboardets CSP (`img-src 'self' data:`, ADR-026) ændres ikke. Ligger mediefilerne på en anden origin (CDN eller offload), bruges default-logoet, som for produktbilleder (ADR-039 P8).

**Login**

8. **WordPress' `wp-login.php` brandes kun i Merch Dashboard-kontekst.** Resten af sitets login er uændret. En anmodning er i Merch Dashboard-kontekst, når alle tre betingelser er opfyldt:
   1. **Login-skærmen:** WordPress har afgjort, at handlingen er `login` (også standardhandlingen uden `action`). Det konstateres passivt med actionen `login_form_login`, som WordPress kun udløser for netop den handling. Observatøren sætter kun et internt flag og giver aldrig output, redirect, `exit` eller ændring af request eller credentials. Det er ikke en overtagelse af `login_form_*`.
   2. **Ikke interim login:** `interim-login` er ikke sat (wp-admin's modal ved sessionsudløb).
   3. **Dashboard-mål:** `$_REQUEST['redirect_to']` er en string, og efter `wp_unslash()` giver `DashboardRoute::dashboard_url_for()` en værdi (dvs. `wp_validate_redirect()`, samme host, `http(s)`, ingen `..`, `\` eller `@`, og en dashboard-sti).

   Konteksten dækker første visning efter dashboardets redirect, mislykkede forsøg (WordPress bevarer `redirect_to` som skjult felt), udløbet session og sprogskift. Den dækker ikke glemt adgangskode, reset, registrering, `checkemail`, `confirm_admin_email`, `postpass`, `confirmaction`, interim login eller login uden en dashboard-`redirect_to` (fx administratorer og WooCommerce' "Min konto").
9. **`redirect_to` bruges kun som betingelse** (ja/nej), aldrig som output. Der indføres ingen ny redirect-mekanisme. Redirect efter login er uændret: WordPress' `login_redirect` og `wp_safe_redirect()` samt `LoginRedirect` for rene creators (ADR-026).
10. **Hooks i konteksten (kun præsentation):**
    - `login_enqueue_scripts`: en statisk CSS-fil uden build, afhængig af WordPress' `login`-style, så den indlæses efter den. Logo-URL'en tilføjes via `wp_add_inline_style()` og escapes til CSS-kontekst; er den ugyldig, udelades reglen.
    - `login_body_class`: klassen `merchboard-login`, som scoper al CSS.
    - `login_headerurl`: `home_url( '/' )`.
    - `login_headertext`: sitenavnet.

    Ingen JavaScript, ingen React-bundle, ingen afhængighed af WooCommerce (registreres uden for WooCommerce-modulet).
11. **Merch Dashboard må ikke:**
    - oprette en separat login-route eller egen login-formular;
    - håndtere credentials;
    - kalde `wp_signon()` som eget credential-flow;
    - ændre `login_url`, `lostpassword_url` eller `logout_url`;
    - overtage `login_form_*` eller `login_init`;
    - ændre login-designet uden for konteksten.

    WordPress' egne cookies og sessions, testcookie, login-hooks, fejlvisning, CAPTCHA- og 2FA-integration, logout, glemt adgangskode og reset samt redirects bruges uændret.
12. **Farver følger systemet:** `@media (prefers-color-scheme: dark)` giver en mørk login (værdier fra Dark Black, ADR-043); ellers lys (Light).
    - Ingen relation til brugerens dashboard-tema, som ikke er kendt før login.
    - Ingen localStorage, cookie, user meta eller JavaScript.
    - **Fallback:** kan et element i WordPress' login-markup ikke styles sikkert mørkt, bevarer det WordPress' lyse standardudseende. Kan login som helhed ikke styles sikkert mørkt, bruges kun den lyse udgave. Der indføres aldrig JS for at løse det.
13. **Glemt adgangskode, reset og øvrige handlinger** vises i WordPress' standarddesign (accepteret begrænsning).
14. **WordPress-versioner:** CSS'en er bundet til WordPress' login-markup (fx `body.login`, `#login h1 a`, `#loginform`, `.button-primary`, `#nav`, `#backtoblog`) og verificeres ved runtime pr. WordPress-version (LocalWP: 7.1.3). En markup-ændring kan give visuelle, men ikke funktionelle, regressioner.

## Alternatives considered
- **A1 – egen login-side med `wp_signon()`:** fravalgt.
  - Et nyt credential-endpoint, som hosting- og WAF-regler og "skjul login"-plugins på `/wp-login.php` ikke beskytter (SECURITY.md lægger brute-force-beskyttelse der).
  - `login_init`, `login_form_login`, `login_form` og `login_redirect` kører ikke. Captcha-plugins, der viser feltet via `login_form` og tjekker i `authenticate`, ville afvise alle logins. 2FA- og SSO-plugins forventer `wp-login.php`.
  - Testcookie, secure cookie, fejlbeskeder uden brugeroptælling, sessionsudløb og admin-e-mail-bekræftelse skulle genopfindes.
  - I praksis et eget authentication-flow, i strid med ADR-006.
- **A2 – egen side med `wp_login_form()`:** fravalgt.
  - Formularen poster til `wp-login.php`, så fejl, captcha, 2FA, lockout-beskeder og glemt adgangskode alligevel ender der. Den egne side er kun brandet ved første visning.
  - At sende brugeren tilbage via `wp_login_failed` virker ikke ved tomme felter og skjuler pluginbeskeder.
  - Formularen udløser ikke `login_form` og har ingen testcookie.
  - En ekstra formularflade med færre garantier og en ny offentlig route.
- **C – global branding af `wp-login.php`:** fravalgt. Ændrer login for alle sitets brugere, og det er ikke nødvendigt for Merch Dashboard.
- **Pæn login-adresse (`/<slug>/login/`) som alias:** fravalgt. Giver intet ud over dashboard-URL'en og reserverer et sektionsnavn.
- **To logo-uploads (lys og mørk):** fravalgt nu. Logo-pladen løser læseligheden med én upload.
- **Gemme URL'en eller kopiere filen ind i pluginet:** fravalgt. Kan ikke valideres, kan blive forældet og kræver ejerskab af filer.
- **SVG-upload med sanitizer:** fravalgt (ADR-004, SECURITY.md).
- **Udvidet CSP for medier på en anden origin:** fravalgt uden security-review.

## Reason
Kun præsentationen ændres. Authentication, redirects og alle sikkerhedsmekanismer forbliver WordPress' egne og plugin-kompatible, og brandingen rammer kun Merch Dashboards egne login-anmodninger. Ét logo med fast fallback og en plade holder modellen enkel.

## Security
- **CSRF:** WordPress' login har ingen nonce, og det ændres ikke. Brandingen udfører ingen handlinger. Admin-formularen har capability og nonce, ligesom de øvrige settings.
- **Open redirect:** uændret, fordi WordPress og `LoginRedirect` står for redirect. En manipuleret `redirect_to` til en dashboard-URL kan kun slå vores egen branding til.
- **Brute force, CAPTCHA og 2FA:** uændret; plugins og hosting virker som før.
- **Output:** logo-URL'en kommer kun fra den validerede service og escapes til CSS-kontekst. `login_headerurl` og `login_headertext` er sitets egne værdier.
- **Upload:** kun raster; ingen SVG fra admins.
- **CSP:** uændret. `wp-login.php` har ingen CSP fra pluginet, og der tilføjes ingen.

## Accessibility
- WordPress' login-formular (labels, `autocomplete`, fejlboks) bevares og virker med tastatur alene og uden JavaScript.
- Pluginets CSS skal overholde ADR-043's krav: tekst ≥ 4,5:1; UI, fokus og væsentlig grafik ≥ 3:1; synlig fokus; respekt for `prefers-reduced-motion`.
- `login_headertext` giver logo-linket et tilgængeligt navn.
- Admin-sektionen: knapper med labels, tastaturbetjening og Media Library som WordPress' egen.

## Migration/data implications
- Ingen migration og ingen schemaændring: én ny nøgle i den eksisterende `merchboard_settings`.
- Uninstall uændret: optionen slettes allerede ved opt-in (ADR-025).
- Attachmentet ejes af Media Library og slettes aldrig af pluginet.

## Consequences
- Settings-siden får en Branding-sektion. ADMIN.md og SECURITY.md opdateres i implementeringstrinene.
- Logo-servicen bruges af både dashboard og login.
- Login-CSS skal gen-verificeres ved nye WordPress-versioner.
- Glemt adgangskode og reset forbliver standard. En senere ændring kræver en ny beslutning.
- Brand-font er ikke en del af ADR'en (ROADMAP "Senere").

## Related
ADR-004, ADR-006, ADR-016, ADR-025, ADR-026, ADR-039 (P8), ADR-043; SECURITY.md; FRONTEND.md (bootstrap-data); fase 21-rapporten.

### Præcisering 2026-10-08: implementering (fase 21, trin 6)
Beslutningerne er uændrede. Implementeringen præciserer fire detaljer:

- **Alt-tekst (pkt. 6):** default-logoet er et grafisk mærke uden tekst (`assets/brand/logo.svg`).
  - I dashboard-headeren står det derfor ved siden af sitenavnet som tekst og har `alt=""`, så alt-teksten aldrig beskriver noget, billedet ikke viser.
  - Et admin-logo erstatter sitenavnet og har sitenavnet som `alt`, som besluttet.
  - Bootstrap-feltet `logo` er `{ url, width, height, alt, custom }` eller `null` (sitenavnet som tekst).
- **Synlighed (pkt. 3):** attachmentet skal være offentligt, dvs. `get_post_status()` giver `publish` (uvedhæftet eller vedhæftet et publiceret indlæg). Papirkurv og medier på private indlæg eller kladder afvises. Filendelsen skal give samme MIME-type som den gemte.
- **Logo-plade (pkt. 5):** pladen bruges på alle mørke temaer, både til admin-logoet og default-logoet. Default-logoet har sin egen mørke baggrundsflade og er læseligt på pladen og på Light.
- **Login-konteksten (pkt. 8):** `wp-login.php` i WordPress 7.1.2 (repoets dev-dependency) har den forventede markup (`#login`, `.wp-login-logo`, `#nav`, `#backtoblog`). Stylesheetet er scopet til `body.merchboard-login` og indlæses først efter WordPress' `login`-style. Runtime-verifikation i Merch Dashboard Local (WordPress 7.1.3) og i en browser mangler.

---

# ADR-043: Dashboard-temaer – seks temaer på semantiske tokens med `data-theme` (fase 21)

## Date
2026-10-07

## Status
Accepteret (fase 21, trin 2, før implementering). Besluttet af projektejeren. Supplerer ADR-010 (eget design system med CSS custom properties) og ADR-041 (graf og negative værdier). Persistens: ADR-044. Login-farver: ADR-042.

## Context
Dashboardet har allerede CSS custom properties (`_tokens.scss`, 149 anvendelser), men tokens sidder på `.merchboard-app`.
- Komponenter bruger rå palette-trin 13 steder, og 5 farver er hardcodet.
- `body`-baggrunden ligger uden for tokens, og no-access- og unavailable-skabelonerne har egne farver.
- Input-kanten har kontrast cirka 1,48:1 (krav ≥ 3:1).

Målt i trin 2 med trin 1's paletter er afstanden mellem accent og danger:

| Tema | ΔE (OKLab) |
| --- | --- |
| Light | 34,6 |
| Dark Black | 18,6 |
| Dark Green | 20,9 |
| Dark Blue | 20,5 |
| Dark Red | **8,5** |
| Dark Pink | **7,7** |

Dark Red og Dark Pink ville altså lade accent og danger ligne hinanden.

## Decision
1. **Seks temaer, lukket sæt:** `light`, `dark-black`, `dark-green`, `dark-blue`, `dark-red`, `dark-pink`. Et nyt tema kræver en ny beslutning.
2. **Ét token- og stylesheet-system:** tokens defineres på dokumentets rod (`:root`/`html` i shellen, så `body` er med).
   - Light er standardværdierne. De mørke temaer overstyrer via `[data-theme="…"]` i samme stylesheet i den eksisterende bundle.
   - Ingen separate stylesheets pr. tema, ingen runtime-dependency, intet JS-temabibliotek, ingen eksterne fonte, CDN'er eller services. Systemfonte bevares.
3. **Rækkefølge:**
   1. brugerens eksplicitte valg (serverrenderet `data-theme`, ADR-044) vinder altid;
   2. uden valg bestemmer `prefers-color-scheme` (mørk → Dark Black, ellers Light);
   3. uden systempræference: Light.

   Det løses i CSS uden JS.
4. **Semantiske tokens:** komponenter bruger kun semantiske tokens (præfiks `--merchboard-`). Rå paletter må kun findes i token-filen. Rollerne er:
   - flader og tekst: `bg`, `surface`, `surface-muted`, `border` (dekorativ), `border-strong` (UI-afgrænsning ≥ 3:1), `text`, `text-muted`;
   - accent: `primary`, `primary-hover`, `on-primary`, `link`, `nav-active-bg`, `nav-hover-bg`;
   - stabile roller: `focus`, `success`, `warning`, `danger`, `info`, deres `-bg`, `on-danger`, `negative`;
   - komponenter: `toast-bg`, `toast-text`, `skeleton`, `chart-bar`, `chart-bar-negative`, `chart-baseline`, `shadow-sm`, `shadow`, `logo-plate`.

   De 13 rå referencer, de 5 hardcodede farver, `body`-baggrunden og no-access/unavailable-skabelonerne flyttes på tokens. Input-kanten rettes til ≥ 3:1.
5. **Accent må variere** mellem temaer (`primary`, `link`, navigation og `on-primary`).
6. **Stabile semantiske roller:** success, warning, danger, negative værdier og fokus har samme betydning og nuance-familie i alle temaer (danger rød, success grøn, warning gul/rav).
   - Kun lyshed og mætning tilpasses lys/mørk for kontrast.
   - De afledes aldrig af temaets accent, og `--merchboard-focus` er en egen rolle.
7. **Accent ≠ danger og negative:** i hvert tema skal accenten kunne skelnes tydeligt fra `danger` og `negative`.
   - **Mål:** OKLab ΔE ≥ 15, valideres ved implementering.
   - Dark Red og Dark Pink må ikke genbruge danger- eller negativ-farven som primary eller accent. Deres identitet bæres af tonede baggrunde og flader og en accent, der holdes væk fra danger. **Trin 1's udkast til Dark Red og Dark Pink opfylder ikke kravet og skal revideres.**
   - Danger og fejl bæres aldrig af farve alene (tekst eller ikon, fx "Error:"-præfiks).
8. **Negative værdier:** vises fortsat ved placering under baselinen, minustegn og tabellen (ADR-041). Farven (`chart-bar-negative`/`negative`) er sekundær og følger punkt 6–7.
9. **Paletter:** trin 1's paletter (rapportens afsnit E) er designudkast og udgangspunkt, ikke verificerede værdier. Endelige værdier fastlægges og kontrastvalideres i implementeringstrinene.
10. **Gælder kun creator-dashboardet** (shellen). wp-admin og admin-sektionerne temes ikke. Login følger systemet (ADR-042) og genbruger Light- og Dark Black-værdierne.
11. **Temaskift** sker uden animation.

## Alternatives considered
- **Et stylesheet pr. tema:** flere filer, dobbelt vedligehold og risiko for blink. Fravalgt.
- **Tokens fortsat på `.merchboard-app`:** `body` og skabeloner uden for appen ville ikke følge temaet. Fravalgt.
- **Accent afledt automatisk (fx `color-mix`):** uforudsigelig kontrast. Fravalgt.
- **Fri farvevælger eller admin-styret primærfarve** (ADMIN.md's oprindelige "primærfarve"): kan ikke kontrastgaranteres. Ikke en del af fase 21.
- **Danger farvet efter tema:** bryder rollernes stabilitet. Fravalgt.

## Reason
Én tokenstruktur gør temaer til ren data, giver forudsigelig kontrast og kræver hverken JS eller nye afhængigheder. Stabile semantiske roller sikrer, at fejl og negative tal betyder det samme i alle temaer.

## Security
- Temaet er en attributværdi fra en lukket allowlist (ADR-044). Den bruges aldrig som CSS-tekst, klassenavn fra input eller filnavn.
- Ingen eksterne ressourcer og ingen CSP-ændring.

## Accessibility
Krav i alle seks temaer og på login:
- normal tekst ≥ 4,5:1 (stor tekst ≥ 3:1);
- UI-komponenters afgrænsning, fokusindikator og væsentlig grafik ≥ 3:1 (WCAG 2.1 SC 1.4.3/1.4.11);
- synlig tastaturfokus;
- ingen information kun via farve;
- `prefers-reduced-motion` respekteres;
- temavælgeren er tastaturbetjent med korrekt label (ADR-044).

Input-kantens cirka 1,48:1 løses i token-refaktoreringen. **Kontrasten er ikke verificeret endnu.** Den valideres under implementering og runtime-verifikation og erklæres først opfyldt der.

## Migration/data implications
Ingen. Ren CSS i den eksisterende bundle. Valget lagres efter ADR-044.

## Consequences
- FRONTEND.md's design-system-afsnit ("tokens på `.merchboard-app`") opdateres, når token-refaktoreringen implementeres.
- Kontrasttests eller -checks pr. tema er en del af implementeringen.
- Jest dækker ikke CSS, så visuel kontrol er en del af runtime-verifikationen.

## Related
ADR-010, ADR-041, ADR-042, ADR-044; FRONTEND.md; fase 21-rapporten (afsnit C, E og H).

### Præcisering 2026-10-08: implementering (fase 21, trin 3–4 og 6)
Beslutningerne er uændrede. De faktiske tokennavne og værdier:

- **Tokennavne (pkt. 4):** implementeret som `selected-bg`/`selected-text` (aktiv navigation; ADR'ens `nav-active-bg`), `hover-bg` (`nav-hover-bg`), `inverse-surface`/`on-inverse` (toast; `toast-bg`/`toast-text`) og `chart-series`/`chart-negative`/`chart-baseline` (`chart-bar`/`chart-bar-negative`).
- **Ekstra roller:** `text-subtle`, `focus-on-inverse`, `control-bg`, `control-border` og (trin 6) `logo-plate`. Listen står i FRONTEND.md (Tokens og Temaer).
- **Placering:** Light på `:root`; de fem mørke temaer som SCSS-mixins, der overstyrer alle farvetokens, med en fælles mixin for de stabile mørke roller og `color-scheme`.
- **Dark Red og Dark Pink (pkt. 7):** accenterne er `#e9c6c8` og `#ebbbe0` (ΔE 19,4 og 18,7 fra den mørke danger `#f87171`). Trin 1-udkastene er afvist og beskyttet af en regressionstest.
- **Light-ændringer (trin 3):**
  - kontrolkant og grafbaseline 3,63:1;
  - negative søjler som selvstændig neutral rolle (`#64748b`);
  - fokus på toast 8,96:1.
- **Verifikation:** statisk på token-niveau (`DesignTokensTest`). Visuel browser-verifikation er ikke udført.

---

# ADR-044: Theme-persistence – user meta og `PATCH /me/preferences` (fase 21)

## Date
2026-10-07

## Status
Accepteret (fase 21, trin 2, før implementering). Besluttet af projektejeren. Supplerer ADR-007 (`/me`-scope), ADR-008 (REST) og ADR-025 (præcis oprydning ved opt-in-uninstall). Bruges af ADR-043.

## Context
Temaet (ADR-043) er en personlig UI-præference. Dashboardets ESLint forbyder `localStorage`, `sessionStorage`, `indexedDB` og `document.cookie`. Shellen renderes server-side for den indloggede bruger, så præferencen kan sættes, før CSS'en tolkes. Bootstrap-data må ikke indeholde creator-data (FRONTEND.md).

## Decision
1. **Lagring:** user meta `merchboard_dashboard_theme` på den autentificerede WordPress-bruger, ikke på creator-recorden.
   - Præferencen tilhører login-kontoen, i tråd med adskillelsen mellem login og forretningsenhed (ADR-005/ADR-020).
   - Gyldige værdier er præcis de seks temaer fra ADR-043. Ingen værdi betyder "intet eksplicit valg" (systemstandard).
2. **Læsning og ingen blink:** `ShellController` læser værdien for den aktuelle bruger og validerer den mod allowlisten.
   - Kun ved en gyldig værdi sætter `shell.php` `data-theme` på `<html>`, før stylesheetet indlæses. En ugyldig gemt værdi behandles som manglende og repareres ikke automatisk.
   - Der tilføjes intet bootstrap-felt. Klienten læser det aktuelle valg fra den serverrenderede attribut og validerer mod de seks værdier, så bootstrap-reglen er uændret.
3. **Skrivning:** `PATCH /merchboard/v1/me/preferences` med JSON-body `{ "theme": "<en af de seks>" | null }`.
   - Adgang som de øvrige `/me`-routes: `CreatorContext`, cookie og `X-WP-Nonce`; 401 udlogget, 403 uden adgang.
   - Der skrives kun for `get_current_user_id()`; ID'er i body eller query ignoreres (ADR-007).
   - Schemaet har `theme` som enum. Enhver anden værdi afvises med 400 (`rest_invalid_param`), og intet gemmes. Ukendte felter ignoreres som ved `PATCH /me/profile`.
   - `null` sletter metaen (tilbage til systemstandarden).
   - Svaret er `{ "theme": <værdi> | null }`. Efter succes sætter eller fjerner klienten `data-theme` på `<html>`.
4. **Eksplicit valg vinder altid** over systemets præference (ADR-043). Der gemmes ingen "auto"-værdi; tilbage til systemet = `null`.
5. **Ingen** localStorage, sessionStorage, IndexedDB eller cookie (ESLint-reglen bevares). Ingen audit: en personlig UI-præference uden sikkerheds- eller forretningsbetydning. Ingen caching.
6. **Rækkevidde:** kun creator-dashboardet. wp-admin og login påvirkes ikke (login følger systemet, ADR-042). En administrator, der også er creator, bruger samme præference i dashboardet.
7. **Uninstall:** nøglen tilføjes den præcise oprydning i `Uninstaller` (`delete_metadata( 'user', 0, 'merchboard_dashboard_theme', '', true )`). Den slettes kun ved opt-in (ADR-025); uden opt-in bevares den som alle andre data. Deaktivering sletter intet.

## Alternatives considered
- **localStorage:** forbudt af den eksisterende ESLint-regel, kun én browser og blink ved første visning. Fravalgt.
- **Cookie:** `document.cookie` er forbudt, og en server-cookie gælder kun én browser og er unødvendig, når serveren kender brugeren. Fravalgt.
- **Kolonne på creator-tabellen:** migration og forkert entitet (præferencen tilhører login-kontoen). Fravalgt.
- **Map pr. bruger i `merchboard_settings`:** autoloaded, vokser med brugere og blander global konfiguration med personlige data. Fravalgt.
- **`PATCH /me`:** `/me` beskriver creator-identiteten; præferencer er en selvstændig ressource. Fravalgt.
- **Bootstrap-felt eller `GET /me/preferences`:** unødvendigt, når den serverrenderede attribut allerede er sandheden. `GET` kan tilføjes, hvis flere præferencer kommer.
- **Admin-post-formular med sidegenindlæsning:** passer ikke til SPA'en (ADR-008 dækker JS via REST). Fravalgt.

## Reason
Persistens på tværs af enheder, intet blink, ingen klientlager og en route, der følger `/me`-mønstret med en lukket allowlist.

## Security
- Kun den indloggede bruger kan ændre sin egen præference (`/me`-scope, nonce).
- Værdien er en enum og bruges kun som attributværdi fra allowlisten, aldrig som CSS, filnavn eller klassenavn fra input.
- Ingen eskalering og ingen nye capabilities.

## Accessibility
- Temavælgeren i dashboardet er et rigtigt formularelement (radiogruppe eller `<select>`) med label ("Tema"). Navnene vises som tekst, ikke kun som farveprikker.
- Vælgeren er fuldt tastaturbetjent med synlig fokus, og resultatet annonceres via det eksisterende toast- og fejlmønster.
- Vælgeren giver mulighed for at vende tilbage til systemets standard.

## Migration/data implications
- Ingen migration og ingen schemaændring (WordPress' `usermeta`).
- Ny plugin-ejet user meta-nøgle, der skal listes i `Uninstaller` (præcist navn, ADR-025).
- Den fremtidige GDPR exporter/eraser (ROADMAP "Senere") skal medtage nøglen.

## Consequences
- API.md, FRONTEND.md og DATABASE.md opdateres, når routen og metaen implementeres.
- Integrationstests skal dække 401/403, enum-afvisning, `null`, IDOR (ID'er ignoreres) og uninstall.
- Flere præferencer senere kan bruge samme ressource.

## Related
ADR-005, ADR-007, ADR-008, ADR-020, ADR-025, ADR-043; API.md; FRONTEND.md (bootstrap-data og ESLint-regler).

### Præcisering 2026-10-08: implementering (fase 21, trin 5)
Beslutningerne er uændrede.

- **Fejl (pkt. 3):** et ugyldigt `theme` giver 400, enten `rest_invalid_param` (argument-skemaet) eller `merchboard_invalid_preferences` med `data.fields.theme = "invalid"` (`theme` mangler, eller endpointets egen kontrol afviser værdien). `required` bruges ikke i skemaet, fordi WordPress behandler `null` som manglende. Kun body'en tæller, ikke query-parametre. `GET /me/preferences` findes ikke (404).
- **Vælgeren (pkt. 6 og Accessibility):** et kort "Udseende" på Profile-siden med en native `<select>` (Systemstandard + de seks temaer på dansk). Gem er serialiseret, så serverens sidste skrivning altid er brugerens sidste valg, og ved fejl rulles der tilbage til det gemte tema.
- **Runtime:** verificeret i Merch Dashboard Local 2026-10-08 (fase 21, trin 9) via WP-CLI og HTTP, ikke i en browser.

---

# ADR-045: Creator pricing-model (fase 22)

## Date
2026-10-08

## Status
Accepteret (fase 22, trin 3). Besluttet af projektejeren på grundlag af de 22 bindende beslutninger i [reports/2026-10-08-fase-22-creator-pricing.md](reports/2026-10-08-fase-22-creator-pricing.md) (afsnit 0) og designet i samme rapport (trin 2, foreslået som PROPOSED). Supplerer ADR-030 (ejerskab), ADR-037/038 (ledgeren er uændret) og ADR-039 P7 (`/me/products`).

## Context
Creators skal tjene en profit oven på virksomhedens grundpris, og creator-prisen er den pris, kunden betaler. WooCommerce er sandheden for salgsprisen; grundpris og regler findes ikke i WooCommerce. Penge regnes uden floats (ADR-038 B1). WooCommerce sletter selv en udsalgspris ≥ regular price ved gemning.

## Decision
1. **Listepris** (creator-pris) = WooCommerce' regular price for den salgbare enhed = grundpris + profit pr. enhed. Grundprisen er virksomhedens andel af listeprisen før rabatter, i butikkens momsgrundlag.
2. **Profit:** fast override (ADR-046), ellers `round_half_away(grundpris × markup_bps / 10000)` til butikkens prisdecimaler. Markup pr. creator i basis points 0–10 000, standard 0.
3. **Data og valuta:** grundpris pr. salgbar enhed og creatorens markup er Merch Dashboard-data (`merchboard_item_pricing`, `merchboard_creators.default_markup_bps`) med valuta; ingen omregning. Regler i en anden valuta end butikkens anvendes ikke.
4. **Anvendelse:** creator-prisen publiceres kun ved en eksplicit, auditeret handling (`merchboard_manage_creator_pricing` + `edit_product`).
   - Den skriver kun regular price via CRUD og gemmer den anvendte tilstand (`applied_*`).
   - Ingen runtime-filtre; sale price og `_price` skrives aldrig.
   - En forhåndsvisning viser udsalg, som WooCommerce fjerner.
5. **Udsalg og rabatter** er rabatter på listeprisen. De ændrer hverken regel, tilstand eller profit pr. enhed; den realiserede profit reduceres forholdsmæssigt (ADR-047).
6. **Tilstand pr. enhed** beregnes deterministisk (rapportens afsnit 5): `unavailable`, `not_applied` (`no_base_price`, `never_applied`, `creator_changed`, `config_changed`), `drift` og `applied`. Afvigelser overskrives aldrig automatisk, og WooCommerce-priser låses ikke.
7. **Første aktivering:** WooCommerce' regular price må foreslås som grundpris, men kun en eksplicit "Gem" gemmer den.
8. **Creators** ser deres standardregel, deres anvendte creator-pris og profit (`/me`, `/me/products`), aldrig grundprisen eller konfigurationen.

## Alternatives considered
- **Runtime-prisfiltre:** risiko for salg til grundpris ved deaktivering eller oversete prisveje. Fravalgt.
- **Creator-pris kun i dashboardet:** kunden betaler ikke creator-prisen (strider mod beslutning 1). Fravalgt.
- **WooCommerce COGS:** `float`, valgfri feature og en anden betydning. Fravalgt.
- **Grundpris som produkt-meta:** kræver flere beskyttede WooCommerce-meta-nøgler og spredt validering. Fravalgt.
- **Udsalgspris som separat pricing** (fx profit-fradrag først): en skjult anden model. Fravalgt.

## Reason
Kunden betaler altid det, WooCommerce viser. Pluginet kan deaktiveres uden tab. Én forklarlig model for listepris, udsalg og rabat, og deterministiske tilstande.

## Security
- Konfiguration og anvendelse kræver den nye capability `merchboard_manage_creator_pricing` (kun administrator, ADR-022-mønstret). Anvendelse kræver desuden `edit_product` på produktet.
- Creators får kun læseadgang til egne, anvendte værdier via `/me`-scope (ADR-007). Grundprisen sendes aldrig.

## Migration/data implications
- Migration 6: kolonnen `merchboard_creators.default_markup_bps` og tabellen `merchboard_item_pricing` (se DATABASE.md).
- Ny capability; rolleversionen bumpes (ADR-022).
- Nye audit-actions, når skrivende handlinger implementeres.
- Opt-in-uninstall sletter pricing-tabellerne og kolonnen med creator-tabellen. WooCommerce-priser, som pluginet har skrevet, nulstilles aldrig.

## Consequences
- **Brugerflader:** admin-sektioner og felter i `/me` og `/me/products` (senere trin).
- **Dokumentation:** WOOCOMMERCE.md præciseres (grundpris og regler er pluginets data; listeprisen publiceres via CRUD).

## Related
ADR-004, ADR-007, ADR-019, ADR-022, ADR-025, ADR-030, ADR-037, ADR-038, ADR-039, ADR-046, ADR-047; [reports/2026-10-08-fase-22-creator-pricing.md](reports/2026-10-08-fase-22-creator-pricing.md).

### Præcisering 2026-10-08: fundamentet (fase 22, trin 3)
Beslutningerne er uændrede. Implementeret: migration 6, capability og det rene domæne (`src/Modules/WooCommerce/Pricing/`). Ingen UI, ingen skrivning til WooCommerce og ingen skrivende endpoints endnu.

- **Tilstandsnavn (pkt. 6):** `unavailable` hedder `cannot_apply` i koden og API'et. Årsagerne er:
  - `no_creator`;
  - `trashed`;
  - `unsupported_product`;
  - `currency_mismatch` (butikkens valuta ≠ grundprisens eller den faste profits);
  - `invalid_precision` (butikkens prisdecimaler uden for 0–4, eller en grundpris eller fast profit med flere decimaler end butikken).
- **Rækkefølge:** `cannot_apply` → `not_applied` (`no_base_price`, `never_applied`) → `drift` → `not_applied` (`creator_changed`, `config_changed`) → `applied`. `drift` vinder over en konfigurationsændring, fordi WooCommerce-prisen er ændret uden om pluginet.
- **Ændrede prisdecimaler (rapportens afsnit 14, pkt. 6):** giver `config_changed`, når beløbene stadig kan udtrykkes, ellers `cannot_apply`/`invalid_precision`.
- **Beregning:** én eksakt heltalsdivision i 1/10 000-enheder (`PricingMath::mul_div_round`, cifferstrenge, ingen floats, ingen bcmath), så der ikke afrundes to gange. Overflow afvises.

### Præcisering 2026-10-08: WooCommerce-integrationen (fase 22, trin 4)
Beslutningerne er uændrede. Detaljerne står i WOOCOMMERCE.md ("Creator pricing").

- **Anvendelse (pkt. 4 og 6):**
  - `PricingService::apply()` skriver kun i tilstanden `not_applied`.
  - Ved `drift` skriver den intet og returnerer resultatet `drift`.
  - Den separate, eksplicitte `apply_despite_drift()` er "admin anvender igen" (beslutning 11): den kræver den afvigende pris, admin så, auditeres som `creator_pricing_drift_overwritten` og kaldes aldrig automatisk.
- **Første aktivering (pkt. 7):** preview foreslår WooCommerce' regular price som grundpris uden at gemme den. "Gem" er den eksplicitte anvendelse, der gemmer grundprisen og den anvendte pris i samme transaktion. Der er endnu ingen separat "gem kun grundprisen"-handling.
- **Udsalg:**
  - Merch Dashboard kalder kun `set_regular_price()` + `save()`.
  - At WooCommerce fjerner en udsalgspris, der ikke er under den nye regular price, er WooCommerce' egen regel. Preview forudsiger den (`sale_would_be_removed`) i stedet for at vise den som en separat forhåndsvisning af udsalg.
- **Konsistensgrænse:**
  - Item pricing, audit og WooCommerce-gemningen (sidst) kører i én databasetransaktion.
  - Pluginets tabeller rulles altid tilbage; WooCommerces data kun på en transaktionel engine.
  - Sideeffekter uden for databasen kan ikke rulles tilbage.
- **Adgang:** preview kræver kun `merchboard_manage_creator_pricing`, da preview ikke skriver; skrivning kræver desuden `edit_product` på parent.
- **Hooks i transaktionen (låst 2026-10-08, trin 5):** WooCommerce' gemning kører egne og andre plugins' hooks, mens transaktionen er åben. Deres databaseskrivninger følger transaktionen, men andre sideeffekter gør ikke, og langsomme hooks holder transaktionen og dens rækkelåse åbne længere. Det er en dokumenteret konsistens- og ydelsesgrænse, ikke et redesign.

### Statusnote 2026-10-09: erstattet af ADR-049
**Status: Erstattet af ADR-049**, besluttet af projektejeren 2026-10-09. Ny låst forretningsregel: WooCommerce-salgsprisen er allerede den endelige kundepris inkl. creator-profit og moms. Merch Dashboard lægger derfor aldrig profit oven i en grundpris og skriver aldrig WooCommerce-priser.

- Punkt 1, 2, 4, 6 og 7 (listepris = grundpris + profit, anvendelse, tilstande, grundpris) gælder ikke længere.
- Koden er fjernet, før den blev frigivet: `PricingService`, `PricingInspector`, tilstandsevalueringen og `merchboard_item_pricing` (droppet af migration 8).
- Punkt 3 (markup i basis points på creatoren, ingen floats og ingen omregning) og capability'en `merchboard_manage_creator_pricing` består med ny betydning: markup er creatorens andel af salgsprisen.
- Historikken ovenfor ændres ikke.

---

# ADR-046: Produktspecifik fast profit som eksklusiv override (fase 22)

## Date
2026-10-08

## Status
Accepteret (fase 22, trin 3). Besluttet af projektejeren (foreslået i fase 22, trin 2). Bruges af ADR-045.

## Context
Admin skal kunne give et creator-produkt en fast profit, der erstatter procenten. Ejerskab ligger på parent (ADR-030), og grundpriser kan variere pr. variant.

## Decision
1. **Én override pr. parent-produkt** i `merchboard_pricing_overrides` (`product_id` PK), bundet til ejeren (`creator_id`) og valutaen. Den gælder pr. enhed for alle varianter. Ingen variantregler i v1.
2. **Gyldighed:** en override gælder kun, når `creator_id` = produktets nuværende ejer, og valutaen = butikkens. Så er profit = den faste profit, ellers creatorens standard. Procent og fast profit summeres aldrig, og override-tabellen har ingen procentkolonne.
3. **Nulstilling og 0:** "Brug standard" sletter rækken (auditeret). En fast profit på 0 er en gyldig override.
4. **Ejerskift:** en override kan kun oprettes for den nuværende ejer.
   - Ved ejerskift eller frakobling slettes den i samme transaktion som koblingen i `ProductCreatorLink` (manuelt og via kategori-afstemning) og auditeres med årsag `ownership_changed`.
   - WooCommerce-prisen ændres ikke; enhederne får tilstand `creator_changed` eller `unavailable`.
5. **Permanent sletning** af produktet sletter overriden.

## Alternatives considered
- **Override pr. variant eller begge niveauer:** overlap og uklar forrang. Fravalgt.
- **Procent og fast profit i samme række:** muliggør ugyldige kombinationer. Fravalgt.
- **At beholde overriden inaktiv ved ejerskift:** den ville vågne skjult op, hvis produktet vender tilbage. Fravalgt.

## Reason
Strukturel eksklusivitet, ingen cross-creator-lækage og ingen skjult genaktivering.

## Migration/data implications
- Migration 6: tabellen `merchboard_pricing_overrides` (se DATABASE.md).

## Consequences
- **Validering:** domænevalidering og CHECK (`fixed_profit >= 0`).
- **Integration:** `ProductCreatorLink` får et pricing-trin i sin transaktion (senere trin).
- **Tests:** forrang, nulstilling, 0-override, ejerskift (A→B, A→B→A), kategori-afstemning og varianter med forskellig grundpris.

## Related
ADR-019, ADR-030, ADR-045, ADR-047.

### Præcisering 2026-10-08: fundamentet (fase 22, trin 3)
Beslutningerne er uændrede.

- **Ingen CHECK-constraint (Consequences):** skemaet bruger dbDelta og har ingen CHECK-constraints nogen steder (ADR-019). `fixed_profit >= 0`, gyldig valuta og positive ID'er håndhæves af domænet (`PricingOverride`) før skrivning og igen ved læsning (`PricingOverrideRepository` afviser en ugyldig række med `DatabaseException`).
- **Forrang (pkt. 2):** `ResolvedRule::resolve()` bruger kun en override, der tilhører den nuværende ejer. Ellers gælder ejerens standard, så B arver aldrig A's faste profit, heller ikke før oprydningen i `ProductCreatorLink` er implementeret.
- **Ikke implementeret endnu:** sletning ved ejerskift og permanent sletning (pkt. 4–5) og audit.

### Præcisering 2026-10-08: admin-UI (fase 22, trin 6)
Beslutningerne er uændrede.

- **Admin-UI (trin 6):** at sætte eller fjerne en fast profit kræver ud over `merchboard_manage_creator_pricing` også `edit_product` på parent-produktet, som for produktkoblingen (ADR-030). Det er strengere end "kun ved prisskrivning", fordi det er produktkonfiguration. Den faste profit bindes til den nuværende ejer, og "Brug creatorens markup" sletter rækken. Begge auditeres (`product_fixed_profit_set`/`_removed`) og ændrer aldrig WooCommerce-prisen.

### Statusnote 2026-10-09: erstattet af ADR-049
**Status: Erstattet af ADR-049**, besluttet af projektejeren 2026-10-09. En fast profit pr. enhed har ingen entydig betydning, når profitten er en andel af salgsprisen. I stedet kan et parent-produkt få sin egen markup-procent, som erstatter creatorens markup (samme eksklusivitet, ejerbinding og `edit_product`-krav som her).

- Migration 8 genopretter `merchboard_pricing_overrides` med `markup_bps` i stedet for `fixed_profit`/`currency`. Eksisterende faste beløb kan ikke omregnes til en andel uden at gætte og slettes derfor; deres historik står i audit-loggen.
- Audit-actions `product_fixed_profit_set`/`_removed` skrives ikke længere, men bevares til den eksisterende append-only-historik. Nye actions: `product_markup_set`/`_removed`.

---

# ADR-047: Historiske creator-pricing-snapshots (fase 22)

## Date
2026-10-08

## Status
Accepteret (fase 22, trin 3). Besluttet af projektejeren (foreslået i fase 22, trin 2). Supplerer ADR-037/038 uden at ændre dem.

## Context
Historisk creator-profit skal kunne garanteres (beslutning 13), men vises ikke endnu (beslutning 14). `merchboard_sales` er append-only, med `net_amount` efter rabatter, ekskl. moms, og overlever ordresletning. Pricing-regler og WooCommerce-priser ændrer sig over tid.

## Decision
1. **Snapshot:** når WooCommerce opretter en produktlinje med creator (`woocommerce_new_order_item`), skrives et snapshot i den immutable tabel `merchboard_sales_line_pricing`.
   - Nøglen er `order_item_id` (PK).
   - Snapshottet gemmer linjens creator, produkt, variant og ordrevaluta samt status `priced`/`unpriced` med årsag.
   - Ved `priced` gemmes de **anvendte** værdier: regel, markup, grundpris, profit og listepris pr. enhed.
   - Kun INSERT, aldrig UPDATE eller DELETE (undtagen opt-in-uninstall).
2. **Status:** `priced` kun når alle tre gælder:
   - WooCommerce' regular price = den anvendte pris;
   - prisen er anvendt for den nuværende ejer;
   - ordrens valuta = den anvendte valuta.

   Ellers `unpriced` med en årsag: `no_base_price`, `never_applied`, `creator_changed`, `price_drift`, `currency_mismatch` eller `unavailable`.
3. **Realiseret profit** for en ledger-række = `round_half_away(net_amount × profit_unit / price_unit, 4)`.
   - Kun når snapshottet er `priced`, og creator og valuta matcher rækken; ellers NULL.
   - Beregnes overflow-sikkert i heltal uden floats.
   - Gælder alle rækketyper (sale, refund, reversal, correction). Udsalg, kuponer og delvise refunds reducerer profitten forholdsmæssigt.
4. **Ledgeren** ændres ikke: hverken ledgeren, dens invariant eller dens rækker, og der kommer ingen profitkolonne. Profit rekonstrueres aldrig ud fra nuværende regler. Linjer uden snapshot (før fase 22) har ukendt profit.
5. **Scope i fase 22:** snapshot og beregningsfunktion (unit-testet). Aggregering og visning kommer med Payouts og statistik, og SQL-aggregering skal give samme resultat som funktionen.

## Alternatives considered
- **Profitkolonne i ledgeren:** en cache af en ren funktion af immutable data. Fravalgt.
- **Ordrelinje-meta som kilde:** tabes ved permanent ordresletning, og der bliver to kilder. Fravalgt.
- **Fast profit pr. enhed uanset rabat:** strider mod den godkendte rabatregel. Fravalgt.
- **Beregning ud fra nuværende regler:** ændrer historikken. Fravalgt.

## Reason
Hver ordrelinje bærer den pricing, kunden faktisk så. Ledgeren forbliver uændret og append-only, og profit er deterministisk og reviderbar.

## Migration/data implications
- Migration 6: tabellen `merchboard_sales_line_pricing` uden fremmednøgler (se DATABASE.md). `merchboard_sales` er uændret.

## Consequences
- **Implementering:** hook ved oprettelse af ordrelinjer (senere trin) og beregningsfunktion.
- **Tests:** udsalg, kupon, delvis og fuld refund, reversal, korrektioner, ordre- og produktsletning, ejerskift, afvigelse, valuta og ordrer før fase 22.

## Related
ADR-019, ADR-037, ADR-038, ADR-045, ADR-046.

### Præcisering 2026-10-08: fundamentet (fase 22, trin 3)
Beslutningerne er uændrede. Implementeret: tabellen, `SalesLinePricingRepository` (kun `insert` og `find`, ingen update- eller delete-metode), `SalesLinePricingCapture` (ren regel) og `RealizedProfit`. Hooket på ordrelinjer er ikke implementeret.

- **Status (pkt. 2):** `priced` følger præcis de tre betingelser, uanset enhedens øvrige tilstand. Fx giver `config_changed` eller en ændret præcision stadig `priced` med de anvendte værdier, fordi kunden betalte den anvendte pris.
- **Årsager:** en `unpriced`-linje får enhedens tilstandsårsag (ADR-045, præcisering). `unavailable` svarer til årsagerne under `cannot_apply`.
- **Ingen snapshot uden ejer:** en linje uden creator får intet snapshot. Den kommer heller ikke i ledgeren.
- **Dobbelt insert:** en ekstra INSERT for samme ordrelinje (også samtidig) beholder det første snapshot uændret og rapporteres som "ikke gemt", ikke som fejl.
- **Realiseret profit (pkt. 3):** kræver også, at snapshottets `order_item_id` matcher rækkens. Er `price_unit` 0, er profitten 0.
- **Ingen CHECK-constraints:** sammenhængen mellem `priced`, årsag og beløb håndhæves af `SalesLinePricing` ved skrivning og læsning.

### Statusnote 2026-10-08: moms og payout (ADR-048)
`RealizedProfit` (pkt. 3) er uændret og er, fordi ledgerens `net_amount` er ekskl. moms, creatorens profit **ekskl. moms**. Payout er et separat begreb (`RealizedPayout`, ADR-048) med creatorens momsstatus fra snapshottet; snapshottet har fået feltet `vat_registered`.

### Præcisering 2026-10-08: snapshot og ledger-integration (fase 22, trin 5)
Beslutningerne er uændrede. Detaljerne står i WOOCOMMERCE.md.

- **Hook (pkt. 1):** `woocommerce_new_order_item`, kun for produktlinjer på `shop_order`; refund-linjer springes over. Repositoriets append-only-insert er den sidste beskyttelse mod dobbelt registrering.
- **Kun persisteret pricing:** snapshottet bruger aldrig WooCommerce' regular price som foreslået grundpris. Uden gemt item pricing er årsagen `no_base_price`.
- **Fejl:** snapshottet kaster aldrig og afbryder aldrig ordreoprettelsen. En fejl logges og efterlader linjen uden snapshot, altså med ukendt profit.
- **Matching (pkt. 3):** `RealizedProfit::for_entry()` kræver desuden samme ordre, parent-produkt og variation som ledger-rækken (ud over ordrelinje, creator og valuta).
- **Ledger:** profit hentes on demand via `SalesProfit`. Der er ingen profitkolonne, og ledgerens schema og invarianter er uændrede.

### Statusnote 2026-10-09: delvist erstattet af ADR-049
Princippet består: et immutable snapshot pr. ordrelinje ved `woocommerce_new_order_item`, append-only, ingen profitkolonne i ledgeren, aldrig beregning ud fra nuværende regler. Indholdet og beregningen er erstattet af ADR-049:

- Snapshottet gemmer nu creatorens **andel** (`share_bps`), dens kilde (`share_source`), butikkens prisdecimaler og momsstatus på **hver** linje med creator. Status er `share`; `priced`/`unpriced` og grundpris/profit/listepris pr. enhed skrives ikke længere.
- Punkt 3 er erstattet: realiseret profit = `round_half_away((net_amount + rækkens faktiske moms) × share_bps / 10 000)` til butikkens præcision. Den gamle formel `net × profit_unit / price_unit` er trukket tilbage.
- Snapshots fra før migration 8 (`priced`/`unpriced`) bevares uændrede, men har ukendt andel og dermed ukendt profit. De genfortolkes aldrig.

---

# ADR-048: Creatorens momsstatus og historisk payout (fase 22)

## Date
2026-10-08

## Status
Accepteret (fase 22, før trin 7). Besluttet af projektejeren 2026-10-08 efter arkitekturanalysen: profit forbliver ekskl. moms, den faktiske moms gemmes pr. ledger-række (mulighed A), standard er "ikke momsregistreret", og moms må slås midlertidigt til på Merch Dashboard Local til runtime-test. Supplerer ADR-045 og ADR-047. ADR-037/038 er uændrede (se statusnoten der).

## Context
Ny forretningsregel: momsregistrerede creators får deres profit udbetalt inkl. moms, ikke-momsregistrerede ekskl. den moms, der faktisk gælder for det konkrete salg. Analysen viste:

1. **Ledgerens grundlag:** `net_amount` er linjens beløb **ekskl. moms**, efter WooCommerces egen opdeling (`get_total`, ADR-037/038).
   - `RealizedProfit = round(net × profit_unit / price_unit)` er derfor creatorens andel **ekskl. moms**.
   - Forholdet profit/pris afhænger ikke af, om butikkens priser er inkl. eller ekskl. moms.
2. **Hvor momsen findes:** WooCommerce har moms pr. ordrelinje og pr. refund-linje (`get_total_tax`). For reversals og korrektioner findes den ikke; de udledes af ledgerens egne balancer.
3. **Når data forsvinder:** efter slettede refunds og ordrer kan WooCommerce ikke længere levere momsen.

## Decision
1. **Creatorens momsstatus:** `merchboard_creators.vat_registered` (0/1).
   - Standard er 0 (ikke momsregistreret) for nye og eksisterende creators. Det udbetaler det lavere beløb, indtil admin eksplicit vælger "Ja".
   - Den er forretningsdata på creatoren, aldrig user meta.
   - Den ændres kun via `CreatorPricingSettings::set_vat_registered()` (`merchboard_manage_creator_pricing`) og auditeres som `creator_vat_status_changed`.
2. **Snapshot:** `merchboard_sales_line_pricing.vat_registered` gemmer ejerens status, når ordrelinjen oprettes (ADR-047).
   - Snapshots fra før schema-version 7 har NULL (ukendt), og det udfyldes aldrig bagefter.
   - En senere ændring af creatorens status ændrer aldrig snapshottet.
3. **Faktisk moms pr. ledger-række:** append-only-tabellen `merchboard_sales_tax` (`event_key` → `tax_amount`, valuta).
   - Den skrives af `SalesRepository::write()` i ledgerens transaktion, kun for rækker, der faktisk indsættes.
   - Planneren beregner rækkens moms efter samme regel som beløbet:
     - salg: linjens moms;
     - refund: refund-linjens egen moms;
     - `c-rd`/`c-rdo`/`c-ro`: spejlet gemt moms;
     - reversal: `−` momsbalancen;
     - linjeændring: WooCommerces moms (linje + refunds) minus momsbalancen.
   - Momsen læses via CRUD (`get_total_tax( 'edit' )`). Der bruges aldrig en sats, en global standard, produktets momsklasse eller nuværende indstillinger.
   - En ukendt værdi (ikke en ren decimal, eller rækker fra før version 7) gør linjens momsbalance ukendt. Der skrives så ingen momsrække, og den gættes aldrig.
4. **Profit og payout er adskilt** (`RealizedProfit` og `RealizedPayout`):
   - **profit** = `round_half_away( net × profit_unit / price_unit, 4 )` (uændret, ekskl. moms);
   - **ikke momsregistreret:** payout = profit. Den faktiske moms er allerede trukket fra af WooCommerce i `net`;
   - **momsregistreret:** payout = `round_half_away( (net + tax) × profit_unit / price_unit, 4 )` med rækkens gemte faktiske moms.
5. **Afrunding og præcision:**
   - 1/10 000-enheder, én eksakt division (`PricingMath::mul_div_round`), half away from zero, ingen floats og ingen omregning.
   - Fortegn følger rækken, så refunds, reversals og korrektioner giver negative eller positive beløb.
   - En momsfri linje (tax 0) giver samme payout begge veje. Pris 0 giver 0.
6. **Ukendt (null):**
   - intet matchende `priced`-snapshot (ordre, ordrelinje, creator, valuta, produkt og variation, som ADR-047);
   - snapshot uden momsstatus;
   - for momsregistrerede: ingen gemt moms for rækken, eller moms i en anden valuta.
   - Der er aldrig fallback til creatorens nuværende status, nuværende priser eller momsindstillinger.
7. **Ledgeren:** `merchboard_sales` får ingen kolonne, og dens rækker, invarianter, idempotens og tællende statusser er uændrede. Profit og payout beregnes ved læsning (`SalesProfit`) og gemmes aldrig.

## Alternatives considered
- **Momsandel snapshottet én gang pr. ordrelinje:** en refund med ikke-proportional moms ville blive beregnet ud fra linjens andel i stedet for sin egen moms. Fravalgt.
- **Moms læst live fra WooCommerce:** overlever ikke slettede refunds og ordrer, og ordrer kan ændres bagefter. Fravalgt.
- **Fast eller konfigurerbar momssats i Merch Dashboard:** strider mod beslutningen og forkert for nulsats, andre satser og fritagne linjer. Fravalgt.
- **Moms- eller payout-kolonne i `merchboard_sales`:** strider mod den låste ledger. Fravalgt.
- **"momsregistreret" som standard:** overbetaler ikke-registrerede, indtil admin retter det. Fravalgt.

## Reason
Payout følger kun uforanderlige historiske data: ledger-rækken, snapshottet og den moms, WooCommerce havde for netop den række. Det er korrekt for alle rækketyper og alle satser, kræver ingen sats og ændrer ikke ledgeren.

## Security
- Momsstatus kan kun ændres af `merchboard_manage_creator_pricing` (capability, nonce og audit). Creators kan hverken se eller ændre den.
- Snapshot- og momsrækker indeholder kun ID'er, beløb, valuta og status, aldrig kundedata.

## Migration/data implications
- Migration 7 (`AddCreatorVatAndSalesTax`):
  - kolonnen `merchboard_creators.vat_registered` (default 0);
  - kolonnen `merchboard_sales_line_pricing.vat_registered` (NULL for eksisterende snapshots);
  - tabellen `merchboard_sales_tax`.
- Ledger-rækker fra før version 7 har ingen moms, så payout er ukendt for momsregistrerede. Der sker ingen backfill.
- Opt-in-uninstall sletter `merchboard_sales_tax` sammen med de øvrige tabeller.

## Consequences
- Fase 18-skrivevejen (læser, planner og repository) bærer nu momsen ved siden af beløbet. Ledgerens beløb og nøgler beregnes uændret.
- **Kendt grænse:** en ren momsændring på en linje uden ændret beløb eller antal giver ingen ny ledger-række, og dermed heller ingen momsrække. Momsbalancen følger rækkerne.
- Admin-UI: feltet "Momsregistreret" (Ja/Nej) på creatorens Pricing-sektion med forklaring.
- Der er endnu intet creator-API og ingen `/me/payout`.

## Related
ADR-019, ADR-024, ADR-025, ADR-037, ADR-038, ADR-045, ADR-046, ADR-047.

### Statusnote 2026-10-09: payout-formlen erstattet af ADR-049
Punkt 1–3 og 7 består: creatorens momsstatus (standard "ikke momsregistreret", audit), status i snapshottet ved oprettelsen, `merchboard_sales_tax` med den faktiske moms pr. ledger-række og den uændrede ledger. Punkt 4 er erstattet, besluttet af projektejeren 2026-10-09:

- **Profit:** salgsprisen × markup %, hvor salgsprisen er det beløb, kunden betalte inkl. moms (`net + moms`).
- **Momsregistreret:** payout = profit.
- **Ikke momsregistreret:** payout = profit × 80 % (et fradrag på 20 % af profitten).
- Formlen `(net + tax) × profit_unit / price_unit` og "payout = profit ekskl. moms" er trukket tilbage. Den gemte moms bruges kun til at genskabe det betalte beløb inkl. moms, aldrig som sats eller som separat profitformel. Der antages aldrig en fast sats.
- Det ukendte tilfælde "momsregistreret uden gemt moms" gælder nu alle: uden rækkens gemte moms er salgsprisen, og dermed profit og payout, ukendt.

---

# ADR-049: Creator-profit som andel af WooCommerce-salgsprisen (fase 22)

## Date
2026-10-09

## Status
Accepteret. Besluttet af projektejeren 2026-10-09 som låst forretningsregel, med fire afklaringer samme dag: pris-skrivningen fjernes helt, produktspecifik procent i stedet for fast profit, grundlaget er det faktisk betalte beløb inkl. moms, og ny migration 8 med ukendt andel for gamle snapshots. Erstatter ADR-045 og ADR-046 og dele af ADR-047 og ADR-048 (se statusnoterne der). ADR-037/038 er uændrede.

## Context
Den første fase 22-model (ADR-045) lagde creatorens profit oven på en grundpris og skrev resultatet til WooCommerce. Det er forkert: WooCommerce-salgsprisen er allerede den endelige kundepris inkl. creator-profit og moms. Merch Dashboard må ikke øge den og må aldrig skrive den.

## Decision
1. **WooCommerce ejer prisen.** Merch Dashboard læser aldrig en pris for at skrive den, skriver aldrig regular price, sale price eller `_price`, og har ingen prisfiltre. `PricingProductStore` har ingen skrivemetode.
2. **Markup** er creatorens andel af salgsprisen i basis points (0–10 000, 1 bp = 0,01 %), standard 0. Den ligger på creatoren (`merchboard_creators.default_markup_bps`). Et parent-produkt kan få sin egen markup (`merchboard_pricing_overrides.markup_bps`), som erstatter creatorens for produktet og alle varianter; de summeres aldrig. En produkt-markup gælder kun for den creator, der ejede produktet, da den blev sat.
3. **Formel (`CreatorShare`):**
   - `creator_profit = salgspris × markup / 100`;
   - momsregistreret: `creator_payout = creator_profit`;
   - ikke momsregistreret: `creator_payout = creator_profit × 0,80`.

   Eksempel: 100,00 × 20 % = 20,00; udbetaling 20,00 (momsregistreret) eller 16,00 (ikke momsregistreret).
4. **Salgsprisen pr. ledger-række** er det beløb, kunden faktisk betalte inkl. moms: rækkens `net_amount` (efter alle rabatter, ekskl. moms, ADR-037) + rækkens faktiske moms (`merchboard_sales_tax`, ADR-048). Udsalg, kuponer og manuelle rabatter indgår derfor automatisk. Refunds, reversals og korrektioner har fortegn og giver negativ eller positiv profit og payout.
5. **Afrunding:** heltal i 1/10 000-enheder, én eksakt division, half away from zero, til butikkens prisdecimaler fra snapshottet. Payout afrundes fra den afrundede profit. Ingen floats. Der afrundes pr. ledger-række.
6. **Snapshot (ADR-047):** når en produktlinje med creator oprettes, gemmes ejeren, den gældende andel (`share_bps`), kilden (`creator`/`product`), butikkens prisdecimaler og ejerens momsstatus. Senere ændringer af markup, produkt-markup, momsstatus, WooCommerce-pris eller ejer ændrer aldrig snapshottet, profitten eller udbetalingen.
7. **Ukendt (null):** uden matchende snapshot med andel (ordre, ordrelinje, creator, valuta, produkt og variation), uden rækkens gemte moms i rækkens valuta, eller (kun payout) uden momsstatus i snapshottet. Der er aldrig fallback til nuværende værdier.
8. **Ledgeren** er uændret (ingen kolonne, samme invarianter); profit og payout beregnes ved læsning (`SalesProfit`).

## Alternatives considered
- **Markup oven på en grundpris (ADR-045):** strider mod reglen. Trukket tilbage.
- **Profit ud fra linjens moms eller `(net + tax) × profit_unit / price_unit`:** forbudt af reglen.
- **Listepris på salgstidspunktet × antal:** ignorerer rabatter og kuponer. Fravalgt af projektejeren.
- **Fast profit pr. enhed:** har ingen entydig betydning i en andelsmodel. Erstattet af produktspecifik procent.
- **Omskrive migration 6/7:** kræver nulstilling af tabeller. Fravalgt; migration 8 er additiv for historikken.
- **Genfortolke gamle snapshots:** deres `markup_bps` var en markup på grundprisen, ikke en andel af salgsprisen. Fravalgt; de forbliver ukendte.

## Reason
Én entydig model: kunden betaler WooCommerce-prisen, creatoren får en kendt andel af det betalte beløb, og udbetalingen afhænger kun af den momsstatus, der gjaldt, da ordrelinjen blev oprettet.

## Security
- Uændret: `merchboard_manage_creator_pricing` for markup og momsstatus, desuden `edit_product` på parent for produkt-markup. Nonce bundet til handling og creator; audit pr. ændring.
- Snapshots indeholder kun ID'er, procent, decimaler, valuta og status, aldrig kundedata.

## Migration/data implications
Migration 8 (`AddCreatorShareModel`):
- `merchboard_sales_line_pricing` får `share_bps`, `share_source` og `price_decimals` (NULL for eksisterende rækker). Eksisterende rækker og deres gamle kolonner ændres ikke.
- `merchboard_pricing_overrides` genoprettes med `markup_bps` (aktuel konfiguration, ingen historik). Faste beløb kan ikke omregnes uden at gætte og slettes.
- `merchboard_item_pricing` (grundpriser og anvendte priser) droppes. Konstanten bevares som "retired", så opt-in-uninstall også fjerner tabellen på en installation, hvor migration 8 endnu ikke har kørt.
- WooCommerce-priser, ledgeren, `merchboard_sales_tax` og audit-loggen røres ikke. Fase 22 var ikke frigivet; på Merch Dashboard Local var de berørte tabeller tomme.

## Consequences
- Admin-sektionen viser markup, momsstatus og pr. produkt den gældende andel, WooCommerce-priserne (kun læsning) og et eksempel på profit og udbetaling ved den aktuelle pris. Apply, drift, grundpris og preview-panelet er fjernet.
- Audit-actions `creator_pricing_applied`, `creator_pricing_drift_overwritten` og `product_fixed_profit_set`/`_removed` skrives ikke længere, men bevares til historikken. Nye: `product_markup_set`/`_removed`.
- **Åbent spørgsmål (moms og bogføring):** et fradrag på 20 % af en profit beregnet af et beløb inkl. 25 % moms svarer præcis til momsandelen (20/1,25 = 16). For linjer med en anden sats eller 0 % (fx momsfritaget salg eller eksport) afviger fradraget fra den faktiske moms. Reglen er implementeret som besluttet; om fradraget skal være momsandelen ved andre satser, skal afklares med projektejeren og eventuelt revisor, før payout bruges til afregning.
- Rækker uden gemt moms (registreret før schema-version 7) har ukendt profit; der sker ingen backfill.

## Related
ADR-007, ADR-019, ADR-022, ADR-030, ADR-037, ADR-038, ADR-045, ADR-046, ADR-047, ADR-048.

### Præcisering 2026-10-09: review før afslutning af fase 22
Beslutningerne er uændrede. Præciseret:

- **Forudsætning om moms i prisen:** modellen forudsætter, at WooCommerce-prisen, kunden betaler, er inkl. moms (butikken bruger "priser inkl. moms"). Grundlaget for profitten er altid det faktisk betalte beløb inkl. moms (`net_amount` + rækkens gemte moms). Indtastes priser ekskl. moms, beregnes profitten stadig af det betalte beløb inkl. moms; det er ikke valideret mod butikkens indstilling.
- **20 % er et fast fradrag, ikke moms:** for ikke-momsregistrerede er udbetalingen creator-profitten × 0,80. Fradraget er altid 20 % af profitten. Det beregner ikke og afhænger ikke af linjens faktiske WooCommerce-moms, sats eller momsklasse; den gemte moms bruges kun til at genskabe det betalte beløb. Ved 25 % moms svarer fradraget tilfældigvis til momsandelen af profitten; ved andre satser gør det ikke (åbent spørgsmål ovenfor).
- **Ukendt er aldrig 0:** profit og udbetaling er `null` (ukendt), når snapshot, andel, prisdecimaler, rækkens moms eller (for udbetalingen) momsstatus mangler. En ukendt moms på en linje gør også dens reversal ukendt; en refund eller korrektion med sin egen kendte moms beregnes for sig selv. Kun en faktisk markup på 0 % giver 0.
- **Migration 8 og faste beløb:** sletningen af `merchboard_pricing_overrides` sker kun, mens tabellen mangler `markup_bps`. Fejler `DROP`, afbrydes migrationen, før `dbDelta()` kører, så gamle rækker med fast profit aldrig kan blive til produktmarkups på 0 %. Fase 22 er ikke frigivet (migration 6–8 findes ikke i nogen commit); de eneste miljøer, der har kørt dem, er integrationstest-databasen og Merch Dashboard Local, hvor tabellen var tom.

---

# ADR-050: Creator-profit i dashboardet pr. produkt, ordre og periode (fase 22)

## Date
2026-10-09

## Status
Accepteret. Besluttet af projektejeren 2026-10-09 som opgave til fase 22 ("Creator dashboard: profit pr. produkt, salg og ordre"). Udvider ADR-039 (P3 udskød beløb i Orders "i fase 19") og ADR-040 (rapportformatet) additivt; beregningen er uændret ADR-049. Ingen låst beslutning ændres.

## Context
Creators skal se deres historiske profit pr. produkt, pr. ordre og for en valgt periode. Profitten findes pr. ledger-række (ADR-049: `RealizedProfit`), men endpoints har brug for summer over mange rækker. En række kan mangle data (intet snapshot med andel, ingen gemt moms), og så må en sum aldrig se komplet ud.

## Decision
1. **Ét profitobjekt** i alle tre endpoints: `{ "currency", "status", "amount" }`.
   - `complete`: alle rækker er kendte; `amount` er summen (kan være 0 eller negativ).
   - `incomplete`: nogle rækker mangler historiske data; `amount` er `null` (den kendte del er hverken en nedre eller øvre grænse, fordi refunds og reversals er negative).
   - `unknown`: ingen række er kendt; `amount` er `null`.
   Ukendt eller ufuldstændig profit vises aldrig som 0. Feltet hedder profit og er ikke udbetaling; udbetaling vises ikke i denne opgave.
2. **Felter:**
   - `/me/products`: `profit` = liste pr. valuta over creatorens ledger-rækker for parent-produktet og dets variationer (al tid), eller `null` uden salg.
   - `/me/orders`: `profit` (linjens rækker), `order_profit` (creatorens linjer i samme ordre, også på andre sider) og `order_lines` (antal). Ledgerens `order_id` og `order_item_id`; ingen ny ejerrelation.
   - `/me/sales`: `profit` pr. valuta for de samme rækker og samme periode som `totals`. Salgstallene er uændrede.
   De eksisterende felter, paginering, sortering og privacy-regler er uændrede; kun creatorens egen profit tilføjes (ingen kundedata, ingen salgsbeløb i Orders).
3. **Én aggregering** (`CreatorProfits`, implementeret af `LedgerCreatorProfits`) bruges af alle tre endpoints. Den læser kun pluginets egne tabeller (`merchboard_sales` + `merchboard_sales_line_pricing` + `merchboard_sales_tax`, joinet på nøgler), ingen WooCommerce-tabeller, med én forespørgsel pr. kald (ingen N+1) og ingen nye indexes.
4. **Formlen i SQL er ADR-049 pr. række** (`ROUND((net + moms) × share_bps × 0,0001, prisdecimaler)`, eksakt DECIMAL, half away from zero) og summeres derefter. Som ADR-047 pkt. 5 kræver, testes det, at hver sum er lig summen af `RealizedProfit` over de samme rækker, også for refunds, reversals, korrektioner og afrundingsgrænser. Kendt-betingelsen er den samme som i `RealizedProfit`.
5. **Kun creatorens egne rækker** (ledgerens `creator_id` fra `CreatorContext`); et creator-ID fra klienten bruges aldrig.
6. **Cache:** `/me/sales` caches som før (ADR-040 pkt. 10); rapportformatet er bumpet til `v2`, og ledger-skrivninger invaliderer som hidtil. Snapshots skrives før ledger-rækkerne og ændres aldrig, og momsrækker skrives i ledgerens transaktion, så ledger-invalideringen dækker profitten. `/me/products` og `/me/orders` caches ikke (ADR-039 P12).

## Alternatives considered
- **Profit pr. række i PHP og summering i PHP:** samme formel ét sted, men læser alle rækker i perioden ind i PHP (op til hundredtusinder for 5 år) og regner med cifferstrenge pr. række. Fravalgt af ydelseshensyn; i stedet sikrer ækvivalenstests, at SQL og PHP er ens.
- **En delvis sum ved ufuldstændige data:** misvisende, fordi negative rækker kan mangle. Fravalgt.
- **Profit pr. ordre som separat liste:** dashboardets ordreoversigt er pr. linje (ADR-039 P2); linjeprofit plus ordretotal bevarer kontrakten.
- **Payout i samme kolonne:** blander to begreber (ADR-049). Fravalgt.

## Reason
Creators ser én konsistent profit i alle tre visninger, beregnet efter ADR-049 fra historiske snapshots, og en ufuldstændig historik kan aldrig ligne et komplet beløb.

## Security
- Uændret adgangsmodel: `CreatorContext` i permission callback og endpoint; kun ledger-rækker med creatorens `creator_id`.
- Ingen kundedata; profitobjektet indeholder kun valuta, status og beløb.

## Migration/data implications
Ingen migration og ingen nye tabeller, kolonner eller indexes. Cache-formatet for `/me/sales` er `v2`.

## Consequences
- Dashboardet viser kolonnen **Profit** i Products og Orders og nøgletallet **Profit** i Sales (ikke i Overview) med en forklaring af "Ufuldstændig" og "Ukendt".
- `/me/sales` laver én ekstra ledger-forespørgsel ved cache-miss.
- Åbne begrænsninger: profit for ældre rækker (før schema-version 7/8) er ukendt; afrunding sker pr. række (ADR-049).

## Related
ADR-007, ADR-037, ADR-038, ADR-039, ADR-040, ADR-047, ADR-048, ADR-049.

### Præcisering 2026-10-09: Overview og "Ufuldstændig" på Merch Dashboard Local
Beslutningerne er uændrede.

- **Overview** viser nu også nøgletallet Profit for sin standardperiode (de seneste 30 dage). Det er det samme `GET /me/sales`-kald og det samme profitobjekt som i Sales, så der er ingen ny API og ingen tredje beregning. Konsekvensafsnittets "ikke i Overview" er dermed erstattet.
- **Diagnose af "Ufuldstændig" i Sales:** creator 1 havde i perioden 12 ledger-rækker. 4 rækker fra en ordre oprettet efter migration 8 har snapshot med andel og gemt moms (1,67 + 1,67 + 1,67 + 2,00). 6 rækker fra ordrer oprettet 2026-10-01 og 2026-10-07 er registreret før snapshots og gemt moms fandtes. 2 rækker (ordrelinjer oprettet 2026-10-01 og 2026-10-08 for et produkt, der først senere fik creatoren) fik ledger- og momsrækker ved en senere synkronisering, men intet snapshot, fordi snapshottet kun tages, når ordrelinjen oprettes, og kun når produktet da har en creator (ADR-047). Status `incomplete` er derfor korrekt og ikke en fejl i hook, ledger, beregning eller cache. Der gættes ingen andel bagud; en eventuel tilbagevirkende tildeling ville kræve en ny, eksplicit og auditeret forretningsbeslutning.

### Note 2026-10-09 (review): aktuel diagnose af "Ufuldstændig" på Merch Dashboard Local
Beslutningerne og den foregående note er uændrede; noten ovenfor beskriver tilstanden på sit tidspunkt (12 rækker).

Ved reviewet (kun læsning, SELECT og `wc_get_order`; ingen data ændret) havde creator 1 **14 ledger-rækker** i Sales' standardperiode (2026-09-10 til og med 2026-10-09, UTC), alle i DKK: **6 kendte og 8 ukendte**, og derfor status `incomplete` uden beløb.

- **6 kendte:** ordre 330 (linjerne 185–188) og ordre 333 (linjerne 190 og 191). De har snapshot med andel (1667 bps, kilde `creator`, 2 decimaler) og gemt moms (0,00).
- **6 ukendte uden både snapshot og gemt moms:** ordre 177 (linjerne 88, 89 og 91) og ordre 201 (linjerne 103–105). De er registreret, før gemt moms (migration 7) og snapshots med andel (migration 8) fandtes.
- **2 ukendte med gemt moms, men uden snapshot:** linje 90 (ordre 177) og linje 116 (ordre 217), begge for produkt 16. Snapshottet tages kun, når ordrelinjen oprettes, og kun hvis produktet da har en creator (ADR-047).
- Ordrerne 177, 201, 217 og 330 ligger i WooCommerce' papirkurv og tæller fortsat med (ADR-037: papirkurven ændrer intet). Ordre 333 har status `processing`. Hvem der har oprettet ordre 333, er ikke afklaret; den og dens ledger-data er ikke ændret eller undersøgt yderligere.

Ingen række er gjort til 0, og ingen andel er udledt af nuværende markup, ejerskab eller momsstatus.

---

# ADR-051: Admin-ordrehistorik for sales ledgeren (fase 22)

## Date
2026-10-09

## Status
Accepteret som ren visning (projektejerens beslutning 2026-10-09; se "Beslutning 2026-10-09: kun læsning" nederst). Oprindelig status: Delvist accepteret. Besluttet af projektejeren 2026-10-09 som opgave til fase 22 ("Admin UI for sales ledger and order history"). Visningen og diagnosen (pkt. 1–6) er implementeret. **Sletning af Merch Dashboard-data pr. ordre (pkt. 7) er ikke implementeret**, fordi den strider mod låste beslutninger; den afventer projektejerens beslutning. Ingen låst beslutning er ændret.

## Context
Admin skal kunne se, hvad Merch Dashboard har gemt pr. WooCommerce-ordre (ledger-hændelser, ordrelinjer, snapshots og gemt moms), og hvorfor en ordres profit er komplet, ufuldstændig eller ukendt. Opgaven bad også om sletning af pluginets data pr. ordre, men med instruktionen at stoppe og rapportere, hvis sletningen ville bryde en dokumenteret uforanderlighedsregel.

## Decision
1. **Side:** "Merch Dashboard → Order history" (`admin.php?page=merchboard-order-history`, `OrderHistoryPage`, WooCommerce-modulet), kun med WooCommerce. Undermenu under den eksisterende Merch Dashboard-menu; ingen ny capability: `merchboard_manage_creators` (samme som Sales på creatorens side). Andre afvises med `wp_die` 403. Siden er ren GET og skriver intet (ingen formularer med sideeffekt, ingen nonce nødvendig, ingen audit).
2. **Liste:** én række pr. ordre i ledgeren (`OrderHistory`), server-side pagineret (20 pr. side) og sorteret efter første ledger-dato, nyeste først. Kolonner: ordre-ID (link til detaljen), ledger-datoer (første/seneste), creators, valuta, hændelser/linjer (og antal `sale`-rækker), gemt nettobeløb, momsdata (rækker med gemt moms af alle) og profitdata (komplet med beløb, ufuldstændig eller ukendt med antal kendte og ukendte rækker). Filtre: ordre-ID, creator og profitstatus. Ugyldige parametre ignoreres med en notice. Én grupperet SQL på pluginets egne tabeller; profitbetingelsen og -formlen er de samme konstanter som i `LedgerCreatorProfits` (ADR-050), så listen og dashboardet aldrig kan være uenige.
3. **Detalje pr. ordre** (`OrderHistoryDetails`): WooCommerce-status og link (kun via CRUD; "Deleted in WooCommerce", hvis ordren ikke findes), ledgerens cyklusser (åbnet/lukket), ordrelinjer med deres snapshot (andel, kilde, momsstatus, eller "Missing"/"Unreadable"/"Withdrawn model") og alle ledger-hændelser med event-nøgle, type, korrektion, creator, linje, produkt, antal, netto, gemt moms og profit eller "Unknown" med årsag.
4. **Diagnose** (`ProfitDiagnosis`, `ProfitGap`): profitten kommer altid fra `RealizedProfit`; kun når den er ukendt, bestemmes én årsag i fast rækkefølge: intet snapshot, ulæseligt snapshot, snapshot fra den tilbagetrukne model, snapshot for en anden creator/produkt/variation/ordre/valuta, ingen gemt moms, moms i anden valuta, andet. Manglende data udfyldes aldrig, genberegnes aldrig fra nuværende indstillinger og vises aldrig som 0 (ADR-049).
5. **Ingen PII:** ingen kundedata, adresser, e-mails eller betalingsdata; kun ID'er, creatornavne, produkt-ID'er, beløb og status.
6. **Ingen nye tabeller, kolonner eller indexes.**
7. **Sletning af pluginets data pr. ordre: ikke implementeret.** Den ville fjerne rækker i `merchboard_sales` og `merchboard_sales_cycles` (ordrens `order_id`), `merchboard_sales_tax` (rækkernes `event_key`) og `merchboard_sales_line_pricing` (ordrens `order_item_id`) og invalidere salgscachen. Det strider mod:
   - ADR-037 pkt. 2 (ledgeren er append-only) og pkt. 8 (permanent sletning af en ordre registreres som reversal, og historikken bevares);
   - ADR-038 pkt. 5 (historiske ledger-rækker slettes eller ændres aldrig);
   - ADR-047 (snapshots er INSERT-only og uforanderlige);
   - ADR-048 (gemt moms er append-only);
   - ADR-023/026 (audit er append-only; en sletning skal kunne forklares bagefter).
   Desuden: findes WooCommerce-ordren stadig, registrerer næste synkronisering dens rækker igen ud fra den aktuelle tilstand, men uden snapshot (det tages kun ved `woocommerce_new_order_item`), så profitten bliver permanent ukendt. Sletningen kræver derfor en ny ADR og en eksplicit forretningsbeslutning.

## Alternatives considered
- **Sletning trods ADR'erne:** fravalgt; opgaven krævede stop ved konflikt.
- **WP_List_Table:** fravalgt for at holde én grupperet SQL med HAVING-filter og egen paginering; siden følger de eksisterende server-renderede afsnit.
- **Diagnose i SQL:** fravalgt; detaljen bruger de samme PHP-klasser (`RealizedProfit`) som beregningen, så årsagen aldrig afviger fra resultatet.

## Reason
Admin får indsigt i præcis det, pluginet har gemt, og hvorfor profitten mangler, uden at historikken kan ændres.

## Security
- Capability tjekkes i menuen og i `render()`; ingen skrivninger, så ingen nonce eller audit.
- Alle parametre valideres (positive heltal, kendt status); output escapes.
- WooCommerce læses kun via `wc_get_order` (HPOS-sikkert) og ændres aldrig.

## Migration/data implications
Ingen.

## Consequences
- Ny undermenu "Order history".
- Åben beslutning: sletning pr. ordre. Forslag, hvis projektejeren vil have den: en ny ADR, der udtrykkeligt undtager ADR-037/038/047/048; en egen capability; POST med nonce og indtastet ordre-ID som bekræftelse; én transaktion over de fire tabeller; audit-posten med antal slettede rækker pr. tabel (ingen beløb eller kundedata); kun tilladt, når WooCommerce-ordren ikke længere findes (eller med en vedvarende "registrér ikke igen"-markering); invalidering af salgscachen for de berørte creators.

## Related
ADR-023, ADR-026, ADR-037, ADR-038, ADR-040, ADR-047, ADR-048, ADR-049, ADR-050.

### Beslutning 2026-10-09: kun læsning
Projektejeren har besluttet den åbne del (pkt. 7): **fysisk sletning af ordrehistorik er ikke en del af fase 22.**

- Append-only- og snapshotreglerne i ADR-037, ADR-038, ADR-047 og ADR-048 gælder uændret; der indføres ingen undtagelse.
- Der slettes ingen rækker i `merchboard_sales`, `merchboard_sales_cycles`, `merchboard_sales_tax` eller `merchboard_sales_line_pricing`, og der indføres ingen vedvarende "ignorér/registrér ikke igen"-markering.
- WooCommerce-ordrer røres ikke.
- Historiske rækker uden tilstrækkeligt snapshot forbliver ukendte; profit udledes aldrig af nuværende markup, nuværende creator-ejerskab eller nuværende momsstatus.
- Siden forbliver ren visning med de eksisterende capability-tjek, validering, escaping, paginering, filtre og diagnoser. Teksterne siger nu, at historikken er append-only og aldrig ændres eller slettes (ikke blot "ikke her"), og en test sikrer, at siden ingen POST-formular, nonce, `admin-post`-handling eller sletteknap har.
- Forslaget i pkt. 7 og "Åben beslutning" under Consequences er dermed ikke længere åbne. De bevares som historik. En eventuel sletning senere kræver en ny ADR.

### Beslutning 2026-10-10: gamle ordrer skjules i visningen
Projektejeren ønskede at "cleare" de gamle ordrer fra ordrehistorikken og valgte at skjule dem i visningen frem for at slette dem. Beslutningen fra 2026-10-09 og ADR-037/038/047/048 er uændrede.

- Listen viser som standard kun ordrer med profitdata: mindst én række med snapshot med andel og gemt moms (status komplet eller ufuldstændig, filterværdien `with_data`). Ordrer, hvor ingen række har profitdata (status ukendt), skjules.
- En notits viser antallet af skjulte ordrer med linket "Vis alle ordrer" (`status=all`). Filteret har valgene "Alle ordrer", "Med profitdata", "Komplet", "Ufuldstændig" og "Ukendt". En skjult ordre kan stadig åbnes direkte.
- Kun visningen ændres: intet slettes, markeres eller gemmes, og Sales, Overview, `/me`-endpoints og creator-profit er uændrede. Der er ingen indstilling og ingen vedvarende tilstand.
- En ny ordre, hvis linjer blev oprettet, før produktet havde en creator, har heller ingen profitdata og skjules også som standard; notitsen nævner begge årsager.

### Note 2026-10-10: sletning af en ordres historik er besluttet i ADR-052
Projektejeren har ændret beslutningen fra 2026-10-09 om, at fysisk sletning ikke er en del af fase 22: admin skal kunne slette en testordres historik helt. Det er besluttet og afgrænset i ADR-052. Teksten ovenfor bevares som historik; siden er ikke længere ren visning, men selve visningen og filtrene skriver stadig intet.

---

# ADR-052: Sletning af én ordres historik, fx en testordre (fase 22)

## Date
2026-10-10

## Status
Accepteret. Besluttet af projektejeren 2026-10-10: "det skal være muligt for admin at kunne helt slette en ordre uden den fremgår, da der nogle gange skal køres en testordre igennem", med valget "når ordren er i papirkurv/slettet". Erstatter beslutningen i ADR-051 (note 2026-10-09) om, at fysisk sletning ikke er en del af fase 22. **Udtrykkelig, afgrænset undtagelse** fra append-only-reglerne i ADR-037 pkt. 2 og 8, ADR-038 pkt. 5, ADR-047 og ADR-048; de ADR'er er ellers uændrede og omskrives ikke.

## Context
Testordrer, der køres igennem butikken, registreres som rigtigt salg og indgår i creatorens Sales, Overview og profit. Ledgeren er append-only, og en permanent slettet WooCommerce-ordre registreres som en reversal, så den stadig fremgår af historikken. Admin skal kunne fjerne sådan en ordre helt.

## Decision
1. **Hvad slettes:** for én ordre alle Merch Dashboards egne rækker med ordrens `order_id`: `merchboard_sales` (ledger-rækker inkl. refunds, reversals og korrektioner), `merchboard_sales_cycles`, `merchboard_sales_tax` og `merchboard_sales_line_pricing`. Intet andet: ingen WooCommerce-ordre, ordrelinjer, refunds, produkter, priser eller WooCommerce-tabeller, ingen andre ordrer og ingen audit-records.
2. **Betingelse:** kun når WooCommerce-ordren ligger i papirkurven eller ikke længere findes (`OrderStatusLookup::erasable_state()`, via `wc_get_order()`). Begge tilstande springes over af synkroniseringen (ADR-038 Q8), så historikken ikke registreres igen. Gendannes ordren senere fra papirkurven, registreres den igen ud fra sin aktuelle tilstand uden snapshot (profit ukendt); det står i UI'et. Der indføres ingen "registrér ikke igen"-markering.
3. **Adgang:** ny capability `merchboard_delete_order_history` (kun administratorer, `RoleManager::VERSION` 4). At se ordrehistorikken kræver fortsat `merchboard_manage_creators`.
4. **Request:** `admin-post.php?action=merchboard_delete_order_history` (`OrderHistoryActions`), kun POST og kun for indloggede: capability (403), nonce bundet til ordren (403), ordre-ID'et indtastet som bekræftelse, derefter `OrderHistoryEraser`. Post/Redirect/Get med en besked. Ingen AJAX, REST eller `nopriv`.
5. **Konsistens:** sletningen sker under ledgerens ordrelås (samme som synkroniseringen) og i én transaktion (`SalesRepository::erase_order()`); betingelsen tjekkes igen under låsen. Hver DELETE er bundet til låsens forbindelse, ligesom ledgerens INSERTs. Audit-posten skrives i samme transaktion: fejler den, rulles sletningen tilbage. Efter COMMIT fornyes salgscachen for de berørte creators.
6. **Audit:** `order_history_deleted` med aktøren, ordre-ID, WooCommerce-tilstanden (`trash`/`deleted`), antal slettede rækker pr. tabel og de berørte creator-ID'er. Ingen beløb og ingen kundedata. Audit-records slettes aldrig (ADR-023).
7. **UI:** på ordrens side i "Order history" vises afsnittet "Slet gemt historik" kun med capability'en: en forklaring, hvis ordren stadig er aktiv i WooCommerce, ellers en formular med advarsel og indtastning af ordre-ID'et. Ingen massesletning og ingen "slet alt".

## Alternatives considered
- **Sletning uanset WooCommerce-status med en "registrér ikke igen"-markering:** fravalgt af projektejeren; en rigtig ordre kunne udelukkes ved en fejl.
- **Kun når WooCommerce-ordren er permanent slettet:** fravalgt; papirkurven er nok, fordi synkroniseringen springer den over.
- **Kun skjule i visningen (ADR-051, note 2026-10-10):** bevaret som standardfilter, men fjerner ikke ordren fra creatorens salg og profit.

## Reason
Testordrer skal kunne fjernes helt fra creatorens tal, uden at Merch Dashboard rører WooCommerce, og uden at historikken for andre ordrer kan påvirkes.

## Security
- Egen capability, nonce pr. ordre, indtastet bekræftelse, kun POST og kun admin-post for indloggede.
- Ordre-ID'et valideres som positivt heltal; ingen anden input bruges.
- Audit i samme transaktion; tekniske fejl logges kun med ordre-ID og fejltype.

## Migration/data implications
Ingen migration, ingen nye tabeller, kolonner eller indexes. `RoleManager::VERSION` 4 giver administratorer den nye capability ved næste request; opt-in-uninstall fjerner den som de øvrige.

## Consequences
- Creatorens Sales, Overview, Products, Orders og profit ændres for den slettede ordre (den forsvinder). Det er hensigten.
- Historiske rapporter for perioden ændres; audit-posten dokumenterer, hvem der slettede hvad.
- Sletningen kan ikke fortrydes.

## Related
ADR-023, ADR-037, ADR-038, ADR-040, ADR-047, ADR-048, ADR-049, ADR-050, ADR-051.

**Note 2026-10-11 (D5, ADR-060):** sletning afvises nu, når ordrens historik indgår i et afregningsbilag (fejlkode `settled`); ellers uændret.

---

# ADR-053: Salg vises inkl. moms (fase 22)

## Date
2026-10-10

## Status
Accepteret. Besluttet af projektejeren 2026-10-10: "Den skal stadig vise salget på både overblik og salgssiden inkl. moms i stedet for ekskl. moms." Ændrer visningen fra ADR-040 pkt. 2 og 14 (nettosalg ekskl. moms); ADR-040's ledger-definitioner og de eksisterende API-felter er uændrede.

## Context
Creatorens Overview og Sales viste "Nettosalg (ekskl. moms)" fra ledgerens `net_amount`. Projektejeren vil se det beløb, kunderne betalte, inkl. moms. Den faktiske moms pr. ledger-række er gemt siden schema-version 7 (ADR-048); ældre rækker har ingen.

## Decision
1. **Beløb inkl. moms** = rækkens `net_amount` + rækkens gemte faktiske moms i `merchboard_sales_tax` (samme rækkevalg, perioder, fortegn og valutaregler som ADR-040). Ingen sats og ingen genberegning.
2. **Manglende moms:** har en række i perioden ingen gemt moms i rækkens valuta, er beløbene inkl. moms for hele valutaen ukendte (`null`), og `vat.status` er `incomplete` med antallet af rækker. Manglende moms regnes aldrig som 0, og der blandes aldrig grundlag i en valuta.
3. **API:** additive felter i `/me/sales` (`totals.*_incl_vat`, `vat`, `series[].net_sales_incl_vat`, `products[].net_sales_incl_vat`); de eksisterende felter ekskl. moms er uændrede. Cacheformat `v3`.
4. **Visning:** Overview, Sales (nøgletal, graf, tabel) og admin-afsnittet Sales viser "Salg (inkl. moms)", når momsen er komplet, ellers "Nettosalg (ekskl. moms)" med noten "Momsen mangler for nogle ældre salg i perioden, så beløbene vises ekskl. moms." Forklaringen siger "inkl. moms, ekskl. fragt og gebyrer".
5. **Uændret:** ledgeren, enheder, ordrer, profit (ADR-049/050) og ordrehistorikken.

## Alternatives considered
- **Beregne moms med en sats:** fravalgt; det betalte beløb skal komme fra WooCommerce' faktiske moms (ADR-048).
- **Blande inkl. og ekskl. moms i samme valuta:** fravalgt; tallene ville ikke kunne sammenlignes.
- **Vise begge beløb:** fravalgt af projektejeren ("i stedet for").

## Reason
Creatorer ser det beløb, kunderne betalte, uden at historik uden moms vises forkert.

## Security
Uændret adgangsmodel (`CreatorContext`, kun creatorens rækker); kun pluginets egne tabeller læses; ingen kundedata.

## Migration/data implications
Ingen migration. Statistikforespørgslerne joiner `merchboard_sales_tax` på `event_key` (primærnøgle); ingen nye indexes.

## Consequences
- Hvis WooCommerce ikke beregner moms (moms slået fra), er den gemte moms 0, og beløbet inkl. moms er lig beløbet ekskl. moms.
- Perioder med salg fra før schema-version 7 vises ekskl. moms med noten.

## Related
ADR-037, ADR-040, ADR-041, ADR-048, ADR-049, ADR-050.

---

# ADR-054: Optjent payout og disponibelt beløb (fase 23)

## Date
2026-10-10

## Status
Accepteret. Godkendt af projektejeren 2026-10-10 sammen med D1 og D2 (rapporten [reports/2026-10-10-fase-23-payouts.md](reports/2026-10-10-fase-23-payouts.md), afsnit 3, 15.2, 15.3 og 16.1). Bygger på planlægningsbeslutning 2, 3 og 12 (afsnit 1). Trin 23.2 implementerer optjent payout; reservationer og gennemførte payouts (ADR-055) er ikke besluttet. **Note 2026-10-10:** D6 (afregningsstarten gemmes i `merchboard_creators.payout_start_at`) og D3 (afregningsstarten kan ændres med audit, indtil creatoren har et afregningsbilag, og er derefter låst; låsen følger med bilagene i trin 23.8) er godkendt af projektejeren; ADR-055 er accepteret samme dag.

## Context
Creatorer skal kunne anmode om udbetaling af det, de har optjent efter ADR-049. Beløbet skal kunne beregnes deterministisk fra ledgeren uden at gætte på ukendte data, uden at udbetale for meget ved refunds og uden at medregne historik fra før en eksplicit afregningsstart.

## Decision
1. **Optjent payout** pr. creator og valuta = Σ `RealizedPayout` (ADR-049) over ledger-rækker, der er i vinduet og frigivet. Heltal i 1/10 000; valutaer summeres og omregnes aldrig på tværs.
2. **Afregningsstart (D1):** uden afregningsstart tæller hele historikken. Med afregningsstart hører en række til vinduet, når **salgsrækken for samme ordrelinje og cyklus** (`s:<linje>:<cyklus>`) er sket på eller efter afregningsstarten. Et salg fra før afregningsstarten og dets refunds, reversals og korrektioner udelukkes derfor sammen. Mangler salgsrækken, kan rækken ikke placeres og er ukendt (blokerer). Afregningsstarten gives til aggregeringen som parameter; hvor den gemmes og hvornår den kan ændres, er ikke besluttet (D3, D6).
3. **Karens (D2):** en række med payout **< 0** tæller fra sin egen `occurred_at`; en række med payout **≥ 0** tæller, når `occurred_at < nu − karens` (standard 14 dage, planlægningsbeslutning 2). Disponibelt kan derfor midlertidigt være lavere, når et salg i karens refunderes, men aldrig for højt.
4. **Ukendt blokerer:** en række i vinduet uden kendt payout (intet snapshot med andel, prisdecimaler eller momsstatus, ingen gemt moms i rækkens valuta, eller ingen salgsrække med afregningsstart) tælles som ukendt, uanset fortegn og karens. Én ukendt række gør valutaens beløb ukendt (`null`); den behandles aldrig som 0 og springes aldrig over.
5. **Saldo** (når ADR-055 er besluttet) = optjent − gennemført − reserveret; disponibelt = saldo, hvis > 0 og kendt, ellers 0; negativ saldo vises som udestående og modregnes i fremtidig optjening (planlægningsbeslutning 12).
6. **Implementering:** én SQL-aggregering (`LedgerCreatorPayouts`) på pluginets egne tabeller med de samme rækkebetingelser og den samme profitformel som `LedgerCreatorProfits` (ADR-050) plus payout-reglen; resultatet har samme form som profit (`ProfitSummary`: `complete`, `incomplete`, `unknown`). Ingen cache.

## Alternatives considered
- **D1 b) ordrelinjens første salg:** behandler et nyt salg i en senere cyklus efter en gammel dato. Fravalgt.
- **D1 c) rækkens egen dato:** en refund efter afregningsstart af et salg fra før ville trække saldoen ned for et salg, der aldrig indgik. Fravalgt.
- **D2: alle rækker venter på karens:** kan reservere beløb, som en kendt refund allerede har fjernet. Fravalgt.
- **D2: negative rækker følger deres salgs karens:** mere kompleks uden at forhindre for højt disponibelt. Fravalgt.

## Reason
Beregningen bruger kun eksisterende, uforanderlige ledger-data, kan aldrig udbetale for meget på grund af en kendt refund og gætter aldrig på ukendte data.

## Security
Kun creatorens egne rækker (`creator_id`), kun pluginets egne tabeller, ingen kundedata. Adgang og visning følger med ADR-055/058.

## Migration/data implications
Ingen migration og ingen nye indexes: salgsrækken findes via ordre, ordrelinje og cyklus (indexet på `order_id`).

## Consequences
- En række, der ikke kan beregnes, blokerer valutaens beløb, indtil den er afklaret, eller afregningsstarten udelukker den.
- Ledger-skader (en manglende salgsrække) blokerer kun, når der er en afregningsstart.

## Related
ADR-037, ADR-038, ADR-047, ADR-048, ADR-049, ADR-050.

---

# ADR-055: Payout-statusser, reservation og idempotens (fase 23)

## Date
2026-10-10

## Status
Accepteret. Godkendt af projektejeren 2026-10-10 (rapporten [reports/2026-10-10-fase-23-payouts.md](reports/2026-10-10-fase-23-payouts.md), afsnit 4, 5, 14.5 og 19.1). Bygger på planlægningsbeslutning 8, 9 og 10. Gavekort følger samme statusser, når ADR-057 er besluttet.

## Context
En creator skal kunne anmode om udbetaling af sit disponible beløb (ADR-054), og admin skal kunne behandle anmodningen. Samme beløb må aldrig reserveres eller udbetales to gange, heller ikke ved gensendte eller samtidige requests.

## Decision
1. **Statusser** for alle metoder: `pending` (anmodet og reserveret), `completed`, `rejected` og `cancelled`. Intet separat godkendelsestrin; ingen `issuing`.
2. **Overgange** kun fremad og kun fra `pending`: `completed` eller `rejected` (admin), `cancelled` (creatoren). Beløb, metode og creator ændres aldrig; rækker slettes aldrig. Hver overgang er en betinget UPDATE efter en læsning `FOR UPDATE`.
3. **Lås og transaktion:** anmodning, annullering, afvisning og gennemførelse kører under `GET_LOCK` pr. creator og i én `Transaction` med audit; hver skrivning har `CONNECTION_ID()`-vagt, og forbindelsen kontrolleres før COMMIT (mønstret fra ADR-024/038).
4. **Reservation:** `pending` er reservationen. Saldo = optjent − gennemført − reserveret (ADR-054); `completed`, `rejected` og `cancelled` frigiver reservationen. Saldoen beregnes på ny under låsen ved anmodning og ved gennemførelse.
5. **Idempotens:** creatorens anmodning bærer en nøgle (`UNIQUE(creator_id, idempotency_key)`); en gensendt anmodning returnerer den gemte. En gentaget adminhandling, der allerede er sket, svarer "allerede behandlet" uden ny audit; en anden handling på en afsluttet payout afvises.
6. **Validering:** beløb > 0, ≤ disponibelt og med højst butikkens decimaler; "hele beløbet" rundes ned til butikkens præcision. Ukendt saldo blokerer og er aldrig 0.
7. **Loft:** højst 3 ventende og 10 nye anmodninger (uanset senere status) pr. creator i 24 timer. Intet minimum.
8. **Gennemførelse:** dækker saldoen uden payoutens egen reservation ikke længere beløbet (fx efter en refund), eller er den ukendt, afvises gennemførelsen til manuel behandling; payouten forbliver `pending` og kan afvises.

## Alternatives considered
- **Separat godkendelsesstatus før udbetaling:** et ekstra trin uden gevinst; behandlingen er godkendelsen. Fravalgt.
- **Reservation af bestemte ledger-rækker:** kompleks og unødvendig med en saldomodel. Fravalgt.
- **Kun databasens unikke nøgler uden lås:** forhindrer ikke, at to anmodninger tilsammen overskrider saldoen. Fravalgt.

## Reason
Ingen dobbelt reservation eller udbetaling, også ved timeout, dobbeltklik og samtidige requests, og ingen beslutning på grundlag af ukendte data.

## Security
Creatoren identificeres kun via `CreatorContext` af kalderen; fremmede payout-ID'er giver "ikke fundet". Admin-handlinger kræver `merchboard_manage_payouts` (ADR-058).

## Migration/data implications
Schema-version 9: `merchboard_payouts` (se DATABASE.md).

## Consequences
- REST (trin 23.4), dashboard (23.5) og admin-sider (23.6) bygger på disse services.
- En refund under behandling kan kræve, at admin afviser en anmodning.
- **Note 2026-10-11:** gavekort (ADR-057) følger de samme statusser; en gavekort-payout bliver kun `completed` ved registrering af et valideret kort, aldrig ved manuel gennemførelse.
- **Note 2026-10-12 (K6.1, låseprotokol; forberedende trin uden adfærdsændring):**
  - **Låseprotokol:** de godkendte payout-veje låser creator-rækken (`merchboard_creators`) med `FOR UPDATE` som transaktionens første sætning, før saldo, payout eller bilagsdata læses. Rækkefølgen er: den navngivne creator-lås (`GET_LOCK`), derefter `Transaction`, derefter creator-rækken. Låsen holdes til COMMIT eller ROLLBACK.
  - **Omfattede veje:** anmodning, annullering, afvisning, gennemførelse inkl. udstedelse af afregningsbilag (ADR-060), registrering af gavekort (ADR-057) og ændring af afregningsstarten (ADR-054 D3).
  - **Fælles hjælpefunktion:** `CreatorRowLock::acquire()`. Den afviser at låse, hvis den navngivne lås ikke er holdt.
  - **Ingen adfærdsændring:** alle veje holder allerede den navngivne lås for samme creator, så rækkelåsen giver ingen ny gensidig udelukkelse mellem dem. Den gør det muligt for en senere afgørelse, der låser flere creator-rækker (i stigende ID-orden) uden de navngivne låse, at vente på alle payout-veje for de creators.
  - **Endnu ikke implementeret:** sådanne udelukkelsesafgørelser findes ikke endnu.
  - **Uden for:** `SettlementDocuments::correct()`, synkroniseringen og ændring af karensperioden.
  - **Fælles rækkeregel:** samtidig bruger `LedgerCreatorPayouts::earned()` og `unsettled_rows()` samme rækkeregel (ADR-054 D1, D2 og ukendte rækker) via fælles private hjælpere. Offentlige konstanter og signaturer er uændrede.
  - **Ingen skemaændring.**
  - **Validering:** lokalt på MariaDB 10.11.19. MySQL 8 er ikke valideret, før CI har kørt.

## Related
ADR-024, ADR-038, ADR-049, ADR-054, ADR-056, ADR-058.

---

# ADR-056: Kryptering af bank- og PayPal-oplysninger (fase 23)

## Date
2026-10-10

## Status
Accepteret. Godkendt af projektejeren 2026-10-10. Bygger på planlægningsbeslutning 6 og 11.

## Context
Bank- og PayPal-oplysninger er nødvendige, mens en udbetaling venter, og må hverken lække via databasen, logs, audit eller lister.

## Decision
1. **Nøgle:** `MERCHBOARD_PAYOUT_KEY` i `wp-config.php`: 32 tilfældige bytes i base64. Nøglen afledes aldrig af WordPress' salts, gemmes aldrig i databasen og logges aldrig.
2. **Algoritme:** sodium `secretbox` (fra PHP's ext-sodium eller WordPress' sodium_compat); ciphertext = base64(nonce ‖ secretbox) med et `key_id` (hash-præfiks af nøglen) til senere nøgleskift.
3. **Lagring:** i `merchboard_payout_secrets`, adskilt fra `merchboard_payouts`. Ciphertext fjernes (`purged_at`), når payouten bliver `completed`, `rejected` eller `cancelled`. Oplysninger gemmes ikke til senere anmodninger.
4. **Uden gyldig nøgle** er bank og PayPal utilgængelige og afvises server-side. En ventende anmodning, der ikke kan dekrypteres, kan kun afvises.
5. **Visning:** overalt kun et maskeret hint ("•••• 1234", aldrig reg.nr.; "j•••@g•••.com"). Fulde oplysninger kun for en ventende payout og kun med `merchboard_view_payout_details` (ADR-058), audit-logget før visning.

## Alternatives considered
- **Nøgle afledt af WordPress' salts:** en rotation af salts ville gøre data ulæselige, og nøglen ville afhænge af hemmeligheder til andre formål. Fravalgt.
- **Kryptering i samme tabel:** enhver læsning af anmodninger ville også læse hemmeligheder. Fravalgt.
- **Ekstern hemmelighedstjeneste:** en ekstern service (ADR-016). Fravalgt.

## Reason
Følsomme oplysninger findes kun krypteret og kun så længe, de er nødvendige.

## Security
Kun administratorer med begge payout-capabilities kan se fulde oplysninger; ingen REST-route returnerer dem.

## Migration/data implications
Schema-version 9: `merchboard_payout_secrets` (se DATABASE.md). Ingen ny afhængighed (ADR-004).

## Consequences
- Et site uden nøgle kan ikke bruge bank eller PayPal, før administratoren har sat den.
- Nøgleskift kræver en senere procedure (genkryptering af ventende rækker).

## Related
ADR-004, ADR-016, ADR-055, ADR-058.

---

# ADR-058: Payout-capabilities og audit (fase 23)

## Date
2026-10-10

## Status
Accepteret. Godkendt af projektejeren 2026-10-10. Bygger på planlægningsbeslutning 7.

## Context
Payouts flytter penge og indeholder følsomme oplysninger. Adgangen skal være afgrænset, og hver ændring skal kunne spores uden at gemme hemmeligheder i audit.

## Decision
1. **Capabilities** (kun administratorer, `RoleManager::VERSION` 5; creators får dem aldrig):
   - `merchboard_manage_payouts`: afvise, registrere en gennemført udbetaling, sætte creatorens afregningsstart og karensperioden; senere køen og historikken.
   - `merchboard_view_payout_details`: sammen med den første, se fulde bank- og PayPal-oplysninger for en ventende payout.
2. **Audit-actions** (i samme transaktion som ændringen): `payout_requested`, `payout_cancelled`, `payout_rejected`, `payout_completed`, `payout_details_viewed`, `payout_start_changed` og `payout_settings_changed`. Gavekortets `payout_gift_card_registered` følger med ADR-057.
3. **Audit-indhold:** kun ID'er, beløb, valuta, metode og flag (`has_reason`, `has_reference`), samt gammel og ny værdi for indstillinger og afregningsstart. Aldrig konti, e-mails, hints, referencer, begrundelsestekst eller gavekortkoder.

## Alternatives considered
- **Genbruge `merchboard_manage` eller `merchboard_manage_creators`:** for bred; alle med creator-administration ville kunne udbetale. Fravalgt.
- **Én capability til både håndtering og fulde oplysninger:** strider mod planlægningsbeslutning 7. Fravalgt.

## Reason
Mindst mulig adgang til penge og følsomme data, og fuld sporbarhed uden at audit selv bliver en kilde til lækager.

## Security
Kontrolleres både i admin-handlingen (før nonce og input) og i servicen.

## Migration/data implications
Rolle-synkronisering ved næste request (ADR-022); opt-in-uninstall fjerner capabilities som de øvrige (ADR-025).

## Consequences
- Andre administratorroller end `administrator` skal have capabilities tildelt eksplicit.

## Related
ADR-022, ADR-023, ADR-025, ADR-055, ADR-056.

**Note 2026-10-11 (ADR-057 accepteret):** audit-actionen `payout_gift_card_registered` er indført med trin 23.7. Kontekst: payout-ID, beløb, valuta, metode, `provider` og `external_id` (kortets ID i pluginet); aldrig koden. Nøglerne indeholder bevidst ikke ordet "card", fordi logsanitizeren (`LogContext`) redigerer sådanne nøgler. Valget af gavekortplugin auditeres som `payout_settings_changed` med `setting = payout_gift_card_provider`.

---

# ADR-057: Payout-gavekort: manuel oprettelse, registrering bag `GiftCardProvider` (fase 23)

## Date
2026-10-11

## Status
Accepteret. Godkendt af projektejeren (057-1 til 057-4) som grundlag for trin 23.7 (rapporten [reports/2026-10-10-fase-23-payouts.md](reports/2026-10-10-fase-23-payouts.md), afsnit 8, 18 og 19.2). 057-2: payout-referencen i pluginets interne note er **ikke** et krav nu. 057-3: en adapter uden `reveal_code` er utilgængelig; ingen krypteret kopi af koden. 057-4: omfang = kontrakt, `FakeGiftCardProvider` (kun tests), registrering, creatorens visning og YITH-adapteren som kode; YITH-adapteren accepteres kun efter bestået runtime-test (bestået 2026-10-11 for registreringen, se Consequences). **Ikke godkendt til produktion:** G1–G3, R1 og ADR-059 er uafklarede.

## Context
Creators skal kunne vælge et gavekort til butikken som udbetaling. Kortet oprettes manuelt af en administrator i et gavekortplugin (YITH WooCommerce Gift Cards 4.38.0 er den anbefalede første integration), og Merch Dashboard må ikke blive afhængig af ét plugins interne klasser i payout-logikken (ADR-016: et plugin er en valgfri tredjepartsintegration, ikke en ekstern service).

## Decision
1. **Kontrakt `GiftCardProvider`** (kun læsning): `id()`, `label()`, `availability()` (`not_installed`, `inactive`, `unsupported_version`, `self_test_failed`), `find_by_code()` og `find_by_id()` (`null` = findes ikke), `reveal_code()` og `admin_url()`. `GiftCardSnapshot` indeholder ID, beløb og saldo i heltal (1/10 000), valuta, status (`active`, `disabled`, `expired`, `other`), udløb og `can_reveal_code`, aldrig koden. Fejl er `GiftCardProviderException` (`unavailable`, `ambiguous`, `invalid_data`, `read_failed`); ulæselige data er aldrig 0.
2. **Valg i Settings:** `payout_gift_card_provider` (standard: ingen). `GiftCardProviders` giver `not_configured`, `unknown_provider`, `unavailable` eller `available`. Gavekort kan kun anmodes om og registreres ved `available`; bank og PayPal er uafhængige. Merch Dashboard installerer, aktiverer eller konfigurerer aldrig et plugin.
3. **Anmodning:** metoden `gift_card` har ingen betalingsoplysninger og ingen række i `merchboard_payout_secrets`; ellers som ADR-055.
4. **Registrering** (admin-post, nonce bundet til payout og handling, `merchboard_manage_payouts`, kodefelt med `autocomplete="off"`): under creator-låsen og i én transaktion genlæses payouten `FOR UPDATE`, kortet læses ved koden og skal være præcis ét kort, ikke knyttet til en anden payout, aktivt, ikke udløbet, i payoutens valuta, med beløb = saldo = payout-beløbet (uden moms); creatorens saldo kontrolleres som ved manuel gennemførelse. Derefter `completed` med udbyder-ID og eksternt ID og audit `payout_gift_card_registered`. Samme kort på samme payout igen er "allerede registreret"; alt andet på en afsluttet payout afvises. `UNIQUE(gift_card_provider, gift_card_external_id)` håndhæver ét kort pr. payout også ved samtidige requests.
5. **Creatorens visning:** `GET /me/payouts/{id}/gift-card` (`no-store, private`), kun for creatorens egen gennemførte gavekort-payout, læst live (`find_by_id` + `reveal_code`); ellers 404, og 503 når kortet ikke kan vises nu (payouten forbliver `completed`).
6. **Koden** gemmes aldrig i Merch Dashboard: ikke i payouts, secrets, audit, logs, options, URL'er eller admin-lister.
7. **YITH-adapteren** (`YithGiftCardProvider`, id `yith-wcgc`): kun gratisversionen 4.38.x; selvtest af post-type og meta-nøgler; læser databasen direkte (pluginets egen klasse skriver meta ved læsning og ser kun publicerede kort); kode = posttitel; `publish` = aktiv, `ywgc-disabled`/`ywgc-dismissed` = deaktiveret; beløb kun som præcise decimaltal med højst butikkens decimaler (komma, float-rester, negative eller manglende værdier giver `invalid_data`); mangler `_ywgc_currency`, er kortet i butikkens valuta, og mangler `_ywgc_expiration`, udløber det ikke (verificeret: pluginets "Create Code" gemmer ingen af delene).

## Alternatives considered
- **Automatisk udstedelse via pluginets klasser:** trukket tilbage (planlægningsbeslutning 4).
- **WooCommerce-kupon som gavekort:** restværdien kan gå tabt. Fravalgt.
- **Krypteret kopi af koden:** unødvendig, når adapteren kan hente koden (057-3).

## Reason
Én lille, udskiftelig kontrakt; reglerne ligger i payout-logikken og gælder for enhver adapter; intet kort uden validering og ingen kode uden for pluginet og creatorens private visning.

## Security
Koden er en ihændehaverkode (G2): runtime-testen viste, at en gæst kan indløse den via Store API. YITH viser koder for alle med produktrettigheder. Kortets saldo kan ændres i pluginet uden for Merch Dashboards lås mellem læsning og COMMIT (kendt restrisiko).

## Migration/data implications
Schema-version 10 (`AddPayoutGiftCards`): `gift_card_provider`, `gift_card_external_id` og den unikke nøgle `gift_card` på `merchboard_payouts`. Eksisterende rækker ændres ikke.

## Consequences
- Runtime-test 2026-10-10/11 på Merch Dashboard Local (rapporten, afsnit 16.5): registrering, validering, idempotens, samtidighed og lækagesøgning bestået mod YITH 4.38.0.
- **G1 er ikke opfyldt (16.3):** admin-oprettede ordrer og REST-ordrer med kortet som kupon får rabat uden at saldoen fratrækkes eller ordren registreres på kortet. Payout-gavekort må ikke bruges i produktion, før det er løst eller håndhævet.
- **G3:** ved annullering og refund af ordrer betalt via kuponfeltet (klassisk og blokcheckout) gendanner YITH ikke saldoen; kun YITH's egen formular gendanner.
- Kodeændringer i et andet gavekortplugin kræver en ny adapter og en ny runtime-test.

## Related
ADR-004, ADR-016, ADR-049, ADR-054, ADR-055, ADR-056, ADR-058, ADR-059.

---

# ADR-059: Opbevaring ved afinstallation: bevaret historik (B2) og afbrydelse ved ventende payouts

## Date
2026-10-12

## Status
Accepteret af projektejeren 2026-10-12 (B2, arkivering af creators, markøren `merchboard_restored_history` og afbrydelse af sletningen ved ventende payouts, mulighed 1). Supplerer ADR-025 og erstatter ADR-060 060-7 a.

**Ikke afklaret:** det juridiske grundlag og opbevaringsperioden for de bevarede persondata afventer revisor/jurist (R3); der er ingen automatisk sletning. Runtime-test af afbrydelsen på Local kræver en særskilt godkendelse. MySQL 8 verificeres først i CI efter push.

## Context
ADR-025 lod opt-in-afinstallationen slette alle pluginets tabeller, også payouts, afregningsbilag og audit-loggen. Bilag og udbetalinger er dokumentation, som ikke må forsvinde ved én handling. En geninstallation over bevarede data må hverken genaktivere gamle creator-konti eller vise historik til en forkert bruger. WordPress tilbyder ingen måde at afvise sletningen af ét plugin: `delete_plugins()` ignorerer, hvad `uninstall.php` returnerer, og sletter filerne bagefter (verificeret i WordPress 7.1's kode).

## Decision
1. **Bevares ved opt-in-afinstallation** (`Tables::retained_on_uninstall()`): `merchboard_creators`, `merchboard_audit_log`, `merchboard_payouts`, `merchboard_settlement_documents`, `merchboard_settlement_document_rows` og `merchboard_settlement_counters`.
2. **Slettes altid** med opt-in: `merchboard_payout_secrets` (de krypterede betalingsoplysninger), ledgeren, pricing- og momsdata, creator-meta og -kategorier, product- og ordremeta, roller, capabilities og options, inklusive schema-versionen. En geninstallation kører derfor alle migrationer igen hen over de bevarede tabeller.
3. **Arkivering:** før tabellerne droppes, sættes alle creators til `inactive` med `user_id = NULL`. Fejler det, droppes intet, og opt-in'en bevares. Der skrives ingen audit (ADR-025 gælder uændret).
4. **Markør:** afinstallationen skriver option'en `merchboard_restored_history` (`archived_at`, `max_creator_id`) efter arkiveringen og før tabellerne droppes. Det er den eneste option, der bevares.
   - Creators med ID til og med `max_creator_id` er arkiverede.
   - `Installer` udleder aldrig markøren. Ved frisk installation (schema-version 0) sletter den den kun, hvis creator-tabellen mangler. En opgradering rører den aldrig.
5. **Geninstallation understøtter kun historisk dokumentation.**
   - Genaktivering af arkiverede creators og normal payout-drift for dem er ikke understøttet.
   - Det er ikke blokeret teknisk (ingen migration, ingen hård blokering).
   - Det advares i Settings, på creator-redigeringssiden og i creator-listen ("archived on uninstall"): ledgeren genopbygges ud fra de nuværende produktkoblinger, så ordrer kan blive tilskrevet en anden creator end i de bevarede bilag.
6. **Ventende payouts (fail closed):**
   - Med opt-in tælles de ventende payouts (alle metoder) før enhver ændring.
   - Er der én, eller fejler optællingen, ændres intet, og `uninstall.php` afslutter slette-requesten, så WordPress beholder filerne. Mekanismen afhænger af requesten:
     - Plugins-skærmen: `wp_send_json_error()` med WordPress' egne felter.
     - WP-CLI: `WP_CLI::error()`.
     - Ellers: `wp_die()` med tilbagelink.
   - **Sideeffekt:** ved sletning uden JavaScript og i WP-CLI med flere plugins i samme request behandles plugins efter Merch Dashboard ikke.
   - **Forhåndsvarsel:** på Plugins-skærmen og ved opt-in-indstillingen i Settings, samt teknisk log.
   - At beskeden når frem, er ikke garanteret.
7. **Logfiltret** redigerer også nøgler med `account` og `registration`.

## Alternatives considered
- **A: slet alt som ADR-025:** uigenkaldeligt tab af bilag og udbetalingshistorik, og dubletter af bilagsnumre efter en geninstallation. Fravalgt.
- **B uden creators:** et nyt creator-ID kunne genbruge et arkiveret og arve dets payouts og bilag. Fravalgt til fordel for B2.
- **Bevare schema-versionen:** ville efterlade de slettede tabeller ugenskabte, fordi `Migrator` kun kører nyere versioner. Fravalgt.
- **Lade filerne slette ved ventende payouts og vise et varsel efter geninstallation:** varslet ses måske aldrig, og de krypterede oplysninger ville ligge tilbage uden plugin. Fravalgt.

## Reason
Dokumentationen for udbetalinger og afregninger bevares, uden at en geninstallation kan genskabe adgang eller vise historik til en forkert bruger, og uden at ventende udbetalinger efterlades uden plugin.

## Security
Bevarede data indeholder ingen fulde kontonumre eller PayPal-adresser (kun den maskerede angivelse, se SECURITY.md). De fritekstfelter, en administrator udfylder (`reference`, afvisningsbegrundelse), afviser betalingsoplysninger (`PayoutFreeText`, se SECURITY.md). Adgang efter geninstallation kræver capability, en koblet record og status `active` (ADR-007); arkiveringen fjerner koblingen.

## Migration/data implications
Ingen ny migration. Migration 1–11 skriver ingen data og ændrer ingen bevarede rækker, når de køres igen (`dbDelta()` og betingede `ALTER ... ADD`); testet på MariaDB 10.11 med byte-sammenligning og sammenligning af tabeldefinitionerne med en frisk installation (`ReinstallAfterUninstallTest`).

## Consequences
- Bevarede persondata (creatornavne, bruger-ID'er i payouts og audit, maskerede angivelser) ligger i databasen, indtil de fjernes manuelt; grundlag og periode afventer R3.
- Multisite: kun det aktuelle site (uændret).
- Fritekstvagten (`PayoutFreeText`), dens hjælpetekster og fejlbesked kom i et separat commit efter ADR-059 (commit B). De danske oversættelser af ADR-059's og vagtens tekster følger i et senere commit.

## Related
ADR-007, ADR-023, ADR-025, ADR-029, ADR-054, ADR-055, ADR-056, ADR-057, ADR-058, ADR-060.

---

# ADR-060: Afregningsbilag ved omvendt fakturering (fase 23)

## Date
2026-10-11

## Status
Accepteret. Godkendt af projektejeren 2026-10-11 (060-1 til 060-9, 060-7 = a) sammen med D5, D8 a, D9 a (præfiks `AFR-`), D10 a og D11 a (rapporten [reports/2026-10-10-fase-23-payouts.md](reports/2026-10-10-fase-23-payouts.md), afsnit 13 og register 16.1). **Foreløbig:** intet er juridisk eller regnskabsmæssigt godkendt; R1–R4 og ADR-059 er uafklarede og blokerer produktionsbrug.

## Context
Afregningen skal ifølge projektejerens oplysning fra SKAT (mundtlig) håndteres som omvendt fakturering: Merch Dashboard danner et afregningsbilag på creatorens vegne for kommissionen. Bilaget skal være sporbart til ordrer, beregning og payout og må aldrig ændres efter udstedelsen.

## Decision
1. **060-1 Udstedelse:** i samme transaktion og under samme creator-lås, som gør payouten `completed` (manuel overførsel og registrering af gavekort). Ingen gennemført payout uden bilag og intet bilag uden payout; en fejl ruller tæller, bilag, rækker og payoutens status tilbage. `UNIQUE(settlement_payout_id)`; en gentagen handling returnerer det eksisterende bilag.
2. **060-2 Indhold (D8 a: ét bilag pr. payout):** alle endnu ikke afregnede ledger-rækker for creatoren og payoutens valuta, som er frigivet efter D1/D2 på udstedelsestidspunktet; hver række højst i ét bilag (`UNIQUE(event_key)`). Periode fra forrige bilag (eller afregningsstarten) til udstedelsen. Bilaget viser kommission (Σ payout pr. række, ADR-049), overført saldo fra forrige bilag, udbetalt beløb og saldo efter bilaget; resten overføres til næste bilag. En ukendt række gør saldoen ukendt, og gennemførelsen afvises (ADR-054); den bliver aldrig 0.
3. **060-3 Datamodel (schema-version 11):** `merchboard_settlement_documents`, `merchboard_settlement_document_rows` (snapshot af hver rækkes værdier) og `merchboard_settlement_counters` (tællerrække pr. serie, låst og øget i transaktionen).
4. **060-4 Uforanderlighed:** bilag og rækker opdateres og slettes aldrig (undtagen opt-in-uninstall, 060-7) og genberegnes aldrig. "Korrigeret" afledes af et korrigerende bilag. D3: afregningsstarten låses, når creatoren har et bilag.
5. **060-5 Adgang:** creatoren ser og udskriver egne bilag (`GET /me/payouts/{id}/settlement-document`, `no-store`; udskriftsvenlig visning, ingen PDF-afhængighed); admin ser dem på payout-siden med `merchboard_manage_payouts`. Aldrig fulde konti, e-mails eller gavekortkoder.
6. **060-6 Audit:** `settlement_document_issued` og `settlement_document_corrected` med ID'er, bilagsnummer og payout.
7. **060-7 Uninstall (a):** tabellerne følger ADR-025 og slettes kun ved opt-in-uninstall. ADR-059 er uafklaret og en produktionsblokering. **Erstattet af ADR-059 (2026-10-12):** bilag, bilagsrækker og tællere bevares ved opt-in-uninstall, så nummerserien fortsætter efter en geninstallation.
8. **060-8:** ingen bilag med tilbagevirkende kraft for payouts gennemført før schema-version 11.
9. **060-9:** bilaget mærkes "Foreløbigt, ikke godkendt af revisor/SKAT".
10. **Nummerering (D9 a):** én fortløbende serie `AFR-000001` for afregningsbilag og korrigerende bilag, uden årlig nulstilling; tildeles først ved udstedelse.
11. **Indhold (D10 a):** kun eksisterende data: creatorens ID, visningsnavn og momsstatus; butikkens navn og WooCommerce-butiksadresse; payout-ID, metode og maskeret modtager eller gavekortets udbyder og ID; ingen nye personoplysninger.
12. **Rettelser (D11 a):** refunds og korrektioner efter et bilag indgår i næste bilag (fremadrettet modregning); fejl i et bilag rettes med et korrigerende bilag med henvisning (`UNIQUE(corrects_document_id)`, en ny rettelse retter det seneste korrigerende bilag). Datamodel og service i 23.8; admin-formularen kommer senere. Regnskabsreglen afventer R1.
13. **D5:** sletning af en ordres historik (ADR-052) afvises, når ordrens rækker indgår i et bilag.

## Alternatives considered
Periodiske bilag (D8 b), separate eller årlige nummerserier (D9 b/c), indsamling af nye stamdata nu (D10 b), kreditnota for hver refund (D11 c), undtagelse fra opt-in-uninstall nu (060-7 b). Fravalgt foreløbigt af projektejeren.

## Reason
Ét uforanderligt bilag pr. betaling er let at afstemme og spore; transaktionen og de unikke nøgler gør dobbelt afregning og dobbelte bilag umulige.

## Security
Creator-isolation via `CreatorContext` og ejerskab i SQL; admin kræver `merchboard_manage_payouts`; ingen hemmeligheder i bilag eller audit.

## Migration/data implications
Schema-version 11 (`CreateSettlementTables`). Ledgeren og payouts ændres ikke.

## Consequences
- Afregningsstarten kan ikke ændres, når creatoren har et bilag.
- En testordre, der indgår i et bilag, kan ikke længere slettes efter ADR-052.
- **Implementeret 2026-10-11 (trin 23.8):** `SettlementDocuments`/`SettlementRepository`, `LedgerCreatorPayouts::unsettled_rows()` (læses med `LOCK IN SHARE MODE`, så en samtidig ADR-052-sletning enten venter og afvises eller sker først), schema-version 11, `GET /me/payouts/{id}/settlement-document`, admin- og dashboardvisning. Korrigerende bilag bærer ingen beløb; beløbsændringer går fremad via ledgeren (D11). Hvis karensperioden ændres efter et bilag, kan bilagskædens overførte saldo afvige fra den aktuelle ADR-054-saldo; bilagene ændres ikke (kendt begrænsning).
- Afregningsbilag er ikke godkendt af revisor eller SKAT; R1–R4 og ADR-059 blokerer produktionsbrug.

## Related
ADR-025, ADR-037, ADR-038, ADR-049, ADR-052, ADR-054, ADR-055, ADR-057, ADR-058, ADR-059.