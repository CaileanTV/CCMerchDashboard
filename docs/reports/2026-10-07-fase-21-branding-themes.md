# Fase 21 – Branding & Themes: arkitektur- og scopeafklaring (trin 1)

**Dato:** 2026-10-07 · **Status:** analyse; ingen implementering, ingen ADR endnu (login-arkitekturen er revideret i tillæg K, trin 2). **Beslutninger:** ADR-042, ADR-043 og ADR-044 i [DECISIONS.md](../DECISIONS.md) (2026-10-07). ADR'erne har forrang for denne rapport. Afvigelser: én logo-upload med logo-plade i stedet for en mørk variant; Dark Red og Dark Pink skal revideres (accent og danger ligger kun ΔE 8,5 og 7,7 fra hinanden); intet bootstrap-felt for temaet · **Grundlag:** kun dette repo (kode, docs og ADR'er). Ingen eksterne kilder.

Formål: afklare, hvordan Merch Dashboard får et admin-styret logo (dashboard-header og WordPress-login) og seks temaer (Light, Dark Black, Dark Green, Dark Blue, Dark Red, Dark Pink) inden for de eksisterende ADR'er, og foreslå trin og nødvendige beslutninger.

## A. Eksisterende branding

- **Intet logo-asset findes.** Repoet indeholder ingen SVG-, PNG-, WebP- eller ICO-filer uden for `node_modules`/`vendor`. `assets/` har kun `assets/js/creator-user-search.js` (vanilla JS uden build) og `assets/src/dashboard/` (React-kilden).
- **Dashboard-headeren** (`app/layout/Layout.tsx`) viser sitenavnet som tekst (`<p class="merchboard-topbar__site">{ config.siteName }</p>`), creatorens navn og "Log out". Sitenavnet kommer fra bootstrap-data (`site_name`, `ShellController::bootstrap()`).
- **Byg og levering:** `@wordpress/scripts` bygger `assets/src/dashboard` til `build/dashboard.js` og `.css` (+ RTL), som `DashboardAssets` registrerer med version fra `build/dashboard.asset.php`. Shellen (`templates/dashboard/shell.php`) udskriver kun det handle og dets WordPress-dependencies, uden theme og uden `wp_head()`.
- **Login:** pluginet rører ikke `wp-login.php`'s udseende. `Access/LoginRedirect` styrer kun redirect efter login (`login_redirect`, `woocommerce_login_redirect`), og `AdminAreaGuard` holder rene creators ude af wp-admin (ADR-026).
- **Konsekvens for login:** login-siden kan ikke genbruge React-bundlen (den er kun registreret i shellen). Et fælles brand-asset skal derfor være en statisk fil, som både PHP (login) og bootstrap-data (dashboard) refererer med URL. Det passer med `assets/js`-præcedensen: statiske filer uden build.
- **Planlagt i docs:** ADMIN.md (Settings-tabellen "General": "Branding (logo, primærfarve, navn vist i dashboard)"), feature-doc'en ("Planlagt: branding") og ROADMAP's "Senere"-række "Branding: Branded login-side, dark mode og brand-font (self-hosted)". Brand-font er ikke en del af fase 21's opgave (systemfonte bevares, ingen eksterne fonte).

**Vurdering af format og placering:**

| Spørgsmål | Anbefaling |
| --- | --- |
| Default-logo | Et first-party SVG i repoet: `assets/brand/` (fx `logo.svg` og evt. `logo-on-dark.svg`). SVG'en er vores egen, versioneret og reviewet, så den er tillid-værdig; den vises kun som `<img>` (dashboard) og CSS-`background-image` (login), hvor scripts i SVG ikke kører. |
| Admin-valgt logo | Raster fra Media Library (PNG, WebP, JPEG). **Ingen SVG-upload**: SECURITY.md siger "Ingen SVG-upload (XSS-risiko), medmindre den saniteres med et dedikeret bibliotek (kræver ADR)", og ADR-004 udelukker runtime-dependencies. WordPress core tillader heller ikke SVG-uploads. |
| URL og versionering | Default: `plugins_url( 'assets/brand/…', MERCHBOARD_FILE )` med `?ver=MERCHBOARD_VERSION`. Admin-logo: `wp_get_attachment_image_src()` (passende størrelse) med attachmentets `post_modified_gmt` som cache-busting, ellers URL'en som WordPress giver den (en ny fil får ny URL). |
| Same-origin | Dashboardets CSP er `img-src 'self' data:` (ADR-026, SECURITY.md). Et logo fra en anden origin (CDN/offload) falder tilbage til default, ligesom produktbilleder i fase 19 (ADR-039 P8). En CSP-udvidelse kræver security-review og er ikke foreslået. |
| Fallback | Admin-logo mangler, er slettet, ikke længere et billede, har forkert MIME-type, er cross-origin eller for stort/lille → default-asset. Mangler default-filen (fx en ødelagt pakke) → sitenavnet som tekst, som i dag. |
| Kopiere til pluginets assets? | Nej. Media Library er WordPress' naturlige mekanisme; kopiering ville kræve et eget fil-upload-system og ejerskab af filer (skal ikke slettes ved uninstall; ADR-025 sletter kun pluginets egne data). Kun attachment-ID'et gemmes. |
| Ændring/fjernelse | Ændring: nyt attachment-ID gemmes (audit). Fjernelse: nøglen nulstilles (audit), default bruges. Sletning af selve attachmentet i Media Library håndteres ved læsning (fallback); der er ingen hook-afhængighed. |

## B. Admin-styret logo

