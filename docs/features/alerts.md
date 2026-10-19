# Feature: Alerts (integration af MerchAlert)

> Opdateres, når featuren ændres. Beslutninger: [ADR-065](../DECISIONS.md), [ADR-066](../DECISIONS.md) (Twitch) og [ADR-067](../DECISIONS.md) (Google Fonts). Plan: [ROADMAP.md](../ROADMAP.md), fase 25.

## Purpose
Når en kunde køber en creators merch, skal creatoren kunne vise det live på sin stream: en alert i OBS-overlayet med billede, tekst og lyd og eventuelt en besked i Twitch-chatten. Funktionen kom fra projektejerens ældre plugin MerchAlert og samles nu i Merch Dashboard, så creators kun har ét login og én kategori-mapping.

## Current status
I gang (fase 25). Trin 25.2 (2026-10-19, committet): alerts registreres ved betalt ordre bag kill switch'en Settings → Alerts (slået fra som standard) og MerchAlert-vagten. Trin 25.3 (2026-10-19, committet): den offentlige overlay-side for OBS med feed (ny og legacy URL); overlays oprettes fra dashboardet. Trin 25.4 (2026-10-19, committet): sektionen "Alerts" i creator-dashboardet med opsætning, OBS-URL (kopiér, åbn, ny URL), testalert og indstillingsformularen. Trin 25.5 (2026-10-19): designeren, fontvælgeren og admin-undermenuen Merch Dashboard → Alerts med fanen "Fonts" (Google Fonts). Lyde, historik-UI, chatbot, admin-ekstra og import mangler.

## User-facing functionality
Creatoren får sektionen **"Alerts"** i creator-dashboardet med undersiderne:

- **Overlay:** browser source-URL til OBS (kopiér, åbn), nyt overlay-ID (den gamle URL holder op med at virke med det samme), testalert med et tilfældigt produkt fra creatorens kategori, og indstillinger for tekst (skabelon, sekundær skabelon, font, størrelse, vægt, farve, skygge, justering), boks (billedstørrelse, baggrund, gennemsigtighed, hjørner, luft) og timing (animation og visningstid).
- **Designer ✅ (25.5):** 1920×1080-lærred, hvor billede, hovedtekst og sekundær tekst trækkes og skaleres (fra nederste højre hjørne), med egenskabspanel (position, størrelse, synlighed, billedets hjørneradius og bevaret billedformat), snap-to-grid og en forhåndsvisning med eksempelværdier, der følger formularens gemte udseende. Fonte vælges i formularen blandt dem, administratoren har godkendt; er den valgte font fjernet, får creatoren en advarsel, og overlayet viser standardfonten.
- **Lyde:** alert-lyd til og fra, standardlyd (upload, erstat, slet og afspil; MP3, WAV eller OGG inden for administratorens grænser) og lyd pr. kategori, kun for creatorens egne kategorier.
- **Historik:** ordrer med alerts, 10 pr. side, med detaljer pr. ordre, "Anonymisér kundenavne" (huskes i browseren) og gensend af en enkelt alert eller hele ordren (gensender også chatbeskeden).
- **Chatbot:** vejledning (`/mod` og `/vip` til botten), slå beskeder til og fra, beskedskabelon, preview og testbesked i creatorens kanal. Creatoren kan ikke selv vælge kanal.

Skabelonvariabler i overlayet: `$FirstName`, `$OrderNumber`, `$Product`, `$Quantity`, `$Category`, `$Variation` og `$UserName`. I chatbeskeder: `$UserName`, `$Name`, `$Product` og `$Amount`. **Ingen kundenavne (projektejerens beslutning 2026-10-19):** `$FirstName`, `$Name` og `$UserName` giver alle kundens frivillige brugernavn eller "Anonymous", så skabeloner fra MerchAlert virker uændret. Variablerne erstattes i ét gennemløb, så et produktnavn med fx `$Category` ikke udvides igen.

Kunden kan i checkout angive et valgfrit brugernavn, som kan vises i alerts (`$UserName`).

## Admin functionality
Undermenuen **"Alerts"** under Merch Dashboard (capability `merchboard_manage_alerts`):

- **Ordrelog:** statuskort og ordrer med alerts, filtreret på i dag, seneste 24 timer eller alle.
- **Globalt overlay:** et overlay uafhængigt af creators med URL, testalert, replay af seneste ordre, designer og lyd.
- **Chatbot:** Twitch Client ID og Secret, forbind og afbryd, kanal pr. creator, status, diagnostik og testbesked.
- **Fonts ✅ (25.5):** Google Fonts API-nøgle (gem eller fjern; vises aldrig igen), hentning af kataloget (gemmes i 30 dage), søgning (højst 50 resultater, populæreste først), godkendelse og fjernelse af fonte (højst 100 godkendte).
- **Import:** engangsimport fra MerchAlert med tørkørsel og bekræftelse.

