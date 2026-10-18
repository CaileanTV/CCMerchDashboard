# Feature: Alerts (integration af MerchAlert)

> Opdateres, når featuren ændres. Beslutninger: [ADR-065](../DECISIONS.md), [ADR-066](../DECISIONS.md) (Twitch) og [ADR-067](../DECISIONS.md) (Google Fonts). Plan: [ROADMAP.md](../ROADMAP.md), fase 25.

## Purpose
Når en kunde køber en creators merch, skal creatoren kunne vise det live på sin stream: en alert i OBS-overlayet med billede, tekst og lyd og eventuelt en besked i Twitch-chatten. Funktionen kom fra projektejerens ældre plugin MerchAlert og samles nu i Merch Dashboard, så creators kun har ét login og én kategori-mapping.

## Current status
I gang (fase 25). Trin 25.2 (2026-10-19, committet): alerts registreres ved betalt ordre bag kill switch'en Settings → Alerts (slået fra som standard) og MerchAlert-vagten. Overlay, dashboard-sektion, lyde, historik-UI, chatbot, admin-ekstra og import mangler.

## User-facing functionality
Creatoren får sektionen **"Alerts"** i creator-dashboardet med undersiderne:

- **Overlay:** browser source-URL til OBS (kopiér, åbn), nyt overlay-ID (den gamle URL holder op med at virke med det samme), testalert med et tilfældigt produkt fra creatorens kategori, og indstillinger for tekst (skabelon, sekundær skabelon, font, størrelse, vægt, farve, skygge, justering), boks (billedstørrelse, baggrund, gennemsigtighed, hjørner, luft) og timing (animation og visningstid).
- **Designer:** 1920×1080-lærred, hvor billede, hovedtekst og sekundær tekst trækkes og skaleres, med egenskabspanel, snap-to-grid og opdatering af preview. Fonte vælges blandt dem, administratoren har godkendt.
- **Lyde:** alert-lyd til og fra, standardlyd (upload, erstat, slet og afspil; MP3, WAV eller OGG inden for administratorens grænser) og lyd pr. kategori, kun for creatorens egne kategorier.
- **Historik:** ordrer med alerts, 10 pr. side, med detaljer pr. ordre, "Anonymisér kundenavne" (huskes i browseren) og gensend af en enkelt alert eller hele ordren (gensender også chatbeskeden).
- **Chatbot:** vejledning (`/mod` og `/vip` til botten), slå beskeder til og fra, beskedskabelon, preview og testbesked i creatorens kanal. Creatoren kan ikke selv vælge kanal.

Skabelonvariabler i overlayet: `$FirstName`, `$OrderNumber`, `$Product`, `$Quantity`, `$Category`, `$Variation` og `$UserName`. I chatbeskeder: `$UserName`, `$Name`, `$Product` og `$Amount`.

Kunden kan i checkout angive et valgfrit brugernavn, som kan vises i alerts (`$UserName`).

## Admin functionality
Undermenuen **"Alerts"** under Merch Dashboard (capability `merchboard_manage_alerts`):

- **Ordrelog:** statuskort og ordrer med alerts, filtreret på i dag, seneste 24 timer eller alle.
- **Globalt overlay:** et overlay uafhængigt af creators med URL, testalert, replay af seneste ordre, designer og lyd.
- **Chatbot:** Twitch Client ID og Secret, forbind og afbryd, kanal pr. creator, status, diagnostik og testbesked.
- **Fontbibliotek:** synkronisering af Google Fonts-kataloget og godkendelse af fonte.
- **Import:** engangsimport fra MerchAlert med tørkørsel og bekræftelse.

Settings får sektionen **"Alerts"**: slå alerts til og fra, poll-interval, grænser for lydfiler (størrelse og længde) og brugernavnsfeltet i checkout (til/fra og label).

## Technical implementation
- **Modul:** `src/Modules/Alerts/` (`AlertsModule`), registreret sammen med WooCommerce-modulet.
- **Routing:** `woocommerce_order_status_processing`/`_completed` → creatoren pr. ordrelinje via `ProductCreatorLink::creator_id()` (samme tilskrivning som sales ledgeren; kategori-mappingen sætter linket) → én række i `merchboard_alert_events` pr. ordrelinje og overlay.
- **Overlay-side:** offentlig, standalone HTML på et hemmeligt overlay-ID; long-poll med short-poll som fallback. Legacy-routes `/ccmerch-alert/creator-overlay/{id}/` og `/ccmerch-alert/overlay/{id}/` bevarer migrerede OBS-URL'er (ADR-065 pkt. 5).
- **Dashboard:** React-sektion registreret via `merchboard_dashboard_sections` og `assets/src/dashboard/app/pages.tsx`; REST under `merchboard/v1/me/alerts`.
- **Chatbot:** Twitch Helix via `wp_remote_*`, afsendt af Action Scheduler-jobs (ADR-066).
- **Vagter:** kill switch (`alerts_enabled`) og ingen alerts, mens MerchAlert er aktivt.

