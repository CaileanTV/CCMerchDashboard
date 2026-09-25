# REST API

> Status: **Design.** REST-fundamentet kommer i roadmap-fase 14.

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
| `GET` | `/me/overview` | Nøgletal til Overview | 15 / 20 |
| `GET` | `/me/products` | Creator'ens produkter (pagineret) | 19 |
| `GET` | `/me/orders` | Ordrelinjer med creator'ens produkter **uden kunde-PII** | 19 |
| `GET` | `/me/sales?from=&to=&interval=` | Salgsaggregeringer | 20 |

Fordi der ikke findes `/creators/{id}` i creator-scope, **kan Creator A ikke ændre et ID for at se Creator B**. For underressourcer med ID (fx `/me/orders/{order_id}`) tjekker service-laget ejerskab (`order indeholder mindst én linje for creator'en`). Svaret er **404** ved manglende ejerskab, ikke 403, så eksistensen af andres ressourcer ikke afsløres.

### Admin-scope: `/merchboard/v1/admin/...`

Permission: specifik capability pr. route (fx `merchboard_manage_creators`). Bruges kun, hvis en admin-skærm får behov for JS-interaktivitet. v1-admin er server-renderet og kræver ikke disse endpoints.

| Metode | Route | Capability |
| --- | --- | --- |
| `GET`/`POST` | `/admin/creators` | `merchboard_manage_creators` |
| `GET`/`PATCH` | `/admin/creators/{id}` | `merchboard_manage_creators` |

### Integrations: `/merchboard/v1/integrations/{provider}/webhook` (fremtid)

Offentlige endpoints til eksterne tjenester. Permission består af **HMAC-signaturverifikation** med en secret fra `wp-config.php`, tidsstempel-/replay-check og streng payload-validering. Behandlingen er asynkron (Action Scheduler), og svaret kommer hurtigt.

Ingen konkrete integrationer er godkendt. Hver tjeneste kræver projektejerens eksplicitte godkendelse og en ADR. Dinero og Google Drive er fravalgt (ADR-016).

## Respons-konventioner

- JSON-objekter med `snake_case`-nøgler (som WordPress core).
- Beløb returneres som **strenge med fast decimalantal** plus `currency` (fx `{"amount": "149.0000", "currency": "DKK"}`). Aldrig floats. Formattering sker i klienten via `Intl.NumberFormat`.
- Datoer i ISO 8601 UTC (`2026-09-24T12:00:00Z`).
- Lister: `?page=&per_page=` (max 100). Headers `X-WP-Total` og `X-WP-TotalPages`.
- Fejl: `WP_Error` med koder prefixet `merchboard_` (fx `merchboard_creator_inactive`), passende HTTP-status og **ingen interne detaljer** (SQL, stack traces, stier) i beskeden.

## Controller-basisklasse ✅ (fase 14)

`MerchBoard\Core\Http\RestController` (abstrakt, `NAMESPACE = 'merchboard/v1'`). Controllere ligger i modulerne (`Modules/<Navn>/Rest/`) og registreres af modulet på `rest_api_init`:

- `register_routes()`: hver route har en eksplicit `permission_callback` (aldrig `__return_true` for private data) og `args` med typer.
- `require_creator( CreatorContext ): Creator|WP_Error`: 401 `merchboard_unauthenticated` uden login, 403 `merchboard_forbidden` uden creator-adgang (også inaktiv creator og fejlet adgangstjek). Bruges både i permission callback og igen i endpointet, fordi `CreatorContext` ikke memoiserer.
- `require_capability( string )` til fremtidige admin-routes.
- `run( route, callback )`: `DatabaseException` og uventede exceptions logges via `Logger` (route og årsag, ingen request-data) og giver en generisk `500 merchboard_internal_error`.
- `error( code, message, status, data )`: `WP_Error` med `merchboard_`-kode, oversat generisk besked og `status`.

Paginerings-helpers tilføjes med den første liste-route.

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

## Test-krav

Hvert creator-endpoint skal have integrationstests for mindst:

1. uautentificeret → 401
2. bruger uden creator-adgang → 403
3. inaktiv creator → 403
4. Creator A kan ikke se eller ændre Creator B's ressourcer (IDOR) → 404
5. ugyldigt input → 400 med ændringer ikke gemt

✅ Fase 14: `tests/Integration/Rest/MeEndpointTest.php` dækker punkt 1-5 for `/me` og `/me/profile` (IDOR: ID'er i query og body ignoreres, og en anden creator er uændret), plus route-registrering med permission callbacks, præcise svarfelter, deaktivering på næste request, databasefejl (403 uden detaljer, logget) og fejlet skrivning (500, rollback).
