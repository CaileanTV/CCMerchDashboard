# Feature: Alert-API og webhooks til creatorens egne bots

## Purpose
Creators kan få deres købs-alerts ind i deres egne bots (fx SparkyBot, en Discord- eller Twitch-bot) i stedet for eller ud over den indbyggede chatbot (ROADMAP 25.12, [ADR-073](../DECISIONS.md)).

## Current status
- **25.12.1 (API-nøgler og polling):** implementeret 2026-10-23 og committet som eksperimentel (`9564f5e`); projektejeren har testet det i browseren: godkendt.
- **25.12.2 (webhooks):** implementeret 2026-10-23 og testet automatisk. Webhooks findes i SparkyBots JSON-format og til Discord, og SparkyBot-udviklerens anbefalinger er bygget ind.
- **25.12.3 (fælles test med SparkyBot-udvikleren):** flyttet til "Senere" i ROADMAP 2026-10-24; tages op, når udvikleren svarer.
- **25.12.4 (adgang pr. creator):** implementeret 2026-10-24 og testet automatisk. Administratoren giver hver creator adgang til API-nøgler og webhooks hver for sig på admin-fanen API (slået fra som standard). Uden adgang skjules siden, nøglerne giver 401, og webhooks sendes ikke; nøgler og webhooks bevares. Creators med eksisterende nøgler eller webhooks beholdt adgangen (migration 28).

Funktionen er ikke udgivet i en version endnu.

## User-facing functionality
Siderne **Alerts → API-nøgler** og **Alerts → Webhooks** i creator-dashboardet vises kun, mens administratoren har API'en slået til. De er to sider, fordi de virker hver for sig: med en nøgle spørger botten selv, med en webhook sender shoppen til botten (projektejerens ønske 2026-10-23).
- **API-nøgler:** creatoren opretter en nøgle med et navn. Hele nøglen (`mbk_…`) vises én gang med en kopiér-knap og advarslen om at gemme den nu. Listen viser navn, starten af nøglen, hvornår den blev oprettet og sidst brugt. "Tilbagekald" kræver en bekræftelse. Der er højst 5 nøgler.
- **Webhooks:** højst 3 pr. creator.
  - **Formater:** **JSON** sender én signeret hændelse pr. betalt ordre til creatorens egen bot (fx SparkyBot). **Discord** skriver chatbeskeden i en Discord-kanal, med en valgfri egen besked.
  - **Signeringsnøgle:** til JSON indsættes den fra botten, eller Merch Dashboard laver en, som vises én gang.
  - **Handlinger:** "Send test" sender straks og viser svaret (højst 10 tests pr. minut pr. creator). Webhooken kan slås til og fra, og "Genoptag" bruges efter en pause. JSON-webhooks kan få en ny nøgle, og sletning kræver bekræftelse.
  - **Leveringer:** de seneste 20 med tidspunkt, webhook, type og udfald. En fejl uden svar vises i ord ud fra årsagskoden (25.13 review): adressen fører til et privat eller reserveret netværk, modtageren kunne ikke nås, modtageren svarede ikke i tide, eller adressen kan ikke længere læses (tilføj webhooken igen).
  - **Test-kortet** (tilføjet efter 25.12.2, projektejerens ønske 2026-10-24): "Send test til alle webhooks" sender med det samme et testkøb til hver webhook, der er slået til, og viser svaret fra hver, som API-nøgle-sidens Test-kort.
