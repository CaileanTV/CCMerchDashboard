# Feature: Creator Dashboard

## Purpose
Et selvstændigt, moderne og responsivt dashboard, hvor den enkelte creator ser og vedligeholder sine egne oplysninger og senere sine produkter, ordrer og salg.

## Current status
**MVP ✅ (fase 15).** Route og sikker shell (12), frontend-fundament (13), `/me` og Profile (14), Overview, sektion-registry og dansk oversættelse (15).

## User-facing functionality (MVP)
- Adgang via `/creator-dashboard/` (slug kan konfigureres) efter login.
- Layout: sidebar (desktop) / off-canvas (mobil), topbar med navn/avatar og log ud.
- **Overview ✅ (fase 15):** velkomst med navn og status, profil-resumé (bio og links, ellers link til Profile) og nøgletal som empty state "Salgsdata kommer snart" (rigtige tal i fase 20).
- **Profile ✅ (fase 14):** se og redigere visningsnavn, bio (maks. 1000 tegn) og sociale links (website, Instagram, TikTok, YouTube, X, Facebook; https). Link til WordPress' password-reset. Bekræftelse med toast.
- Loading-, empty- og error-states overalt.

Senere sektioner: Products, Orders, Sales, Payouts og Settings. Se [FRONTEND.md](../FRONTEND.md#sektioner-informationsarkitektur).

## Admin functionality
- ✅ Settings: dashboard-slug (fase 12, validering og audit, se [ADMIN.md](../ADMIN.md#settings)).
- Planlagt: branding (logo, primærfarve, navn).

## Technical implementation
- **Route ✅:** `Modules/Dashboard/DashboardRoute` registrerer rewrite rule og query vars og ugyldiggør gemte rewrite rules ved behov. `ShellController` (`template_redirect`, prioritet 0) håndterer auth-gate via `CreatorContext` og renderer `templates/dashboard/shell.php` eller `no-access.php` uden theme. Se [FRONTEND.md](../FRONTEND.md#serving-dedikeret-route-og-standalone-shell--fase-12).
- **Assets ✅ (fase 13):** `DashboardAssets` registrerer `build/dashboard.js` og `.css` (+ RTL) med dependencies og version fra `build/dashboard.asset.php`. Kun dette handle og dets WordPress-dependencies udskrives i shellen, alle med CSP-nonce.
- **Bootstrap-data ✅ (fase 13):** `window.merchboardDashboard` (sitenavn, dashboard-URL, routing, logout- og login-URL, locale og sektioner; ingen creator-data). REST-root og nonce sættes af WordPress' `wp-api-fetch`. Se [FRONTEND.md](../FRONTEND.md#bootstrap-data).
- **Sektioner:** `DashboardSections`-registry (filter `merchboard_dashboard_sections`). Hver sektion har `id`, `label`, `icon`, `path` og `capability`. Serveren filtrerer på capability, og klienten lazy-loader.
- **Data:** `GET /merchboard/v1/me` og `PATCH /merchboard/v1/me/profile`.

## Relevant files
✅ `src/Modules/Dashboard/{DashboardModule,DashboardRoute,ShellController,ShellResponse}.php`, `src/Core/Lifecycle/Deactivator.php`, `templates/dashboard/{shell,no-access}.php`, `tests/Unit/Modules/Dashboard/*` og `tests/Integration/Access/DashboardAccessTest.php`. ✅ `src/Modules/Dashboard/DashboardAssets.php`, `assets/src/dashboard/**` (fase 13, struktur i [FRONTEND.md](../FRONTEND.md#struktur--fase-13)), `tsconfig.json` og `jest.config.js`.

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
- ✅ JS unit (fase 13): bootstrap-validering, router, API-klient, `useApi`, `ApiErrorState`, komponenter og app (se [TESTING.md](../TESTING.md#frontend-tests-fase-13)). Senere: formularvalidering (fase 14).
- Manuel: mobil (375px), tablet og desktop samt tastaturnavigation.

## Known limitations
- Ingen offline-understøttelse.
- Ingen dark mode i v1 (tokens forberedt).

## Future improvements
Avatar-upload, notifikationscenter, dark mode og PWA-manifest.
