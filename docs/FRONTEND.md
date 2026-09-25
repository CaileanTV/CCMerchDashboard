# Frontend – Creator Dashboard

> Status: **Frontend-fundament ✅ (fase 13).** Route og sikker shell (fase 12), React/TypeScript-app med layout, navigation, router, API-klient, `useApi` og kernekomponenter (fase 13). Creator-data via REST (`/me`) kommer i fase 14.

## Teknologivalg

**Standard (ADR-010, ADR-027): React via WordPress' egen `@wordpress/element`, skrevet i TypeScript (strict), bygget med `@wordpress/scripts`, med data fra WordPress REST API i namespace `merchboard/v1`.**

| Alternativ | Vurdering |
| --- | --- |
| PHP/HTML + lidt vanilla JS | Simpelt i starten, men dashboardet skal have navigation uden reload, grafer, tabeller med filtrering, modaler, formularer med validering og loading/empty/error-states for mange moduler. Det bliver hurtigt til hjemmelavet state-håndtering og DOM-kode, som er svær at vedligeholde. |
| WordPress Interactivity API | God til progressive enhancement af server-renderede blocks, men ikke designet til en app med client-side routing og mange datavisninger. Økosystemet til tabeller og grafer er tyndt. |
| React (via `@wordpress/element`) | **Valgt.** WordPress leverer React selv (script handles `react`, `react-dom`, `react-jsx-runtime`, `wp-element`), så vi bundler den ikke. `@wordpress/scripts` giver build, dependency extraction, i18n, lint og test uden ekstra konfiguration. Stort økosystem, og mange udviklere kender det. |
| Vue/Svelte/andet | Kræver eget bundled runtime og egen toolchain og står uden for WordPress' økosystem (i18n, api-fetch). Ingen gevinst, der opvejer det. |
| Vite i stedet for wp-scripts | Hurtigere dev-server, men mangler WordPress' dependency extraction og `*.asset.php`-versionering og kræver manuel opsætning af lint og i18n. wp-scripts vinder på integration. |

**Hvorfor ikke `@wordpress/components`?** De ligner wp-admin/Gutenberg, og kravet er et dashboard, der *ikke* ligner WordPress. Vi bygger et lille eget komponentsæt. `@wordpress/components` må gerne bruges i admin-skærme, hvis de nogensinde bliver React-baserede.

**TypeScript (✅ besluttet, ADR-027):** Al dashboard-kode er `.ts`/`.tsx`. `@wordpress/scripts` transpilerer den med Babel (ingen ekstra bundler eller webpack-konfiguration). `tsc` bruges kun til typetjek (`npm run typecheck`, `noEmit`). TypeScript giver compile-time sikkerhed for vores egen kode og API-kontrakter. **Det er ikke sikkerhed:** data fra serveren er `unknown`, indtil en runtime-validering har tjekket det, og adgang afgøres altid af serveren.

**Ingen global state-library:** Almindelig React-state (`useState`, `useEffect`, context og egne hooks). Redux, Zustand, MobX, `@wordpress/data` og TanStack Query kræver en ny ADR med et dokumenteret behov.

## Serving: dedikeret route og standalone shell

- Rewrite rule (`Modules/Dashboard/DashboardRoute`, lagt øverst): `^creator-dashboard(?:/(.*?))?/?$` → `index.php?merchboard_dashboard=1&merchboard_dashboard_path=$matches[1]`. Slug'en (default `creator-dashboard`) kan ændres under Merch Dashboard → Settings. Uden pretty permalinks er URL'en `/?merchboard_dashboard=1` (deep link: `&merchboard_dashboard_path=sales`). Query-formen virker også med pretty permalinks.
- Rewrite rules: De gemte regler slettes ved aktivering, deaktivering (`Deactivator`) og slug-ændring, og WordPress genopbygger dem ved næste request. Vi bruger ikke `flush_rewrite_rules()`, som ville genopbygge med den aktuelle requests regler (den gamle slug). På `init` tjekkes desuden, om de gemte regler indeholder den aktuelle regel (fx efter en plugin-opdatering). Hvis ikke, slettes de.
- På `template_redirect` (prioritet 0, før `redirect_canonical`) i `ShellController`:
  1. Ikke logget ind → `wp_safe_redirect( wp_login_url( <dashboard-URL inkl. deep link> ) )`.
  2. Logget ind uden creator-adgang (`CreatorContext::current()` er null: ingen capability, ingen record, inaktiv, frakoblet eller databasefejl) → HTTP 403 med `templates/dashboard/no-access.php` ("Your access is not active", kun et log ud-link). Siden siger ikke hvorfor, linker aldrig til wp-admin og indlæser **ingen** JavaScript. Bundlen registreres slet ikke.
  3. OK → HTTP 200 med `templates/dashboard/shell.php` (React-appen). Mangler bygget (`build/dashboard.asset.php`/`dashboard.js`), svares HTTP 503 med en statisk besked, og fejlen logges.
