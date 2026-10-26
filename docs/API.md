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

Permission: `CreatorContext::current()` (✅ fase 8) skal returnere en creator. Den håndhæver hele reglen: brugeren skal have `merchboard_access_dashboard` **og** en **aktiv** creator-record koblet til sig. Permission callbacks bruger `CreatorContext::current_user_has_access()` og gentager ikke reglen selv. Fra trin 27.2 (ADR-075) svarer `/me` også for den creator, en administrator "ser som"; under en sådan session afviser `ImpersonationGuard` udbetalinger, gavekortkoder, chatbot-forbindelse og YouTube-vilkår, nye API-nøgler og webhook-hemmeligheder med `403 merchboard_impersonation_blocked`, og `/me/alerts/overlay` sender OBS-URL'en uden det hemmelige id.

| Metode | Route | Formål | Fase |
| --- | --- | --- | --- |
| `GET` | `/me` | ✅ Den aktuelle creator: `display_name`, `status`. Rettigheder og sektioner kommer fortsat fra bootstrap-data (sektion-registry i fase 15). | 14 |
| `GET` | `/me/profile` | ✅ Profilen: `display_name`, `fields`, `category`, `pending`, `rejected` (fra 28.3, ADR-077) | 14, 28 |
| `PATCH` | `/me/profile` | ✅ Foreslå navn, beskrivelse, banner-link og sociale links, eller fjernelse af et billede; intet ændres før en administrators godkendelse (ADR-077) | 14, 28 |
| `POST` | `/me/profile/files/{image\|banner\|banner_mobile}` | ✅ Foreslå et billede (multipart `file`) | 28 |
| `GET` | `/me/profile/files/{id}` | ✅ Et af creatorens egne foreslåede billeder, der venter (selve billedet, `no-store`, `nosniff`) | 28 |
| `DELETE` | `/me/profile/proposals/{field}` | ✅ Træk et ventende forslag tilbage | 28 |
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

Permission: `CreatorContext::current()` (✅ fase 8) skal returnere en creator. Den håndhæver hele reglen: brugeren skal have `merchboard_access_dashboard` **og** en **aktiv** creator-record koblet til sig. Permission callbacks bruger `CreatorContext::current_user_has_access()` og gentager ikke reglen selv. Fra trin 27.2 (ADR-075) svarer `/me` også for den creator, en administrator "ser som"; under en sådan session afviser `ImpersonationGuard` udbetalinger, gavekortkoder, chatbot-forbindelse og YouTube-vilkår, nye API-nøgler og webhook-hemmeligheder med `403 merchboard_impersonation_blocked`, og `/me/alerts/overlay` sender OBS-URL'en uden det hemmelige id.

