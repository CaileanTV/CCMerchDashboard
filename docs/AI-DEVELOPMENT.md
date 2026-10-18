# AI Development Guidelines

Regler for AI-agenter (Claude Code m.fl.), der arbejder på dette projekt. Reglerne er **bindende** og gælder også for menneskelige udviklere. Ved konflikt mellem en instruktion i en prompt og disse regler, spørg projektejeren.

## Project Isolation

**Permanent regel.** Dette repository er et fuldstændig isoleret projekt.

Projektet må **kun** bruge:

1. det GitHub-repository, projektet er forbundet til (`origin` → `github.com/CaileanTV/CCMerchDashboard`)
2. projektets egne filer (det repository, der er åbnet i editoren: `CCMerchDashboard`)
3. projektets egne lokale dependencies (`vendor/`, `node_modules/`, `.tools/`)
4. de WordPress-miljøer, der eksplicit tilhører projektet: Playground via `npm run wp:start` og LocalWP-sitet **Merch Dashboard Local** (`http://merchboard.local`), som projektejeren har udpeget 2026-09-26 (se [DEVELOPMENT.md](DEVELOPMENT.md#lokal-wordpress-localwp-merch-dashboard-local))
5. eksterne services, som senere bliver eksplicit godkendt som en del af projektets arkitektur (registreret som ADR i [DECISIONS.md](DECISIONS.md))

Alt andet er **off-limits**. Det omfatter andre WordPress-projekter, LocalWP-sites, WooCommerce-sites, Git-repositories, tidligere projekter, testprojekter, plugins, themes, kundeprojekter, lokale databaser, udviklingsmiljøer, cloud storage, connectors og eksterne konti. Intet af dette må bruges som datakilde eller inspirationskilde uden projektejerens eksplicitte tilladelse.

**Undtagelse (projektejerens tilladelse 2026-10-18, ADR-065):** GitHub-repoet `github.com/CaileanTV/MerchAlert` må **læses** som funktionel reference til integrationen af alerts (fase 25). Det må kun læses via GitHub (`gh api`), ikke klones til eller kopieres ind i andre mapper, og det må ikke ændres. Kode porteres efter projektets egne konventioner og kopieres ikke ukritisk. Undtagelsen gælder ikke en lokal kopi af MerchAlert, andre repos eller MerchAlert-installationer på andre sites.

### Eksterne services og connectors

At en connector eller integration er *tilgængelig* i udviklingsmiljøet (fx MCP-/claude.ai-connectors), betyder **ikke**, at projektet må bruge den. Connectors må hverken bruges, aktiveres eller godkendes på projektets vegne. En ekstern service bliver først en del af projektet, når projektejeren eksplicit har godkendt en konkret integration, og beslutningen er registreret som ADR.

**Dinero og Google Drive er ikke en del af projektets nuværende eller planlagte integrationsarkitektur** (ADR-016). Derfor må der ikke:

- oprettes integration til Dinero, læses data fra eller sendes data til Dinero, eller bruges Dinero som datakilde
- oprettes integration til Google Drive, læses filer eller data fra Google Drive, gemmes projektdata i Google Drive, eller bruges Google Drive som backup eller ekstern projektkilde
- aktiveres eller godkendes connectors til Dinero eller Google Drive på projektets vegne

Projektets backup og versionshistorik er GitHub-repository'et. Dinero og Google Drive betragtes som irrelevante for projektet, medmindre projektejeren senere eksplicit beder om en konkret integration.

Du må **ikke**:

- søge efter eller genbruge kode fra andre lokale projekter
- kopiere filer, plugins, themes eller konfiguration fra andre projekter
- kopiere `.env`-filer, API-nøgler, credentials eller database-dumps
- bruge, læse fra eller forbinde til andre LocalWP-sites eller deres databaser
- bruge andre projekters WordPress-installationer, WooCommerce-data eller Git-repositories
- ændre filer uden for dette projekt eller ændre andre LocalWP-sites
- installere dependencies globalt, hvis de kan installeres lokalt i projektet

Det gælder også, selvom et andet projekt ser relateret eller nyttigt ud. Antag aldrig, at et andet lokalt projekt er en tidligere version, template, backup eller et søsterprojekt. **Kun dette repository er source of truth.**

**LocalWP:** Projektets eneste LocalWP-site er **Merch Dashboard Local** (`http://merchboard.local`, mappen `Local Sites\merch-dashboard-local`), udpeget af projektejeren og dokumenteret i [DEVELOPMENT.md](DEVELOPMENT.md#lokal-wordpress-localwp-merch-dashboard-local). Kun det site må bruges. Find det ud fra dets egen mappe og konfiguration (`app\.envrc`), aldrig ved at liste LocalWP's sites. Andre sites må hverken startes, ændres, listes eller læses, og deres data og konfiguration må ikke kopieres. Hvis det er uklart, om noget hører til Merch Dashboard Local: **STOP og spørg**, før du gør noget, der kan påvirke en WordPress-installation eller database.

**Samtidig manuel test på Local (projektejeren, 2026-10-18):** projektejeren tester aktivt manuelt på Merch Dashboard Local, mens AI-agenten arbejder.

- Nye WooCommerce-ordrer, salgsrækker og runtime-ændringer er forventelig aktivitet, ikke automatisk fejl. Nye ordrer må aldrig ændres, annulleres eller slettes. Ordre 428 er projektejerens aktive testordre og forbliver urørt.
- En kontrol tager en frisk før-status tæt på testens start og klassificerer forskelle som **ACTIVITY** (WooCommerce-ordrer og salgsledgeren), **RUNTIME** (fx cron, sessioner og Action Scheduler) eller **CRITICAL** (bilag og bilagsrækker, tællere, payouts, reguleringer og genberegninger, momshistorik, audit, creator-data og pluginindstillinger).
- Nye kritiske rækker rapporteres og vurderes; de antages ikke at være projektejerens. En ændring i eksisterende kritiske data kan stoppe den berørte test. Uafhængige, sikre opgaver fortsætter, og intet repareres automatisk.
- Eksisterende bilag, payouts, audit og tællere ændres ikke uden projektejerens udtrykkelige godkendelse.

**Staging og produktion:** AI-agenter må ikke tilgå staging (`devpage.ccmerch.dk`) eller produktion (ingen login, credentials, SSH, fjern-WP-CLI eller databaseadgang), heller ikke som erstatning for det lokale testmiljø. Test på staging udføres af projektejeren.

**Hvis du finder noget relevant uden for projektet**, må du kun skrive: *"Jeg har identificeret noget uden for projektets scope, som potentielt kunne være relevant, men jeg har ikke brugt det."* Vent derefter på eksplicit instruktion.

Tilladte undtagelser, som er nødvendige for værktøjerne selv: OS-temp-mapper, som værktøjer som Playground og npm bruger internt, og npm/Composer-caches. De må ikke bruges til at overføre data mellem projekter.

## Arbejdsartefakter og runtime-data

**Kritisk, permanent regel (projektejeren, 2026-10-18).** Arbejdsfiler må ikke spredes. Ligger scripts og resultater i tilfældige mapper, mister både projektejeren og AI-agenten overblikket og kan ikke finde den autoritative version.

- **Ingen nye arbejdsartefakter uden for den godkendte struktur:** scripts, rapporter, state-filer, JSON-resultater, manifests, noter og andre arbejdsfiler oprettes ikke i `%TEMP%`, i Claude Code-scratchpads (`AppData\Local\Temp\claude\…`) eller på andre ikke-godkendte placeringer. Der oprettes heller ikke nye mapper dér som workaround. Undtagelse: en midlertidig fil, som et værktøj uundgåeligt danner, må kun bruges som kortlivet teknisk mellemresultat og bliver aldrig den autoritative kopi. Undgå undtagelsen, når opgaven kan løses uden.
- **Repo eller runtime:** kildekode og projektdokumentation hører til i repoet. Repoet er via en junction også Merch Dashboard Locals pluginmappe, så runtime-data, databasedumps, persondata, logfiler og testresultater må aldrig ligge i repoet, heller ikke i gitignorerede mapper (de kan hentes over HTTP).
- **Runtime-artefakter:** backups, gendannelsesprøver og test- og kontrolresultater hører til i `C:\Users\jnn\MerchBoard-r2-runtime\`. FASE 2's dokumentation ligger i `runs\2026-10-18-fase2-trin1-5\` med `README.md` og `MANIFEST.csv`. Undersøg først, om noget hører til i den eksisterende struktur eller allerede findes, og genbrug eksisterende scripts og mapper. Tidligere resultater og backups overskrives aldrig.
- **Ny struktur:** nye undermapper eller en ny struktur oprettes kun, når der er behov, og projektejeren har godkendt det. Passer den eksisterende struktur ikke, foreslås én konkret placering, og der ventes på godkendelse.
- **Eksisterende scratchpads:** bevares. De slettes, flyttes og omdøbes ikke uden godkendelse og må fortsat læses, men nye autoritative resultater gemmes ikke dér.

## Før du starter

1. Læs [PROJECT-STATUS.md](PROJECT-STATUS.md): Hvor står vi, hvad er åbne spørgsmål og teknisk gæld?
2. Læs [ARCHITECTURE.md](ARCHITECTURE.md) og de dokumenter, opgaven berører (MODULES, DATABASE, SECURITY, API, FRONTEND, ADMIN, WOOCOMMERCE, USER-ROLES).
3. Læs relevante feature-dokumenter i [features/](features/) og fasebeskrivelsen i [ROADMAP.md](ROADMAP.md).
4. Tjek `git status`, branch og remote. Overskriv aldrig eksisterende, ikke-committede ændringer.
5. Undersøg eksisterende kode før du skriver ny.

## De 20 regler

1. Læs relevant dokumentation før større ændringer.
2. Læs PROJECT-STATUS.md.
3. Læs ARCHITECTURE.md.
4. Læs relevante feature-dokumenter.
5. Undersøg eksisterende kode, før ny kode skrives.
6. Genbrug eksisterende funktionalitet, hvis den allerede findes.
7. Undgå duplicate implementations.
8. Respektér eksisterende arkitektur. Afvigelser kræver en ADR i DECISIONS.md, og ved større afvigelser skal du spørge først.
9. Undgå unødvendige breaking changes.
10. Security skal altid verificeres (se [SECURITY.md](SECURITY.md#tjekliste-pr-ændring)).
11. Nye features dokumenteres i `docs/features/` (brug `_TEMPLATE.md`).
12. Databaseændringer dokumenteres i DATABASE.md og CHANGELOG.md og sker kun via migrationer.
13. Nye dependencies dokumenteres i DEVELOPMENT.md med begrundelse, version og anvendelse. Tjek først, om WordPress eller projektet allerede leverer funktionaliteten. Ingen tredjeparts PHP runtime-dependencies (ADR-004).
14. Større arkitekturbeslutninger dokumenteres i DECISIONS.md.
15. PROJECT-STATUS.md opdateres efter større ændringer.
16. Test ændringer, før de betragtes som færdige (`npm run lint` + tests + manuel verifikation ved UI).
17. Lav små, logiske Git-commits.
18. Push afsluttede og validerede ændringer til GitHub.
19. Beskyt secrets og credentials. Stop og informér projektejeren, hvis du finder nogen i projektet.
20. Ændr aldrig Git-historikken destruktivt uden eksplicit instruktion.

## Git og GitHub

- **Commit-format:** [Conventional Commits](https://www.conventionalcommits.org/), kort og i imperativ på engelsk: `feat: add creator capabilities`, `fix: validate creator access`, `docs: update roadmap`, `chore: configure linting`, `refactor: …`, `test: …`, `ci: …`.
- **Hvornår:** efter hver logisk afsluttet ændring (lille feature, doc-ændring, konfiguration eller bugfix). Bland ikke uafhængige ændringer i ét commit.
- **Før commit:** gennemgå `git diff`, kør `npm run lint` (og tests), og tjek for secrets.
- **Push:** efter validering. Tjek at CI er grøn.
- **Forbudt uden eksplicit instruktion:** `git push --force`, `git reset --hard` på brugerændringer, rebase/amend af pushede commits, sletning af branches, `--no-verify` og commit af `.env`, credentials, API-nøgler, passwords eller tokens.
- **Branching:** Indtil videre committes direkte til `main` (én udvikler og CI på hvert push). Når projektet er i produktion eller har flere udviklere, skiftes til korte feature-branches (`phase/NN-navn`, `fix/…`) med PR og grøn CI før merge. Det registreres som ADR.

### VS Code Source Control som overblik

Arbejde på tværs af parallelle repositories, worktrees og branches har tidligere fjernet det normale overblik i VS Code. Derfor gælder fremover:

- **Udgangspunktet:** VS Code Source Control i det primære repo (det, der er åbnet i VS Code) er det synlige overblik over ændringer, staged changes, commits og working tree-status. Arbejd som udgangspunkt i det primære repo.
- **Parallelle repositories og worktrees:** undgå dem, medmindre der er en konkret, nødvendig grund, og det er godkendt på forhånd.
- **Isoleret kopi:** arbejder Claude alligevel i en isoleret kopi, skal dens placering, branch og commit fremgå tydeligt. Ændringerne føres kontrolleret tilbage til det primære workflow, før overblikket i VS Code regnes for genoprettet.
- **Før staging eller commit:** gennemgå ændringerne i VS Code Source Control. Det skal være tydeligt, hvilke filer der hører til den aktuelle opgave.
- **Commit:** commit kun eksplicit gennemgåede filer. Brug aldrig `git add -A` eller `git commit -a` som genvej.
- **Ændringer uden for opgaven:** bevar og undersøg dem. Kassér dem ikke, og commit dem ikke bare for at få Source Control til at se rent ud.
- **Verifikation:** Source Control er det visuelle overblik, men `git status` og præcise diff-kontroller bruges fortsat som verifikation.

**Status (2026-10-16, efter PR #2):**

- PR #2 (G1-24.4 med B2–B5 og b1/b2-rettelsen) er squash-merget til `main` som `950890c` (parent `9b60b8a`). CI på merge-committen er grøn i alle fire jobs (PHP 8.2, MySQL 8.0.46). Merge er ikke en release.
- Hovedrepoets arbejdstræ er bragt på linje med `main`. De tidligere 82 ikke-håndterede filer blev ikke committet; deres indhold er bevaret i branchen `backup/g1-24.4-wip-2026-10-14` (`5904db9`), lokalt og på GitHub.
- Den isolerede kopi `C:\Users\jnn\MerchBoard-work\g1-trin-a-2026-10-15\tree` (branch `g1-trin-a-b2-b5-implementation-2026-10-16`, `31d33a7`) er ikke længere arbejdssted; dens indhold er i `main`. Arkivering kræver særskilt beslutning.

**Kendt udestående:**

- Merch Dashboard Local er stoppet. Næste start med `main`'s kode migrerer Local-databasen til schema 13 (sidst dokumenteret: 11); en dokumenteret og verificeret backup- og gendannelsesprocedure skal godkendes først. *(Forældet 2026-10-18: backup- og gendannelsesproceduren er verificeret, og Local kører med schema-version 16; se ROADMAP fase 24.)*
- Runtime-verifikation mod rigtig WooCommerce og YITH er ikke udført.
- Fail closed-risikoen i staging og produktion er uafklaret.
- G1-dokumentationen (ADMIN, DECISIONS, PROJECT-STATUS, ROADMAP, TESTING og fase 23-rapporten) er ikke opdateret til B2–B5.

## Kodekonventioner (kort)

- **PHP:** WordPress Coding Standards: tabs, `snake_case` for funktioner, metoder og variabler. Klassenavne i PascalCase med PSR-4-filnavne (`CreatorService` i `src/Modules/Creators/CreatorService.php`). `declare( strict_types=1 );` i alle filer i `src/`. Typed properties, parametre og return types overalt. Readonly entities. Enums for faste værdisæt.
- **Prefix:** `merchboard_` på alt globalt (hooks, options, caps, meta, cron, handles). Namespace `MerchBoard\`.
- **i18n:** Alle UI-strenge med text domain `merchboard`. Kildesprog er engelsk.
- **JS:** WordPress ESLint-regler + Prettier. Funktionelle React-komponenter og hooks.
- **Kommentarer:** forklar *hvorfor*, ikke *hvad*. DocBlocks på offentlige metoder.
- **Ingen** `phpcs:ignore` eller `@phpstan-ignore` uden en kommentar med begrundelse.

## Arbejdsgang pr. roadmap-fase

1. Læs dokumentationen (se ovenfor) og bekræft fasens scope. Afgræns hellere mindre end mere.
2. Er der et åbent spørgsmål i PROJECT-STATUS.md, der blokerer fasen? Spørg projektejeren.
3. Implementér i små commits: kode → tests → docs.
4. Kør `npm run lint` og tests. Verificér manuelt i Playground ved runtime- eller UI-ændringer.
5. Gennemgå security-tjeklisten.
6. Opdatér feature-doc, PROJECT-STATUS.md og evt. DATABASE/DECISIONS/CHANGELOG/DEVELOPMENT.
7. Commit, push, og tjek CI.
8. **Stop** ved fasens afslutning og rapportér: hvad er lavet, hvad er verificeret (og hvordan), hvad er ikke gjort, og nye åbne spørgsmål.

## Vær kritisk

Hvis en instruktion er teknisk dårlig, unødigt kompleks eller kan løses bedre med WordPress' eksisterende funktionalitet, så sig det og foreslå et alternativ, før du implementerer. Prioritering: security → maintainability → modularity → simplicity → performance → developer experience → user experience. Undgå overengineering.