- **Test og gensendelser:** "Send testhændelse" lægger et testkøb (`kind: test`) klar til hver nøgle. Gensender creatoren en alert under Historik, får nøglerne den (`kind: resend`), og webhooks får en `resend`-levering, også uden et overlay. Fra 25.13 virker gensend pr. kanal (overlay, API, webhooks og chatbot hver for sig), og Historik svarer kun "Opsæt dit overlay først", når ingen kanal er sat op.
- **Vejledning:**
  - **API:** adressen, et `curl`-eksempel med `Authorization: Bearer`, hvordan cursoren bruges (polling hvert 5.–10. sekund), grænsen på 60 forespørgsler pr. minut, fejlkoderne, et eksempelsvar og alle felter forklaret.
  - **Webhooks:** en eksempelhændelse, tjek af signaturen i Node.js, at `id` bruges til at fjerne dubletter, ét forsøg og pausen, og hvordan man opretter en Discord-webhook.

## Admin functionality
Merch Dashboard → Alerts → fanen **API** (`merchboard_manage_alerts`):
- Afkrydsningen "Creators kan bruge alert-API'en" er slået fra som standard. Slået fra svarer API'en 503, creators kan ikke oprette nøgler eller webhooks, og webhooks sendes ikke; alt bevares.
- Tabellen **"Creators"** (fra 25.12.4, ADR-073 pkt. 10; aktive creators og inaktive med nøgler eller webhooks) har én række pr. creator:
  - **Adgang:** afkrydsningerne "API-nøgler" og "Webhooks" og knappen "Gem adgang" (slået fra som standard, auditeret som `alert_api_access_changed`).
  - **API-nøgler:** antal virksomme nøgler og sidst brugt. Med en påkrævet afkrydsning kan administratoren tilbagekalde alle nøgler for creatoren. Selve nøglerne vises aldrig.
  - **Webhooks:** hver webhooks navn, format, vært og status. "Slå alle fra" kræver en afkrydsning. Adresser og hemmeligheder vises aldrig.

## Technical implementation
- **`Api/`:**
  - `AlertApiKeys` (service: oprettelse, tilbagekaldelse, `authenticate()`);
  - `AlertApiKeyRepository`;
  - `AlertApiSwitch` (indstillingen);
  - `AlertApiAccess` (adgang pr. creator til nøgler og webhooks, creator meta `alert_api_access`; 25.12.4);
  - `AlertApiFeed` (svaret pr. nøgle, test, gensendelse);
  - `AlertApiEvents` (hændelsens form);
  - `AlertApiQueue` (test og gensendelser pr. nøgle);
  - `AlertApiFeedController` (den offentlige rute).
- **`Webhooks/`:**
  - `AlertWebhooks` (service);
  - `AlertWebhookRepository` og `WebhookDeliveryRepository` (de seneste 50 pr. webhook);
  - `WebhookDispatcher` (betalt ordre, test, gensendelse, Action Scheduler-hooket `merchboard_alert_webhook_send`);
  - `WebhookPayloads` (JSON, Discord, signatur);
  - `WebhookUrl` (adresseregler).
  - Kryptering sker med `ChatCipher` og domænet `webhooks`.
- **Øvrigt:** `Rest/MeAlertsApiController`, `Admin/AlertApiTab` og `Admin/AlertApiActions`.
- **Nøgleformat:** `mbk_{lookup}_{secret}` (12 og 48 hex-tegn). Kun opslagsdelen og `hash_hmac('sha256', secret, wp_salt('auth'))` gemmes; sammenligning med `hash_equals()`. Ændres WordPress' auth-salt, holder alle nøgler op med at virke, og webhook-adresser og -hemmeligheder kan ikke længere læses (creatoren tilføjer webhooken igen).
- **Hændelser i API'en:** én pr. ordrelinje, som overlayet. `order_ref` er en HMAC af creator og ordre (16 hex-tegn), ens for linjerne i en ordre og forskellig for hver creator; aldrig ordrenummeret. Beløbene er tal med butikkens decimaler (`AlertMoney::plain()`).
- **Hændelser i webhooks:** én pr. betalt ordre pr. creator med varerne (SparkyBots format).
  - `id` er en HMAC af creator og ordre, så samme køb altid har samme id.
  - `display_name` er brugernavnet eller "Anonymous".
  - Signaturen er `X-MerchBoard-Signature: sha256=` HMAC af `{timestamp}.{body}`.
  - Afsendelse sker via Action Scheduler efter betalingen med ét forsøg, timeout 5 s og ingen redirects.
  - **SSRF-værn (25.13 review):** værten slås op (A og AAAA), og leveringen afvises som `blocked_address`, medmindre alle adresser er offentlige (`FILTER_FLAG_GLOBAL_RANGE`); cURL forbinder til præcis den tjekkede adresse (`http_api_curl`, `CURLOPT_RESOLVE`). Creatoren ser årsagskoderne `blocked_address`, `unreachable`, `timeout`, `unreadable` og `http_NNN`; transportens fejltekst går til den tekniske log.
  - Et 2xx-svar nulstiller fejltælleren, og 10 fejl i træk giver pause.
