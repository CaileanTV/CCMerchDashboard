# REST API

> Status: **Delvist implementeret.** REST-fundamentet, `/me` og `/me/profile` er implementeret (fase 14), pagineringen af liste-routes er tilføjet i fase 19, trin 1, og `/me/products` og `/me/orders` er implementeret i fase 19 (ADR-039), `/me/sales` er implementeret i fase 20, trin 3 (ADR-040), og `PATCH /me/preferences` er implementeret i fase 21, trin 5 (ADR-044). Fase 22 (ADR-050) tilføjer creatorens profit til `/me/products`, `/me/orders` og `/me/sales`. `/me/payouts` er implementeret i fase 23, trin 23.4 (ADR-054–056). Øvrige routes i tabellerne nedenfor er design.

## Principper

1. **Kun WordPress REST API** (`register_rest_route`). **Ingen `admin-ajax.php`**: REST har schema, permission callbacks og standard-fejlformat. Klassiske admin-formularer poster til `admin-post.php` (se [ADMIN.md](ADMIN.md)).
2. Namespace **`merchboard/v1`**. Breaking changes giver `v2`, og `v1` bevares i en overgangsperiode.
3. **Hver route har en eksplicit `permission_callback`.** `__return_true` er forbudt, medmindre endpointet bevidst er offentligt, og det kræver en ADR.
4. **Hver route har `args` med `type`, `required`, `validate_callback`/`enum`/`minimum` osv. og `sanitize_callback`.** Ukendte felter ignoreres.
5. **Creator-endpoints tager aldrig et creator-ID fra klienten.** Creator'en udledes server-side via `CreatorContext` (se nedenfor).
6. Controllers er tynde: validering → service → `to_array()`-respons. Forretningslogik ligger i services.

## Authentication

- **Creator dashboard og admin-scripts:** cookie-authentication og `X-WP-Nonce` (`wp_rest`). Nonce og root-URL sættes af WordPress' eget `wp-api-fetch`-script (inline middleware). Dashboardet gemmer intet i browseren.
- **Frontend-boundary (✅ fase 13):** `assets/src/dashboard/api/client.ts` er den eneste vej til REST. Den tillader kun `/merchboard/v1/`, bruger `apiFetch( { parse: false } )`, validerer hvert svar med en endpoint-specifik validator (svaret er `unknown` indtil da) og returnerer `ApiResult<T>`. Fejl reduceres til en fast type (`unauthenticated`, `forbidden`, `not_found`, `server`, `network`, `invalid_response`, `aborted`) og WordPress' fejlkode. Serverens fejlbesked vises aldrig. Ved 401/403 redirectes der ikke automatisk. UI'et viser et login- eller log ud-link. TypeScript-typerne for svar skal matche backendens kontrakt og må ikke opfinde felter. Endpoints og deres typer kommer fra fase 14.
- **Application Passwords** er deaktiveret for rene creators (✅ fase 12, `wp_is_application_passwords_available_for_user`, ADR-026) for at reducere angrebsfladen. WordPress tjekker filteret både ved oprettelse (REST svarer `application_passwords_disabled_for_user`) og ved login med et eksisterende password, så også passwords oprettet før brugeren blev ren creator afvises. Administratorer, editors og shop managers, der også er creators, bevarer dem.
- Uautentificerede requests (også cookie uden gyldig `X-WP-Nonce`) til `/me`-routes giver `401 merchboard_unauthenticated`. Uden creator-adgang: `403 merchboard_forbidden`. En nonce, der ikke længere passer til sessionen, afviser WordPress selv med `403 rest_cookie_invalid_nonce`, og frontend viser det som udløbet session.

## Route-familier

### Creator-scope: `/merchboard/v1/me/...`

Permission: `CreatorContext::current()` (✅ fase 8) skal returnere en creator. Den håndhæver hele reglen: brugeren skal have `merchboard_access_dashboard` **og** en **aktiv** creator-record koblet til sig. Permission callbacks bruger `CreatorContext::current_user_has_access()` og gentager ikke reglen selv.