Settings får sektionen **"Alerts"**: slå alerts til og fra, poll-interval, grænser for lydfiler (størrelse og længde) og brugernavnsfeltet i checkout (til/fra og label).

## Technical implementation
- **Modul:** `src/Modules/Alerts/` (`AlertsModule`), registreret sammen med WooCommerce-modulet.
- **Routing:** `woocommerce_order_status_processing`/`_completed` → creatoren pr. ordrelinje via `ProductCreatorLink::creator_id()` (samme tilskrivning som sales ledgeren; kategori-mappingen sætter linket) → én række i `merchboard_alert_events` pr. ordrelinje og overlay.
- **Overlay-side ✅ (25.3):** `OverlayRoute` (`/merchboard-alerts/overlay/{id}/`, legacy `/ccmerch-alert/creator-overlay/{id}/`, kun når MerchAlert ikke er aktivt; uden pretty permalinks `?merchboard_alert_overlay={id}`) → `OverlayPage` (standalone HTML i `templates/alerts/overlay.php`, egen CSP, `noindex`, `Referrer-Policy: no-referrer`) med `OverlaySettings` som validerede CSS-variabler og bokse og overlay-bundlet (`assets/src/overlay/`, `OverlayAssets`). Bundlet long-poller `GET merchboard/v1/alerts/overlay/{id}/feed` (`OverlayFeedController` → `OverlayFeed`: cursor på alert-id, op til 8 sekunders venten, `OverlayRateLimiter` 60 pr. minut) og viser alerts med MerchAlerts animation; teksterne bygges af `AlertTemplate`. Legacy-URL'en for det globale overlay (`/ccmerch-alert/overlay/{id}/`) kommer med det globale overlay i 25.9.
- **Dashboard:** React-sektion registreret via `merchboard_dashboard_sections` og `assets/src/dashboard/app/pages.tsx`; REST under `merchboard/v1/me/alerts`.
- **Fonte ✅ (25.5):** `AlertFonts` henter kataloget fra Google Fonts Developer API (`wp_remote_get`, kun på administratorens forespørgsel) og gemmer nøgle og godkendte fonte i options uden autoload og kataloget i en transient. `AlertFont` validerer familienavnet og bygger stylesheet-URL'en med familiens vægte. `AlertOverlays` gemmer kun en godkendt font eller `inherit`; `OverlayPage` indlæser kun en godkendt font (ellers standardfonten). CSP'en for dashboard-shellen og overlay-siden tillader Google Fonts (ADR-067).
- **Designer ✅ (25.5):** `OverlayDesigner.tsx` gemmer hele layoutet via `PATCH /me/alerts/overlay` (`layout`), som `OverlaySettings` normaliserer.
- **Chatbot:** Twitch Helix via `wp_remote_*`, afsendt af Action Scheduler-jobs (ADR-066).
- **Vagter:** kill switch (`alerts_enabled`) og ingen alerts, mens MerchAlert er aktivt.

## Relevant files
- ✅ `src/Modules/Alerts/`: `AlertsModule`, `AlertsAvailability` (kill switch og MerchAlert-vagt), `AlertHooks`, `AlertRouter`, `PaidOrderReader`/`WooCommercePaidOrderReader`, `PaidOrder`/`PaidOrderLine`, `AlertEvent`/`AlertEventRepository`, `AlertSettings`/`AlertSettingsException` og `Admin/AlertsSettingsSection`/`AlertsSettingsActions`.
- ✅ `src/Modules/Alerts/Overlay/`: `AlertOverlay`/`AlertOverlayRepository`, `OverlayId`, `OverlaySettings`, `AlertTemplate`, `OverlayFeed`, `OverlayFeedController`, `OverlayRateLimiter`, `OverlayRoute`, `OverlayPage` og `OverlayAssets`; `templates/alerts/overlay.php`; `assets/src/overlay/` (`feed.ts`, `player.ts`, `index.ts`, `overlay.css`).
- ✅ `src/Core/Database/Migrations/CreateAlertEventsTable.php` (migration 18) og `CreateAlertOverlaysTable.php` (migration 19).
- ✅ Tests: `tests/Unit/Modules/Alerts/`, `tests/Integration/Alerts/` og `tests/Integration/Database/MigrationTest.php`.
- ✅ `src/Modules/Alerts/Overlay/AlertOverlays.php` og `OverlayTestQueue.php`, `src/Modules/Alerts/Rest/MeAlertsController.php`, `src/Modules/Alerts/Dashboard/AlertsDashboardSection.php`; `assets/src/dashboard/sections/alerts/` (`AlertsSection.tsx`, `OverlaySettingsForm.tsx`), `api/alerts.ts`, `types/alerts.ts` og `test/alerts.test.tsx`.
- ✅ `src/Modules/Alerts/Fonts/` (`AlertFonts`, `AlertFont`, `AlertFontsException`), `src/Modules/Alerts/Admin/AlertsAdminPage.php` og `AlertsAdminActions.php`; `assets/src/dashboard/sections/alerts/` (`OverlayDesigner.tsx`, `designer.ts`, `useFontStylesheet.ts`) og `test/alerts-designer.test.ts`.

