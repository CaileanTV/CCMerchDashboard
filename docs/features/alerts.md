# Feature: Alerts (integration af MerchAlert)

> Opdateres, når featuren ændres. Beslutninger: [ADR-065](../DECISIONS.md), [ADR-066](../DECISIONS.md) (Twitch) og [ADR-067](../DECISIONS.md) (Google Fonts). Plan: [ROADMAP.md](../ROADMAP.md), fase 25.

## Purpose
Når en kunde køber en creators merch, skal creatoren kunne vise det live på sin stream: en alert i OBS-overlayet med billede, tekst og lyd og eventuelt en besked i Twitch-chatten. Funktionen kom fra projektejerens ældre plugin MerchAlert og samles nu i Merch Dashboard, så creators kun har ét login og én kategori-mapping.

## Current status
I gang (fase 25). Trin 25.2 (2026-10-19, committet): alerts registreres ved betalt ordre bag kill switch'en Settings → Alerts (slået fra som standard) og MerchAlert-vagten. Trin 25.3 (2026-10-19, committet): den offentlige overlay-side for OBS med feed (ny og legacy URL); overlays oprettes fra dashboardet. Trin 25.4 (2026-10-19, committet): sektionen "Alerts" i creator-dashboardet med opsætning, OBS-URL (kopiér, åbn, ny URL), testalert og indstillingsformularen. Trin 25.5 (2026-10-19, committet): designeren, fontvælgeren og admin-undermenuen Merch Dashboard → Alerts med fanen "Fonts" (Google Fonts). Trin 25.6 (2026-10-19, committet): lyde. Trin 25.6.1–25.6.3 (2026-10-19): kort side om side, maskeret OBS-URL og lydbibliotek fra admin. Historik-UI, chatbot, admin-ekstra og import mangler.

## User-facing functionality
Creatoren får menupunktet **"Alerts"** i creator-dashboardet, som åbner og lukker (25.6.4), med siderne "Overlay-indstillinger" (`alerts/`) og "Historik" (`alerts/history/`, fra 25.7). Indholdet:

- **Overlay:** browser source-URL til OBS (maskeret, indtil creatoren trykker "Show URL"; kopiér og åbn virker uden at vise den, 25.6.2), nyt overlay-ID (den gamle URL holder op med at virke med det samme), testalert med et tilfældigt produkt fra creatorens kategori, og indstillinger for tekst (skabelon, sekundær skabelon, font, størrelse, vægt, farve, skygge, justering), boks (billedstørrelse, baggrund, gennemsigtighed, hjørner, luft) og timing (animation og visningstid).
- **Tekstanimationer ✅ (25.6.9):** ind-animation, effekt mens teksten vises og ud-animation for hoved- og sekundærteksten hver for sig, med farve og hastighed; et eksempel i kortet, der afspiller valget i loop, og "Afspil animation" i designeren (viser også ikke-gemte valg).
- **Tekst rundt om billedet ✅ (25.6.11):** hovedteksten kan løbe langs en ramme om produktbilledet og glide rundt (med eller mod uret, stille, langsom, normal eller hurtig), gentaget hele vejen rundt; virker med animationer og tekstfyld.
- **Tekst med baggrund ✅ (25.6.10):** bogstaverne fyldes med en gradient eller et mønster (8 færdige), creatorens eget billede eller et billede fra administratorens bibliotek, stille, glidende eller faldende; virker sammen med animationerne. Kortet "Billeder til tekst" lige under, når en tekst fyldes med et billede (højst 10 egne billeder, PNG/JPG/GIF/WebP, højst 5 MB og 4096 px).
- **Tekststørrelse ✅ (25.6.5):** fast størrelse, formindsk teksten så den passer i boksen, eller lad boksen vokse med teksten (nær bunden opad, og billedet følger med).
- **Flere lag og justering ✅ (25.6.6):** vælg flere bokse og flyt dem sammen; justér til venstre, højre, top, bund eller centrér, i forhold til lærredet eller de valgte elementer; fortryd og gentag med Ctrl+Z og Ctrl+Shift+Z. Boksen til sekundær tekst vises kun, når den er slået til.
- **Designer ✅ (25.5):** 1920×1080-lærred, hvor billede, hovedtekst og sekundær tekst trækkes og skaleres (fra nederste højre hjørne), med egenskabspanel (position, størrelse, synlighed, billedets hjørneradius og bevaret billedformat), snap-to-grid og en forhåndsvisning med eksempelværdier, der følger formularens gemte udseende. Fonte vælges i formularen blandt dem, administratoren har godkendt; er den valgte font fjernet, får creatoren en advarsel, og overlayet viser standardfonten.
- **Lyde ✅ (25.6):** alert-lyd til og fra, standardlyd (upload, erstat, slet og afspil; MP3, WAV eller OGG, højst 10 MB og 30 sekunder; administratoren kan ændre grænserne fra 25.9) og lyd pr. kategori, kun for creatorens egne kategorier. En underkategori afspiller overkategoriens lyd. Den første lyd slår lyde til. I stedet for at uploade kan creatoren vælge en lyd fra administratorens lydbibliotek (25.6.3); bibliotekslydene har de navne, administratoren giver dem (25.6.7). Med "Vis alerten så længe lyden spiller" (25.6.8) varer en alert med lyd præcis så længe lyden. OBS afspiller lyden automatisk uden klik, også efter genstart; lyden går til browser source'ens lyd i mixeren (lydmonitorering skal være slået til for selv at høre den). En almindelig browserfane kan blokere lyd, medmindre autoplay er tilladt for sitet i browserens indstillinger.
- **Historik ✅ (25.7):** ordrer med alerts, 10 pr. side, med detaljer pr. ordre, "Anonymisér brugernavne" (gemmes for den indloggede bruger, ikke i browseren: dashboardet bruger ikke browser-storage) og gensend af en enkelt alert eller hele ordren (chatbeskeden gensendes fra 25.8); "Opdatér" øverst til højre, der tæller ned til den automatiske opdatering hvert minut. **"Stop alle alerts"** fjerner straks alerten på streamen (også lyden) og tømmer køen; nye køb vises som normalt.
- **Chatbot:** vejledning (`/mod` og `/vip` til botten), slå beskeder til og fra, beskedskabelon, preview og testbesked i creatorens kanal. Creatoren kan ikke selv vælge kanal.

