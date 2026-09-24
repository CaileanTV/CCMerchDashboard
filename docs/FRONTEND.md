# Frontend – Creator Dashboard

> Status: **Design.** Route og shell kommer i fase 12, frontend-fundament i fase 13.

## Teknologivalg

**Anbefaling: React via WordPress' egen `@wordpress/element`, bygget med `@wordpress/scripts`.** (ADR-010)

| Alternativ | Vurdering |
| --- | --- |
| PHP/HTML + lidt vanilla JS | Simpelt i starten, men dashboardet skal have navigation uden reload, grafer, tabeller med filtrering, modaler, formularer med validering og loading/empty/error-states for mange moduler. Det bliver hurtigt til hjemmelavet state-håndtering og DOM-kode, som er svær at vedligeholde. |
| WordPress Interactivity API | God til progressive enhancement af server-renderede blocks, men ikke designet til en app med client-side routing og mange datavisninger. Økosystemet til tabeller og grafer er tyndt. |
| React (via `@wordpress/element`) | **Valgt.** WordPress leverer React selv (script handle `react`/`wp-element`), så vi bundler den ikke. `@wordpress/scripts` giver build, dependency extraction, i18n, lint og test uden ekstra konfiguration. Stort økosystem, og mange udviklere kender det. |
| Vue/Svelte/andet | Kræver eget bundled runtime og egen toolchain og står uden for WordPress' økosystem (i18n, api-fetch). Ingen gevinst, der opvejer det. |
| Vite i stedet for wp-scripts | Hurtigere dev-server, men mangler WordPress' dependency extraction og `*.asset.php`-versionering og kræver manuel opsætning af lint og i18n. wp-scripts vinder på integration. |

**Hvorfor ikke `@wordpress/components`?** De ligner wp-admin/Gutenberg, og kravet er et dashboard, der *ikke* ligner WordPress. Vi bygger et lille eget komponentsæt. `@wordpress/components` må gerne bruges i admin-skærme, hvis de nogensinde bliver React-baserede.

