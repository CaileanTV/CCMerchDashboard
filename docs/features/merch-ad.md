# Feature: Merch-reklame i streamen og !merch

## Purpose
Creatoren kan vise sine produkter i streamen med et reklame-overlay, der kommer frem med få minutters mellemrum. Botten svarer på `!merch` i chatten med et link til creatorens kollektion ([ADR-113](../DECISIONS.md), ROADMAP fase 50).

## Current status
Bygget i fase 50 og runtime-testet i Playground 2026-11-15:
- alle fem designs;
- den automatiske fritlægning i browseren;
- forhåndsvisningen i dashboardet;
- `!merch`-kortet.

Kommer med i 2.2.0. `!merch` på rigtige Twitch- og Kick-kanaler testes på stg.

## User-facing functionality
**Creatoren: Merch-reklame** (MitCCMerch → Community Features → Merch-reklame; siden findes kun, når admin har slået den til):
- **Til/fra:** reklamen slås til og fra (fra som standard).
- **Design:**
  - Spotlight: ét stort produkt ad gangen med navn og pille.
  - Hylde: op til fire produkter på en glasbjælke.
  - Karrusel: en stribe, hvor produkterne glider forbi.
  - Kort i hjørnet: et lille kort med produkt, navn og pille.
  - Tre i front: tre produkter, de yderste på skrå i 3D, med pillen under.
- **Pillen:** valgfri tekst, fx "Skriv !merch i chatten". Højst 60 tegn, kommandoer med `!` vises med fed, links og domæner afvises.
- **Timing:** hvor ofte reklamen vises (hvert 1.–60. minut) og hvor længe (5–60 sekunder).
- **Udseende:** animation ind og ud (fade, glid, pop, zoom, sløring), accentfarve og lyst eller mørkt kort.
- **Produkter:** op til 12 af creatorens produkter i valgt rækkefølge, ellers de nyeste udgivne.
- **Billeder uden baggrund:**
  - Når creatoren gemmer, fjerner browseren automatisk en ensfarvet baggrund fra hvert produktbillede.
  - Lykkes det ikke, siger siden det, og creatoren kan uploade sin egen PNG uden baggrund.
  - "Brug shoppens billede" fjerner det fritlagte billede igen; så vises shoppens billede i en flise.
- **I OBS:** linket til en browser source (fx 1920 × 1080) med "Kopiér", "Vis nu" (reklamen vises inden for 10 sekunder) og "Nyt link" (det gamle holder op med at virke).
- **Forhåndsvisning:** den gemte reklame vises i en ramme igen og igen på en stream-lignende baggrund, også før reklamen er slået til.

**Creatoren: `!merch`** (Alerts → Chatbot, kortet "Kommandoen !merch"):
- **Til/fra:** kommandoen slås til og fra (fra som standard).
- **Svaret:** højst 200 tegn med `$Link`. Uden `$Link` kommer linket til sidst, tomt giver standardteksten "Find min merch her: $Link", og svaret må ikke starte med `!merch`.
- **Pause:** højst ét svar pr. 10–300 sekunder pr. kanal (standard 30).
- **Visning:** kortet viser, hvad chatten ser, og hvilke forbundne kanaler (Twitch, Kick) kommandoen virker i.

**Seerne:**
- ser reklamen i streamen;
- kan skrive `!merch` (også fx "!merch tak", store eller små bogstaver) og få linket til creatorens kollektion: creatorens kategoriside i shoppen, ellers shoppens forside.

## Admin functionality
- **Global knap:** "Merch-reklame" under Indstillinger → Community, fra som standard (ADR-108). Slået fra forsvinder siden, overlayet viser intet, og statuskaldet svarer 404.
- **Kommandoen:** `!merch` hører til chatbotten og har ingen egen global knap.
- **Audit:** `merch_ad_changed` og `merch_command_changed` med feltnavne.

## Technical implementation
- **Indstillinger og lager:**
  - `MerchAdSettings` og `MerchAdRepository`: creator-meta `merch_ad`. Overlay-id'et (32 hex) laves ved første gem og kan fornyes. "Vis nu" og de fritlagte billeder gemmes uden audit, og `creator_for()` finder creatoren ud fra id'et.
  - `MerchAdFiles`: PNG'er i `uploads/merchboard-merch-ad/<creator>/p<produkt>-<12 hex>.png`. Filen kontrolleres med `getimagesizefromstring` (PNG, 16–2000 px, højst 4 MB), og serveren navngiver den.
  - `MerchAdView`: produkterne (navn, shoppens billede `woocommerce_single`, det fritlagte billede), det, overlayet tegner, og en version, der skifter ved enhver ændring.
- **Overlayet:**
  - `MerchAdPage`: siden `?merchboard_merch_ad=<id>` uden tema, med streng CSP (`frame-ancestors 'self'` til dashboardets ramme). Den bruger build-filerne `build/merch-ad.js` og `.css` (`assets/src/merch-ad/`), og `&preview=1` er forhåndsvisningen.
  - `MerchAdStatusController`: det offentlige `GET /merchboard/v1/merch-ad/{id}` → `{ version, show_at }`, rate-limited (60/minut pr. id, `OverlayRateLimiter`).
  - Overlayet (`assets/src/merch-ad/`) spørger hvert 10. sekund. Ved ny version genindlæses siden, og ved nyt `show_at` vises reklamen nu.
- **Fritlægning** (`assets/src/dashboard/sections/community/cutout.ts`):
  - **Baggrund:** baggrundsfarven er medianen af kanten. Er mindst 60 % af kanten inden for tolerancen (40), fyldes den fra kanterne og gøres gennemsigtig.
  - **Motivet:** kanten blødgøres, og billedet beskæres til motivet med 2 % luft.
  - **Andre domæner:** billeder fra et andet domæne kan ikke læses (canvas), og så foreslås upload.