**Eksisterende settings-arkitektur:** én autoloaded option `merchboard_settings` (`Core\Settings\Settings`, læses altid gennem `sanitize()`), ændres kun via `Modules\Settings\SettingsService` (capability, nonce, transaktion og `settings_updated`-audit). Siden Merch Dashboard → Settings (`SettingsPage`, `merchboard_manage_settings`) har formularer med egen nonce og admin-post-action pr. sektion (dashboard-slug; "Data on uninstall") og udvidelsespunktet `merchboard_settings_sections` (bruges af "Sales ledger").

**Anbefalet flow:**

1. **Placering:** en ny sektion "Branding" på den eksisterende Settings-side med egen formular, nonce og admin-post-action (samme mønster som dashboard-slug). Ingen ny side og ingen REST.
2. **Capability:** `merchboard_manage_settings` (eksisterende; kun administratorer). Creators har den ikke og har heller ikke `upload_files` (USER-ROLES.md), så de kan hverken åbne Media Library eller ændre logoet. Ingen nye capabilities.
3. **Lagring:** nøglen `logo_attachment_id` (int, 0 = ingen) i `merchboard_settings`. Ingen migration (options er nok), uninstall uændret (optionen slettes allerede præcist). Eventuelt `logo_dark_attachment_id` (se nedenfor).
4. **Valg i UI:** "Select logo" åbner WordPress' Media Library-modal (`wp_enqueue_media()` og en lille vanilla JS-fil uden build, som `creator-user-search.js`), med forhåndsvisning, "Remove logo" og tilbage-til-default. Uden JavaScript: forklaring og "Remove logo" virker stadig; valg kræver Media Library (WordPress' egen afhængighed). Knapper er rigtige `<button>`-elementer med labels.
5. **Server-validering (aldrig stol på klienten):** attachmentet findes (`post_type = attachment`), MIME-type i en allowlist (`image/png`, `image/webp`, `image/jpeg`), `wp_get_attachment_metadata()` giver dimensioner inden for grænserne (se G), og URL'en har samme origin som sitet. Ellers afvises valget med en notice, og intet gemmes.
6. **Audit:** `settings_updated` med `setting = logo_attachment_id` og gammel/ny værdi (kun ID'er), i samme transaktion som optionen (som de øvrige settings).

**Attachment-ID frem for URL:** ID'et kan valideres, giver altid den aktuelle URL og størrelser og overlever ændrede upload-stier; en gemt URL kan pege på noget vilkårligt og blive forældet.

**Ét logo eller light/dark-varianter:** et logo med mørk tekst på transparent baggrund er ulæseligt på de fem mørke temaer. To muligheder: (a) to felter, "Logo" og "Logo for mørke baggrunde" (valgfri; fallback: lyst logo på en lys "logo-plade"), eller (b) ét logo, der altid vises på en lys plade i headeren. **Anbefaling: (a)**, med pladen som fallback, når der kun findes ét logo. Det samme gælder default-assetet (to SVG-varianter eller én SVG, der virker på begge).

**Genbrug af konfigurationen:** en lille service (fx `Core\Branding\BrandLogo` eller i Dashboard-modulet) løser `logo_attachment_id` → `{ url, width, height, alt }` med alle fallbacks. Den bruges af:

1. **Dashboard-headeren:** `ShellController::bootstrap()` tilføjer `logo` (og evt. `logo_dark`) til bootstrap-data; `bootstrap.ts` validerer med `readSameOriginUrl()` (findes); `Layout.tsx` viser `<img>` med sitenavnet som `alt` (logoet erstatter teksten, så navnet skal være det tilgængelige navn).
2. **WordPress-login:** samme service i PHP (se F).
3. **Fremtidige flader** (fx e-mails, no-access-siden): samme service; ingen ny lagring.

## C. Eksisterende design tokens

`assets/src/dashboard/styles/_tokens.scss` har CSS custom properties på `.merchboard-app` med kommentaren "so a dark theme can later swap them". Brugen i `index.scss`: **149** `var(--merchboard-*)`.

| Lag | Status |
| --- | --- |
| Rå paletter | `--merchboard-gray-50…900`, `--merchboard-primary-50…700` |
| Semantiske | `--merchboard-surface`, `-surface-muted`, `-border`, `-text`, `-text-muted`, `-focus`, status-par `-success/-warning/-danger/-info` + `-bg` |
| Typografi, spacing, radius, shadow, z-index, motion | tokens (systemfonte, ingen eksterne fonte) |
| **Rå palette brugt direkte i komponenter** | **13 steder**: links og aktiv navigation (`primary-700`, `primary-50`, `gray-100`), primær knap (`primary-600/700`), sekundær knap og input-kant (`gray-300`), skeleton (`gray-200`), toast (`gray-900`), graf (`primary-600`, `primary-500`, baseline `gray-300`) |
| **Hardcodet** | 5: `.merchboard-body { background: #f8fafc }` (uden for token-scope, fordi tokens sidder på `.merchboard-app`), `#fff` som tekst på primær- og danger-knap og i toast |
| **Uden for systemet** | `templates/dashboard/no-access.php` (eget `<style>` med WordPress-agtige farver) og shell'ens "unavailable"-kort (inline `style`) |
| Fokus | `:focus-visible` med 3 px outline i `--merchboard-focus` (findes) |
| Reduced motion | `@media (prefers-reduced-motion: reduce)` slår transitions/animationer fra (findes) |
| Dark mode / `prefers-color-scheme` | findes ikke |
| Graf | egen SVG (ADR-041), farver fra tokens; negative værdier vises ved placering under baselinen, ikke kun ved farve |
| Login-styles | findes ikke |

**Konklusion:** et reelt, men to-lags-blandet token-system. Temaer kræver, at komponenterne kun bruger **semantiske** tokens, og at tokens flyttes til et niveau, der også dækker `body`.

**Fund (eksisterende):** input-kanten `gray-300` på hvid har kontrast **1,48:1**; WCAG 2.1 SC 1.4.11 kræver ≥ 3:1 for afgrænsning af UI-komponenter. Bør rettes i token-refaktoreringen (et `--merchboard-border-strong` ≥ 3:1).

## D. Theme persistence

| Mulighed | Vurdering |
| --- | --- |
| **user meta** | Persisterer på tværs af sessioner og enheder. Ingen migration (WordPress' `usermeta`). Knyttet til den indloggede bruger, ikke til creator-recorden, hvilket passer: det er en personlig UI-præference. **Anbefalet.** |
| localStorage | **Udelukket af eksisterende regel:** ESLint forbyder `localStorage`/`sessionStorage`/`indexedDB` i dashboard-koden (FRONTEND.md, `eslint.config.cjs`). Ville også give et blink ved første visning. |
| cookie | `document.cookie` er ligeledes forbudt i dashboard-koden; en server-cookie kræver egen håndtering og gælder kun én browser. Fravalgt. |
| kombination | Unødvendig: serveren kender user meta allerede ved rendering af shellen. |

**Anbefalet model:**

- Nøgle `merchboard_dashboard_theme` i user meta, værdi i en allowlist (`light`, `dark-black`, `dark-green`, `dark-blue`, `dark-red`, `dark-pink`); alt andet ignoreres (læses altid gennem en validator).
- **Læsning:** `ShellController` læser præferencen for den aktuelle bruger og (1) sætter `data-theme` på roden i `shell.php` (ingen blink, ingen JS nødvendig for første maling) og (2) sender den i bootstrap-data. Bootstrap indeholder i dag "ingen creator-data"; en brugers egen UI-præference er ikke creator-data, men det skal noteres i ADR'en.
- **Skrivning:** dashboardet er en SPA, så et valg skal sendes til serveren. Mindste mulige vej: `PATCH /merchboard/v1/me/preferences` (eller `PATCH /me` med ét felt) med `theme`, kun via `CreatorContext` (samme adgang som de øvrige `/me`-routes), som skriver user meta for **den indloggede bruger** (aldrig et ID fra requesten), validerer mod allowlisten og returnerer den gemte værdi. Ingen audit (ingen sikkerhedsrelevant ændring). Alternativet uden REST (en admin-post-formular med sidegenindlæsning) passer dårligt til SPA'en.
- **Ingen eskalering:** værdien bruges kun som attribut-værdi fra en allowlist, aldrig som CSS, klasse-navn fra input eller filnavn. Ingen nye capabilities.
- **Uden valg:** ingen gemt værdi → følg `prefers-color-scheme` (Light ↔ Dark Black) via CSS, til brugeren vælger eksplicit. Alternativet er altid Light; det er en beslutning til ADR'en.
- **Uninstall:** nøglen skal tilføjes den præcise sletning i `Uninstaller` (ADR-025: kun præcise, plugin-ejede navne; `delete_metadata( 'user', 0, 'merchboard_dashboard_theme', '', true )`).
- **Admins:** temaet gælder kun creator-dashboardets frontend. wp-admin bevarer WordPress' egne farveskemaer; admin-sektionerne (Sales, Products osv.) temes ikke. En admin, der også er creator, bruger samme præference i dashboardet.

## E. Theme architecture

- **Én tokenstruktur, ét stylesheet:** tokens defineres på `:root`/`html` i shellen (så `body` og `.merchboard-app` deler dem), Light som standard, og fem overrides via `[data-theme="dark-…"]`. Ingen separate stylesheets, ingen runtime-dependency, intet JS-tema-bibliotek.
- **`prefers-color-scheme`:** `:root:not([data-theme]) { … }` + `@media (prefers-color-scheme: dark)` giver "følg systemet" uden JS.
- **Semantiske tokens** (med projektets præfiks, ikke `--color-*`, så intet kolliderer med WordPress' egne variabler). Kun dem, gennemgangen viser behov for:

| Token | Bruges til (i dag) |
| --- | --- |
| `--merchboard-bg` | `body`/app-baggrund (i dag `#f8fafc` og `surface-muted`) |
| `--merchboard-surface`, `--merchboard-surface-muted` | kort, tabeller, tabelhoved, badges |
| `--merchboard-border`, `--merchboard-border-strong` | dekorative kanter; input- og knapkanter (≥ 3:1) |
| `--merchboard-text`, `--merchboard-text-muted` | brødtekst og sekundær tekst |
| `--merchboard-primary`, `--merchboard-primary-hover`, `--merchboard-on-primary` | primær knap og tekst på den (i dag `#fff`) |
| `--merchboard-link` | links og aktiv navigation (i dag `primary-700`) |
| `--merchboard-nav-active-bg`, `--merchboard-nav-hover-bg` | navigation (i dag `primary-50`, `gray-100`) |
| `--merchboard-focus` | fokusring (findes) |
| `--merchboard-success/-warning/-danger/-info` + `-bg`, `--merchboard-on-danger` | alerts, badges, danger-knap |
| `--merchboard-toast-bg`, `--merchboard-toast-text` | toast (i dag `gray-900`/`#fff`) |
| `--merchboard-skeleton` | skeleton-linjer (i dag `gray-200`) |
| `--merchboard-chart-bar`, `--merchboard-chart-bar-negative`, `--merchboard-chart-baseline` | SVG-grafen (i dag `primary-600/500`, `gray-300`) |
| `--merchboard-shadow-sm`, `--merchboard-shadow` | pr. tema (skygger på mørke flader er næsten usynlige; kanter bærer afgrænsningen) |
| `--merchboard-logo-plate` | baggrund bag et logo uden mørk variant |

**Foreslåede paletter (udkast; kontrast beregnet med WCAG-formlen, skal verificeres igen ved implementering):**

| Tema | bg | surface | tekst | muted | primary | tekst/surface | muted/surface | primary/surface | on-primary/primary |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Light (i dag) | `#f8fafc` | `#ffffff` | `#0f172a` | `#475569` | `#4f46e5` | 17,85 | 7,58 | 6,29 | 6,29 |
| Dark Black | `#0b0c0e` | `#16181b` | `#e6e8eb` | `#a3aab4` | `#9aa3f2` | 14,49 | 7,59 | 7,53 | 8,29 |
| Dark Green | `#0b1310` | `#132019` | `#e3ece7` | `#a2b7ab` | `#6dbb94` | 13,95 | 7,93 | 7,36 | 8,24 |
| Dark Blue | `#0a1120` | `#121b2e` | `#e2e8f2` | `#a0aec4` | `#7aa7e8` | 13,96 | 7,65 | 6,97 | 7,65 |
| Dark Red | `#150c0d` | `#211415` | `#f0e4e5` | `#bca5a8` | `#e09a9e` | 14,40 | 7,73 | 7,90 | 8,52 |
| Dark Pink | `#140c11` | `#20141b` | `#f1e4ec` | `#bea4b2` | `#e3a0c3` | 14,48 | 7,77 | 8,56 | 9,25 |

De mørke temaer er afdæmpede: næsten sorte, let tonede baggrunde og desaturerede accenter (ingen neon). På mørke temaer er `on-primary` mørk tekst på en lys accent.

**Særlige hensyn:**

- **Negative salg:** vises allerede ved placering under baselinen, minustegn og tabellen (ADR-041); farven er kun sekundær. Det skal bevares.
- **Dark Red og danger/negative:** temaets accent og `--merchboard-danger` må ikke kunne forveksles. Danger skal have en anden nuance og altid ledsages af tekst/ikon (alerts har allerede tekst-præfiks "Error:"/"Advarsel:").
- **Alerts:** `-bg` på mørke temaer som mørke, let tonede flader med lys statusfarve (≥ 4,5:1).
- **Tabeller:** tabelhoved `surface-muted`, kanter `border`; scroll-regionens fokusring dækkes af `--merchboard-focus`.
- **SVG-graf:** farver fra `--merchboard-chart-*` (findes som tokens i dag); kontrast for søjler mod surface ≥ 3:1 (grafisk objekt, SC 1.4.11).
- **WooCommerce-relateret UI:** produktbilleder (egne filer) og pladsholderen skal have en tema-neutral baggrund; status-badges via tokens.
- **Fokus:** `--merchboard-focus` pr. tema med ≥ 3:1 mod både bg og surface.
- **Logo på alle seks:** mørk variant på mørke temaer; uden mørk variant en lys plade (`--merchboard-logo-plate`).

## F. Login branding

> **Revideret i trin 2 (afsnit K):** anbefalingen er nu branding af `wp-login.php` kun i Merch Dashboard-kontekst, ikke site-dækkende. Teknikken nedenfor (stylesheet, logo, `login_headerurl`/`login_headertext`, lys/mørk) gælder fortsat, men kun i den kontekst.

**Tekniske muligheder i WordPress (uden at ændre login-flowet):** `login_enqueue_scripts` (stylesheet + `wp_add_inline_style` for logo-URL'en), `login_headerurl` (logo-linkets mål, i dag wordpress.org), `login_headertext` (logo-linkets tilgængelige navn). Ingen JavaScript nødvendig.

**Forslag:**

- **Stylesheet:** en statisk `assets/css/login.css` (eller en lille SCSS-entry i bygget) med login-tokens (samme farvesystem, kun relevante værdier), uafhængig af React-bundlen og af WooCommerce (registreres i Dashboard- eller Core-laget, ikke i WooCommerce-modulet).
- **Logo:** `.login h1 a { background-image: url(…) }` med URL'en fra logo-servicen (admin-logo eller default), valideret som same-origin og escaped til CSS-kontekst (kun URL-tegn; ellers default). Størrelse via `background-size: contain` inden for en fast boks.
- **`login_headerurl`:** sitets forside (`home_url( '/' )`); **`login_headertext`:** sitenavnet (det tilgængelige navn for logo-linket).
- **Kort, knap, links, fokus:** baggrund, kort med kant og radius, primærknap i brand-farven med hover og synlig fokusring (≥ 3:1), links i `--…-link`.
- **Favicon:** WordPress' Site Icon (Customizer) bruges allerede af login-siden; pluginet bør ikke overstyre det.

**Farvetema på login (bevidst valg):** login-siden kender ikke brugerens dashboard-præference før login (user meta kræver en kendt bruger). **Anbefaling:** neutral Light som standard + `@media (prefers-color-scheme: dark)` med Dark Black-paletten; brugerens personlige tema påvirker **ikke** login. Alternativ: altid Light (enklest). Beslutning i ADR'en.

**Rækkevidde (trin 1, erstattet af K):** `wp-login.php` er hele sitets login (administratorer, shop managers og evt. kunder, der logger ind der). Branding gælder derfor alle, der bruger `wp-login.php`; WooCommerce' "Min konto"-login berøres ikke. Det skal godkendes eksplicit (ADR), og bør ikke kunne slås fra uden en grund.

**Robusthed:** ingen JS, ingen dashboard-bundle, ingen WooCommerce; et manglende eller ugyldigt logo giver default; mangler default-filen, vises WordPress' standardlogo (CSS-reglen udelades).

## G. Logo og design

- **Hent intet eksternt logo.** Et first-party default-asset designes og lægges i `assets/brand/`.
- **Krav til default-SVG:** egen tegning; `viewBox` sat, ingen faste `width`/`height`-krav ud over et sikkert intrinsisk forhold; ingen `<script>`, event-attributter, `<foreignObject>`, eksterne referencer (`href` til andre filer/URL'er) eller indlejrede fonte (tekst som stier); optimeret (fx svgo i hånden, ingen ny dependency); en lys og en mørk variant eller én, der virker på begge; bredde/højde-forhold mellem 1:1 og 4:1.
- **Krav til admin-logo (raster):** PNG, WebP eller JPEG (PNG/WebP anbefales for transparens); højde mindst 64 px (skarpt i en 32–40 px header på HiDPI), største side højst 2000 px; forhold mellem 1:1 og 6:1 (bredere logoer skaleres ned og kan blive ulæselige); transparent baggrund anbefales; vises med `object-fit: contain` i en fast boks; dashboard bruger en registreret mellemstørrelse fra WordPress, login den samme.
- **Mørk anvendelse:** valgfrit "logo for mørke baggrunde"; ellers lys plade bag logoet på mørke temaer.
- **Fallback-kæde:** mørk variant (på mørke temaer) → lyst admin-logo (på plade) → default-SVG (lys/mørk) → sitenavn som tekst.

## H. Accessibility (krav)

- **Kontrast:** tekst ≥ 4,5:1 (stor tekst ≥ 3:1), UI-komponenters afgrænsning og grafiske objekter ≥ 3:1, fokusring ≥ 3:1 mod tilstødende farver, i alle seks temaer og på login. Det eksisterende input-kant-fund (1,48:1) rettes.
- **Tastatur:** temavælgeren og logo-indstillingen fuldt betjenelige med tastatur; Media Library-modalen er WordPress' egen; "Remove logo" er en knap; synlig fokus overalt.
- **Temavælger:** et rigtigt formularelement (radiogruppe eller `<select>`) med label ("Theme"/"Tema"), navne som tekst (ikke kun farveprikker), det aktive valg annonceres; gemning med `ApiErrorState`/toast-mønstret.
- **Ingen information kun via farve:** status, negative salg og fejl har tekst, tegn eller placering.
- **`prefers-reduced-motion`:** bevares; temaskift uden animation.
- **`prefers-color-scheme`:** bruges kun, når brugeren ikke har valgt (dashboard) og på login.
- **Logo:** `alt` = sitenavnet, når logoet erstatter navnet; på login `login_headertext` = sitenavnet; dekorative gentagelser `alt=""`.

## I. Foreslåede implementeringstrin

| Trin | Indhold | Bemærkning |
| --- | --- | --- |
| 1 | Arkitektur- og scopeafklaring | denne rapport ✅ |
| 2 | Beslutninger: ADR'er (se J) | før kode |
| 3 | Token-refaktor uden visuel ændring: semantiske tokens, tokens på roden (også `body`), ingen rå palette i komponenter, input-kant ≥ 3:1, no-access/unavailable på tokens | ren refaktor, Light identisk (bortset fra kontrastrettelsen) |
| 4 | Theme-infrastruktur: de fem mørke paletter, `data-theme`, `prefers-color-scheme`-standard | sammen med kontrasttests pr. tema |
| 5 | Persistence og vælger: user meta, `data-theme` fra serveren, `PATCH /me/preferences`, temavælger i dashboardet, uninstall-nøgle | slået sammen: vælgeren giver ikke mening uden persistence |
| 6 | Brand-asset og logo-service: default-SVG (lys/mørk), `BrandLogo`-service med fallback-kæde, logo i headeren via bootstrap | |
| 7 | Admin-logo: Settings-sektion "Branding", Media Library, validering, audit, remove | |
| 8 | Login-branding i Merch Dashboard-kontekst (K): betinget stylesheet, logo, `login_headerurl`/`login_headertext`, lys/mørk efter beslutning | ingen ny route, ingen egen formular |
| 9 | Accessibility-gennemgang og runtime-verifikation i Merch Dashboard Local (kræver projektejerens godkendelse; ingen browser til rådighed, så visuel kontrol er en begrænsning) | |
| 10 | i18n, docs, slutverifikation, commit, push og grøn CI | |

Begrundelse for rækkefølgen: token-refaktoren først, så temaerne kun er dataændringer; logoet efter temaerne, fordi den mørke variant afhænger af dem; login til sidst, fordi den genbruger logo-servicen.

## J. Nødvendige ADR'er (oprettes ikke i trin 1)

1. **Branding: globalt admin-styret logo** – Media Library-reference (`logo_attachment_id` og evt. mørk variant i `merchboard_settings`), raster-allowlist og ingen SVG-upload (bekræfter SECURITY.md), same-origin og fallback-kæde, default first-party SVG, capability `merchboard_manage_settings`, audit. (Kan samles med 4 som én branding-ADR.)
2. **Theme-arkitektur** – semantiske tokens på roden, seks temaer via `data-theme`, ét stylesheet, standard uden valg (følg system eller Light), kontrastkrav.
3. **Theme-persistence** – user meta-nøgle, allowlist, serverrenderet `data-theme`, bootstrap-feltet (undtagelse fra "ingen creator-data"), `PATCH /me/preferences`, uninstall-sletning (ADR-025).
4. **Login-branding** – branding af `wp-login.php` kun i Merch Dashboard-kontekst (revideret i K; trin 1 foreslog site-dækkende), lys/mørk-valget, `login_headerurl`/`login_headertext`, ingen JS, ingen WooCommerce-afhængighed.

ADR-010 (eget design system) og ADR-041 (graf) suppleres, ikke erstattes. ADR-026 (CSP) ændres ikke.

## Risici

- **Login-markup:** den betingede login-branding (K) styler WordPress' login-markup; en ny WordPress-version kan give visuelle regressioner. Glemt adgangskode og reset forbliver WordPress' standarddesign. (Trin 1's risiko "site-dækkende login" bortfalder med K.)
- **CDN/offload af uploads:** admin-logoet falder tilbage til default på sites med medier på en anden origin (CSP `img-src 'self' data:`), ligesom produktbilleder. Dokumenteres; ingen CSP-udvidelse uden security-review.
- **SVG:** admins vil ofte ønske SVG-logoer; uden en sanitizer (ADR-004/SECURITY.md) understøttes kun raster. Kræver bevidst accept.
- **Kontrast i Dark Red/Dark Pink:** accent og danger/negativ kan forveksles; kræver særskilt danger-nuance og tekst/ikon.
- **Regressionsrisiko i token-refaktoren:** 13 rå referencer og 5 hardcodede farver skal flyttes uden visuel ændring i Light; Jest dækker ikke CSS, så en visuel kontrol mangler (ingen browser).
- **Bootstrap-principper:** at sende temaet og logoet i bootstrap-data udvider indholdet (ikke-sensitive værdier); skal noteres.
- **Blink ved temaskift:** undgås kun, hvis `data-theme` sættes serverside i shellen.
- **Miljø:** LocalWP kører nu WordPress 7.1.3 og WooCommerce 11.1.2 (fase 20, trin 9); login-markup verificeres mod den version.

## K. Tillæg (trin 2, 2026-10-07): separat login-side for Merch Dashboard

**Spørgsmål:** kan Merch Dashboard få sin egen brandede login-side i stedet for at ændre `wp-login.php` for hele sitet, uden eget authentication-flow? **Status:** undersøgt; ADR'erne er ikke låst.

**Kilder:** WordPress 7.1.2's egen kildekode i repoets dev-dependency `vendor/roots/wordpress-no-content` (`wp-login.php`, `wp-includes/general-template.php`, `user.php`, `pluggable.php`, `default-filters.php`); LocalWP kører 7.1.3. Desuden pluginets `ShellController`, `DashboardRoute`, `LoginRedirect`, ADR-006, ADR-026 og SECURITY.md. Ingen ekstern dokumentation.

### Fakta fra kildekoden

| # | Fund | Kilde |
| --- | --- | --- |
| F1 | `wp_login_form()` poster altid til `site_url( 'wp-login.php', 'login_post' )`. Formularen har ingen nonce og ingen `testcookie`, men har `redirect_to` og "Husk mig" (`rememberme`). Den kalder `login_form_top/middle/bottom`-filtrene, men **ikke** `login_form`-actionen, som captcha-plugins typisk bruger til at vise deres felt. | `general-template.php` |
| F2 | `wp-login.php`'s egen login-formular har heller ingen nonce: WordPress beskytter ikke login med CSRF-nonce. Den sender `testcookie` med og bevarer `redirect_to` som skjult felt, også efter en fejl og ved sprogskift. | `wp-login.php` |
| F3 | `login_redirect`-filteret og den efterfølgende `wp_safe_redirect()` findes kun i `wp-login.php`, ikke i `wp_signon()`. | `wp-login.php` |
| F4 | `wp_signon()` er den officielle API (`wp_authenticate`-action, `authenticate`-filterkæden, `wp_set_auth_cookie()`, `wp_login`). Den indeholder ikke testcookie-tjek, `use_ssl`/secure cookie-logik, `login_redirect`, interim login (sessionsudløb i wp-admin), admin-e-mail-bekræftelse eller fejlvisning. Det ligger alt sammen i `wp-login.php`. | `user.php`, `wp-login.php` |
| F5 | `wp_login_failed` udløses ikke ved tomt brugernavn eller tom adgangskode (`empty_username`/`empty_password` ignoreres). | `pluggable.php` (`wp_authenticate`) |
| F6 | **Allerede indlogget:** et GET til `wp-login.php` uden credentials godkender via `wp_authenticate_cookie()` og sender brugeren videre gennem `login_redirect` til `redirect_to`, medmindre `reauth=1`. Et udløbet cookie giver "Please log in again". | `user.php`, `wp-login.php` |
| F7 | `wp-login.php` har hooks til præsentationen: `login_init`, `login_enqueue_scripts`, `login_head`, `login_headerurl`, `login_headertext`, `login_body_class` (får `$action`), `login_title`, `login_message`, `login_footer`. De kører kun på `wp-login.php`. | `wp-login.php` |
| F8 | Glemt adgangskode og reset (`action=lostpassword`, `rp`/`resetpass`) ligger i `wp-login.php`. Linket "Lost your password?" er `wp_lostpassword_url()` uden `redirect_to`, og reset-mailen linker til `wp-login.php?action=rp`. | `wp-login.php`, `general-template.php` |
| F9 | **Pluginet i dag:** logget ud på enhver dashboard-URL giver allerede en redirect til `wp_login_url( <dashboard-URL> )` (`ShellController`). Logout er `wp_logout_url( <dashboard-URL> )`, som efter logout ender samme sted. `DashboardRoute::dashboard_url_for()` validerer en `redirect_to` som en dashboard-URL på samme site (`wp_validate_redirect`, host, scheme, `..`, `\`, `@`). `LoginRedirect` sender rene creators til dashboardet. | pluginet |
| F10 | ADR-006: ingen eget login- eller token-system. SECURITY.md: brute-force-beskyttelse og 2FA håndteres af plugins eller hosting, ikke af os. | docs |

### Muligheder

**A1. Egen login-route med egen POST-håndtering (`wp_signon()`).** En plugin-route (fx `/<slug>/login/`) viser en egen formular og kalder selv `wp_signon()`.

- **Fordele:** fuld kontrol over markup og branding; egen nonce er mulig.
- **Ulemper:**
  - **Det er et nyt credential-endpoint.** URL-baseret beskyttelse, som hosting, WAF'er og "skjul login"-plugins sætter på `/wp-login.php` (SECURITY.md lægger brute-force-beskyttelse netop der), dækker det ikke.
  - Plugins, der kun hooker `login_init`, `login_form_login` eller `login_form`, kører ikke. Captcha-plugins kan derfor afvise alle logins: de tjekker i `authenticate`, men feltet bliver aldrig vist.
  - Vi skal selv genskabe testcookie, secure cookie, `login_redirect`, fejlbeskeder (inkl. at de ikke afslører, om en bruger findes), sessionsudløb og admin-e-mail-bekræftelse.
- **Vurdering:** i praksis et separat credential-flow (F4) og i strid med ånden i ADR-006. **Fravalgt.**

**A2. Egen login-side, der viser `wp_login_form()` og poster til `wp-login.php`.** WordPress behandler credentials (F1).

- **Fordele:** ingen egen authentication. Ved succes sender `login_redirect` og `wp_safe_redirect()` brugeren til dashboardet.
- **Ulemper:**
  - **Fejl, udløbet session, captcha, 2FA, lockout-beskeder og glemt adgangskode ender alle på `wp-login.php`.** Siden er kun brandet ved første visning.
  - At sende brugeren tilbage via `wp_login_failed` er skrøbeligt. Det virker ikke ved tomme felter (F5). Det skjuler pluginbeskeder ("låst i 20 minutter", 2FA-trin) bag en generisk fejl. 2FA-plugins viser alligevel deres trin på `wp-login.php`.
  - Formularen udløser ikke `login_form` (captcha) og har ingen testcookie (F1).
  - Kræver en ny offentlig route og en ny skabelon.
- **Vurdering:** ikke robust alene. Den skal i praksis kombineres med B, og så er A2's egen side kun en ekstra formularflade med færre kompatibilitets­garantier. **Fravalgt.**

**B. Betinget branding af `wp-login.php` i dashboard-kontekst.** Ingen ny route og ingen ny formular. Dashboard-URL'en er Merch Dashboards login-indgang: logget ud sendes man allerede til `wp-login.php?redirect_to=<dashboard-URL>` (F9). På `wp-login.php` aktiveres brandingen **kun** når anmodningens `redirect_to` består `DashboardRoute::dashboard_url_for()`. Det gælder kun login-skærmen (`$action` = `login`, inkl. fejl, `expired_session` og sprogskift, fordi `redirect_to` bevares (F2)). Ellers er siden helt uændret.

- **Fordele:**
  - **WordPress' fulde, uændrede flow:** credentials, cookies, testcookie, secure cookie, `login_redirect`, `wp_safe_redirect()`, allerede-indlogget-adfærd (F6), fejlbeskeder, captcha, 2FA og lockout-plugins, URL-baseret beskyttelse og "skjul login"-plugins (de filtrerer `login_url`/`site_url`, som pluginet allerede bruger).
  - Brandingen kører kun på `wp-login.php` (F7) og kun i dashboard-kontekst.
  - Ingen JS, ingen React-bundle, ingen WooCommerce.
- **Ulemper:**
  - Adresselinjen viser `wp-login.php`.
  - CSS'en styler WordPress' login-markup (`body.login`, `#login h1 a`, `#loginform`, `.button-primary`, `#nav`, `#backtoblog`). En ændring i markup i en ny WordPress-version kan give visuelle, men ikke funktionelle, regressioner.
  - Glemt adgangskode og reset vises i WordPress' standarddesign (F8), medmindre en senere, separat beslutning ændrer det.
- **Vurdering:** robust. **Anbefalet.**

**C. Global branding af `wp-login.php`** (trin 1-forslaget). Robust og enkel, men ændrer login for alle brugere af sitet. **Ikke nødvendig**, hvis B vælges.

**Valgfri pæn adresse:** en `/<slug>/login/`-alias, der redirecter til `wp_login_url( <dashboard-URL> )`, er mulig. Den giver dog intet ud over dashboard-URL'en selv og ville reservere et sektionsnavn i `DashboardRoute`. Anbefales ikke i fase 21.

### Anbefaling: B

Begrundelse:

1. **A er ikke robust.** A1 er et separat credential-flow og et nyt, ubeskyttet login-endpoint. A2 afhænger alligevel af `wp-login.php` for alt andet end den første visning.
2. **B opfylder kravene uden ny authentication-kode:** separat brandet login for Merch Dashboard; resten af sitets `wp-login.php` er uændret; ingen JS, React eller WooCommerce; WordPress' login, logout, redirect og reset er urørte.
3. **B kræver ingen ny route.** Indgangen og `redirect_to`-valideringen findes allerede (`ShellController`, `DashboardRoute`). `LoginRedirect` er uændret.
4. **B reducerer den globale påvirkning** fra "alle brugere af `wp-login.php`" (C) til "kun anmodninger med en gyldig dashboard-`redirect_to`".

### Security trade-offs

| Emne | B (anbefalet) | A1 (fravalgt) |
| --- | --- | --- |
| CSRF/nonce | Som WordPress i dag: login har ingen nonce (F2); intet ændres. Brandingen tager ingen handling. | Egen nonce mulig, men ingen gevinst mod brute force. |
| Open redirect | Uændret: `wp_safe_redirect()` med `allowed_redirect_hosts`; `LoginRedirect` indsnævrer for creators. Kontekst-tjekket bruger samme validator; en fremmed `redirect_to` giver blot den normale side. En manipuleret `redirect_to` til en dashboard-URL viser kun vores egen branding: ingen ny risiko. | Egen redirect-logik. |
| Brute force og rate limiting | WordPress core har ingen. Plugins og hosting virker uændret, også URL-baserede regler på `/wp-login.php`. | URL-baserede regler dækker ikke det nye endpoint; kun hook-baserede plugins virker. |
| Glemt adgangskode, reset, logout | WordPress' egne; uændrede. | Skal linke til WordPress' egne; ingen egen implementering. |
| Cookies og `is_user_logged_in()` | Uændret: `wp_signon()` → `wp_set_auth_cookie()` i `wp-login.php`. | Samme funktion, men uden testcookie og secure cookie. |
| Authentication-hooks | Alle kører som i dag (`login_init`, `login_form_login`, `login_form`, `authenticate`, `wp_login`, `wp_login_failed`, `login_redirect`). | `login_init`, `login_form_*`, `login_form` og `login_redirect` kører ikke. |
| Plugins, der forventer `wp-login.php` | Fuldt kompatibelt (det er siden selv). | Ikke kompatibelt med captcha-, 2FA-, SSO- og "skjul login"-plugins. |
| Output-sikkerhed | Logo-URL fra logo-servicen (same-origin, escaped til CSS-kontekst); `login_headerurl` = `home_url( '/' )`; `login_headertext` = sitenavnet (WordPress escaper). `redirect_to` bruges kun som betingelse, aldrig som output. | Egen skabelon og escaping. |

### Accessibility trade-offs

- **B:** WordPress' formular har labels, `autocomplete`, fejlboks og "vis adgangskode"-knap (kun med JS; uden JS virker formularen stadig). Vores bidrag er kun CSS og skal holde kontrastkravene (H), en synlig fokusring og respektere `prefers-reduced-motion`. `login_headertext` = sitenavnet giver logo-linket et tilgængeligt navn. Login virker med tastatur alene og uden JavaScript.
- **Ulempe:** markup og tekster er WordPress', så vi kan ikke rette eventuelle mangler i dem, kun præsentationen.
- **A2/A1** ville give fuld markup-kontrol, men samtidig ansvar for fejlvisning, fokus på fejl og skærmlæser-annoncering, som WordPress ellers leverer.

### Påvirkning på resten af sitet

- **B:** ingen visuel ændring for anmodninger uden en gyldig dashboard-`redirect_to`. Det gælder administratorer, shop managers, sessionsudløb i wp-admin, glemt adgangskode, reset og registrering.
  - WooCommerce' "Min konto"-login berøres ikke.
  - En administrator, der åbner en dashboard-URL logget ud, ser den brandede side. Efter login gælder WordPress' og `LoginRedirect`s nuværende redirect-regler uændret.
  - Ingen ændring af CSP (dashboardets CSP gælder ikke `wp-login.php`), af cookies eller af sessions.
- **C:** alle `wp-login.php`-skærme for alle brugere.

### WordPress-API'er og hooks (B)

- **Præsentation (kun i dashboard-kontekst):**
  - `login_enqueue_scripts`: `wp_enqueue_style()` af en statisk CSS-fil og `wp_add_inline_style()` med logo-URL'en.
  - `login_headerurl`, `login_headertext`, `login_body_class` (fx en `merchboard-login`-klasse; `$action` bruges til at begrænse til login-skærmen).
  - Eventuelt `login_title` og `login_message` (fx "Log ind på Merch Dashboard").
- **Kontekst:** `$_REQUEST['redirect_to']` (string, `wp_unslash()`) → `DashboardRoute::dashboard_url_for()` (`wp_validate_redirect()`). Kontekstbestemmelsen er ren læsning uden sideeffekter.
- **Eksisterende og uændret:**
  - `wp_login_url()` og `wp_logout_url()` i `ShellController`;
  - `login_redirect` og `woocommerce_login_redirect` i `LoginRedirect`;
  - WordPress' `wp_signon()`, `wp_safe_redirect()`, `wp_lostpassword_url()`.
- **Bruges ikke:** `wp_login_form()`, egen `wp_signon()`-håndtering, `login_form_{action}`-overtagelse, `login_init`-redirects, `login_url`-filteret (ingen ændring af login-URL'en for sitet) eller JavaScript.

### Er global `wp-login.php`-branding stadig nødvendig?

Nej, ikke med B. Global branding (C) er kun relevant, hvis projektejeren bevidst vil brande hele sitets login. Det er en separat beslutning og ikke en del af Merch Dashboards behov.

### Hvad skal ændres i ADR-beslutningen

ADR'en om login-branding (J4) ændres fra "site-dækkende branding af `wp-login.php`" til:

- branding af `wp-login.php` **kun i Merch Dashboard-kontekst**, defineret som at `redirect_to` består `DashboardRoute::dashboard_url_for()`, og kun på login-skærmen;
- ingen ny route, ingen egen formular, ingen egen POST-håndtering, intet `wp_signon()`-kald og ingen ændring af `login_url`;
- glemt adgangskode og reset forbliver WordPress' standarddesign (bevidst accepteret begrænsning);
- lys/mørk-valget (`prefers-color-scheme` eller altid Light) er uændret som beslutningspunkt;
- fravalgte alternativer A1, A2 og C med begrundelse (ADR-006: intet eget login; URL-baseret beskyttelse; plugin-kompatibilitet);
- konsekvens: styling er bundet til WordPress' login-markup og verificeres pr. WordPress-version.

Øvrige ADR'er (logo, temaarkitektur og tema-persistence) påvirkes ikke, bortset fra at logo-servicen også bruges af den betingede login-branding.