**TypeScript:** Åben beslutning (se [PROJECT-STATUS.md](PROJECT-STATUS.md#åbne-spørgsmål)). Default er JavaScript med JSDoc-typer. Skal afgøres før fase 13, så der ikke skal migreres senere.

## Serving: dedikeret route og standalone shell

- Rewrite rule: `^creator-dashboard(/.*)?$` → query var `ccmerch_dashboard`. Slug'en kan ændres i settings, og rewrite rules flushes ved ændring.
- På `template_redirect`:
  1. Ikke logget ind → `wp_safe_redirect( wp_login_url( <nuværende dashboard-URL> ) )`.
  2. Logget ind uden creator-adgang → 403-side i dashboard-design med link til log ud. Vi sender ikke brugeren videre til wp-admin.
  3. OK → render `templates/dashboard/shell.php` og `exit`.
- Shellen er **en komplet HTML-side uden theme**: ingen `get_header()`, og ingen fuld `wp_head()`, som ville trække theme- og plugin-CSS/JS ind. Kun vores registrerede handles og deres WP-dependencies udskrives (`wp_print_styles`/`wp_print_scripts` med eksplicitte handles). Det giver fuld kontrol over design, performance og CSP.
- Headers: `nocache_headers()`, `X-Robots-Tag: noindex`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Content-Type-Options: nosniff`, `frame-ancestors 'self'`. En stram CSP planlægges (se [SECURITY.md](SECURITY.md)).
- Admin bar skjules for creators på alle frontend-sider.
- Client-side routes (fx `/creator-dashboard/profile`) rammer samme rewrite, så deep links og refresh virker.

**Hvorfor ikke en WordPress-side med shortcode/block?** Theme'et ville omslutte dashboardet (header, footer og CSS-konflikter), redaktører kunne slette eller ændre siden, og layoutet ville ikke kunne ligne en selvstændig SaaS-app.

## Bootstrap-data

Shellen udskriver et JSON-objekt via `wp_add_inline_script( 'ccmerch-dashboard', 'window.ccmerchDashboard = …', 'before' )` med `wp_json_encode()`:

```json
{
  "rest_root": "https://…/wp-json/",
  "rest_nonce": "…",
  "base_path": "/creator-dashboard",
  "logout_url": "…",
  "locale": "da_DK",
  "sections": [{ "id": "overview", "label": "Overview", "icon": "home", "path": "/" }]
}
```

Kun ikke-følsomme data. Creator-data hentes via REST (`GET /me`), så der er én sandhed og én permission-sti.

## Struktur (mål)

```text
assets/src/dashboard/
├── index.js                 # mount, api-fetch-middleware (nonce, root), router
├── app/                     # App, Layout (Sidebar, Topbar, MobileNav), Router, ErrorBoundary
├── api/                     # tynde funktioner pr. endpoint (getMe, updateProfile …)
├── hooks/                   # useApi (loading/error/data), useSection …
├── components/              # design system: Button, Card, Table, Badge, Alert, Modal, Field, Skeleton, EmptyState …
├── sections/                # én mappe pr. dashboard-sektion (overview/, profile/ …), lazy-loaded
└── styles/                  # tokens.scss, base.scss, komponent-styles
```

- **Routing:** en lille egen router baseret på History API, afgrænset til `base_path`. Revurderes, hvis behovene vokser (fx nested routes).
- **Data:** `@wordpress/api-fetch` samt en lille `useApi`-hook. Ingen Redux eller `@wordpress/data`. Hvis caching og refetch bliver komplekst, overvejes TanStack Query (ADR påkrævet).
- **Sektioner:** Serveren bestemmer hvilke sektioner creator'en må se (filter `ccmerch_dashboard_sections` + capability pr. sektion). Klienten viser kun dem og lazy-loader deres chunk (`React.lazy`). Et nyt modul tilføjer én PHP-registrering og én mappe i `sections/`.
- **Grafer:** vælges i fase 20. Krav: lille bundle, tilgængelighed og tree-shaking. Kandidat: Chart.js eller en letvægts-SVG-løsning.
- **i18n:** `@wordpress/i18n` (`__( 'Overview', 'ccmerch-dashboard' )`) og `wp_set_script_translations()`. Kildestrenge er på engelsk og oversættes til dansk (`da_DK`).
- **Sikkerhed:** `dangerouslySetInnerHTML` er forbudt (ESLint-regel `react/no-danger` tilføjes i fase 13). Eksterne links bruger `rel="noopener noreferrer"`.

## Sektioner (informationsarkitektur)

| Sektion | Indhold | Fase |
| --- | --- | --- |
| **Overview** | Velkomst, creator-info, nøgletal (salg i perioden, antal ordrer og top-produkter), senere aktivitet | 15 → 20 |
| **Products** | Creator'ens merch (read-only i v1): billede, navn, pris, lager og status | 19 |
| **Orders** | Ordrelinjer med creator'ens produkter: dato, ordrenr., produkt, antal, status og evt. land. **Ingen kundens navn, e-mail eller adresse.** | 19 |
| **Sales** | Omsætning og antal over tid, pr. produkt og refunds. Samler "Sales" og "Statistics" i én sektion. | 20 |
| **Payouts** | Afregninger (fremtid) | – |
| **Profile** | Visningsnavn, bio, avatar og sociale links; link til WP-password-reset | 14 |
| **Settings** | Notifikationspræferencer, sprog (fremtid) | – |

Ændringer i forhold til det oprindelige forslag: "Merchandise" og "Products" er samme ting og hedder nu **Products**. "Sales" og "Statistics" er slået sammen, fordi to sektioner med overlappende grafer forvirrer.

## Design system

Mål: moderne, roligt SaaS-udtryk og konsistens frem for mange varianter. Alle værdier er CSS custom properties på `.ccm-app`, så dark mode senere kan tilføjes ved at skifte tokens.

### Tokens

| Gruppe | Tokens |
| --- | --- |
| Farver | Neutral skala `--ccm-gray-50…900`; `--ccm-primary-{50,100,500,600,700}`; semantiske `--ccm-success`, `--ccm-warning`, `--ccm-danger`, `--ccm-info` (+ `-bg`-varianter); overflader `--ccm-surface`, `--ccm-surface-muted`, `--ccm-border`; tekst `--ccm-text`, `--ccm-text-muted` |
| Typografi | System font stack (`system-ui, -apple-system, "Segoe UI", Roboto, …`) i v1. Skal der bruges en brand-font, **self-hostes** den. Ingen Google Fonts-CDN (GDPR, jf. dansk/EU-praksis). Skala: 12 / 14 (body) / 16 / 20 / 24 / 30 px, line-height 1.5, vægte 400/500/600. |
| Spacing | 4px-grid: `--ccm-space-1` (4) … `--ccm-space-12` (48) |
| Radius | `--ccm-radius-sm` 6px, `--ccm-radius` 10px, `--ccm-radius-lg` 16px, `--ccm-radius-full` |
| Skygge | `--ccm-shadow-sm`, `--ccm-shadow`, `--ccm-shadow-lg` (dæmpede) |
| Breakpoints | `sm` 640, `md` 768, `lg` 1024, `xl` 1280 (mobile-first `min-width`-queries) |
| Z-index | `--ccm-z-nav` 100, `--ccm-z-dropdown` 200, `--ccm-z-modal` 300, `--ccm-z-toast` 400 |
| Motion | 150-200ms ease-out. Respekter `prefers-reduced-motion`. |

### Komponenter (v1)

`Button` (primary/secondary/ghost/danger; sm/md; loading), `Card` (+ `StatCard`), `Table` (responsiv: kort-layout på mobil), `Badge` (status), `Alert` (info/success/warning/danger), `Modal`/`Dialog` (fokusfælde, `Esc`, `aria-modal`), `Field` (label, hjælpetekst, fejl) + `Input`/`Select`/`Textarea`, `Skeleton`, `EmptyState`, `ErrorState`, `Toast`/notifications, `Spinner`, `Avatar`, navigation (`Sidebar`, `Topbar`, `MobileNav`).

### Layout

- **≥ lg:** fast venstre sidebar (logo, sektioner, bund: profil og log ud), topbar med sidetitel og creator-avatar, indhold i max-width-container med kort-grid.
- **< lg:** topbar med hamburger → off-canvas navigation. Ved ≤ 5 sektioner kan en bottom tab bar bruges på mobil.
- Hvert dataområde har fire tilstande: **loading** (skeleton), **empty** (forklaring og evt. handling), **error** (besked og "Prøv igen") og **data**.

### Tilgængelighed

Mål: WCAG 2.1 AA. Kontrast ≥ 4.5:1, synlig fokusmarkering, fuld tastaturnavigation, semantisk HTML (`nav`, `main`, `button`), `aria-live` til toasts og formularfejl.
