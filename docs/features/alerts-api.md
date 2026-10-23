# Feature: Alert-API til creatorens egne bots

## Purpose
Creators kan få deres købs-alerts ind i deres egne bots (fx en Discord- eller Twitch-bot) i stedet for eller ud over den indbyggede chatbot (ROADMAP 25.12, [ADR-073](../DECISIONS.md)).

## Current status
25.12.1 (API-nøgler og polling) er implementeret 2026-10-23 og committet som eksperimentel (`9564f5e`); projektejeren har testet det i browseren: godkendt. 25.12.3 (integration med SparkyBot) er planlagt (ROADMAP). 25.12.2 (webhooks til JSON og Discord) er planlagt og ikke bygget. Ikke udgivet i en version endnu.

## User-facing functionality
Siden **Alerts → API** i creator-dashboardet vises kun, mens administratoren har API'en slået til.
- **API-nøgler:** creatoren opretter en nøgle med et navn. Hele nøglen (`mbk_…`) vises én gang med en kopiér-knap og advarslen om at gemme den nu. Listen viser navn, starten af nøglen, hvornår den blev oprettet og sidst brugt. "Tilbagekald" kræver en bekræftelse. Der er højst 5 nøgler.
- **Test:** "Send testhændelse" lægger et testkøb (`kind: test`) klar til hver nøgle. Gensender creatoren en alert under Historik, får bottene den også (`kind: resend`), også uden et overlay.
- **Vejledning:** adressen, et `curl`-eksempel med `Authorization: Bearer`, hvordan cursoren bruges (polling hvert 5.–10. sekund), grænsen på 60 forespørgsler pr. minut, fejlkoderne, et eksempelsvar og alle felter forklaret.

## Admin functionality
Merch Dashboard → Alerts → fanen **API** (`merchboard_manage_alerts`):
- Afkrydsningen "Creators kan bruge alert-API'en" er slået fra som standard. Slået fra svarer API'en 503, og creators kan ikke oprette nøgler; nøglerne bevares.
- Oversigten viser creators med virksomme nøgler: antal og sidst brugt. Med en påkrævet afkrydsning kan administratoren tilbagekalde alle nøgler for en creator. Selve nøglerne vises aldrig.

## Technical implementation
- **`Api/`:**
  - `AlertApiKeys` (service: oprettelse, tilbagekaldelse, `authenticate()`);
  - `AlertApiKeyRepository`;
  - `AlertApiSwitch` (indstillingen);
  - `AlertApiFeed` (svaret pr. nøgle, test, gensendelse);
  - `AlertApiEvents` (hændelsens form);
  - `AlertApiQueue` (test og gensendelser pr. nøgle);
  - `AlertApiFeedController` (den offentlige rute).
- **Øvrigt:** `Rest/MeAlertsApiController`, `Admin/AlertApiTab` og `Admin/AlertApiActions`.
- **Nøgleformat:** `mbk_{lookup}_{secret}` (12 og 48 hex-tegn). Kun opslagsdelen og `hash_hmac('sha256', secret, wp_salt('auth'))` gemmes; sammenligning med `hash_equals()`. Ændres WordPress' auth-salt, holder alle nøgler op med at virke.
- **Hændelser:** én pr. ordrelinje, som overlayet. `order_ref` er en HMAC af creator og ordre (16 hex-tegn), ens for linjerne i en ordre og forskellig for hver creator; aldrig ordrenummeret. Beløbene er tal med butikkens decimaler (`AlertMoney::plain()`).
- **Test og gensendelser:** de ligger i en transient pr. nøgle i 5 minutter (højst 20). Deres `id` er 0, så de aldrig flytter cursoren.
- **Dashboard:** `sections/alerts/AlertsApiSection.tsx` og `api/alertApi.ts`.

## Relevant files
- `src/Modules/Alerts/Api/`, `src/Modules/Alerts/Rest/MeAlertsApiController.php`, `src/Modules/Alerts/Admin/AlertApiTab.php`, `src/Modules/Alerts/Admin/AlertApiActions.php`, `src/Core/Database/Migrations/CreateAlertApiKeysTable.php`
- `assets/src/dashboard/sections/alerts/AlertsApiSection.tsx`, `assets/src/dashboard/api/alertApi.ts`
- `tests/Integration/Alerts/AlertApiTest.php`, `tests/Integration/Database/MigrationTest.php`, `assets/src/dashboard/test/alert-api.test.tsx`

## Database/data used
- **`merchboard_alert_api_keys`** (schema-version 26): navn, opslagsdel og HMAC samt tidspunkterne for oprettet, sidst brugt og tilbagekaldt. Se [DATABASE.md](../DATABASE.md).
- **Option `merchboard_alerts_api_enabled`:** indstillingen.
- **Transients:** testkøen pr. nøgle og rate limit pr. nøgle.

## Permissions
- **Bot:** API-nøglen i headeren. Kun en virksom nøgle til en aktiv creator virker, og kun mens API'en og alerts er slået til.
- **Creator:** `CreatorContext` (ADR-007).
- **Admin:** `merchboard_manage_alerts`, nonce.

## API endpoints
- `GET /merchboard/v1/alerts/api/events?after={id}&limit={1–50}` (offentlig med nøgle).
- `GET /me/alerts/api`, `POST /me/alerts/api/keys`, `DELETE /me/alerts/api/keys/{id}`, `POST /me/alerts/api/test`.

Se [API.md](../API.md).

## Hooks
`merchboard_dashboard_sections` (siden "alerts-api", kun når API'en er slået til), `rest_api_init`, `admin_post_merchboard_alert_api`.

## Dependencies
Alerts-modulet (WooCommerce). Ingen eksterne services i 25.12.1.

## Tests
- **Integration:**
  - nøglen vises én gang, gemmes som HMAC, med grænsen på 5 og audit uden nøgle;
  - cursor, egne hændelser og felterne;
  - 401 ved forkert, tilbagekaldt, inaktiv eller manglende nøgle og ved nøgle i URL'en;
  - 503 ved slået fra og kill switch;
  - 429;
  - test og gensendelse til alle nøgler;
  - admin-handlinger;
  - migration 26.
- **Unit:** `AlertMoney::plain()` og dashboard-sektionen.
- **Jest:** validering, nøglen vist én gang, tilbagekaldelse med bekræftelse, slået fra.

## Known limitations
- Kun polling i 25.12.1; webhooks kommer i 25.12.2.
- En gensendelse fra Historik før overlayet er sat op, når bottene, men svaret siger stadig "Opsæt dit overlay først".