- Shellen er **en komplet HTML-side uden theme**: ingen `get_header()` og ingen `wp_head()`, som ville trække theme- og plugin-CSS/JS ind. Kun vores handle `merchboard-dashboard` og dens WordPress-dependencies udskrives (`wp_print_styles`/`wp_print_scripts` med eksplicit handle). Andre plugins' enqueued scripts kommer ikke med (verificeret i integrationstest).
- Headers på alle dashboard-svar: `nocache_headers()`, `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` og en CSP. Redirect og 403 har `default-src 'none'` uden `script-src`. React-shellen har `script-src 'self' 'nonce-…'` (ny nonce pr. request, sat på alle `<script>`-tags via `wp_script_attributes`/`wp_inline_script_attributes`, kun mens shellen renderes), `style-src 'self' 'unsafe-inline'`, `connect-src 'self'`, `object-src 'none'`, `base-uri 'none'` og `frame-ancestors 'self'`. Ingen `unsafe-eval` og ingen `unsafe-inline` for scripts (se [SECURITY.md](SECURITY.md)).
- Admin bar skjules for rene creators på alle frontend-sider (fase 12, ADR-026).
- Client-side routes (fx `/creator-dashboard/profile/`) rammer samme rewrite, så deep links og refresh virker.

**Hvorfor ikke en WordPress-side med shortcode/block?** Theme'et ville omslutte dashboardet (header, footer og CSS-konflikter), redaktører kunne slette eller ændre siden, og layoutet ville ikke kunne ligne en selvstændig SaaS-app.

## Build

- `npm run build` → `wp-scripts build dashboard=./assets/src/dashboard/index.tsx --output-path=build`. Output: `build/dashboard.js`, `dashboard.css`, `dashboard-rtl.css` og `dashboard.asset.php` (dependencies og version). Ingen source maps i produktion. `build/` er gitignored og bygges i CI og til releases.
- `npm run start` bygger og overvåger det samme entry i udviklingstilstand.
- `Modules/Dashboard/DashboardAssets` læser og validerer `dashboard.asset.php`, registrerer script og style (med RTL-variant) og vedhæfter bootstrap-data.
- Dependencies (fra dependency extraction): `react-jsx-runtime`, `wp-element` og `wp-i18n`. `wp-api-fetch` kommer med, når den første endpoint-funktion bruges (fase 14). Med `SCRIPT_DEBUG` tilføjer WordPress selv sine udviklings-scripts (fx `react-refresh-runtime`).
- React-versionen følger WordPress (WordPress 6.7 leverer React 18.3, nyere versioner React 19). Koden bruger kun API'er, der findes i begge (`createRoot`, hooks, `useId`). Typerne er `@types/react` 19.

## Bootstrap-data

`ShellController::bootstrap()` → `wp_add_inline_script( 'merchboard-dashboard', 'window.merchboardDashboard = …', 'before' )`. JSON'en kodes med `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`, så intet kan lukke `<script>`-tagget.

```json
{
  "site_name": "…",
  "dashboard_url": "https://…/creator-dashboard/",
  "routing": "path",
  "logout_url": "https://…/wp-login.php?action=logout&_wpnonce=…&redirect_to=…",
  "login_url": "https://…/wp-login.php?redirect_to=…",
  "locale": "da_DK",
  "sections": [ { "id": "overview", "label": "Overview", "path": "" } ]
}
```

- Kun ikke-følsomme data. **Ingen creator-data og intet creator-ID.** Hvem creator'en er, hentes via REST (`GET /me`, fase 14), så der er én sandhed og én permission-sti.
- `routing` er `path` (pretty permalinks) eller `query` (`?merchboard_dashboard=1`).
- REST-root og `X-WP-Nonce` konfigureres af WordPress' eget `wp-api-fetch`-script (inline middleware). De ligger derfor ikke i bootstrap-data.
- Klienten validerer alt i `bootstrap.ts` (`parseConfig`): alle felter skal findes, alle URL'er skal være absolutte `http(s)`-URL'er på sidens egen origin uden credentials, og sektionsstier skal være gyldige dashboard-stier. Ukendte felter ignoreres. Er data ugyldige, vises en generisk fejl uden links.
- Sektionslisten er i fase 13 fast (`overview`). Registry og filter `merchboard_dashboard_sections` kommer i fase 15.

## Struktur (✅ fase 13)