- **Test og gensendelser:**
  - **API:** de ligger i en transient pr. nøgle i 5 minutter (højst 20). Deres `id` er 0, så de aldrig flytter cursoren.
  - **Webhooks:** de får et nyt hændelses-id. Ved gensendelse af én linje er `total` stadig creatorens samlede betalte ordretotal, mens `items` og `item_count` kun dækker den gensendte linje (projektejerens beslutning 2026-10-24).
- **Importerede alerts (schema-version 29):** alerts fra importen fra MerchAlert har `imported = 1` og kommer aldrig i API'ens feed som nye køb.
- **Dashboard:** `sections/alerts/AlertsApiSection.tsx` (API-nøgler), `sections/alerts/AlertsWebhooksSection.tsx` med `WebhooksCard.tsx` (webhooks) og `api/alertApi.ts`.

## Relevant files
- `src/Modules/Alerts/Api/`, `src/Modules/Alerts/Webhooks/`, `src/Modules/Alerts/Rest/MeAlertsApiController.php`, `src/Modules/Alerts/Admin/AlertApiTab.php`, `src/Modules/Alerts/Admin/AlertApiActions.php`, `src/Core/Database/Migrations/CreateAlertApiKeysTable.php`, `src/Core/Database/Migrations/CreateAlertWebhookTables.php`, `src/Core/Database/Migrations/GrantExistingAlertApiAccess.php` (migration 28)
- `assets/src/dashboard/sections/alerts/AlertsApiSection.tsx`, `assets/src/dashboard/sections/alerts/WebhooksCard.tsx`, `assets/src/dashboard/api/alertApi.ts`
- `tests/Integration/Alerts/AlertApiTest.php`, `tests/Integration/Alerts/AlertWebhooksTest.php`, `tests/Integration/Database/MigrationTest.php`, `tests/Unit/Modules/Alerts/WebhookRulesTest.php`, `assets/src/dashboard/test/alert-api.test.tsx`

## Database/data used
- **`merchboard_alert_api_keys`** (schema-version 26): navn, opslagsdel og HMAC samt tidspunkterne for oprettet, sidst brugt og tilbagekaldt.
- **`merchboard_alert_webhooks`** og **`merchboard_alert_webhook_deliveries`** (schema-version 27): krypteret adresse og hemmelighed samt leveringslog. Se [DATABASE.md](../DATABASE.md).
- **Option `merchboard_alerts_api_enabled`:** indstillingen.
- **Transients:** testkøen pr. nøgle og rate limit pr. nøgle.

## Permissions
- **Bot:** API-nøglen i headeren `Authorization: Bearer` eller `X-MerchBoard-Key`; `X-MerchBoard-Key` bruges, når `Authorization` ikke er en Bearer-header (fx når sitet selv bruger Basic auth). Kun en virksom nøgle til en aktiv creator med adgang til API-nøgler (25.12.4) virker, og kun mens API'en og alerts er slået til.
- **Webhooks:** kun for en creator med adgang til webhooks (25.12.4); uden adgang giver oprettelse og test 403 `merchboard_alerts_api_no_access`.
- **Webhook-modtager:** tjekker selv signaturen med hemmeligheden.
- **Creator:** `CreatorContext` (ADR-007).
- **Admin:** `merchboard_manage_alerts`, nonce.