| Metode | Route | Formål | Fase |
| --- | --- | --- | --- |
| `GET` | `/me` | ✅ Den aktuelle creator: `display_name`, `status`. Rettigheder og sektioner kommer fortsat fra bootstrap-data (sektion-registry i fase 15). | 14 |
| `GET` | `/me/profile` | ✅ Profilen: `display_name`, `bio`, `social_links` | 14 |
| `PATCH` | `/me/profile` | ✅ Opdater whitelistede profilfelter | 14 |
| `PATCH` | `/me/preferences` | ✅ Brugerens dashboard-tema (`theme`; `null` = følg systemet). Ingen `GET` | 21 |
| `GET` | `/me/products` | ✅ Creator'ens produkter (pagineret, se nedenfor) | 19 |
| `GET` | `/me/orders` | ✅ Ordrelinjer med creator'ens produkter **uden kunde-PII** (se nedenfor) | 19 |
| `GET` | `/me/sales?from=&to=&interval=` | ✅ Salgsstatistik pr. valuta: totaler, tæt serie og produkter (se nedenfor) | 20 |
| `GET` | `/me/payouts` | ✅ Creatorens egne payouts, nyeste først, pagineret (`page`, `per_page`, `X-WP-Total`): `id`, `status`, `currency`, `amount`, `method`, `destination_hint` (kun maskeret), `requested_at`, `decided_at`, `rejection_reason` (kun ved `rejected`), `gift_card` (true, når et gavekort er registreret og kan vises), `settlement_document` (bilagsnummer eller null, ADR-060). Aldrig dekrypterede oplysninger eller gavekortkoder | 23 |
| `GET` | `/me/payouts/balance` | ✅ Saldo pr. valuta (`status` `complete`/`unknown`, `earned`, `completed`, `reserved`, `available`, `outstanding`, `unknown_rows`; beløb er `null` ved ukendt saldo, aldrig 0), `holding_days`, `payout_start`, `methods`, `methods_usable` (bank og PayPal; false uden gyldig `MERCHBOARD_PAYOUT_KEY`), `gift_card_usable` (true kun med et valgt og tilgængeligt gavekortplugin, ADR-057) | 23 |
| `POST` | `/me/payouts` | ✅ Ny anmodning: `{ currency, amount (decimalstreng eller "all"), method ("bank"\|"paypal"\|"gift_card"), details ({registration, account}, {email} eller {} for gavekort), idempotency_key }`. 201 med payouten; samme nøgle igen giver samme payout og samme svar. Manglende eller forkert typede felter: 400 `merchboard_invalid_payout` med `fields`. Afvisninger: `merchboard_payout_refused` med `data.reason` (400 for ugyldige værdier og utilgængelig metode, 409 for `balance_unknown`, `insufficient_balance` og `busy`, 429 for `too_many_pending`/`too_many_requests`) | 23 |
| `GET` | `/me/payouts/{id}/settlement-document` | ✅ Afregningsbilaget for creatorens egen gennemførte payout (ADR-060), som udstedt og aldrig genberegnet: `number`, `type`, `preliminary` (true for bilag med skabelonversion 1, mærket "Foreløbigt, ikke godkendt af revisor/SKAT"; false fra skabelonversion 2, som i stedet har oplysningen om skat og indberetning), `issued_at`, `period_from`, `period_to`, `currency`, `profit`, `commission`, `commission_vat` (moms af kommissionen fra skabelonversion 3, ADR-062 K05/K12: `rate_bps`, `excl`, `vat`, `incl` = `excl` + `vat` og `untaxed`, kommission af salg, mens creatoren ikke var momsregistreret; null for ældre bilag og når der ikke er beregnet moms af kommissionen), `vat_deduction` (moms fratrukket udbetalingen for salg, mens creatoren ikke var momsregistreret, fra skabelonversion 6, ADR-064: `gross` (kommission inkl. moms), `deduction` og `net` = `gross` − `deduction` (udbetalt); null for ældre bilag og når der ikke er fratrukket moms), `adjustment` (reguleringer, bilaget afregnede, ADR-063; "0.0000" uden) og `adjustment_refs` (genberegningernes referencer), `opening_balance`, `payout`, `closing_balance` (= `opening_balance` + `commission` + `adjustment` − `payout`), `vat_registered`, `creator_name` (visningsnavnet ved udstedelsen), `company_name`, `company_address`, `master_data` (stamdata fra skabelonversion 4, ADR-062 K09: `creator_legal_name`, `creator_address` (liste af linjer), `creator_cvr` (CVR/SE-nr.) og `company_cvr`, som ved udstedelsen; "" eller [] betyder ikke registreret ved udstedelsen, og intet udfyldes senere; null for ældre bilag), `method`, `destination` (maskeret), `rows` (`order_id`, `item_name`, `type`, `quantity`, `net`, `tax`, `payout`) og `corrections` (`number`, `issued_at`, `reason` og `amounts`: for et rettelsesbilag med beløb, ADR-062 K10/K15, `suffix` (fx `K1`), `original`, `corrects`, `rate_bps`, `before`, `after` og `difference` (hver `commission`, `excl`, `vat`, `incl`, `untaxed`) og `payout` (uændret); null for korrigerende bilag uden beløb og for et rettelsesbilag, der ikke stemmer med sin kæde; og `master_data`: rettelsesbilagets stamdata fra dets eget snapshot, aldrig creatorens aktuelle, med `creator_legal_name`, `creator_address`, `creator_cvr`, `company_name`, `company_address`, `company_cvr` ("" eller [] = ikke registreret) og `changed` (om de afviger fra det rettede bilag, dvs. stamdata blev rettet); null i de samme tilfælde som `amounts`). `no-store, private`. En anden creators payout eller en payout uden bilag giver 404 | 23 |
| `GET` | `/me/payouts/{id}/settlement-document/pdf` | ✅ Samme bilag som PDF (A4, ADR-060, note 2026-10-16), dannet ved hver hentning ud fra det gemte bilag og aldrig gemt. Svaret er selve PDF-filen: `Content-Type: application/pdf`, `Content-Disposition: attachment; filename="AFR-000001.pdf"`, `Cache-Control: no-store, private`, `X-Content-Type-Options: nosniff`. Samme bilag giver samme bytes. En anden creators payout eller en payout uden bilag giver 404 (JSON); et bilag, der ikke stemmer med sine rækker, giver ingen PDF, men 500 `merchboard_internal_error`, og intet ændres. Med `?correction=K1` (mønster `^K[1-9][0-9]{0,2}# REST API

> Status: **Delvist implementeret.** REST-fundamentet, `/me` og `/me/profile` er implementeret (fase 14), pagineringen af liste-routes er tilføjet i fase 19, trin 1, og `/me/products` og `/me/orders` er implementeret i fase 19 (ADR-039), `/me/sales` er implementeret i fase 20, trin 3 (ADR-040), og `PATCH /me/preferences` er implementeret i fase 21, trin 5 (ADR-044). Fase 22 (ADR-050) tilføjer creatorens profit til `/me/products`, `/me/orders` og `/me/sales`. `/me/payouts` er implementeret i fase 23, trin 23.4 (ADR-054–056). Øvrige routes i tabellerne nedenfor er design.

## Principper

1. **Kun WordPress REST API** (`register_rest_route`). **Ingen `admin-ajax.php`**: REST har schema, permission callbacks og standard-fejlformat. Klassiske admin-formularer poster til `admin-post.php` (se [ADMIN.md](ADMIN.md)).
2. Namespace **`merchboard/v1`**. Breaking changes giver `v2`, og `v1` bevares i en overgangsperiode.
3. **Hver route har en eksplicit `permission_callback`.** `__return_true` er forbudt, medmindre endpointet bevidst er offentligt, og det kræver en ADR.
4. **Hver route har `args` med `type`, `required`, `validate_callback`/`enum`/`minimum` osv. og `sanitize_callback`.** Ukendte felter ignoreres.
5. **Creator-endpoints tager aldrig et creator-ID fra klienten.** Creator'en udledes server-side via `CreatorContext` (se nedenfor).
6. Controllers er tynde: validering → service → `to_array()`-respons. Forretningslogik ligger i services.

## Authentication

- **Creator dashboard og admin-scripts:** cookie-authentication og `X-WP-Nonce` (`wp_rest`). Nonce og root-URL sættes af WordPress' eget `wp-api-fetch`-script (inline middleware). Dashboardet gemmer intet i browseren.
- **Frontend-boundary (✅ fase 13):** `assets/src/dashboard/api/client.ts` er den eneste vej til REST. Den tillader kun `/merchboard/v1/`, bruger `apiFetch( { parse: false } )`, validerer hvert svar med en endpoint-specifik validator (svaret er `unknown` indtil da) og returnerer `ApiResult<T>`. Fejl reduceres til en fast type (`unauthenticated`, `forbidden`, `not_found`, `server`, `network`, `invalid_response`, `aborted`) og WordPress' fejlkode. Serverens fejlbesked vises aldrig. Ved 401/403 redirectes der ikke automatisk. UI'et viser et login- eller log ud-link. TypeScript-typerne for svar skal matche backendens kontrakt og må ikke opfinde felter. Endpoints og deres typer kommer fra fase 14.
- **Application Passwords** er deaktiveret for rene creators (✅ fase 12, `wp_is_application_passwords_available_for_user`, ADR-026) for at reducere angrebsfladen. WordPress tjekker filteret både ved oprettelse (REST svarer `application_passwords_disabled_for_user`) og ved login med et eksisterende password, så også passwords oprettet før brugeren blev ren creator afvises. Administratorer, editors og shop managers, der også er creators, bevarer dem.
- Uautentificerede requests (også cookie uden gyldig `X-WP-Nonce`) til `/me`-routes giver `401 merchboard_unauthenticated`. Uden creator-adgang: `403 merchboard_forbidden`. En nonce, der ikke længere passer til sessionen, afviser WordPress selv med `403 rest_cookie_invalid_nonce`, og frontend viser det som udløbet session.

## Route-familier

### Creator-scope: `/merchboard/v1/me/...`

Permission: `CreatorContext::current()` (✅ fase 8) skal returnere en creator. Den håndhæver hele reglen: brugeren skal have `merchboard_access_dashboard` **og** en **aktiv** creator-record koblet til sig. Permission callbacks bruger `CreatorContext::current_user_has_access()` og gentager ikke reglen selv.

| Metode | Route | Formål | Fase |
| --- | --- | --- | --- |
| `GET` | `/me` | ✅ Den aktuelle creator: `display_name`, `status`. Rettigheder og sektioner kommer fortsat fra bootstrap-data (sektion-registry i fase 15). | 14 |
| `GET` | `/me/profile` | ✅ Profilen: `display_name`, `bio`, `social_links` | 14 |
| `PATCH` | `/me/profile` | ✅ Opdater whitelistede profilfelter | 14 |
| `PATCH` | `/me/preferences` | ✅ Brugerens dashboard-tema (`theme`; `null` = følg systemet). Ingen `GET` | 21 |
| `GET` | `/me/products` | ✅ Creator'ens produkter (pagineret, se nedenfor) | 19 |
| `GET` | `/me/orders` | ✅ Ordrelinjer med creator'ens produkter **uden kunde-PII** (se nedenfor) | 19 |
| `GET` | `/me/sales?from=&to=&interval=` | ✅ Salgsstatistik pr. valuta: totaler, tæt serie og produkter (se nedenfor) | 20 |
| `GET` | `/me/payouts` | ✅ Creatorens egne payouts, nyeste først, pagineret (`page`, `per_page`, `X-WP-Total`): `id`, `status`, `currency`, `amount`, `method`, `destination_hint` (kun maskeret), `requested_at`, `decided_at`, `rejection_reason` (kun ved `rejected`), `gift_card` (true, når et gavekort er registreret og kan vises), `settlement_document` (bilagsnummer eller null, ADR-060). Aldrig dekrypterede oplysninger eller gavekortkoder | 23 |
| `GET` | `/me/payouts/balance` | ✅ Saldo pr. valuta (`status` `complete`/`unknown`, `earned`, `completed`, `reserved`, `available`, `outstanding`, `unknown_rows`; beløb er `null` ved ukendt saldo, aldrig 0), `holding_days`, `payout_start`, `methods`, `methods_usable` (bank og PayPal; false uden gyldig `MERCHBOARD_PAYOUT_KEY`), `gift_card_usable` (true kun med et valgt og tilgængeligt gavekortplugin, ADR-057) | 23 |
| `POST` | `/me/payouts` | ✅ Ny anmodning: `{ currency, amount (decimalstreng eller "all"), method ("bank"\|"paypal"\|"gift_card"), details ({registration, account}, {email} eller {} for gavekort), idempotency_key }`. 201 med payouten; samme nøgle igen giver samme payout og samme svar. Manglende eller forkert typede felter: 400 `merchboard_invalid_payout` med `fields`. Afvisninger: `merchboard_payout_refused` med `data.reason` (400 for ugyldige værdier og utilgængelig metode, 409 for `balance_unknown`, `insufficient_balance` og `busy`, 429 for `too_many_pending`/`too_many_requests`) | 23 |
| `GET` | `/me/payouts/{id}/settlement-document` | ✅ Afregningsbilaget for creatorens egen gennemførte payout (ADR-060), som udstedt og aldrig genberegnet: `number`, `type`, `preliminary` (true for bilag med skabelonversion 1, mærket "Foreløbigt, ikke godkendt af revisor/SKAT"; false fra skabelonversion 2, som i stedet har oplysningen om skat og indberetning), `issued_at`, `period_from`, `period_to`, `currency`, `profit`, `commission`, `commission_vat` (moms af kommissionen fra skabelonversion 3, ADR-062 K05/K12: `rate_bps`, `excl`, `vat`, `incl` = `excl` + `vat` og `untaxed`, kommission af salg, mens creatoren ikke var momsregistreret; null for ældre bilag og når der ikke er beregnet moms af kommissionen), `vat_deduction` (moms fratrukket udbetalingen for salg, mens creatoren ikke var momsregistreret, fra skabelonversion 6, ADR-064: `gross` (kommission inkl. moms), `deduction` og `net` = `gross` − `deduction` (udbetalt); null for ældre bilag og når der ikke er fratrukket moms), `adjustment` (reguleringer, bilaget afregnede, ADR-063; "0.0000" uden) og `adjustment_refs` (genberegningernes referencer), `opening_balance`, `payout`, `closing_balance` (= `opening_balance` + `commission` + `adjustment` − `payout`), `vat_registered`, `creator_name` (visningsnavnet ved udstedelsen), `company_name`, `company_address`, `master_data` (stamdata fra skabelonversion 4, ADR-062 K09: `creator_legal_name`, `creator_address` (liste af linjer), `creator_cvr` (CVR/SE-nr.) og `company_cvr`, som ved udstedelsen; "" eller [] betyder ikke registreret ved udstedelsen, og intet udfyldes senere; null for ældre bilag), `method`, `destination` (maskeret), `rows` (`order_id`, `item_name`, `type`, `quantity`, `net`, `tax`, `payout`) og `corrections` (`number`, `issued_at`, `reason` og `amounts`: for et rettelsesbilag med beløb, ADR-062 K10/K15, `suffix` (fx `K1`), `original`, `corrects`, `rate_bps`, `before`, `after` og `difference` (hver `commission`, `excl`, `vat`, `incl`, `untaxed`) og `payout` (uændret); null for korrigerende bilag uden beløb og for et rettelsesbilag, der ikke stemmer med sin kæde; og `master_data`: rettelsesbilagets stamdata fra dets eget snapshot, aldrig creatorens aktuelle, med `creator_legal_name`, `creator_address`, `creator_cvr`, `company_name`, `company_address`, `company_cvr` ("" eller [] = ikke registreret) og `changed` (om de afviger fra det rettede bilag, dvs. stamdata blev rettet); null i de samme tilfælde som `amounts`). `no-store, private`. En anden creators payout eller en payout uden bilag giver 404 | 23 |
| `GET` | `/me/payouts/{id}/settlement-document/pdf` | ✅ Samme bilag som PDF (A4, ADR-060, note 2026-10-16), dannet ved hver hentning ud fra det gemte bilag og aldrig gemt. Svaret er selve PDF-filen: `Content-Type: application/pdf`, `Content-Disposition: attachment; filename="AFR-000001.pdf"`, `Cache-Control: no-store, private`, `X-Content-Type-Options: nosniff`. Samme bilag giver samme bytes. En anden creators payout eller en payout uden bilag giver 404 (JSON); et bilag, der ikke stemmer med sine rækker, giver ingen PDF, men 500 `merchboard_internal_error`, , ellers 400) sendes i stedet et af bilagets rettelsesbilag med beløb (ADR-062 K10/K15, filnavn `AFR-000001-K1.pdf`); et ukendt suffiks eller et rettelsesbilag uden beløb giver 404, og et rettelsesbilag, der ikke stemmer med sin kæde, giver ingen PDF (500) | 24 |
| `GET` | `/me/payouts/{id}/gift-card` | ✅ Gavekortet for creatorens egen gennemførte gavekort-payout, læst live fra gavekortpluginet (ADR-057): `code`, `amount`, `balance`, `currency`, `status` (`active`/`disabled`/`expired`/`other`), `expires_at`. `Cache-Control: no-store, private`. En anden creators payout, en ventende payout eller en payout uden kort giver 404 `merchboard_not_found`; kan kortet ikke læses nu, 503 `merchboard_payout_refused` med `reason` `gift_cards_unavailable` | 23 |
| `POST` | `/me/payouts/{id}/cancel` | ✅ Annullerer egen ventende payout (200); en anden creators eller en ukendt payout giver 404 `merchboard_not_found`; en afsluttet giver 409 `not_pending`; en annulleret igen giver 200 | 23 |
| `GET` | `/me/discount-code` | ✅ Creatorens egen aktive rabatkode (ADR-068): `{ "code": "nova10" }` eller `{ "code": null }`. Ingen parametre; `Cache-Control: no-store`. Dashboardet henter den først, når creatoren trykker "Vis rabatkode". Kun med WooCommerce | 1.0.1 |

Der findes intet `/me/overview` (ADR-040 pkt. 12). Logoet (fase 21, trin 6, ADR-042) har ingen REST-route: det vælges i wp-admin (Settings → Branding, `admin-post.php`) og når dashboardet via bootstrap-feltet `logo` (sitekonfiguration, ikke creator-data). Overview kalder `/me/sales` uden parametre (standardperioden: 30 kalenderdage inklusive i dag, `day`) og viser `totals` pr. valuta, så hver KPI kun har én definition og samme cache-post som `/me/sales` (✅ fase 20, trin 5; se [FRONTEND.md](FRONTEND.md#overview--fase-15)).

Fordi der ikke findes `/creators/{id}` i creator-scope, **kan Creator A ikke ændre et ID for at se Creator B**. For underressourcer med ID (fx `/me/orders/{order_id}`) tjekker service-laget ejerskab (`order indeholder mindst én linje for creator'en`). Svaret er **404** ved manglende ejerskab, ikke 403, så eksistensen af andres ressourcer ikke afsløres.

### Admin-scope: `/merchboard/v1/admin/...`

Permission: specifik capability pr. route (fx `merchboard_manage_creators`). Bruges kun, hvis en admin-skærm får behov for JS-interaktivitet. v1-admin er server-renderet og kræver ikke disse endpoints.

| Metode | Route | Capability |
| --- | --- | --- |
| `GET`/`POST` | `/admin/creators` | `merchboard_manage_creators` |
| `GET`/`PATCH` | `/admin/creators/{id}` | `merchboard_manage_creators` |

### Offentlig: alert-overlayets feed ✅ (fase 25, trin 25.3, ADR-065 pkt. 4)

`GET /merchboard/v1/alerts/overlay/{overlay_id}/feed` er pluginets eneste offentlige route (`permission_callback` = `__return_true`, besluttet i ADR-065). OBS kan ikke logge ind, så det hemmelige overlay-ID i stien er adgangen (32 hex-tegn, 128 bit, sammenlignet med `hash_equals()`).

| Parameter | Type | Betydning |
| --- | --- | --- |
| `after` | heltal ≥ 0, valgfri | Cursor: id'et på den sidste leverede alert. Uden `after` svares straks med creatorens nyeste alert-id som cursor og ingen alerts, så overlayet kun viser alerts fra nu af |
| `wait` | boolean, standard `true` | Long-poll: serveren tjekker hvert sekund i op til 8 sekunder, før den svarer uden alerts |

Svar `200`: `{ cursor, version, alerts: [ { id, image, text, secondary } ] }`, højst 20 alerts, ældste først. `text` og `secondary` er færdig tekst (skabelonen med variablerne udfyldt; `$FirstName` og `$UserName` giver kundens frivillige brugernavn eller "Anonymous"), `image` er en billed-URL på samme site eller `""`. `version` skifter, når indstillingerne eller ID'et ændres; siden genindlæses så. Ingen kunde- eller ordre-ID'er. Headers: `Cache-Control: no-store`, `X-Robots-Tag: noindex`.

Fejl: `404 merchboard_overlay_not_found` (ukendt eller ugyldigt ID, eller inaktiv creator; samme svar for alle), `429 merchboard_too_many_requests` (mere end 60 forespørgsler pr. overlay pr. minut; kun eksisterende overlays tælles).

### Integrations: `/merchboard/v1/integrations/{provider}/webhook` (fremtid)

Offentlige endpoints til eksterne tjenester. Permission består af **HMAC-signaturverifikation** med en secret fra `wp-config.php`, tidsstempel-/replay-check og streng payload-validering. Behandlingen er asynkron (Action Scheduler), og svaret kommer hurtigt.

Ingen konkrete integrationer er godkendt. Hver tjeneste kræver projektejerens eksplicitte godkendelse og en ADR. Dinero og Google Drive er fravalgt (ADR-016).

## Respons-konventioner

- JSON-objekter med `snake_case`-nøgler (som WordPress core).
- Beløb returneres som **strenge med fast decimalantal** plus `currency` (fx `{"amount": "149.0000", "currency": "DKK"}`). Aldrig floats. Formattering sker i klienten via `Intl.NumberFormat`.
- Datoer i ISO 8601 UTC (`2026-09-24T12:00:00Z`).
- Lister: `?page=&per_page=` (max 100), headers `X-WP-Total` og `X-WP-TotalPages` og et rent JSON-array som body. Se [Paginering](#paginering).
- Fejl: `WP_Error` med koder prefixet `merchboard_` (fx `merchboard_creator_inactive`), passende HTTP-status og **ingen interne detaljer** (SQL, stack traces, stier) i beskeden.

## Controller-basisklasse ✅ (fase 14)

`MerchBoard\Core\Http\RestController` (abstrakt, `NAMESPACE = 'merchboard/v1'`). Controllere ligger i modulerne (`Modules/<Navn>/Rest/`) og registreres af modulet på `rest_api_init`:

- `register_routes()`: hver route har en eksplicit `permission_callback` (aldrig `__return_true` for private data) og `args` med typer.
- `require_creator( CreatorContext ): Creator|WP_Error`: 401 `merchboard_unauthenticated` uden login, 403 `merchboard_forbidden` uden creator-adgang (også inaktiv creator og fejlet adgangstjek). Bruges både i permission callback og igen i endpointet, fordi `CreatorContext` ikke memoiserer.
- `require_capability( string )` til fremtidige admin-routes.
- `run( route, callback )`: `DatabaseException` og uventede exceptions logges via `Logger` (route og årsag, ingen request-data) og giver en generisk `500 merchboard_internal_error`.
- `error( code, message, status, data )`: `WP_Error` med `merchboard_`-kode, oversat generisk besked og `status`.
- `pagination_args()` og `paginated( items, total, Pagination )` (✅ fase 19, trin 1): argumenterne og svaret for liste-routes, se [Paginering](#paginering). `Core\Http\Pagination` er side og sidestørrelse (`from_request()`, `offset()`, `total_pages()`).

## Paginering

✅ Fase 19, trin 1. Projektets konvention for alle liste-routes:

| Parameter | Regel |
| --- | --- |
| `page` | Heltal ≥ 1, standard 1 |
| `per_page` | Heltal 1-100, standard 20 |

- Ugyldige værdier (fx `page=0`, `per_page=101` eller `per_page=abc`) afvises af WordPress' argumentvalidering med 400 `rest_invalid_param`. Der findes ingen projektspecifik fejlkode for paginering.
- **Body** er altid et rent JSON-array med sidens elementer (`[ … ]`), aldrig et objekt som `{ "items": [ … ] }`.
- **Headers:** `X-WP-Total` er det samlede antal elementer, og `X-WP-TotalPages` er `ceil( total / per_page )`. Ved total 0 er begge `0`, og body er `[]`.
- **Side efter sidste side:** en `page` større end antallet af sider (ved total > 0) giver **200** med `[]` og de samme totaler, ikke en fejl. Det afviger bevidst fra WordPress core (fx `rest_post_invalid_page_number`), så en liste, der skrumper mellem to requests, ikke giver en fejlside.
- Testet i `tests/Integration/Rest/RestPaginationTest.php` (rigtig REST-server og argumentvalidering) og `tests/Unit/Core/Http/PaginationTest.php`.

## Endpoints ✅ (fase 14)

Alle kræver en aktiv creator for den indloggede bruger (`CreatorContext`). Creator'en vælges aldrig ud fra URL, query, body eller bootstrap-data.

**`GET /merchboard/v1/me`** → 200

```json
{ "display_name": "Nova", "status": "active" }
```

**`GET /merchboard/v1/me/profile`** → 200 (alle platforme er altid med, `""` når ikke sat)

```json
{
  "display_name": "Nova",
  "bio": "Merch & music",
  "social_links": { "website": "https://nova.example", "instagram": "", "tiktok": "", "youtube": "", "x": "", "facebook": "" }
}
```

**`PATCH /merchboard/v1/me/profile`** (JSON-body, delvis opdatering) → 200 med samme form som `GET /me/profile`.

| Felt | Regel |
| --- | --- |
| `display_name` | Streng, trimmes, 1-191 tegn (samme regel som admin) |
| `bio` | Streng, ren tekst (tags fjernes med `sanitize_textarea_field`, linjeskift bevares), trimmes, maks. 1000 tegn. `""` sletter |
| `social_links` | Objekt med kun platformene `website`, `instagram`, `tiktok`, `youtube`, `x`, `facebook`. Hver værdi er `""` (slet) eller en `https`-URL med host, uden credentials, whitespace, kontroltegn eller `<>"'\`, maks. 200 tegn. Platforme, der ikke sendes, bevares |

- Andre felter (`status`, `user_id`, `id`, `creator_id` …) og query-parametre ignoreres.
- Ugyldige typer eller ukendte platforme giver 400 `rest_invalid_param` (argument-skema). Regelbrud giver 400 `merchboard_invalid_profile` med `data.fields` = felt → kode (`required`, `invalid`, `too_long`, `invalid_url`, fx `"social_links.x": "invalid_url"`). Input ekkoes aldrig. Ét ugyldigt felt afviser hele opdateringen, og intet gemmes.
- Uden reelle ændringer skrives intet. Ved ændringer skrives navn (creators-tabellen), bio og links (`merchboard_creatormeta`) og én audit-record `creator_updated` med `changed_fields` (actor = creatorens bruger) i én transaktion.

## `PATCH /me/preferences` ✅ (fase 21, trin 5, ADR-044)

Den indloggede brugers dashboard-tema. Adgang som de øvrige `/me`-routes (`CreatorContext`; 401 `merchboard_unauthenticated` udlogget, 403 `merchboard_forbidden` uden creator-adgang). Kræver ikke WooCommerce. Der findes ingen `GET` (404): det aktuelle valg står som `data-theme` på `<html>` i shellen.

**Body** (JSON eller form):

```json
{ "theme": "dark-blue" }
```

eller `{ "theme": null }`.

- **`theme`:** præcis én af `light`, `dark-black`, `dark-green`, `dark-blue`, `dark-red`, `dark-pink` (ADR-043), eller `null`.
- **`null`:** sletter user meta `merchboard_dashboard_theme`, så dashboardet følger systemets `prefers-color-scheme` igen. Intet om systemets valg gemmes.
- **Kun body'en tæller:** query-parametre og andre felter (`user_id`, `creator_id` …) ignoreres. Der skrives kun for `get_current_user_id()`.
- **Ugyldig værdi:** 400 `rest_invalid_param` (argument-skemaet: forkert type eller ukendt tema) eller 400 `merchboard_invalid_preferences` med `data.fields.theme = "invalid"` (`theme` mangler, eller værdien afvises af endpointets egen kontrol). Intet gemmes, og input ekkoes ikke. `required` bruges ikke i skemaet, fordi WordPress behandler `null` som manglende.
- **Ingen audit og ingen caching:** en personlig UI-præference uden forretnings- eller sikkerhedsbetydning.

**Svar** → 200: den gemte værdi, læst tilbage efter skrivningen:

```json
{ "theme": "dark-blue" }
```

eller `{ "theme": null }`.

## `GET /me/products` ✅ (fase 19, trin 2, ADR-039)

Findes kun, når WooCommerce-modulet er indlæst (uden WooCommerce: 404 `rest_no_route`). Adgang som de øvrige `/me`-routes (401, 403, inaktiv creator 403). Paginering efter [konventionen](#paginering) med `page` og `per_page`; andre query-parametre ignoreres, og creator'en kommer kun fra `CreatorContext`. Ingen søgning, filtre eller caching.

**Udvælgelse:** parent-produkter (`post_type = product`, aldrig varianter) med status `publish` eller `private`, som har præcis én koblingsrække `_merchboard_creator_id`, og den er creator'ens ID (sammenlignet byte for byte). Sorteret efter navn og derefter ID.

```json
[
  {
    "id": 12,
    "name": "Tour tee",
    "status": "publish",
    "price": { "min": "149.00", "max": "199.00", "currency": "DKK", "tax": "store_setting" },
    "stock_status": "instock",
    "image": { "url": "https://shop.example/wp-content/uploads/tee-150x150.jpg", "alt": "Tour tee" }
  }
]
```

| Felt | Regel |
| --- | --- |
| `name` | Det aktuelle produktnavn som ren tekst (tags fjernet, entities afkodet) |
| `price` | Den aktive WooCommerce-pris som strenge: `get_price()` for simple produkter (`min` = `max`), min og max af de aktive variationspriser for variable produkter. Ingen momsberegning; grundlaget er butikkens indstilling (`tax: "store_setting"`). Uden aktiv pris: `null` (ADR-039, præcisering af P7) |
| `stock_status` | `instock`, `outofstock` eller `onbackorder`; andre værdier giver `null`. Aldrig lagerantal |
| `image` | Thumbnail med samme origin som sitet (scheme, host og port mod `home_url()`), `alt` = produktnavnet; ellers `null`. CSP'en er uændret |

## `GET /me/orders` ✅ (fase 19, trin 4, ADR-039)

Findes kun, når WooCommerce-modulet er indlæst (uden WooCommerce: 404 `rest_no_route`; ledger-data bevares). Adgang som de øvrige `/me`-routes (401, 403, inaktiv creator 403). Paginering efter [konventionen](#paginering) med `page` og `per_page`; andre query-parametre ignoreres, og creator'en kommer kun fra `CreatorContext`. Ingen søgning, filtre, detalje-route eller caching.

**Udvælgelse og sortering:** sales ledgeren (`SalesOrderLines`) alene: creator'ens ordrelinjer (filter på `creator_id` i SQL), én række pr. ordrelinje, nyeste første salgsdato først og derefter højeste `line_id`. En ordre med linjer fra flere creators giver kun creator'ens egne linjer. Kun sidens ordrer og produkter slås op i WooCommerce (højst ét opslag pr. ordre og pr. produkt).

```json
[
  {
    "line_id": 4101,
    "order_number": "1041",
    "date": "2026-08-15T08:30:00Z",
    "status": "processing",
    "status_label": "Processing",
    "product_name": "Tour tee",
    "quantity": 2,
    "refunded": false,
    "counted": true
  }
]
```

| Felt | Regel |
| --- | --- |
| `line_id` | Ordrelinjens ID (`order_item_id`) |
| `order_number` | WooCommerce' ordrenummer (`get_order_number()`); ordre-ID'et for en slettet ordre |
| `date` | `occurred_at` for linjens første salgsrække, ISO 8601 UTC |
| `status`, `status_label` | WooCommerce' status og label; `trashed`/"Trashed" for en ordre i papirkurven; `deleted`/"Deleted" for en permanent slettet ordre (ADR-039 P4) |
| `product_name` | Det aktuelle navn på varianten (når linjen har en) eller produktet; ledgerens `item_name`, når det ikke findes. Aldrig parent-navnet for en manglende variant. Ren tekst (P6) |
| `quantity`, `refunded`, `counted` | Linjens saldo og markeringer efter ADR-039 P2 |

Svaret indeholder aldrig kundedata (navn, e-mail, telefon, adresse, by, postnummer, land, noter, IP, betaling, kunde- eller bruger-ID), beløb eller ordre-, produkt-, variant- eller creator-ID'er (P3, P5).

## `GET /me/sales` ✅ (fase 20, trin 3, ADR-040)

Findes kun, når WooCommerce-modulet er indlæst (uden WooCommerce: 404 `rest_no_route`; ledger-data bevares). Adgang som de øvrige `/me`-routes (401 udlogget, 403 uden creator-adgang, inaktiv creator 403). Creator'en kommer kun fra `CreatorContext`; andre query-parametre (fx `creator_id`) ignoreres. Ingen paginering. Svaret caches (se [Caching](#caching-af-mesales)).

**Kilde:** sales ledgeren `merchboard_sales` alene (`SalesStatistics`), uafhængigt af HPOS eller legacy-ordrelager. Kun de viste produkters navne slås op i WooCommerce (højst ét opslag pr. unikt produkt-ID, aldrig for produkt-ID 0).

### Parametre

| Parameter | Regel | Standard |
| --- | --- | --- |
| `from` | Dato `YYYY-MM-DD` i sitets tidszone, inklusive | 29 dage før `to` |
| `to` | Dato `YYYY-MM-DD` i sitets tidszone, inklusive | I dag i sitets tidszone |
| `interval` | `day`, `week` eller `month` | `day` |

Uden parametre er perioden altså de seneste 30 dage inklusive i dag, dag for dag.

**Tidszone og perioder:** datoerne fortolkes i sitets tidszone (`wp_timezone()`, angivet i svaret som `timezone`). Internt bliver de til det halvåbne UTC-interval `[from 00:00 lokal tid, dagen efter to 00:00 lokal tid)`; grænserne beregnes i PHP (sommertid håndteres, og et døgn kan være 23 eller 25 timer), aldrig med `CONVERT_TZ()`. Ledgerens `occurred_at` bruges uændret (ADR-038): en refund tæller på refundens dato, ikke på salgsdagen. `week` er ISO-uger (mandag–søndag) og `month` kalendermåneder; første og sidste delperiode kan være delvise, og `from`/`to` udvides ikke.

**Validering:**

| Tilfælde | Svar |
| --- | --- |
| Ugyldigt datoformat eller ugyldig dato (fx `2026-9-01`, `2026-02-30`, tom streng, tidspunkt) | 400 `rest_invalid_param` (WordPress' argumentvalidering) |
| Ukendt `interval` (fx `year`, `DAY`) | 400 `rest_invalid_param` |
| `from` efter `to` (også `from` efter i dag uden `to`) | 400 `merchboard_invalid_period` |
| `to` på eller efter `from` + 5 år (fx `2020-01-01`–`2025-01-01`) | 400 `merchboard_invalid_period` |
| Mere end 366 delperioder (fx 367 dage med `day`) | 400 `merchboard_invalid_period` |

`merchboard_invalid_period` har en generisk besked og gentager ikke input.

### Svar

```json
{
  "from": "2026-09-01",
  "to": "2026-09-30",
  "interval": "day",
  "timezone": "Europe/Copenhagen",
  "currencies": [
    {
      "currency": "DKK",
      "totals": {
        "net_sales": "1234.0000",
        "sales": "1500.0000",
        "adjustments": "-266.0000",
        "units": 12,
        "orders": 7
      },
      "series": [
        { "start": "2026-09-01", "end": "2026-09-01", "net_sales": "0.0000", "units": 0, "orders": 0 }
      ],
      "products": [
        { "product_id": 12, "name": "Tour tee", "net_sales": "500.0000", "units": 5 }
      ],
      "truncated": false
    }
  ]
}
```

| Felt | Regel |
| --- | --- |
| `from`, `to`, `interval`, `timezone` | Den anvendte periode (efter standardværdier) og sitets tidszone |
| `currencies` | Én blok pr. valuta med data i perioden, sorteret alfabetisk. Ingen omregning og ingen samlet total på tværs af valutaer. En periode uden data giver 200 med `currencies: []` |
| `totals.sales` | Summen af `sale`-rækker |
| `totals.adjustments` | Summen af alle andre rækker (refund, reversal, korrektion); typisk negativ |
| `totals.net_sales` | `sales + adjustments` (kan være negativ i en periode med flere refunds end salg) |
| `totals.units` | Summen af antal over alle rækker (med fortegn; kan være negativ) |
| `totals.orders` | Antal unikke ordrer med mindst én `sale`-række i perioden; refunds, reversals og korrektioner ændrer det ikke, og det følger ikke ordrens nuværende tællestatus. Tælles over hele perioden, ikke som summen af serien |
| `series` | Tæt: alle delperioder i rækkefølge, også før første og efter sidste hændelse, med nuller (`"0.0000"`, 0, 0) uden data. `start` og `end` er delperiodens første og sidste lokale dato (inklusive). `orders` tælles pr. delperiode |
| `products` | Grupperet på parent-produkt (`product_id`; varianter samles under deres produkt), sorteret efter `net_sales` faldende og derefter `product_id`. Højst 100 pr. valuta; totalerne omfatter altid alle produkter |
| `products[].name` | Produktets aktuelle navn som ren tekst, når det findes; ellers ledgerens `item_name`-snapshot fra produktets seneste række i perioden (ADR-039 P6). `product_id` 0 er gruppen af rækker uden produkt: den slås aldrig op og får snapshot-navnet. Tomt navn: "Unknown product" (oversat) |
| `truncated` | `true`, når valutaen har mere end 100 produkter i perioden |

### Caching af `/me/sales`

✅ Fase 20, trin 4 (ADR-040 pkt. 10). Hele svaret caches i WordPress' object cache (`wp_cache_*`), gruppe `merchboard_sales`, i 15 minutter; aldrig transients, aldrig databasen. Uden persistent object cache gælder cachen kun den enkelte request. Cachen ændrer ikke svaret: et hit giver præcis samme JSON som en ny beregning, uden ledger-forespørgsler.

- **Nøgle:** `report:<creator-ID>:<generation>:<md5 af formatversion, from, to, interval, tidszone og ugekonvention (ISO)>` med de anvendte værdier efter standardværdier (under 100 tegn). Creator-ID'et kommer fra `CreatorContext`, så en post kan aldrig deles mellem creators. Valuta er ikke en del af nøglen; én post rummer alle valutaer.
- **Generation pr. creator:** nøglen `generation:<creator-ID>` i samme gruppe. Uden gemt generation er den `1`. Efter hver committet `SalesRepository::write()` får hver creator med indsatte ledger-rækker én ny generation (et tilfældigt 64-bit token, ikke en optælling), så alle ældre poster bliver uopnåelige. Det dækker live-synkronisering, refunds, reversals, korrektioner, sletning af refunds og ordrer og backfill, som alle skriver gennem `write()`. Statusskift uden nye rækker, omdøbte produkter, priser og lager invaliderer ikke (et nyt produktnavn ses senest efter 15 minutter).
- **Kapløb:** ved et miss læses generationen før ledgeren, og resultatet gemmes kun, hvis generationen er uændret bagefter. En skrivning, der committes undervejs, giver derfor ikke en forældet post under den nye generation. Se [WOOCOMMERCE.md](WOOCOMMERCE.md#caching-af-salgsstatistik) for de resterende grænser.

Beløb er decimalstrenge med fire decimaler, aldrig floats; der beregnes ingen moms, visningspris, revenue split eller afregning. Svaret indeholder aldrig kundedata (navn, e-mail, telefon, adresse, IP, betaling, kunde- eller bruger-ID), ordre-, ordrelinje-, variant- eller creator-ID'er.

## Profit i `/me/products`, `/me/orders` og `/me/sales` ✅ (fase 22, ADR-050)

Creatorens realiserede profit efter ADR-049 (salgspris inkl. moms × den historiske markup, pr. ledger-række, afrundet til butikkens præcision). Profit er ikke udbetaling og ikke et salgstal. Alle tre endpoints bruger samme aggregering (`CreatorProfits`) og samme objekt:

```json
{ "currency": "DKK", "status": "complete", "amount": "29.8000" }
```

| `status` | Betydning | `amount` |
| --- | --- | --- |
| `complete` | Alle relevante ledger-rækker har de nødvendige historiske data | Summen (decimalstreng med fire decimaler; kan være `0.0000` eller negativ) |
| `incomplete` | Nogle rækker mangler data (intet snapshot med andel eller ingen gemt moms) | `null` |
| `unknown` | Ingen række har data | `null` |

En ufuldstændig eller ukendt profit sendes aldrig som et beløb og vises aldrig som 0. Kun rækker med creatorens `creator_id` tæller; et creator-ID fra klienten ignoreres.

| Endpoint | Felt | Indhold |
| --- | --- | --- |
| `/me/products` | `profit` | Liste med ét objekt pr. valuta for produktet og dets variationer (al tid), eller `null`, når creator'en ikke har salg af produktet |
| `/me/orders` | `profit` | Linjens profit over alle dens rækker (salg, refunds, reversals og korrektioner med fortegn) |
| `/me/orders` | `order_profit` | Summen af creatorens linjer i samme ordre (også linjer på andre sider) |
| `/me/orders` | `order_lines` | Antal af creatorens linjer i ordren |
| `/me/sales` | `currencies[].profit` | Profit for de samme rækker og samme periode som `totals` |

De eksisterende felter, paginering og sortering er uændrede. `/me/sales`-cachens format er `v2` (ADR-050).

## Salg inkl. moms i `/me/sales` ✅ (fase 22, ADR-053)

Additive felter; de eksisterende beløb ekskl. moms er uændrede.

| Felt | Indhold |
| --- | --- |
| `currencies[].totals.net_sales_incl_vat`, `sales_incl_vat`, `adjustments_incl_vat` | Samme summer plus hver rækkes gemte faktiske moms (`merchboard_sales_tax`), decimalstreng; `null`, når en række i perioden ikke har gemt moms i rækkens valuta |
| `currencies[].vat` | `{ "status": "complete" \| "incomplete", "missing_rows": <antal rækker uden gemt moms> }`; `complete` præcis når `missing_rows` er 0 og beløbene inkl. moms ikke er `null` |
| `currencies[].series[].net_sales_incl_vat` | Periodens beløb inkl. moms ("0.0000" uden rækker), eller `null` for hele valutaen, når den er `incomplete` (aldrig en blanding) |
| `currencies[].products[].net_sales_incl_vat` | Produktets beløb inkl. moms, eller `null`, når en af dets rækker mangler moms |

Manglende moms regnes aldrig som 0. Dashboardet viser beløbene inkl. moms, når `vat.status` er `complete`, og ellers beløbene ekskl. moms med tydelig mærkning og en forklaring. `/me/sales`-cachens format er `v3`.

## Test-krav

Hvert creator-endpoint skal have integrationstests for mindst:

1. uautentificeret → 401
2. bruger uden creator-adgang → 403
3. inaktiv creator → 403
4. Creator A kan ikke se eller ændre Creator B's ressourcer (IDOR) → 404
5. ugyldigt input → 400 med ændringer ikke gemt

✅ Fase 14: `tests/Integration/Rest/MeEndpointTest.php` dækker punkt 1-5 for `/me` og `/me/profile` (IDOR: ID'er i query og body ignoreres, og en anden creator er uændret), plus route-registrering med permission callbacks, præcise svarfelter, deaktivering på næste request, databasefejl (403 uden detaljer, logget) og fejlet skrivning (500, rollback).

✅ Fase 21, trin 5: `MeEndpointTest` dækker `PATCH /me/preferences`: alle seks temaer, `null`, ugyldige værdier (400, intet ændret), tom body og query-parameter (400), 401/403 for alle afviste brugertyper, brugerisolation (ID'er i body og query ignoreres, en anden brugers tema er uændret), ingen audit og ingen `GET`-route.

✅ Fase 23, trin 23.4: `tests/Integration/Rest/MePayoutsEndpointTest.php` dækker `/me/payouts` (alle svar `no-store, private`; brugere uden creator-adgang på alle routes; `creator_id` i body ignoreres; IDOR ved annullering; idempotens; valideringsfejl og afvisninger med status; ukendt saldo; loft; manglende nøgle; kun maskerede oplysninger).

✅ Fase 20, trin 3: `tests/Integration/Rest/MeSalesEndpointTest.php` dækker punkt 1-5 for `/me/sales` (IDOR: `creator_id` og andre ID'er i query ignoreres; en ordre med linjer fra to creators), plus standardperiode, lokale datoer og sommertid, `day`/`week`/`month`, tæt serie, valutaer, tom periode, produktgrænse og sortering, navne-fallback, beløb som strenge, ingen `/me/overview` og ingen persondata. `SalesQueryTest`, `SalesPeriodsTest` og `WooCommerceSalesProductNamesTest` er unit-tests.