```text
assets/src/dashboard/
├── index.tsx                # entry: finder #merchboard-dashboard-root og kalder mountDashboard()
├── mount.tsx                # parseConfig() → <App> eller generisk fejl
├── bootstrap.ts             # runtime-validering af window.merchboardDashboard
├── types/                   # config.ts (DashboardConfig, DashboardSection), assets.d.ts (*.scss, Window)
├── api/                     # client.ts (eneste sted med apiFetch), validate.ts (runtime-checks)
├── hooks/                   # useApi.ts (loading/success/error, retry, abort)
├── app/                     # App, config-context, router (RouterProvider, useRouter, Link), routes.ts (ren URL-logik),
│   │                        # pages.tsx (sti → side), ErrorBoundary
│   └── layout/              # Layout (skip link, topbar, sidebar, mobil-nav, main), Navigation
├── components/              # Button, Card, Alert, Skeleton, EmptyState, ErrorState, ApiErrorState, Field
├── sections/overview/       # OverviewSection (fase 13: placeholder med empty state)
├── styles/                  # _tokens.scss, index.scss (kun .merchboard-*-selektorer)
├── testing/                 # setup.ts, helpers.tsx (render/act, fixtures)
└── test/                    # Jest-tests (*.test.ts/tsx)
```

- **Routing:** en lille egen router baseret på History API (`app/router.tsx`, `app/routes.ts`). Stier er `''` eller segmenter af `[a-z0-9-]` (samme regel som serverens `DashboardRoute`), så navigation aldrig kan pege på et andet site eller en `javascript:`-URL. `Link` er et rigtigt `<a href>`, der navigerer client-side ved almindeligt venstreklik. Tilbage-knap og refresh virker. Revurderes, hvis behovene vokser (fx nested routes).
- **API-boundary (`api/client.ts`):** `request( { path, method, data, signal }, validate )` er den eneste vej til REST. Den kalder `apiFetch( { …, parse: false } )`, afviser stier uden for `/merchboard/v1/`, validerer svaret med en endpoint-specifik validator og returnerer `ApiResult<T>` = `{ ok: true, data }` eller `{ ok: false, error: { kind, status, code } }`. Fejltyper: `unauthenticated` (401), `forbidden` (403), `not_found` (404), `server`, `network`, `invalid_response` og `aborted`. Serverens fejlbeskeder bruges aldrig i UI'et. Komponenter kalder aldrig `fetch()`/`apiFetch()` direkte. Endpoint-funktioner (fx `api/me.ts`) kommer med endpoints i fase 14.
- **Data og state:** `useApi( load )` giver `loading`/`success`/`error`, `retry()` og afbryder requesten ved unmount. Ingen global store.
- **401/403 i UI'et (`ApiErrorState`):** 401 → "Your session has expired" og et link til login. 403 → "You do not have access to this" og et log ud-link. Øvrige fejl → generisk besked og "Try again". Der redirectes aldrig automatisk (ingen redirect-loops). Links er server-leverede URL'er på samme origin. Adgang afgøres altid af serveren. UI-state bestemmer aldrig adgang.
- **Sektioner:** Serveren bestemmer, hvilke sektioner der vises (fra fase 15 via filter `merchboard_dashboard_sections` og capability pr. sektion). Klienten viser kun dem. En sektion, som serveren tillader, men som bygget endnu ikke kender, viser "not available yet". Ukendte stier viser "Page not found" med link til startsiden. Lazy-loading (`React.lazy`) indføres, når der er flere sektioner.
- **Grafer:** vælges i fase 20. Krav: lille bundle, tilgængelighed og tree-shaking. Kandidat: Chart.js eller en letvægts-SVG-løsning.
- **i18n:** `@wordpress/i18n` (`__( 'Overview', 'merchboard' )`). Kildestrenge er engelske. Oversættelser (`wp_set_script_translations()`, dansk) kommer i fase 15.
- **Sikkerhed:** `dangerouslySetInnerHTML` er forbudt (ESLint `react/no-danger`). Serverværdier renderes altid som tekst. `any` er forbudt (`@typescript-eslint/no-explicit-any`). `localStorage`, `sessionStorage`, `indexedDB` og `document.cookie` er forbudt i dashboard-koden (ESLint). Eksterne links skal bruge `rel="noopener noreferrer"`.

## Sektioner (informationsarkitektur)

| Sektion | Indhold | Fase |
| --- | --- | --- |
| **Overview** | Velkomst, creator-info, nøgletal (salg i perioden, antal ordrer og top-produkter), senere aktivitet. Fase 13: placeholder med empty state | 13 (placeholder) → 15 → 20 |
| **Products** | Creator'ens merch (read-only i v1): billede, navn, pris, lager og status | 19 |
| **Orders** | Ordrelinjer med creator'ens produkter: dato, ordrenr., produkt, antal, status og evt. land. **Ingen kundens navn, e-mail eller adresse.** | 19 |
| **Sales** | Omsætning og antal over tid, pr. produkt og refunds. Samler "Sales" og "Statistics" i én sektion. | 20 |
| **Payouts** | Afregninger (fremtid) | – |
| **Profile** | Visningsnavn, bio, avatar og sociale links; link til WP-password-reset | 14 |
| **Settings** | Notifikationspræferencer, sprog (fremtid) | – |