## Relevant files
- ✅ `src/Modules/Alerts/`: `AlertsModule`, `AlertsAvailability` (kill switch og MerchAlert-vagt), `AlertHooks`, `AlertRouter`, `PaidOrderReader`/`WooCommercePaidOrderReader`, `PaidOrder`/`PaidOrderLine`, `AlertEvent`/`AlertEventRepository`, `AlertSettings`/`AlertSettingsException` og `Admin/AlertsSettingsSection`/`AlertsSettingsActions`.
- ✅ `src/Core/Database/Migrations/CreateAlertEventsTable.php` (migration 18).
- ✅ Tests: `tests/Unit/Modules/Alerts/`, `tests/Integration/Alerts/` og `tests/Integration/Database/MigrationTest.php`.
- Planlagt: `assets/src/dashboard/sections/alerts/…` og et separat overlay-script.

## Database/data used
Planlagt (migrationer fra 18; detaljer i DATABASE.md ved implementeringen):

| Data | Indhold |
| --- | --- |
| `merchboard_alert_events` ✅ | Schema-version 18: én række pr. ordrelinje og creator (`UNIQUE (order_item_id, creator_id)`); historikken (kundens fornavn og brugernavn) |
| `merchboard_alert_overlays` | Planlagt (25.3/25.4): overlay pr. creator, `overlay_id`, indstillinger (JSON) |
| `merchboard_alert_chatbot` | Twitch-kanal, til/fra og skabelon pr. creator |
| `merchboard_alert_chat_messages` | Log over sendte chatbeskeder; hindrer dobbelte beskeder |
| `merchboard_alert_fonts` | Google Fonts-katalog med godkendelse |
| `merchboard_settings` | `alerts_enabled` ✅ (default `false`); planlagt: poll-interval, lydgrænser, checkout-brugernavn, globalt overlay |
| Options uden autoload | Krypterede Twitch-credentials og -tokens, Google API-nøgle |
| Ordre-meta | `_merchboard_alert_username` (fallback: `_ccmerch_username`) |
| Vedhæftnings-meta | Ejer af uploadede lyde |

## Permissions
- Creator: `merchboard_access_dashboard`; creatoren findes via `CreatorContext` og har kun adgang til egne data.
- Admin: `merchboard_manage_alerts` (ny), nonce pr. handling og Post/Redirect/Get.
- Overlay-siden: hemmeligt overlay-ID, `hash_equals` og rate-limit.

## API endpoints
Planlagt under `merchboard/v1/me/alerts`: overlay og indstillinger, nyt overlay-ID, testalert, preview-data, historik og detaljer, gensend, lyd (upload og sletning) og chatbot (indstillinger, preview og testbesked). Dokumenteres i API.md ved implementeringen.

## Hooks
Lytter på `woocommerce_order_status_processing` og `woocommerce_order_status_completed`. Domæne-events og jobnavne følger `merchboard_alerts_<…>` (MODULES.md).

## Dependencies
WooCommerce-modulet (`ProductCreatorLink`, kategori-mapping, `JobQueue`/Action Scheduler), Dashboard-modulet (sektioner, CSP) og eksternt Twitch (ADR-066) og Google Fonts (ADR-067).

## Tests
Planlagt: unit-tests for routing, skabeloner, validering af CSS-værdier, kryptering og import-mapping; integrationstests for migrationer, duplikatvagt, REST-adgang (kun egen creator), overlay-ID og import; Jest for sektionen og API-validators.

## Known limitations
- Personoplysninger (kundens fornavn og brugernavn) gemmes i alert-historikken og kan vises i overlay og Twitch-chat; retention er ikke besluttet.
- Google Fonts sender brugerens IP-adresse til Google (GDPR-forbehold, ADR-067).
- Twitch-tokens migreres ikke; botten skal forbindes igen efter importen.

## Future improvements
Kanal-autorisation pr. creator, selvhostede fonte og en retention-politik for alert-data.
