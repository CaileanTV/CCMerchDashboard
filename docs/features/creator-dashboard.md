# Feature: Creator Dashboard

## Purpose
Et selvstændigt, moderne og responsivt dashboard, hvor den enkelte creator ser og vedligeholder sine egne oplysninger og senere sine produkter, ordrer og salg.

## Current status
Route og sikker shell ✅ (fase 12): `/creator-dashboard/` svarer med login-redirect, 403 eller en placeholder-shell uden JavaScript. Frontend-fundament i fase 13, `/me` + Profile i fase 14 og Overview i fase 15 (MVP).

## User-facing functionality (MVP)
- Adgang via `/creator-dashboard/` (slug kan konfigureres) efter login.
- Layout: sidebar (desktop) / off-canvas (mobil), topbar med navn/avatar og log ud.
- **Overview:** velkomst, creator-info og plads til nøgletal (fyldes med data i fase 20).
- **Profile:** se og redigere visningsnavn, bio og sociale links. Link til WordPress' password-reset.
- Loading-, empty- og error-states overalt.

Senere sektioner: Products, Orders, Sales, Payouts og Settings. Se [FRONTEND.md](../FRONTEND.md#sektioner-informationsarkitektur).

## Admin functionality
- ✅ Settings: dashboard-slug (fase 12, validering og audit, se [ADMIN.md](../ADMIN.md#settings)).
- Planlagt: branding (logo, primærfarve, navn).

## Technical implementation
- **Route ✅:** `Modules/Dashboard/DashboardRoute` registrerer rewrite rule og query vars og ugyldiggør gemte rewrite rules ved behov. `ShellController` (`template_redirect`, prioritet 0) håndterer auth-gate via `CreatorContext` og renderer `templates/dashboard/shell.php` eller `no-access.php` uden theme. Se [FRONTEND.md](../FRONTEND.md#serving-dedikeret-route-og-standalone-shell--fase-12).
- **Assets:** `build/dashboard.js` og `.css` registreres med dependencies og version fra `build/dashboard.asset.php`. Kun disse handles udskrives i shellen.
- **Bootstrap-data:** `window.merchboardDashboard` (REST-root, nonce, base path, logout-URL, locale og sektioner).
- **Sektioner:** `DashboardSections`-registry (filter `merchboard_dashboard_sections`). Hver sektion har `id`, `label`, `icon`, `path` og `capability`. Serveren filtrerer på capability, og klienten lazy-loader.
- **Data:** `GET /merchboard/v1/me` og `PATCH /merchboard/v1/me/profile`.

## Relevant files
✅ `src/Modules/Dashboard/{DashboardModule,DashboardRoute,ShellController,ShellResponse}.php`, `src/Core/Lifecycle/Deactivator.php`, `templates/dashboard/{shell,no-access}.php`, `tests/Unit/Modules/Dashboard/*` og `tests/Integration/Access/DashboardAccessTest.php`. Planlagt: `assets/src/dashboard/**`.

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
- ✅ Integration (fase 12): route-adgang (logget ud → login-redirect, ikke-creator → 403, inaktiv → 403, creator → 200, deaktivering/frakobling/rolle fjernet virker straks, slettet bruger, databasefejl), rewrite rules og deep links, slug-ændring. Planlagt: `/me` og `/me/profile` (alle security-cases i [API.md](../API.md#test-krav)).
- JS unit: `useApi`, router og formularvalidering.
- Manuel: mobil (375px), tablet og desktop samt tastaturnavigation.

## Known limitations
- Ingen offline-understøttelse.
- Ingen dark mode i v1 (tokens forberedt).

## Future improvements
Avatar-upload, notifikationscenter, dark mode og PWA-manifest.