Ændringer i forhold til det oprindelige forslag: "Merchandise" og "Products" er samme ting og hedder nu **Products**. "Sales" og "Statistics" er slået sammen, fordi to sektioner med overlappende grafer forvirrer.

## Design system

Mål: moderne, roligt SaaS-udtryk og konsistens frem for mange varianter. Alle værdier er CSS custom properties på `.merchboard-app`, så dark mode senere kan tilføjes ved at skifte tokens. Alle selektorer har prefix `merchboard-`. Der er ingen globale element-selektorer uden for `.merchboard-app` og intet CSS-framework.

### Tokens (✅ fase 13 i `styles/_tokens.scss`)

| Gruppe | Tokens |
| --- | --- |
| Farver | Neutral skala `--merchboard-gray-50…900`; `--merchboard-primary-{50,100,500,600,700}`; semantiske `--merchboard-success`, `--merchboard-warning`, `--merchboard-danger`, `--merchboard-info` (+ `-bg`-varianter); overflader `--merchboard-surface`, `--merchboard-surface-muted`, `--merchboard-border`; tekst `--merchboard-text`, `--merchboard-text-muted`; `--merchboard-focus` |
| Typografi | System font stack (`system-ui, -apple-system, "Segoe UI", Roboto, …`) i v1. Skal der bruges en brand-font, **self-hostes** den. Ingen Google Fonts-CDN (GDPR, jf. dansk/EU-praksis). Skala: 12 / 14 (body) / 16 / 20 / 24 px, line-height 1.5, vægte 400/500/600. |
| Spacing | 4px-grid: `--merchboard-space-1` (4) … `--merchboard-space-12` (48) |
| Radius | `--merchboard-radius-sm` 6px, `--merchboard-radius` 10px, `--merchboard-radius-lg` 16px, `--merchboard-radius-full` |
| Skygge | `--merchboard-shadow-sm`, `--merchboard-shadow` (dæmpede) |
| Breakpoints | `lg` 1024 i brug (mobile-first `min-width`). `sm` 640, `md` 768 og `xl` 1280 tilføjes, når de bruges. |
| Z-index | `--merchboard-z-nav` 100. Dropdown, modal og toast tilføjes med komponenterne. |
| Motion | `--merchboard-motion` 150ms ease-out. `prefers-reduced-motion` slår transitions og animationer fra. |

### Komponenter

**✅ Fase 13:** `Button` (primary/secondary/ghost/danger; sm/md; busy-tilstand, native `<button>`, default `type="button"`), `Card` (labelled `<section>` med h2/h3), `Alert` (info/success/warning/danger; tonen skrevet som tekst; `role="alert"` for warning/danger og ellers `status`), `Skeleton` (`role="status"` med skjult tekst, søjler `aria-hidden`), `EmptyState`, `ErrorState` (`role="alert"`, "Error:" som tekst), `ApiErrorState`, `Field` (label, hjælpetekst og fejl koblet via id, `aria-describedby` og `aria-invalid`), navigation (`Layout` med topbar, sidebar og mobil-nav).

**Senere:** `StatCard`, `Table` (responsiv: kort-layout på mobil), `Badge`, `Modal`/`Dialog` (fokusfælde, `Esc`, `aria-modal`), `Input`/`Select`/`Textarea`, `Toast`, `Spinner` og `Avatar`, når en sektion har brug for dem.

### Layout (✅ fase 13)

- Skip link "Skip to content" (første tab-stop) → `<main id="merchboard-main">`.
- `<header>` (topbar): sitenavn, menuknap (under lg, `aria-expanded`/`aria-controls`) og log ud.
- **≥ lg:** fast venstre sidebar (`<nav aria-label="Dashboard">`), indhold i max-width-container.
- **< lg:** menuknappen åbner navigationen under topbaren. `Esc` lukker den og sætter fokus tilbage på knappen, og et klik på et link lukker den. Ved ≤ 5 sektioner kan en bottom tab bar overvejes.
- `<main>` har sidens eneste `<h1>` (sektionens titel). `document.title` følger siden.
- Hvert dataområde har fire tilstande: **loading** (skeleton), **empty** (forklaring og evt. handling), **error** (besked og "Try again") og **data**.

### Tilgængelighed

Mål: WCAG 2.1 AA. Kontrast ≥ 4.5:1, synlig fokusmarkering (`:focus-visible`-outline), fuld tastaturnavigation, semantisk HTML (`header`, `nav`, `main`, `button`, `a`), `aria-current="page"` i navigationen, og `aria-live`/roller for loading- og fejltilstande, så de forstås uden farve.