| Metode | Route | Formål | Fase |
| --- | --- | --- | --- |
| `GET` | `/me` | ✅ Den aktuelle creator: `display_name`, `status`. Rettigheder og sektioner kommer fortsat fra bootstrap-data (sektion-registry i fase 15). | 14 |
| `GET` | `/me/profile` | ✅ Profilen: `display_name`, `fields`, `category`, `pending`, `rejected` (fra 28.3, ADR-077) | 14, 28 |
| `PATCH` | `/me/profile` | ✅ Foreslå navn, beskrivelse, banner-link og sociale links, eller fjernelse af et billede; intet ændres før en administrators godkendelse (ADR-077) | 14, 28 |
| `POST` | `/me/profile/files/{image\|banner\|banner_mobile}` | ✅ Foreslå et billede (multipart `file`) | 28 |
| `GET` | `/me/profile/files/{id}` | ✅ Et af creatorens egne foreslåede billeder, der venter (selve billedet, `no-store`, `nosniff`) | 28 |
| `DELETE` | `/me/profile/proposals/{field}` | ✅ Træk et ventende forslag tilbage | 28 |
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
| `POST` | `/me/discount-code/reset` | ✅ Erstatter creatorens kode med en ny (ADR-078): den gamle virker ikke længere med det samme (kuponen flyttes til papirkurven). Svar `{ "code": "nova-7k3qxp" }` med `Cache-Control: no-store` (navn + 6 tilfældige tegn, små bogstaver). Ingen parametre; kun med en aktiv kode og højst 3 nye koder inden for de seneste 24 timer (også admins oprettelser tæller). Afvisning: 400 `merchboard_code_refused` med `data.reason` (`reset_limit`, `no_active_code`, `busy`, `coupon_not_created`, `coupon_not_retired`, `code_taken`, `gift_card_unverified`); en anden creator 403. Også under "Se som creator" (ADR-075). Kun med WooCommerce | 1.3.0 |
| `GET` | `/me/alerts/overlay` | ✅ Creatorens alert-overlay (ADR-065): `{ overlay, alerts_enabled, merchalert_active, fonts }`, hvor `overlay` er `{ url, settings, layout, font_approved }` eller `null` før opsætningen. `url` er OBS-URL'en med det hemmelige overlay-ID; `settings` er formularens felter (`template`, `secondary_template`, `font_size`, `font_weight`, `text_color`, `text_shadow`, `text_align`, `text_fit` (fra trin 25.6.5: `fixed`, `shrink` eller `grow`; ukendte værdier bliver `fixed`), `image_size`, `bg_color`, `bg_opacity`, `border_radius`, `padding`, `anim_duration`, `display_duration`, `show_secondary`, fra trin 25.6.8 `display_from_sound` (boolean), fra trin 25.6.9 `animations` (`{ main_text, secondary_text }`, hver `{ in, effect, out, color, speed }`; ukendte værdier bliver standarden fade/none/fade), fra trin 25.6.10 `text_fills` (`{ main_text, secondary_text }`, hver `{ type: none|preset|image, preset, image, motion: none|pan|fall }`), fra trin 25.6.11 `text_frame` (`{ enabled, direction: clockwise|counterclockwise, speed: still|slow|normal|fast, repeat }`; hovedteksten rundt om billedet), fra trin 25.5 `font_family`: `inherit` eller en familie); `layout` er designerens `{ elements: { product_image, main_text, secondary_text }, canvas: { snap_grid, grid_size } }`, hvor hver boks er `{ x, y, w, h, visible, lock_aspect, border_radius }` i pixels på 1920 × 1080 (trin 25.5); `font_approved` er `false`, når den gemte font ikke længere er godkendt (overlayet viser så standardfonten). `fonts` er de godkendte fonte `[{ family, url }]` med Google Fonts-stylesheetets URL (ADR-067). Fra trin 25.6 har `overlay` også `audio`: `{ enabled, default, categories: [{ term_id, name, sound }], max_bytes, max_seconds }`, hvor `default` og `sound` er `{ url, name, seconds, library_id }` eller `null` (`library_id` er bibliotekslydens ID, `0` for creatorens egen fil); kun creatorens egne lydfiler og bibliotekets lyde på samme site vises, og `categories` er creatorens egne kategorier. `library` (trin 25.6.3) er bibliotekets lyde `[{ id, url, name, seconds }]`. `Cache-Control: no-store` | 25 |
| — | (alle `/me/alerts/overlay`-svar) | ✅ Trin 25.9.6: svaret har også `page`, administratorens opsætning af siden (samme form som `/admin/alerts/creator-page`), som dashboardet lægger kortene og felterne efter. Det globale overlays og standard-overlays' svar har det ikke | 25 |
| `POST` | `/me/alerts/overlay` | ✅ Opretter overlayet med MerchAlerts standarder (idempotent; audit `alert_overlay_created`). Samme svar som `GET` | 25 |
| `PATCH` | `/me/alerts/overlay` | ✅ Gemmer formularens felter fra JSON- eller form-body (ukendte nøgler ignoreres). Fra trin 25.5 også `font_family` (`inherit` eller en godkendt font; ellers 400 `merchboard_invalid_request` med `data.fields.font_family` = `not_approved`) og `layout` (hele designerens layout som objekt; normaliseres, ukendte elementer forsvinder). Fra trin 25.6 også `audio_enabled` (boolean; afspil lyde) og fra trin 25.6.8 `display_from_sound` (boolean; en alert med lyd vises så længe lyden spiller) og fra trin 25.6.9 `animations` (objekt; normaliseres, ellers 400) fra trin 25.6.11 `text_frame` (objekt; normaliseres, ellers 400) og fra trin 25.6.10 `text_fills` (objekt; normaliseres, ellers 400; et billede, som hverken er creatorens eget eller i biblioteket, ryddes til `type: none`, `image: 0`). Værdier normaliseres (fx clampes tal til grænserne), så svaret viser det gemte. Forkert type: 400 `merchboard_invalid_request`; uden overlay: 404 `merchboard_not_found` | 25 |
| `POST` | `/me/alerts/overlay/regenerate` | ✅ Nyt hemmeligt overlay-ID; den gamle URL virker ikke længere (audit `alert_overlay_id_regenerated`, uden ID'er). 404 uden overlay | 25 |
| `POST` | `/me/alerts/overlay/sounds/{slot}` | ✅ Trin 25.6: uploader en lyd (multipart, feltet `file`), eller (trin 25.6.3) vælger en lyd fra administratorens bibliotek med JSON `{ "library": id }` (ukendt: 400 med `data.fields.library` = `unknown_sound`; en bibliotekslyd slettes aldrig af creatoren) til `slot` = `default` (standardlyden) eller et term-ID blandt creatorens kategorier; den tidligere lyd i samme slot slettes. MP3, WAV eller OGG, inden for administratorens grænser (trin 25.9.2; creatorens egne, hvis administratoren har givet creatoren sådanne, ellers standardgrænserne; som standard højst 10 MB, eller sitets mindre upload-grænse, og 30 sekunder; `GET` sender dem som `audio.max_bytes` og `audio.max_seconds`); filen skal kunne læses som lyd i det format, endelsen angiver. Afvist: 400 `merchboard_invalid_request` med `data.fields.file` = `no_file`, `invalid_type`, `too_large`, `too_long` eller `unreadable`, eller `data.fields.slot` = `unknown_category`; intet gemmes. Samme svar som `GET`. 404 uden overlay | 25 |
| `DELETE` | `/me/alerts/overlay/sounds/{slot}` | ✅ Trin 25.6: fjerner lyden fra slottet og sletter filen, hvis creatoren ejer den, og intet andet slot bruger den. Samme svar som `GET`. 404 uden overlay | 25 |
| `POST` | `/me/alerts/overlay/images` | ✅ Trin 25.6.10: uploader et eget billede til tekstfyld (multipart, feltet `file`; PNG, JPG, GIF eller WebP, højst 5 MB og 4096 × 4096 pixels, højst 10 pr. creator). Samme svar som `GET`, hvor `overlay.images` er `{ own, library, max_bytes, max_pixels, max_own }` (`own`/`library`: `[{ id, url, name, width, height }]`). 400 `merchboard_invalid_request` med `data.fields.file` (`invalid_type`, `too_large`, `too_big`, `unreadable`, `own_full`, `no_file`); 404 før opsætning | 25 |
| `GET` | `/me/alerts/history` | ✅ Trin 25.7: creatorens ordrer med alerts, nyeste først, pagineret (array-body, `X-WP-Total`, `X-WP-TotalPages`; `per_page` standard 10). Hver ordre: `{ order_id, order_number, username, created_at, alerts: [{ id, product, variation, quantity, created_at }] }` (kun det, en alert viser; tider i ISO 8601 UTC). Kun creatorens egne linjer, også i en ordre med flere creators. `Cache-Control: no-store` | 25 |
| `POST` | `/me/alerts/history/alerts/{id}/resend` | ✅ Trin 25.7: viser en af creatorens alerts igen i overlayet (via køen som testalerts). Fra 25.13 sendes den igen på hver af creatorens kanaler for sig: overlayet, API-nøglerne (`kind: resend`), webhooks (`type: resend`) og chatbotten, så en creator uden overlay stadig gensender til de andre. `{ "sent": 1 }`; 404 for en alert, creatoren ikke ejer, og 404 "Set up your overlay first." kun, når alerten ikke når nogen kanal | 25 |
| `POST` | `/me/alerts/history/orders/{id}/resend` | ✅ Trin 25.7: viser creatorens alerts af en ordre igen, fra 25.13 pr. kanal som ovenfor. `{ "sent": n }` (antal alerts); 404 uden creatorens alerts i ordren, og 404 "Set up your overlay first." kun, når ingen kanal nås | 25 |
| `POST` | `/me/alerts/stop` | ✅ Trin 25.7: "Stop alle alerts": tømmer køen af gensendelser og testalerts og giver overlayet en ny version, så den åbne overlay-side genindlæser sig straks (feedets long-poll svarer med det samme). Alerts, siden allerede har hentet, forsvinder med genindlæsningen; fra 25.13 beholder siden sin cursor, så køb, den endnu ikke har hentet, stadig vises bagefter. `{ "stopped": true }`; 404 før opsætning | 25 |
| `GET`, `PATCH` | `/me/alerts/chatbot` | ✅ Trin 25.8, 25.8.2 og 25.8.4 (ADR-066, ADR-069, ADR-070): creatorens chatbot på Twitch, Kick og YouTube. `{ mode, item_format, separator, variables, template, default_template, platforms: [ { platform, label, available, bot_login, enabled, channel: { login, url, connected_at } \| null, last_message: { status, reason, at } \| null } ] }` (platformene i rækkefølgen Twitch, Kick, YouTube; `available` er `false`, før en administrator har sat platformen op; for YouTube er `login` kanalens navn og `url` `https://www.youtube.com/channel/{id}`; `last_message.reason` `not_live` betyder, at creatoren ikke var live: beskeden tjekkes igen efter 5 minutter eller er sprunget over; `terms_not_accepted`, at creatoren ikke har godkendt den gældende version af vilkårene; fra trin 25.8.5 har hver platform `terms: { privacy_url, terms_url, version, accepted } \| null`, kun for YouTube og kun når privatlivspolitikken og vilkårene er offentliggjort, ADR-071). `PATCH { template?, enabled?: { twitch?, kick?, youtube? } }`: den fælles skabelon bliver ren tekst på én linje (højst 500 tegn; `""` = standard), beskeder til og fra pr. platform; en ukendt platform, en ikke-boolsk værdi eller en ikke-tekst `template`: 400. Tokens returneres aldrig. `Cache-Control: no-store` | 25 |
| `POST` | `/me/alerts/chatbot/{platform}/connect` | ✅ Trin 25.8, 25.8.2 og 25.8.4: `{ url }` til platformens godkendelsesside (`twitch`: `channel:bot`; `kick`: `channel:read chat:write events:subscribe` med PKCE; `youtube`: `youtube.readonly` hos Google med PKCE og offline-adgang; kræver body `{ "accept_terms": true }`, ellers 400 `merchboard_terms_required`, og gemmer creatorens godkendelse, ADR-071), hvor creatoren tilføjer botten til sin kanal (enkeltbrugs-state i 10 minutter, bundet til brugeren og creatoren). 409 `merchboard_chatbot_unavailable`, før platformen er sat op | 25 |
| `DELETE` | `/me/alerts/chatbot/{platform}/channel` | ✅ Trin 25.8, 25.8.2 og 25.8.4: fjerner botten fra creatorens kanal på platformen (Kick og YouTube: tilbagekalder og sletter creatorens tokens; afslutter abonnementet og sletter kanalens chatlog; audit). Svarer med chatbot-tilstanden | 25 |
| `POST` | `/me/alerts/chatbot/{platform}/test` | ✅ Trin 25.8, 25.8.2 og 25.8.4: sender en testbesked (testkøb fra "TestFan") til creatorens kanal på platformen. `{ result, reason, message }` (`result`: `sent`, `dropped`, `rate_limited`, `unauthorized`, `forbidden`, `failed` eller for YouTube `not_live`, når creatoren ikke er live; YouTube giver desuden `rate_limited`/`quota_exceeded`, når dagens kvote er brugt op, og `forbidden`/`live_not_allowed`, når Google ikke lader creatorens konto se sine livestreams). En sendt YouTube-testbesked sender også de beskeder, der venter på livestreamen. 409 uden kanal eller opsætning; 429 `merchboard_too_many_requests` ud over 10 testbeskeder pr. minut pr. creator (25.13) | 25 |
| `POST` | `/me/alerts/chatbot/youtube/terms` | ✅ Trin 25.8.5 (ADR-071): body `{ "accept": true }` godkender den gældende version af YouTube-chatbottens privatlivspolitik og vilkår (user meta og audit). Svarer med chatbot-tilstanden. 400 uden `accept: true` (`merchboard_terms_required`) eller for en anden platform; 409, før teksterne er offentliggjort | 25 |
| `GET` | `/me/news` | ✅ Trin 25.11 (ADR-072): `{ items: [ { id, title, body, published_at, seen_at, closed_at } ] }`: de udgivne beskeder fra efter creatorens oprettelse, nyeste først; `body` er renset HTML (allow-list), tiderne ISO 8601 eller `null` | 25 |
| `POST` | `/me/news/{id}/seen`, `/me/news/{id}/closed` | ✅ Trin 25.11: noterer, at creatoren har set eller lukket beskeden (første tidspunkt bevares; "lukket" noterer også "set"). Samme svar som `GET`. 404 for en besked, creatoren ikke ser (kladde, tilbagetrukket, fra før oprettelsen) | 25 |
| `GET` | `/me/alerts/api` | ✅ Trin 25.12.1 (ADR-073); fra 25.12.4 også `access: { keys, webhooks }` (administratorens adgang; uden adgang giver oprettelse og test 403 `merchboard_alerts_api_no_access`, feedet 401, og webhooks sendes ikke): `{ enabled, endpoint, limit, max_keys, keys: [ { id, name, hint, created_at, last_used_at } ], max_webhooks, webhooks, deliveries }` (`webhooks` og `deliveries` som under `POST /me/alerts/api/webhooks`, de seneste 20 leveringer); `hint` er starten af nøglen | 25 |
| `POST` | `/me/alerts/api/keys` | ✅ Trin 25.12.1: `{ "name": "…" }` (højst 60 tegn) → 201 med samme svar plus `key` (hele nøglen, vises kun her). 400 ugyldigt navn, 403 `merchboard_alerts_api_disabled`, 409 `merchboard_alerts_api_key_limit` (højst 5) | 25 |
| `DELETE` | `/me/alerts/api/keys/{id}` | ✅ Trin 25.12.1: tilbagekalder en af creatorens nøgler; samme svar som `GET`. 404 for en anden eller tilbagekaldt nøgle | 25 |
| `POST` | `/me/alerts/api/test` | ✅ Trin 25.12.1: en testhændelse til hver af creatorens nøgler, `{ "sent": antal nøgler }`. 403 når API'en er slået fra | 25 |
| `POST` | `/me/alerts/api/webhooks` | ✅ Trin 25.12.2: `{ name, format: "json"\|"discord", url, secret, template }` → 201 med siden (`max_webhooks`, `webhooks: [ { id, name, format, host, hint, template, enabled, paused, failures, created_at, last_delivery_at } ]`, `deliveries`) og `secret`, når Merch Dashboard lavede signeringsnøglen (vises kun her). 400 ugyldigt felt (`fields.name\|format\|url\|secret\|template`), 403 slået fra, 409 `merchboard_alerts_webhook_limit` (højst 3), 503 `merchboard_alerts_webhook_unavailable` (ingen sodium) | 25 |
| `PATCH`, `DELETE` | `/me/alerts/api/webhooks/{id}` | ✅ Trin 25.12.2: `PATCH` med `{ name?, template?, enabled?, resume? }` (`resume` genoptager efter pause og nulstiller fejl); `DELETE` sletter webhooken og dens log. 404 for en anden creators webhook | 25 |
| `POST` | `/me/alerts/api/webhooks/test` | ✅ Tilføjelse efter 25.12.2 (projektejerens ønske 2026-10-24): et testkøb til hver webhook, der er slået til (også en på pause), med det samme; siden plus `tested: [ deliveries ]`. Tests tæller ikke med i pausen. 403 når API'en er slået fra; 429 `merchboard_too_many_requests` ud over 10 webhook-tests pr. minut pr. creator (25.13) | 25 |
| `POST` | `/me/alerts/api/webhooks/{id}/test`, `/me/alerts/api/webhooks/{id}/secret` | ✅ Trin 25.12.2: test sender et testkøb med det samme og svarer med siden plus `delivery`; secret giver en JSON-webhook en ny signeringsnøgle (`{ "secret": "…" }` indsat, eller `""` for en ny, der vises én gang). Test: 429 `merchboard_too_many_requests` ud over 10 webhook-tests pr. minut pr. creator (25.13) | 25 |
| `GET`, `PATCH` | `/me/alerts/preferences` | ✅ Trin 25.7: `{ "anonymize": bool }`, om historikken forkorter brugernavne; gemmes som user meta `merchboard_alerts_anonymize` på den indloggede bruger (som dashboard-temaet, ADR-044). `PATCH` med en ikke-boolsk værdi: 400 | 25 |
| `DELETE` | `/me/alerts/overlay/images/{id}` | ✅ Trin 25.6.10: sletter et eget billede; tekstfyld, der brugte det, slås fra. Samme svar som `GET`. 400 med `data.fields.image` = `unknown_image` for et billede, creatoren ikke ejer (fx et biblioteksbillede); 404 før opsætning | 25 |
| `GET` | `/me/alerts/presets` | ✅ Trin 25.9.5: standard-overlays, creatoren kan starte fra: `{ presets: [ { id, name, settings, font_approved, layout, images } ] }` (samme felter som overlayet; `images` er billedbiblioteket) | 25 |
| `POST` | `/me/alerts/overlay/preset` | ✅ Trin 25.9.5: `{ "preset": id }` kopierer standardens udseende og tekster ind i creatorens overlay; creatorens lyde og URL bliver. Samme svar som `GET`. 400 med `data.fields.preset` = `unknown_preset`; 404 uden overlay | 25 |
| `GET`, `POST` | `/me/alerts/designs` | ✅ Creatorens egne designs (tilføjelse efter 25.9.5): `{ designs: [ { id, name, saved_at, settings, font_approved, layout, images } ], max }` (ældste først; kun creatorens egne). `POST { "name" }` gemmer overlayets nuværende design (udseende, tekster og lyde) som et nyt. 400 med `data.fields.name` = `invalid_name` (tomt eller over 60 tegn) eller `designs_full` (højst 10); 404 uden overlay | 25 |
| `POST` | `/me/alerts/designs/{id}/update`, `/me/alerts/designs/{id}/name` | ✅ Overskriver designet med overlayets nuværende design (samme navn) eller omdøber det (`{ "name" }`). Samme svar som listen. 400 med `data.fields.design` = `unknown_design` for et design, creatoren ikke har | 25 |
| `DELETE` | `/me/alerts/designs/{id}` | ✅ Sletter designet; creatorens egne lydfiler, som hverken overlayet eller et andet design bruger, slettes. Samme svar som listen. 400 `unknown_design` | 25 |
| `POST` | `/me/alerts/overlay/design` | ✅ `{ "design": id }` tager et eget design i brug: dets udseende, tekster og lyde erstatter overlayets; URL'en bliver. En font, der ikke længere er godkendt, giver standardfonten. Samme svar som `GET /me/alerts/overlay`. 400 `unknown_design`; 404 uden overlay | 25 |
| `POST` | `/me/alerts/overlay/test` | ✅ Sender en testalert med et tilfældigt publiceret produkt fra creatoren til overlayet via feedet (`{ "sent": true }`); gemmes ikke som alert og sendes ikke til Twitch. 404 uden overlay | 25 |

Der findes intet `/me/overview` (ADR-040 pkt. 12). Logoet (fase 21, trin 6, ADR-042) har ingen REST-route: det vælges i wp-admin (Settings → Branding, `admin-post.php`) og når dashboardet via bootstrap-feltet `logo` (sitekonfiguration, ikke creator-data). Overview kalder `/me/sales` uden parametre (standardperioden: 30 kalenderdage inklusive i dag, `day`) og viser `totals` pr. valuta, så hver KPI kun har én definition og samme cache-post som `/me/sales` (✅ fase 20, trin 5; se [FRONTEND.md](FRONTEND.md#overview--fase-15)).

Fordi der ikke findes `/creators/{id}` i creator-scope, **kan Creator A ikke ændre et ID for at se Creator B**. For underressourcer med ID (fx `/me/orders/{order_id}`) tjekker service-laget ejerskab (`order indeholder mindst én linje for creator'en`). Svaret er **404** ved manglende ejerskab, ikke 403, så eksistensen af andres ressourcer ikke afsløres.

### Admin-scope: `/merchboard/v1/admin/...`

Permission: specifik capability pr. route (fx `merchboard_manage_creators`). Bruges kun, hvis en admin-skærm får behov for JS-interaktivitet. v1-admin er server-renderet og kræver ikke disse endpoints.

| Metode | Route | Capability |
| --- | --- | --- |
| `GET`/`POST` | `/admin/creators` | `merchboard_manage_creators` |
| `GET`/`PATCH` | `/admin/creators/{id}` | `merchboard_manage_creators` |
| `GET`, `POST`, `DELETE` | `/admin/alerts/creator-page` | `merchboard_manage_alerts`. ✅ Trin 25.9.6: opsætningen af creatorernes side `{ version, cards: { full, left, right }, fields: { alert_text, text_style, box_image, animations, timing }, designs_compact }`. `POST` gemmer en opsætning (normaliseret: ukendte og gentagne nøgler fjernes, manglende kort og felter kommer på deres standardplads; audit `alert_creator_page_changed`, kun ved ændring); `DELETE` nulstiller til standarden. Alle svar er den komplette opsætning | 25 |
| `GET`/`POST` | `/admin/alerts/presets` | `merchboard_manage_alerts`. ✅ Trin 25.9.5: listen `{ presets: [ { id, name } ], max }`; `POST { "name" }` opretter (400 `invalid_name`/`presets_full`). `GET`/`PATCH`/`DELETE …/{id}` læser (samme JSON som `/me/alerts/overlay`, `audio` null, kun billedbiblioteket), gemmer design og sletter; `POST …/{id}/name` omdøber; 404 for ukendt id |
| `GET`/`POST`/`PATCH` | `/admin/alerts/global-overlay` | `merchboard_manage_alerts`. ✅ Trin 25.9.4: det globale overlay (læs, opret, gem indstillinger) i samme JSON som `/me/alerts/overlay` plus `overlay.enabled`; desuden `POST …/enabled` (`{ "enabled": bool }`), `…/regenerate`, `…/test`, `…/replay` (404 `merchboard_no_orders` uden ordrer), `POST`/`DELETE …/sounds/{default\|term-id}` og `POST …/images`, `DELETE …/images/{id}`. Ændrer aldrig en creators overlay |

### Offentlig med nøgle: alert-API til creatorens egne bots ✅ (fase 25, trin 25.12.1, ADR-073)

`GET /merchboard/v1/alerts/api/events` er offentlig (`permission_callback` = `__return_true`); creatorens API-nøgle er adgangen. Den sendes kun i headeren `Authorization: Bearer mbk_…` (eller `X-MerchBoard-Key: mbk_…` på værter, der fjerner Authorization), aldrig i URL'en. `X-MerchBoard-Key` bruges, når `Authorization` ikke er en Bearer-header, også når sitet selv sender en Basic-`Authorization` (25.13).

| Parameter | Type | Betydning |
| --- | --- | --- |
| `after` | heltal ≥ 0, valgfri | Cursor: id'et på den sidste hændelse, botten har. Uden `after` svares med creatorens nyeste id som cursor og ingen køb, så botten starter fra nu |
| `limit` | heltal 1–50, standard 20 | Flest hændelser i svaret |

Svar `200`: `{ cursor, events: [ { id, kind, created_at, order_ref, username, product, quantity, category, variation, image, price, line_total, total, currency } ] }`, ældste først.

- **Hændelser:** én pr. ordrelinje af creatorens egne varer. `kind` er `purchase`, `test` eller `resend`; test og gensendelser har `id` 0 og flytter aldrig cursoren.
- **`order_ref`:** 16 hex-tegn, ens for linjerne i en ordre og forskellig for hver creator; aldrig ordrenummeret.
- **Felter:** `username` er kundens frivillige brugernavn eller `""`. `image` (25.12.5) er URL'en til produktets billede (variationens, ellers produktets) i butikkens produktstørrelse (`woocommerce_single`), eller `""`. Beløbene er tal som streng med butikkens decimaler og punktum, eller `null`.
- **Ingen persondata:** ingen kunde- eller ordre-id'er.
- **Headers:** `Cache-Control: no-store`, `X-Robots-Tag: noindex`.

Fejl:
- `503 merchboard_alerts_api_unavailable`: API'en er slået fra af administratoren, alerts er slået fra, eller MerchAlert er aktivt.
- `401 merchboard_unauthorized`: nøglen mangler, er forkert eller er tilbagekaldt, creatoren er inaktiv, eller creatoren har ikke adgang til API-nøgler (25.12.4). Svaret er det samme for alle årsager.
- `429 merchboard_too_many_requests`: mere end 60 forespørgsler pr. nøgle pr. minut.

### Udgående: webhooks til creatorens egne bots ✅ (fase 25, trin 25.12.2, ADR-073)

Efter en betalt ordre sender pluginet én `POST` pr. webhook til creatorens adresse (via Action Scheduler, ét forsøg, timeout 5 s, ingen redirects; fra 25.13 kun når alle værtens adresser er offentlige, og forbundet til den tjekkede adresse, se SECURITY.md).

**JSON** (SparkyBots format):

- **Body:** `{ id, type, created_at, order_ref, username, display_name, total, currency, item_count, items: [ { product, quantity, category, variation, image, price, line_total } ] }`. `image` som i polling (25.12.5).
- **`id`:** `evt_` + 32 hex, ens hver gang det samme køb sendes (til at fjerne dubletter). Test og gensendelser har `type` `test`/`resend` og et nyt `id`.
- **`total` ved gensendelse:** altid creatorens samlede betalte ordretotal (det, kunden betalte), også når kun én linje gensendes fra Historik; `items` og `item_count` dækker kun de gensendte linjer (projektejerens beslutning 2026-10-24, 25.13 review).
- **Navn:** `display_name` er brugernavnet eller "Anonymous".
- **Headers:** `Content-Type: application/json`, `User-Agent: MerchDashboard-Webhooks/1`, `X-MerchBoard-Event-Id`, `X-MerchBoard-Timestamp` (Unix-tid) og `X-MerchBoard-Signature: sha256=` HMAC-SHA256 af `{timestamp}.{body}` med webhookens hemmelighed.

**Discord:**

- **Body:** `{ content, allowed_mentions: { parse: [] }, embeds }`, højst 2000 tegn, én besked pr. ordre. `embeds` (25.12.5) er produktbillederne under beskeden, `[ { image: { url } } ]`, hvert billede én gang, højst 10; uden billeder udelades feltet.

**Svar:** et 2xx-svar tæller som leveret. Alt andet er en fejl, og 10 fejl i træk sætter webhooken på pause.

**Leveringslog (`deliveries`):** `[ { id, webhook_id, event_id, kind, status, http_status, error, created_at } ]`. `error` er en årsagskode, aldrig transportens fejltekst (den går til den tekniske log, 25.13 review): `http_NNN` (modtagerens svar), `blocked_address` (værten har en privat, loopback-, link-local-, delt eller reserveret adresse eller ingen adresse; SSRF-værn), `unreachable` (ingen forbindelse), `timeout` (intet svar inden 5 s), `unreadable` (adressen eller hemmeligheden kan ikke længere dekrypteres, fx efter et nyt auth-salt), `queue_unavailable` (ingen Action Scheduler) eller `skipped` (da jobbet kørte, var webhooken slået fra eller på pause, creatoren inaktiv, eller adgangen, API'en eller alerts slået fra). Dashboardet viser koderne i ord.

### Offentlig: alert-overlayets feed ✅ (fase 25, trin 25.3, ADR-065 pkt. 4)

`GET /merchboard/v1/alerts/overlay/{overlay_id}/feed` er en offentlig route (de andre er Twitchs callback og webhook, trin 25.8, Kicks callback og webhook, trin 25.8.2, YouTubes callback, trin 25.8.4, det globale overlays feed, trin 25.9.4, og `/alerts/api/events`, trin 25.12.1) (`permission_callback` = `__return_true`, besluttet i ADR-065). OBS kan ikke logge ind, så det hemmelige overlay-ID i stien er adgangen (32 hex-tegn, 128 bit, sammenlignet med `hash_equals()`).

| Parameter | Type | Betydning |
| --- | --- | --- |
| `after` | heltal ≥ 0, valgfri | Cursor: id'et på den sidste leverede alert. Uden `after` svares straks med creatorens nyeste alert-id som cursor og ingen alerts, så overlayet kun viser alerts fra nu af |
| `wait` | boolean, standard `true` | Long-poll: serveren tjekker hvert sekund i op til 8 sekunder, før den svarer uden alerts |

Svar `200`: `{ cursor, version, alerts: [ { id, image, text, secondary, sound } ] }`, højst 20 alerts, ældste først. `text` og `secondary` er færdig tekst (skabelonen med variablerne udfyldt; `$FirstName` og `$UserName` giver kundens frivillige brugernavn eller "Anonymous"), `image` er en billed-URL på samme site eller `""`. `sound` (fra trin 25.6) er URL'en på alertens lyd på samme site eller `""`: med lyde slået til lyden for den første af produktets kategorier (eller deres overkategorier), der har en, ellers standardlyden. `version` skifter, når indstillingerne eller ID'et ændres; siden genindlæses så (fra 25.13 med cursoren i adressen, `merchboard_after` og `merchboard_at`, gyldig i 60 sekunder, så køb under genindlæsningen ikke tabes). Ingen kunde- eller ordre-ID'er. Headers: `Cache-Control: no-store`, `X-Robots-Tag: noindex`.

Fejl: `404 merchboard_overlay_not_found` (ukendt eller ugyldigt ID, eller inaktiv creator; samme svar for alle), `429 merchboard_too_many_requests` (mere end 60 forespørgsler pr. overlay pr. minut; kun eksisterende overlays tælles).

### Offentlig: det globale overlays feed ✅ (fase 25, trin 25.9.4)

`GET /merchboard/v1/alerts/global-overlay/{overlay_id}/feed`: samme parametre, svar og rate limit som creatorens feed ovenfor, men over alle creators' alerts og på sin egen route, så et creator-ID aldrig åbner det globale overlay og omvendt. `404 merchboard_overlay_not_found`, når ID'et er ukendt, overlayet er slået fra, alerts' kill switch er fra eller MerchAlert er aktiv.

### Twitch: callback, EventSub-webhook og admin-chat ✅ (fase 25, trin 25.8, ADR-066)

| Metode | Route | Adgang og indhold |
| --- | --- | --- |
| `GET` | `/alerts/twitch/callback` | Offentlig (Twitch sender browseren hertil; registreres som redirect-URL i Twitch-appen). Kræver en gyldig enkeltbrugs-`state`; ombytter `code`, slår kontoen op og svarer `302` til admin-fanen "Chatbot" (bot-kontoen) eller til dashboardets Alerts → Chatbot med `?chatbot=connected&platform=twitch` (eller `denied`, `bot_is_broadcaster` eller en anden fejlkode). Ukendt state: `302` til forsiden. Bruger-tokenet gemmes ikke |
| `POST` | `/alerts/twitch/eventsub` | Offentlig, men kun med gyldig signatur: HMAC-SHA256 over `Twitch-Eventsub-Message-Id` + `-Timestamp` + rå body med webhook-secret'en (`hash_equals`; højst 10 minutter gammel), ellers `403`. `webhook_callback_verification`: svarer med `challenge` som ren tekst (`200`). `notification` (`channel.chat.message`): gemmer beskeden i chatloggen (`204`). `revocation`: noterer status (`204`) |
| `GET` | `/alerts/chat/{platform}/{creator}?after=` | `merchboard_manage_alerts` (REST-nonce). `{ messages: [ { id, login, name, color, text, at } ] }`, de seneste (højst 100) eller dem efter `after`; til admin-chatvinduet, for Twitch og Kick (trin 25.8.2; erstatter `/alerts/twitch/chat/{creator}`). For YouTube altid tom: admin-siden indlejrer YouTubes egen live-chat (trin 25.8.4). `Cache-Control: no-store` |

### Kick: callback og webhook ✅ (fase 25, trin 25.8.2, ADR-069)

| Metode | Route | Adgang og indhold |
| --- | --- | --- |
| `GET` | `/alerts/kick/callback` | Offentlig (Kick sender browseren hertil; registreres som redirect-URL i Kick-appen). Kræver en gyldig enkeltbrugs-`state` med PKCE-verifier; ombytter `code`, gemmer creatorens tokens krypteret, slår kanalen op (`/public/v1/channels`) og svarer `302` til dashboardets Alerts → Chatbot med `?chatbot=connected&platform=kick` (eller en fejlkode). Ukendt state: `302` til forsiden |
| `POST` | `/alerts/kick/webhook` | Offentlig, men kun med gyldig signatur: RSA-SHA256 (PKCS#1 v1.5) over `Kick-Event-Message-Id` + "." + `Kick-Event-Message-Timestamp` + "." + rå body, verificeret med Kicks offentlige nøgle (hentes fra `/public/v1/public-key`, caches et døgn); højst 10 minutter gammel, ellers `403`. `chat.message.sent` for en kanal med botten gemmes i chatloggen (`200`); andre events ignoreres (`200`) |

### Offentlige sider: YouTube-chatbottens privatlivspolitik og vilkår ✅ (fase 25, trin 25.8.5, ADR-071)

Ikke REST, men HTML-sider under dashboardets adresse uden login (en præcis whitelist; alle andre dashboard-stier kræver login): `/{slug}/legal/youtube/` (app-forside), `/{slug}/legal/youtube-privacy/` og `/{slug}/legal/youtube-terms/`, på dansk med `/da/` til sidst. `200` med siden, når teksterne er offentliggjort, ellers `404`. Headers: CSP uden scripts (`default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'`), `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`; ingen `noindex`, så Google kan læse dem.

### YouTube: callback ✅ (fase 25, trin 25.8.4, ADR-070)

| Metode | Route | Adgang og indhold |
| --- | --- | --- |
| `GET` | `/alerts/youtube/callback` | Offentlig (Google sender browseren hertil; registreres som redirect-URL i Google-appens OAuth-klient). Kræver en gyldig enkeltbrugs-`state` med PKCE-verifier (10 minutter, bundet til brugeren og creatoren); ombytter `code` (Google skal give et refresh-token), slår kanalen op (`channels.list?mine=true`) og svarer `302` til admin-fanen "Chatbot" med `youtube_bot_connected` (bot-kontoen) eller til dashboardets Alerts → Chatbot med `?chatbot=connected&platform=youtube` (eller `denied`, `bot_is_broadcaster`, `unexpected_answer` eller en anden fejlkode). Ukendt state: `302` til forsiden. YouTube har ingen chat-webhooks, så det er den eneste route, Google kalder |


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

**`GET /merchboard/v1/me/profile`** → 200 (fra trin 28.3, ADR-077; erstatter `bio` og `social_links` fra fase 14)

```json
{
  "display_name": "Nova",
  "fields": [ "display_name", "description", "image", "banner", "banner_mobile", "banner_link", "social_instagram", "…", "social_twitch" ],
  "category": {
    "name": "Nova Merch",
    "description": "Merch & music",
    "image_url": "https://shop.example/…/logo.png",
    "banner_url": "https://shop.example/…/ccm-bannere/Banner_Nova_Merch.png",
    "banner_default": true,
    "banner_mobile_url": "",
    "banner_link": "",
    "social_links": { "instagram": "https://www.instagram.com/nova", "tiktok": "", "youtube": "", "x": "", "facebook": "", "kick": "", "twitch": "" }
  },
  "pending": {
    "description": { "id": 12, "value": "New story", "file": null, "remove": false, "created_at": "2026-10-26T10:00:00Z" },
    "banner": { "id": 13, "value": "", "file": { "mime": "image/png", "width": 1400, "height": 300 }, "remove": false, "created_at": "2026-10-26T10:01:00Z" }
  },
  "rejected": {
    "social_x": { "id": 9, "reason": "Forkert konto", "decided_at": "2026-10-26T11:00:00Z" }
  }
}
```

- `fields`: de felter, der kan foreslås på sitet: navnet altid; beskrivelse og logo med WooCommerce; banner, mobilbanner, link og sociale links kun med bannerpluginet CCMerch Kategoribannere.
- `category`: creatorens produktkategori, som den er nu, eller `null` uden kategori. `banner_url` er det egne banner, ellers bannerpluginets standardbanner (`banner_default`).
- `pending`: ventende forslag pr. felt; et billede vises via `GET /me/profile/files/{id}`. `rejected`: seneste afvisning pr. felt med administratorens note, indtil et nyt forslag til feltet.

**`PATCH /merchboard/v1/me/profile`** (JSON-body) → 200 med samme form som `GET /me/profile`. Hvert sendt felt bliver et forslag; en værdi lig den nuværende trækker et ventende forslag tilbage. Intet ændres i butikken eller i navnet, før en administrator godkender.

| Felt | Regel |
| --- | --- |
| `display_name` | Streng, trimmes, 1-191 tegn |
| `description` | Ren tekst (`sanitize_textarea_field`, linjeskift bevares), maks. 1000 tegn; `""` foreslår at fjerne den |
| `banner_link` | `""` eller en `https`-URL (uden skema tilføjes `https://`), maks. 500 tegn |
| `image`, `banner`, `banner_mobile` | Kun `""`: foreslår at fjerne billedet (et nyt billede uploades med `POST /me/profile/files/{field}`) |
| `social_links` | Objekt med `instagram`, `tiktok`, `youtube`, `x`, `facebook`, `kick`, `twitch`. Hver værdi er `""`, et brugernavn (`navn` eller `@navn` bliver profil-URL'en) eller en `https`-URL på platformens domæner (ADR-077 pkt. 6), maks. 200 tegn |

- Andre felter (`status`, `user_id`, `id`, `creator_id`, `website` …) ignoreres eller afvises af argument-skemaet (400 `rest_invalid_param`).
- Regelbrud giver 400 `merchboard_invalid_profile` med `data.fields` = felt → kode (`required`, `invalid`, `too_long`, `invalid_url`, `unavailable`; sociale links som `"social_links.x"`). Input ekkoes aldrig, og intet gemmes.
- Forslag og audit (`category_proposal_submitted`, `category_proposal_withdrawn`) skrives i én transaktion.

**`POST /merchboard/v1/me/profile/files/{image|banner|banner_mobile}`** (multipart `file`) → 200 med profilen. JPG, PNG eller WebP, højst 5 MB og 4096 px; logoet kvadratisk, mindst 300 × 300 og skaleret ned til højst 1000 × 1000; banneret mindst 1280 px bredt; mobilbanneret mindst 600 px bredt. Fejl: 400 `merchboard_invalid_request` med `data.fields` = { felt: `too_large` | `invalid_type` | `unreadable` | `too_small` | `too_big` | `not_square` | `no_file` | `unavailable` }. Filen gemmes ikke-offentligt (ADR-077 pkt. 5).

**`GET /merchboard/v1/me/profile/files/{id}`** → selve billedet (`Content-Type` = billedets type, `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`), kun for creatorens eget forslag, mens billedet venter; ellers 404.

**`DELETE /merchboard/v1/me/profile/proposals/{field}`** → 200 med profilen; et ukendt felt giver 404.

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