- **`!merch`:**
  - `MerchCommandSettings`, `MerchCommandRepository` og `MerchCommand` lytter på `merchboard_twitch_chat_message` og `merchboard_kick_chat_message`.
  - Pausen er en transient pr. platform og creator.
  - Svaret sendes med `ChatSender::send_now()` på den platform, beskeden kom fra.
  - Kollektionslinket er `get_term_link()` for creatorens første kategori (`CreatorCategoryService`), ellers `wc_get_page_permalink( 'shop' )`.
- **Kick:** `KickController` udløser nu `merchboard_kick_chat_message`, kun én gang pr. besked (`ChatLogRepository::add()` siger, om den er ny).
- **Dashboardets CSP** har `frame-src 'self'` til forhåndsvisningen (SECURITY.md).

## Relevant files
- `src/Modules/Community/MerchAd/` (`MerchAdSettings`, `MerchAdRepository`, `MerchAdFiles`, `MerchAdView`, `MerchAdPage`, `MerchAdCreatorCleanup`)
- `src/Modules/Community/MerchCommand/` (`MerchCommandSettings`, `MerchCommandRepository`, `MerchCommand`)
- `src/Modules/Community/Rest/MeMerchAdController.php`, `MerchAdStatusController.php`, `MeMerchCommandController.php`
- `templates/community/merch-ad.php`, `assets/src/merch-ad/`
- `assets/src/dashboard/api/merchAd.ts`, `api/merchCommand.ts`, `sections/community/MerchAdSection.tsx`, `sections/community/cutout.ts`, `sections/alerts/MerchCommandCard.tsx`
- `tests/Integration/Community/MerchAdTest.php`, `assets/src/merch-ad/test/ad.test.ts`, `assets/src/dashboard/test/cutout.test.ts`, `assets/src/dashboard/test/merch-ad.test.tsx`

## Database/data used
- Ingen schemaændring.
- Creator-meta `merch_ad` og `merch_command`.
- Filerne i `uploads/merchboard-merch-ad/`. De slettes med creatoren og ved opt-in-afinstallation.
- Indstillingen `community_merch_ad_enabled`.
- Transienter til pausen (`merchboard_merch_cd_<platform>_<creator>`) og rate limit (`merchboard_merchad_rl_…`).

## Permissions
- `/me`-ruterne bruger `CreatorContext` (ADR-007). Kun creatorens egne produkter kan vælges og få billeder.
- Overlay-siden og statuskaldet har det hemmelige id som eneste adgang og viser kun offentlige data (produktnavne og billeder), aldrig priser eller leverandører.

## API endpoints
- `GET`/`POST /merchboard/v1/me/community/merch-ad`, plus:
  - `POST …/regenerate`;
  - `POST …/show`;
  - `POST …/cutouts` med `{ product_id, png: "data:image/png;base64,…" }`;
  - `DELETE …/cutouts/{product}`.
  - Fejl: 400 `merchboard_invalid_merch_ad` (felterne `required`, `invalid`, `too_many`, `not_owned`, `too_long`, `no_links`, `not_png`, `too_large`, `size`); 404 `merchboard_community_disabled`.
- `GET /merchboard/v1/merch-ad/{id}`: offentlig, `{ version, show_at }`; 404 og 429.
- `GET`/`POST /merchboard/v1/me/alerts/chatbot/merch-command`:
  - svar: `enabled`, `text`, `cooldown`, `default_text`, `example`, `link`, `channels`, `limits`;
  - fejl: 400 `merchboard_invalid_merch_command` (felterne `required`, `invalid`, `too_long`, `is_command`).

## Hooks
- Nyt: `merchboard_kick_chat_message` (creator, chatterens id, slug, tekst).
- Lytter: `merchboard_twitch_chat_message` og `merchboard_kick_chat_message`.

## Dependencies
- Alerts-modulet: chatbotten og overlayets rate limiter.
- WooCommerce: produkter og billeder.
- Creatorens kategori (ADR-033).

## Tests
- **Integration:**
  - reklamen: validering, audit, OBS-id og fornyelse, "Vis nu", billederne (kontrol og fallback), versionen, creatorens ruter, statuskaldet (rate limit, 404);
  - `!merch`: validering, svaret med link, pause pr. platform;
  - Kick-actionen fyrer én gang;
  - afinstallation.
- **Jest:**
  - overlayet: tolkning, pille, visninger pr. design, timing og forhåndsvisning;
  - fritlægningen: baggrund, kant, beskæring, ikke-ensfarvet;
  - siden: gem, automatisk fritlægning, OBS-link, forhåndsvisning;
  - `!merch`-kortet.
- **Runtime i Playground (2026-11-15):** produkter med hvid baggrund blev fritlagt i browseren. Alle fem designs, lyst kort, statuskaldet, 404, CSP, forhåndsvisningen og `!merch`-kortet blev tjekket med skærmbilleder.

## Known limitations
- **Fritlægningen:** kun ensfarvede baggrunde (mønstre og fotos kræver upload).
- **Kommandoen:** `!merch` virker ikke på YouTube (ingen chatbeskeder uden løbende opslag).
- **Billeder fra andre domæner:** produktbilleder fra et CDN på et andet domæne kan hverken fritlægges i browseren eller vises i overlayet (CSP `img-src 'self'`).

## Future improvements
- Fritlægning med en AI-model i browseren, hvis upload ikke rækker.
