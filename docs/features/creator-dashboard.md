# Feature: Creator Dashboard

## Purpose
Et selvstændigt, moderne og responsivt dashboard, hvor den enkelte creator ser og vedligeholder sine egne oplysninger og senere sine produkter, ordrer og salg.

## Current status
Planlagt. Route/shell i fase 12, frontend-fundament i fase 13, `/me` + Profile i fase 14 og Overview i fase 15 (MVP).

## User-facing functionality (MVP)
- Adgang via `/creator-dashboard/` (slug kan konfigureres) efter login.
- Layout: sidebar (desktop) / off-canvas (mobil), topbar med navn/avatar og log ud.
- **Overview:** velkomst, creator-info og plads til nøgletal (fyldes med data i fase 20).
- **Profile:** se og redigere visningsnavn, bio og sociale links. Link til WordPress' password-reset.
- Loading-, empty- og error-states overalt.

Senere sektioner: Products, Orders, Sales, Payouts og Settings. Se [FRONTEND.md](../FRONTEND.md#sektioner-informationsarkitektur).

## Admin functionality
- Settings → General: dashboard-slug og branding (logo, primærfarve, navn).

## Technical implementation
- **Route:** `Modules/Dashboard/Route` registrerer rewrite rule og query var. `ShellController` på `template_redirect` håndterer auth-gate og renderer `templates/dashboard/shell.php` uden theme. Se [FRONTEND.md](../FRONTEND.md#serving-dedikeret-route-og-standalone-shell).
- **Assets:** `build/dashboard.js` og `.css` registreres med dependencies og version fra `build/dashboard.asset.php`. Kun disse handles udskrives i shellen.
- **Bootstrap-data:** `window.merchboardDashboard` (REST-root, nonce, base path, logout-URL, locale og sektioner).
- **Sektioner:** `DashboardSections`-registry (filter `merchboard_dashboard_sections`). Hver sektion har `id`, `label`, `icon`, `path` og `capability`. Serveren filtrerer på capability, og klienten lazy-loader.
- **Data:** `GET /merchboard/v1/me` og `PATCH /merchboard/v1/me/profile`.

## Relevant files
Planlagt: `src/Modules/Dashboard/*`, `templates/dashboard/shell.php`, `assets/src/dashboard/**` og `tests/Integration/Dashboard/*`.

## Database/data used
`merchboard_creators`, `merchboard_creatormeta` (profilfelter: `bio`, `social_links`, `avatar_id`) og `merchboard_settings` (slug og branding).

## Permissions
- Route og alle endpoints: `CreatorContext::current()` ≠ null.
- `PATCH /me/profile`: whitelist `display_name`, `bio` og `social_links`. Nonce via `X-WP-Nonce`.

## API endpoints
| Metode | Route | Beskrivelse |
| --- | --- | --- |
| `GET` | `/merchboard/v1/me` | Profil, rettigheder og sektioner |
| `PATCH` | `/merchboard/v1/me/profile` | Opdater whitelistede profilfelter |

## Hooks
`merchboard_dashboard_sections` (filter) og `merchboard_creator_updated` (action ved profilændring).

## Dependencies
Access (CreatorContext), Creators (repository/service), Core (Settings, Assets) og `@wordpress/element`/`api-fetch`/`i18n` (leveret af WordPress).

## Tests
- Integration: route-adgang (logget ud → login-redirect, ikke-creator → 403, suspenderet → 403, creator → 200), `/me` og `/me/profile` (alle security-cases i [API.md](../API.md#test-krav)).
- JS unit: `useApi`, router og formularvalidering.
- Manuel: mobil (375px), tablet og desktop samt tastaturnavigation.

## Known limitations
- Ingen offline-understøttelse.
- Ingen dark mode i v1 (tokens forberedt).

## Future improvements
Avatar-upload, notifikationscenter, dark mode og PWA-manifest.