## API endpoints
- `GET /merchboard/v1/alerts/api/events?after={id}&limit={1–50}` (offentlig med nøgle).
- `GET /me/alerts/api`, `POST /me/alerts/api/keys`, `DELETE /me/alerts/api/keys/{id}`, `POST /me/alerts/api/test`.
- `POST /me/alerts/api/webhooks`, `POST /me/alerts/api/webhooks/test` (alle webhooks), `PATCH`/`DELETE /me/alerts/api/webhooks/{id}`, `POST /me/alerts/api/webhooks/{id}/test`, `POST /me/alerts/api/webhooks/{id}/secret`. Webhook-tests: højst 10 pr. minut pr. creator, ellers 429 `merchboard_too_many_requests`.

Se [API.md](../API.md), også for webhook-formatet.

## Hooks
- `merchboard_dashboard_sections` (siderne "alerts-api" og "alerts-webhooks", kun når API'en er slået til);
- `rest_api_init`;
- `admin_post_merchboard_alert_api`;
- `woocommerce_order_status_processing|completed` (prioritet 30, efter chatbotten);
- `merchboard_alert_webhook_send` (Action Scheduler).

## Dependencies
- **Plugin:** Alerts-modulet (WooCommerce, Action Scheduler).
- **PHP:** sodium til krypteringen af webhooks.
- **Udgående kald:** kun til adresser, som creatoren selv angiver (fx SparkyBot eller Discord). Merch Dashboard har ingen konto hos dem.

## Tests
- **Integration, API:**
  - nøglen vises én gang, gemmes som HMAC, med grænsen på 5 og audit uden nøgle;
  - cursor, egne hændelser og felterne;
  - 401 ved forkert, tilbagekaldt, inaktiv eller manglende nøgle og ved nøgle i URL'en;
  - 503 ved slået fra og kill switch;
  - 429;
  - test og gensendelse til alle nøgler;
  - admin-handlinger;
  - migration 26.
- **Integration, webhooks** (`pre_http_request`-stub):
  - krypteret lagring og audit uden hemmeligheder;
  - adresseregler;
  - én signeret hændelse pr. ordre med varerne og stabilt id;
  - Discord i linje- og ordre-tilstand uden mentions;
  - ét forsøg, pause efter 10 og nulstilling ved succes;
  - ingen levering når API'en, alerts, creatoren eller webhooken siger nej;
  - test og gensendelser markeret med nye id'er;
  - loggen på 50;
  - en vært med en privat eller reserveret adresse kaldes aldrig (25.13);
  - creator-ruterne og admin "Slå alle fra";
  - migration 27.
- **Unit:** `AlertMoney::plain()`, dashboard-sektionen, adresseregler, signatur og krypteringsdomæne.
- **Jest:** validering, nøglen vist én gang, tilbagekaldelse med bekræftelse, slået fra, webhook oprettet med vist hemmelighed, test, pause og sletning med bekræftelse; leveringernes årsagskoder i ord (25.13).

## Known limitations
- **Ét forsøg pr. levering:** SparkyBot anbefaler begrænsede genforsøg. Det, en webhook mistede, hentes med polling eller gensend fra Historik.
- **`total` ved gensendelse af én linje** er creatorens samlede betalte ordretotal, ikke linjens (accepteret af projektejeren 2026-10-24).
- **Accepterede lave risici (25.13 review, projektejerens beslutning 2026-10-24):** to samtidige forespørgsler kan give én nøgle eller webhook ud over grænsen; test- og gensendelseskøen pr. nøgle (transient) kan miste en hændelse, hvis en forespørgsel fejler midtvejs. Se også [alerts.md](alerts.md), "Known limitations".
