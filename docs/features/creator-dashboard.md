# Feature: Creator Dashboard

## Purpose
Et selvstændigt, moderne og responsivt dashboard, hvor den enkelte creator ser og vedligeholder sine egne oplysninger og ser sine produkter, ordrelinjer (fase 19) og salg (fase 20).

## Current status
**MVP ✅ (fase 15).** Route og sikker shell (12), frontend-fundament (13), `/me` og Profile (14), Overview, sektion-registry og dansk oversættelse (15). **Fase 19 ✅:** Products og Orders (kun med WooCommerce, ADR-039). **Fase 20 ✅:** salgsnøgletal på Overview og sektionen Sales (kun med WooCommerce, ADR-040/041).

## User-facing functionality (MVP)
- Adgang via `/creator-dashboard/` (slug kan konfigureres) efter login.
- Layout: sidebar (desktop) / off-canvas (mobil), topbar med navn/avatar og log ud.
- **Overview ✅ (fase 15):** velkomst med navn og status, profil-resumé (bio og links, ellers link til Profile) og nøgletal (fase 20): "Nettosalg (ekskl. moms)", enheder og ordrer for de seneste 30 dage pr. valuta fra `GET /me/sales`, ellers en empty state.
- **Profile ✅ (fase 14):** se og redigere visningsnavn, bio (maks. 1000 tegn) og sociale links (website, Instagram, TikTok, YouTube, X, Facebook; https). Link til WordPress' password-reset. Bekræftelse med toast.
- **Products ✅ (fase 19):** creator'ens udgivne og private produkter med billede, navn, status, aktiv pris og lagerstatus, pagineret. Se [FRONTEND.md](../FRONTEND.md#products-trin-6).
- **Orders ✅ (fase 19):** creator'ens ordrelinjer med dato, ordrenummer, produkt, antal, status og markeringerne "Refund recorded"/"Not counted", pagineret; ingen kundedata og ingen beløb. Se [FRONTEND.md](../FRONTEND.md#orders-trin-7).
- **Sales ✅ (fase 20):** valgt periode og interval (dag, uge, måned), nøgletal, egen SVG-søjlegraf og altid en tabel med samme tal, pr. valuta. Se [FRONTEND.md](../FRONTEND.md#sales--fase-20-trin-6-adr-040-adr-041).
- Loading-, empty- og error-states overalt.

Senere sektioner: Payouts og Settings. Se [FRONTEND.md](../FRONTEND.md#sektioner-informationsarkitektur).

## Admin functionality
- ✅ Settings: dashboard-slug (fase 12, validering og audit, se [ADMIN.md](../ADMIN.md#settings)).
- Planlagt: branding (logo, primærfarve, navn).

## Technical implementation
- **Route ✅:** `Modules/Dashboard/DashboardRoute` registrerer rewrite rule og query vars og ugyldiggør gemte rewrite rules ved behov. `ShellController` (`template_redirect`, prioritet 0) håndterer auth-gate via `CreatorContext` og renderer `templates/dashboard/shell.php` eller `no-access.php` uden theme. Se [FRONTEND.md](../FRONTEND.md#serving-dedikeret-route-og-standalone-shell--fase-12).
- **Assets ✅ (fase 13):** `DashboardAssets` registrerer `build/dashboard.js` og `.css` (+ RTL) med dependencies og version fra `build/dashboard.asset.php`. Kun dette handle og dets WordPress-dependencies udskrives i shellen, alle med CSP-nonce.
- **Bootstrap-data ✅ (fase 13):** `window.merchboardDashboard` (sitenavn, dashboard-URL, routing, logout- og login-URL, locale og sektioner; ingen creator-data). REST-root og nonce sættes af WordPress' `wp-api-fetch`. Se [FRONTEND.md](../FRONTEND.md#bootstrap-data).
- **Sektioner:** `DashboardSections`-registry (filter `merchboard_dashboard_sections`). Hver sektion har `id`, `label`, `path` og `capability` (ingen ikon). Serveren filtrerer på capability og sender kun synlige sektioner (uden capability) i bootstrap-data. Klienten slår sektionens komponent op efter id i `app/pages.tsx`; alle komponenter ligger i samme bundle (ingen lazy-loading). WooCommerce-modulet tilføjer `sales`, `products` og `orders` efter `overview` (`DashboardSectionRegistration`).
- **Data:** `GET /merchboard/v1/me`, `GET`/`PATCH /merchboard/v1/me/profile`, og med WooCommerce `GET /merchboard/v1/me/products` og `GET /merchboard/v1/me/orders` (paginerede lister via `requestList()`, fase 19) og `GET /merchboard/v1/me/sales` (fase 20).

## Relevant files
✅ `src/Modules/Dashboard/{DashboardModule,DashboardRoute,ShellController,ShellResponse}.php`, `src/Core/Lifecycle/Deactivator.php`, `templates/dashboard/{shell,no-access}.php`, `tests/Unit/Modules/Dashboard/*` og `tests/Integration/Access/DashboardAccessTest.php`. ✅ `src/Modules/Dashboard/DashboardAssets.php`, `assets/src/dashboard/**` (fase 13, struktur i [FRONTEND.md](../FRONTEND.md#struktur--fase-13)), `tsconfig.json` og `jest.config.js`. ✅ Fase 19: `src/Modules/WooCommerce/Rest/{MeProductsController,MeOrdersController}.php`, `src/Modules/WooCommerce/Dashboard/*` (read-model for produkter, berigelse af ordrelinjer, sektionsregistrering), `src/Modules/WooCommerce/Sales/{SalesOrderLines,SalesOrderLine}.php` og i frontend `api/{products,orders}.ts`, `hooks/usePagedApi.ts`, `components/{Table,Pagination}.tsx` og `sections/{products,orders}/`.

## Database/data used
`merchboard_creators`, `merchboard_creatormeta` (profilfelter: `bio`, `social_links`, `avatar_id`) og `merchboard_settings` (slug og branding).

## Permissions
- Route og alle endpoints: `CreatorContext::current()` ≠ null.
- `PATCH /me/profile`: whitelist `display_name`, `bio` og `social_links`. Nonce via `X-WP-Nonce`.

## API endpoints
| Metode | Route | Beskrivelse |
| --- | --- | --- |
| `GET` | `/merchboard/v1/me` | Den aktuelle creator: `display_name`, `status` |
| `GET` | `/merchboard/v1/me/profile` | Profilen |
| `PATCH` | `/merchboard/v1/me/profile` | Opdater whitelistede profilfelter |
| `GET` | `/merchboard/v1/me/products` | Creator'ens produkter, pagineret (fase 19, kun med WooCommerce) |
| `GET` | `/merchboard/v1/me/orders` | Creator'ens ordrelinjer, pagineret, uden kundedata og beløb (fase 19, kun med WooCommerce) |

## Hooks
`merchboard_dashboard_sections` (filter) og `merchboard_creator_updated` (action ved profilændring).

## Dependencies
Access (CreatorContext), Creators (repository/service), Core (Settings, Assets) og `@wordpress/element`/`api-fetch`/`i18n` (leveret af WordPress).

## Tests
- ✅ Integration (fase 12): route-adgang (logget ud → login-redirect, ikke-creator → 403, inaktiv → 403, creator → 200, deaktivering/frakobling/rolle fjernet virker straks, slettet bruger, databasefejl), rewrite rules og deep links, slug-ændring. Planlagt: `/me` og `/me/profile` (alle security-cases i [API.md](../API.md#test-krav)).
- ✅ JS unit (fase 13): bootstrap-validering, router, API-klient, `useApi`, `ApiErrorState`, komponenter og app (se [TESTING.md](../TESTING.md#frontend-tests-fase-13)). Senere: formularvalidering (fase 14).
- ✅ Fase 19: REST-integrationstests for `/me/products` og `/me/orders` (adgang, IDOR, paginering), JS-tests for liste-fundament, Products og Orders, og runtime-verifikation i Merch Dashboard Local (se [TESTING.md](../TESTING.md#products-og-orders-fase-19)).
- Manuel: mobil (375px), tablet og desktop samt tastaturnavigation.

## Known limitations
- Ingen offline-understøttelse.
- Ingen dark mode i v1 (tokens forberedt).

## Future improvements
Avatar-upload, notifikationscenter, dark mode og PWA-manifest.