## Database/data used
Migrationer fra 18 (detaljer i DATABASE.md):

| Data | Indhold |
| --- | --- |
| `merchboard_alert_events` ✅ | Schema-version 18: én række pr. ordrelinje og creator (`UNIQUE (order_item_id, creator_id)`); historikken (kundens frivillige brugernavn; fornavnet blev fjernet af migration 19) |
| `merchboard_alert_overlays` ✅ | Schema-version 19: overlay pr. creator, hemmeligt `overlay_id` (32 hex), indstillinger (JSON) |
| `merchboard_alert_chatbot` | Twitch-kanal, til/fra og skabelon pr. creator |
| `merchboard_alert_chat_messages` | Log over sendte chatbeskeder; hindrer dobbelte beskeder |
| Fontbibliotek ✅ (25.5) | Options uden autoload `merchboard_alert_fonts_key` og `merchboard_alert_fonts_approved`, transient `merchboard_alert_fonts_catalog` (30 dage); erstatter den planlagte tabel `merchboard_alert_fonts` (ADR-065, statusnote) |
| `merchboard_settings` | `alerts_enabled` ✅ (default `false`); planlagt: poll-interval, lydgrænser, checkout-brugernavn, globalt overlay |
| Options uden autoload | Krypterede Twitch-credentials og -tokens (planlagt) |
| Ordre-meta | `_merchboard_alert_username` (fallback: `_ccmerch_username`) |
| Vedhæftnings-meta | Ejer af uploadede lyde |

## Permissions
- Creator: `merchboard_access_dashboard`; creatoren findes via `CreatorContext` og har kun adgang til egne data.
- Admin: `merchboard_manage_alerts` (ny), nonce pr. handling og Post/Redirect/Get.
- Overlay-siden: hemmeligt overlay-ID, `hash_equals` og rate-limit.

## API endpoints
✅ Offentligt: `GET merchboard/v1/alerts/overlay/{overlay_id}/feed` (API.md, ADR-065 pkt. 4). ✅ Creator: `GET`/`POST`/`PATCH /me/alerts/overlay` (fra 25.5 med font og layout), `POST /me/alerts/overlay/regenerate` og `POST /me/alerts/overlay/test` (API.md). Admin-fontbiblioteket bruger `admin-post.php`, ikke REST. Planlagt under `merchboard/v1/me/alerts`: preview-data, historik og detaljer, gensend, lyd (upload og sletning) og chatbot (indstillinger, preview og testbesked). Dokumenteres i API.md ved implementeringen.

## Hooks
Lytter på `woocommerce_order_status_processing` og `woocommerce_order_status_completed`. Domæne-events og jobnavne følger `merchboard_alerts_<…>` (MODULES.md).

## Dependencies
WooCommerce-modulet (`ProductCreatorLink`, kategori-mapping, `JobQueue`/Action Scheduler), Dashboard-modulet (sektioner, CSP) og eksternt Twitch (ADR-066) og Google Fonts (ADR-067).

## Tests
Planlagt: unit-tests for routing, skabeloner, validering af CSS-værdier, kryptering og import-mapping; integrationstests for migrationer, duplikatvagt, REST-adgang (kun egen creator), overlay-ID og import; Jest for sektionen og API-validators.

## Known limitations
- Kundens frivillige brugernavn gemmes i alert-historikken og vises i overlay og Twitch-chat; retention er ikke besluttet.
- Overlayet viser kun produktbilleder fra sitet selv (CSP); billeder på et eksternt CDN vises ikke.
- Feedets long-poll holder en PHP-proces i op til 8 sekunder pr. åbent overlay (som i MerchAlert).
- Google Fonts sender brugerens IP-adresse til Google (GDPR-forbehold, ADR-067).
- Designerens forhåndsvisning bruger eksempelværdier og formularens gemte udseende; ændringer i formularen vises i designeren, når de er gemt.
- Designeren gemmer hele layoutet på én gang; ændringer i to faner samtidig overskriver hinanden (den sidste gemning vinder).
- Twitch-tokens migreres ikke; botten skal forbindes igen efter importen.

## Future improvements
Kanal-autorisation pr. creator, selvhostede fonte og en retention-politik for alert-data.