Skabelonvariabler i overlayet: `$FirstName`, `$OrderNumber`, `$Product`, `$Quantity`, `$Category`, `$Variation` og `$UserName`. I chatbeskeder: `$UserName`, `$Name`, `$Product` og `$Amount`. **Ingen kundenavne (projektejerens beslutning 2026-10-19):** `$FirstName`, `$Name` og `$UserName` giver alle kundens frivillige brugernavn eller "Anonymous", så skabeloner fra MerchAlert virker uændret. Variablerne erstattes i ét gennemløb, så et produktnavn med fx `$Category` ikke udvides igen.

Kunden kan i checkout angive et valgfrit brugernavn, som kan vises i alerts (`$UserName`).

## Admin functionality
Undermenuen **"Alerts"** under Merch Dashboard (capability `merchboard_manage_alerts`):

- **Ordrelog:** statuskort og ordrer med alerts, filtreret på i dag, seneste 24 timer eller alle.
- **Globalt overlay:** et overlay uafhængigt af creators med URL, testalert, replay af seneste ordre, designer og lyd.
- **Chatbot:** Twitch Client ID og Secret, forbind og afbryd, kanal pr. creator, status, diagnostik og testbesked.
- **Sounds ✅ (25.6.3):** lydbiblioteket: upload og fjern lyde (højst 50), som creators kan vælge imellem.
- **Images ✅ (25.6.10):** billedbiblioteket til tekstfyld: upload, navngiv, omdøb og fjern billeder (højst 50), som creators kan vælge imellem; auditeret.
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
- ✅ `src/Modules/Alerts/Sounds/` (`AlertSounds`, `AlertSound`, `AlertSoundException`, `SoundFiles`, `AlertSoundLibrary`), `assets/src/dashboard/sections/alerts/AlertSoundsCard.tsx` og `tests/Integration/Alerts/AlertSoundsTest.php`.
- ✅ `src/Modules/Alerts/Images/` (`AlertImages`, `AlertImage`, `AlertImageException`, `ImageFiles`, `AlertImageLibrary`), `assets/src/overlay/textfill.ts`/`.css`, `assets/src/dashboard/sections/alerts/AlertImagesCard.tsx` og `tests/Integration/Alerts/AlertImagesTest.php` (trin 25.6.10).
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
| Vedhæftnings-meta ✅ (25.6) | `_merchboard_alert_sound_creator`: ejeren af en uploadet lyd; `_merchboard_alert_sound_library` (25.6.3): en lyd i administratorens bibliotek; `_merchboard_alert_image_creator` og `_merchboard_alert_image_library` (25.6.10): ejeren af et billede til tekstfyld eller et billede i administratorens billedbibliotek |

## Permissions
- Creator: `merchboard_access_dashboard`; creatoren findes via `CreatorContext` og har kun adgang til egne data.
- Admin: `merchboard_manage_alerts` (ny), nonce pr. handling og Post/Redirect/Get.
- Overlay-siden: hemmeligt overlay-ID, `hash_equals` og rate-limit.

## API endpoints
✅ Offentligt: `GET merchboard/v1/alerts/overlay/{overlay_id}/feed` (API.md, ADR-065 pkt. 4). ✅ Creator: `GET`/`POST`/`PATCH /me/alerts/overlay` (fra 25.5 med font og layout), `POST /me/alerts/overlay/regenerate`, `POST /me/alerts/overlay/test` og `POST`/`DELETE /me/alerts/overlay/sounds/{slot}` (fra 25.6) (API.md). Admin-fontbiblioteket bruger `admin-post.php`, ikke REST. Planlagt under `merchboard/v1/me/alerts`: preview-data, historik og detaljer, gensend og chatbot (indstillinger, preview og testbesked). Dokumenteres i API.md ved implementeringen.

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
- Lydfilerne bevares ved opt-in-afinstallation som almindelige medier.
- En almindelig browserfane kan blokere lyd (autoplay-politik), medmindre autoplay er tilladt for sitet; OBS afspiller altid uden klik. Overlay-siden har ingen knap til at slå lyd til (projektejerens beslutning 2026-10-19).
- Grænserne for lydfiler (10 MB, 30 sekunder) er faste indtil 25.9.
- Designeren gemmer hele layoutet på én gang; ændringer i to faner samtidig overskriver hinanden (den sidste gemning vinder).
- Twitch-tokens migreres ikke; botten skal forbindes igen efter importen.

## Future improvements
Kanal-autorisation pr. creator, selvhostede fonte og en retention-politik for alert-data.
