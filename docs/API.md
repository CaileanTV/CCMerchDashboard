# REST API

> Status: **Design.** REST-fundamentet kommer i roadmap-fase 14.

## Principper

1. **Kun WordPress REST API** (`register_rest_route`). **Ingen `admin-ajax.php`**: REST har schema, permission callbacks og standard-fejlformat. Klassiske admin-formularer poster til `admin-post.php` (se [ADMIN.md](ADMIN.md)).
2. Namespace **`ccmerch/v1`**. Breaking changes giver `v2`, og `v1` bevares i en overgangsperiode.
3. **Hver route har en eksplicit `permission_callback`.** `__return_true` er forbudt, medmindre endpointet bevidst er offentligt, og det kræver en ADR.
4. **Hver route har `args` med `type`, `required`, `validate_callback`/`enum`/`minimum` osv. og `sanitize_callback`.** Ukendte felter ignoreres.
5. **Creator-endpoints tager aldrig et creator-ID fra klienten.** Creator'en udledes server-side via `CreatorContext` (se nedenfor).
6. Controllers er tynde: validering → service → `to_array()`-respons. Forretningslogik ligger i services.

## Authentication

- **Creator dashboard og admin-scripts:** cookie-authentication og `X-WP-Nonce` (`wp_rest`). Nonce og root-URL leveres af `@wordpress/api-fetch`-middleware, som dashboard-shellen initialiserer.
- **Application Passwords** deaktiveres for creator-brugere (`wp_is_application_passwords_available_for_user`) for at reducere angrebsfladen. Admins bevarer dem.
- Uautentificerede requests til beskyttede routes giver `401 rest_forbidden`.

## Route-familier

### Creator-scope: `/ccmerch/v1/me/...`

Permission: `CreatorContext::current()` skal returnere en **aktiv** creator for den indloggede bruger, og brugeren skal have `ccmerch_access_dashboard`.

| Metode | Route | Formål | Fase |
| --- | --- | --- | --- |
| `GET` | `/me` | Creator-profil (whitelistede felter), brugerens rettigheder og tilgængelige dashboard-sektioner | 14 |
| `PATCH` | `/me/profile` | Opdater redigerbare profilfelter (whitelist) | 14 |
| `GET` | `/me/overview` | Nøgletal til Overview | 15 / 20 |
| `GET` | `/me/products` | Creator'ens produkter (pagineret) | 19 |
| `GET` | `/me/orders` | Ordrelinjer med creator'ens produkter **uden kunde-PII** | 19 |
| `GET` | `/me/sales?from=&to=&interval=` | Salgsaggregeringer | 20 |

Fordi der ikke findes `/creators/{id}` i creator-scope, **kan Creator A ikke ændre et ID for at se Creator B**. For underressourcer med ID (fx `/me/orders/{order_id}`) tjekker service-laget ejerskab (`order indeholder mindst én linje for creator'en`). Svaret er **404** ved manglende ejerskab, ikke 403, så eksistensen af andres ressourcer ikke afsløres.

### Admin-scope: `/ccmerch/v1/admin/...`

Permission: specifik capability pr. route (fx `ccmerch_manage_creators`). Bruges kun, hvis en admin-skærm får behov for JS-interaktivitet. v1-admin er server-renderet og kræver ikke disse endpoints.

| Metode | Route | Capability |
| --- | --- | --- |
| `GET`/`POST` | `/admin/creators` | `ccmerch_manage_creators` |
| `GET`/`PATCH` | `/admin/creators/{id}` | `ccmerch_manage_creators` |

### Integrations: `/ccmerch/v1/integrations/{provider}/webhook` (fremtid)

Offentlige endpoints til eksterne tjenester. Permission består af **HMAC-signaturverifikation** med en secret fra `wp-config.php`, tidsstempel-/replay-check og streng payload-validering. Behandlingen er asynkron (Action Scheduler), og svaret kommer hurtigt.

Ingen konkrete integrationer er godkendt. Hver tjeneste kræver projektejerens eksplicitte godkendelse og en ADR. Dinero og Google Drive er fravalgt (ADR-016).

## Respons-konventioner

- JSON-objekter med `snake_case`-nøgler (som WordPress core).
- Beløb returneres som **strenge med fast decimalantal** plus `currency` (fx `{"amount": "149.0000", "currency": "DKK"}`). Aldrig floats. Formattering sker i klienten via `Intl.NumberFormat`.
- Datoer i ISO 8601 UTC (`2026-09-24T12:00:00Z`).
- Lister: `?page=&per_page=` (max 100). Headers `X-WP-Total` og `X-WP-TotalPages`.
- Fejl: `WP_Error` med koder prefixet `ccmerch_` (fx `ccmerch_creator_inactive`), passende HTTP-status og **ingen interne detaljer** (SQL, stack traces, stier) i beskeden.

## Controller-basisklasse (plan)

`CCMerch\Core\Http\RestController` leverer:

- `require_creator(): Creator|WP_Error`, som bruges i permission callbacks for `/me`
- `require_capability( string $cap )`
- paginerings-helpers og `rest_ensure_response`-wrapping
- ensartet fejlformat og logning af uventede exceptions (channel `rest`)

## Test-krav

Hvert creator-endpoint skal have integrationstests for mindst:

1. uautentificeret → 401
2. bruger uden creator-adgang → 403
3. suspenderet creator → 403
4. Creator A kan ikke se eller ændre Creator B's ressourcer (IDOR) → 404
5. ugyldigt input → 400 med ændringer ikke gemt
