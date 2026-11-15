# Feature: Twitch Extension (panel med creatorens produkter)

## Purpose
Creatoren kan vise sine produkter i et panel under sin stream på Twitch. Det sker med shoppens egen Twitch Extension, som alle creators installerer ([ADR-112](../DECISIONS.md), ROADMAP fase 49).

## Current status
Bygget i fase 49 og runtime-testet i Playground 2026-11-15. Panelet er testet med en stand-in for Twitchs Extension Helper; selve Twitch er ikke testet endnu. Kommer med i 2.2.0. Extensionen skal oprettes og godkendes hos Twitch (trin 49.2 og 49.6), før creators kan bruge den.

## User-facing functionality
**Seeren på Twitch** ser panelet under streamen:
- **Indhold:** "Merch fra <creator>" og creatorens pille-tekst, hvis creatoren har skrevet en (fx "Skriv **!merch** i chatten", hvor kommandoen står med fed). Derunder op til 12 produkter med billede og navn i to kolonner.
- **Ikke med:** pris, link, knap eller køb. Twitchs regler 4.6.3 og "Commerce in Extensions" forbyder links til shops og køb i en extension.
- **Udseende:** Twitchs lyse og mørke tema og dansk eller engelsk efter Twitchs sprog.
- **Tomt panel:** er panelet slået fra, eller har creatoren ingen produkter, står der "Ingen produkter at vise endnu".

**Creatoren** (MitCCMerch → Community Features → Twitch Extension; siden findes kun, når admin har slået funktionen til):
- **Til/fra:** slår panelet til og fra (fra som standard).
- **Pillen:** skriver eventuelt en tekst til pillen, højst 60 tegn. Links og domæner afvises.
- **Produkter:** vælger op til 12 af sine produkter og deres rækkefølge (flyt op og ned). Vælges ingen, viser panelet de 12 nyeste udgivne produkter.
- **Forhåndsvisning:** ser panelet, som det vises efter gem.
- **Guide:**
  1. forbind Twitch-kanalen;
  2. installer extensionen (link fra admin);
  3. aktivér den som panel under Creator Dashboard → Extensions;
  4. læg et almindeligt Twitch-panel med link til shoppen.
- Twitch-forbindelsen er den samme som for de andre community features og alert-chatbotten.

## Admin functionality
Under Indstillinger → Community:
- **Global knap:** "Twitch Extension", fra som standard (ADR-108). Slået fra forsvinder siden fra dashboardet, og panelet viser intet.
- **Client ID** fra Twitchs udviklerkonsol.
- **Hemmeligheden** gemmes krypteret og vises aldrig igen. Et tomt felt beholder den gemte. Alternativt sættes konstanten `MERCHBOARD_TWITCH_EXTENSION_SECRET` i `wp-config.php`, og den har så forrang.
- **Installationslink:** extensionens side på Twitch, som vises i creatorernes guide.
- **Allowlist:** siden viser den URL, panelet læser fra. Sitets domæne skal på allowlisterne i udviklerkonsollen (URL fetching og billeddomæner).

Ændringer auditeres (`settings_updated` med `twitch_extension_client_id`, `twitch_extension_install_url` og `twitch_extension_secret`, aldrig selve hemmeligheden).

**Vilkår og privatlivspolitik** (Twitch kræver begge URL'er i udviklerkonsollen):
- **URL'erne:** pluginet viser selv siderne på engelsk og dansk:
  - `/?merchboard_extension=terms` (vilkår, EULA/terms of service);
  - `/?merchboard_extension=privacy` (privatlivspolitik).
  - Tilføj `&lang=da` for den danske udgave.
- **Indhold:** firmanavn, CVR, adresse og e-mail kommer fra Indstillinger → Firmaoplysninger. Teksten siger, at panelet kun viser produkter (ingen priser, links eller køb), at kun kanalens id læses fra Twitchs token, og at der ikke gemmes noget om seerne og ikke bruges cookies eller sporing.
- **Admin:** begge URL'er vises under Indstillinger → Community.
- **Gennemlæsning:** teksten er ikke juridisk rådgivning; projektejeren læser den igennem, før extensionen indsendes.

## Technical implementation
- **Kontrol af JWT** (`ExtensionJwt`): HS256 med extensionens hemmelighed (base64-dekodet). Tokenet skal have `exp` i fremtiden og et numerisk `channel_id`. Kanalen tages kun fra tokenet.
- **Panelets indhold** (`TwitchExtensionPanel`): kanalen kobles til creatoren via den forbundne Twitch-kanal (`ChatbotChannelRepository::find_by_broadcaster()`). Svaret caches i en transient i 5 minutter pr. creator, og creatorens gem rydder cachen.
  - Produkterne er udgivne, synlige forælderprodukter (`CommunityModule::panel_product()`): navn i ren tekst og WooCommerce-billedet `woocommerce_thumbnail`.
  - De nyeste produkter kommer fra `CreatorProducts::newest()`.
- **Endpoint** (`TwitchExtensionController`): svaret sendes med `Cache-Control: no-store` og `Vary: Origin, Authorization`. URL'en er den samme for alle kanaler, så browseren må aldrig genbruge et svar til en anden kanal. CORS klarer WordPress' REST-API (Origin ekkoes, `Authorization` er tilladt).
- **Creatorens indstillinger** (`TwitchExtensionRepository`, `TwitchExtensionSettings`): creator-meta `twitch_extension`. Kun egne produkter kan vælges, højst 12. Pille-teksten renses med `sanitize_text_field` og afvises, hvis den er over 60 tegn eller indeholder en URL eller et domæne.
- **Admin** (`TwitchExtensionApp`): optionen `merchboard_twitch_extension` (ikke autoload) med `client_id`, `secret` (krypteret med `ChatCipher('twitch-extension')`) og `install_url` (kun `https://dashboard.twitch.tv/…` eller `https://www.twitch.tv/…`).
- **Panelet** (`assets/src/twitch-extension/`): vanilla TypeScript og CSS. Twitchs Extension Helper indlæses først i `panel.html`. Panelet henter data én gang pr. kanal ved `onAuthorized` (`credentials: 'omit'`), følger `onContext`-temaet og bygger alt med `textContent`, aldrig HTML.
- **ZIP til Twitch:** `npm run twitch-extension -- --site https://<shop>` bygger panelet og laver `dist/merchboard-twitch-extension-<version>-<host>.zip` med `panel.html`, `panel.js` og `panel.css`. Sitets REST-URL står i `panel.html`. Brug `--api <url>` for sites uden `/wp-json/`. Hver site (live, stg) får sin egen ZIP, og hemmeligheden er aldrig i ZIP'en.

## Relevant files
- `src/Modules/Community/TwitchExtension/` (`ExtensionJwt`, `TwitchExtensionApp`, `TwitchExtensionSettings`, `TwitchExtensionRepository`, `TwitchExtensionPanel`, `TwitchExtensionLegal`), `templates/community/extension-legal.php`
- `src/Modules/Community/Rest/TwitchExtensionController.php`, `MeTwitchExtensionController.php`
- `src/Modules/Community/Admin/CommunitySettingsSection.php`, `CommunityModule.php` (`register_extension()`, `panel_product()`), `src/Modules/WooCommerce/Dashboard/CreatorProducts.php` (`newest()`)
- `assets/src/twitch-extension/` (`panel.ts`, `panel.css`, `panel.html`), `bin/twitch-extension.mjs`
- `assets/src/dashboard/api/twitchExtension.ts`, `sections/community/TwitchExtensionSection.tsx`
- `tests/Integration/Community/TwitchExtensionTest.php`, `tests/Unit/Modules/Community/ExtensionJwtTest.php`, `assets/src/twitch-extension/test/panel.test.ts`, `assets/src/dashboard/test/twitch-extension.test.tsx`

## Database/data used
- Ingen schemaændring.
- Creator-meta `twitch_extension` (`enabled`, `product_ids`, `note`).
- Optionen `merchboard_twitch_extension`.
- Indstillingen `community_twitch_extension_enabled`.
- Transienten `merchboard_twitch_ext_<creator>` (5 minutter).

## Permissions
- `GET /twitch-extension/panel` er offentlig, men kræver extensionens JWT. Svaret indeholder kun offentlige data: creatorens navn, pille-teksten og udgivne produkters navn og billede.
- `/me`-ruten bruger `CreatorContext` (ADR-007).
- Admin-felterne kræver `merchboard_manage_settings`.

## API endpoints
- `GET /merchboard/v1/twitch-extension/panel` med `Authorization: Bearer <JWT>`:
  - 200 `{ enabled, creator, note, products: [{ id, name, image }] }`;
  - 401 `merchboard_twitch_extension_token`;
  - 503 `merchboard_twitch_extension_unavailable`, før extensionen er sat op.
- `GET`/`POST /merchboard/v1/me/community/twitch-extension`:
  - svar: `enabled`, `product_ids`, `note`, `max_products`, `max_note`, `preview`, `channel`, `twitch`, `ready`, `install_url`;
  - 400 `merchboard_invalid_twitch_extension` med feltkoderne `required`, `invalid`, `too_many`, `not_owned`, `too_long` og `no_links`;
  - 404 `merchboard_community_disabled`, når funktionen er slået fra for shoppen.

## Hooks
Ingen nye. Twitch-forbindelsen sender creatoren tilbage til `community/twitch-extension` (`TwitchOAuth::RETURN_PAGES`).

## Dependencies
- Twitch (ADR-066, ADR-112).
- Alerts-modulet: creatorens forbundne kanal.
- WooCommerce: produkter og billeder.

## Tests
- **Integration:**
  - creatorens valg, validering og audit;
  - panelets indhold (nyeste, valgte, ikke udgivne, ukendt kanal, slået fra, cachen ryddes);
  - endpointet med gyldigt, forkert, udløbet og usigneret token;
  - admin-opsætningen (krypteret hemmelighed, aldrig i audit);
  - `/me`-ruten.
- **Unit:** JWT-kontrollen.
- **Jest:**
  - panelet: sprog, tolkning, tekst i stedet for HTML, pille, tema og ét kald pr. kanal;
  - siden: parser, valg og rækkefølge, pille, guide og feltfejl.
- **Runtime i Playground (2026-11-15):** endpointet inkl. CORS fra `*.ext-twitch.tv`, panelet i lyst og mørkt tema og på dansk og engelsk, admin-felterne og MitCCMerch-siden.

## Known limitations
- Kun panel. Ingen komponent over videoen og ingen mobil-specifik visning.
- Billederne er WooCommerce-produktbilledet (med baggrund).
- Extensionen skal godkendes af Twitch (8–14 dage). Hver ny version af panelet kræver en ny ZIP og en ny gennemgang.
- Twitch kan stadig afvise den. Et afslag er endeligt for den extension.

## Future improvements
- Komponent over videoen, hvis projektejeren ønsker det.
- Billeder uden baggrund, når fase 50 har afklaret, hvor de kommer fra.
