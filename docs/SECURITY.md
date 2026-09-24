# Security

> Security er prioritet nr. 1. Dette dokument er både arkitektur og tjekliste. Hver fase, der rører input, output, data eller adgang, skal gennemgå [tjeklisten](#tjekliste-pr-ændring).

## Trusselsmodel (kort)

| Aktør | Mål | Primært forsvar |
| --- | --- | --- |
| Anonym besøgende | Tilgå dashboard/API, enumerere creators | Login-krav, permission callbacks, 401/404, ingen offentlige endpoints |
| Creator A | Se/ændre Creator B's data (IDOR) | `/me`-API uden ID'er, `CreatorContext`, ejerskabs-tjek i services, 404 |
| Creator | Eskalere til admin-rettigheder | Minimal rolle, ingen rolle-/meta-skrivning fra creator-input, felt-whitelist |
| Suspenderet creator med aktiv session | Fortsætte adgang | Status tjekkes på **hver** request (ingen caching af adgang i sessionen) |
| Tredjeparts-site (CSRF) | Få en indlogget bruger til at udføre handlinger | Nonces på alle state-ændrende requests, SameSite-cookies (WP-standard) |
| Ondsindet input | XSS, SQL injection | Sanitization ved input, escaping ved output, `prepare()` |
| Kompromitteret admin-konto | Alt | Uden for pluginets kontrol. Anbefal 2FA for admins (plugin), audit-log gør misbrug synligt. |

## Mekanismer

### Authentication

WordPress' egen login og sessions. Intet eget auth-system (ADR-006). Application Passwords slås fra for creators. Brute-force-beskyttelse og 2FA håndteres af etablerede plugins eller hosting, ikke af os.

### Authorization

- **Admin:** `current_user_can( '<specifik cap>' )` i *hver* handler og page-callback, ikke kun i menuregistreringen.
- **Creator:** `CreatorContext::current()` (se [USER-ROLES.md](USER-ROLES.md#adgangsregel-for-creator-dashboard)) i hver controller og REST-permission callback. Services, der modtager et ressource-ID, tjekker ejerskab mod den aktuelle creator.
- **Direkte filadgang:** Hver PHP-fil i `src/` og `templates/` starter med `defined( 'ABSPATH' ) || exit;`, bortset fra rene klassefiler, der ikke eksekverer kode ved include. Dem kræver PHPCS ikke guard for, men vi tilføjer den for konsistens.

### Bootstrap (fase 2)

- Autoloaderen håndterer kun `CCMerch\`-klasser og afviser klassenavne, der ikke er rene namespace-segmenter (`..`, `/`, `:`, null-bytes). Klassenavne kan nå autoloadere fra utroværdigt input via `class_exists()` i anden kode (ADR-017).
- Versionstjekkets admin notice vises kun for brugere med `activate_plugins` (den afslører serverversioner), og alt output er escaped.
- Pluginet skriver intet til databasen og læser intet brugerinput i fase 2. `uninstall.php` sletter intet endnu.

### IDOR

1. Creator-API'et har **ingen creator-ID-parametre** (`/me/...`).
2. Underressourcer (ordre, produkt) slås op *gennem* creator'en: `WHERE creator_id = %d AND id = %d`, ikke `WHERE id = %d` efterfulgt af et tjek, der kan glemmes.
3. Svar ved manglende ejerskab er **404** (skjuler eksistens).
4. Obligatoriske integrationstests "Creator A ↛ Creator B" for hvert endpoint.

### Privilege escalation

- Creator-rollen har kun `read` og `ccmerch_access_dashboard`.
- Creator-redigerbare felter er en **eksplicit whitelist** (fx `display_name`, `bio`, `social_links`). `status`, `user_id`, `slug` og rolle kan aldrig sættes fra creator-endpoints.
- Admin-handlinger, der opretter brugere, kan kun tildele `ccmerch_creator`. Aldrig vilkårlige roller fra input.
- Ingen `update_user_meta` med nøgler fra input.

### CSRF

- REST: cookie-auth **kræver** `X-WP-Nonce` (WordPress afviser ellers som uautentificeret). Leveres af `api-fetch`.
- Admin-formularer: `wp_nonce_field( 'ccmerch_<handling>' )` + `check_admin_referer( 'ccmerch_<handling>' )`.
- Row actions/links med sideeffekter: `wp_nonce_url()` + `check_admin_referer()`.
- Ingen state-ændringer på `GET`.

### Input: validering og sanitization

- REST: `args`-schema med type, enum, længde og `sanitize_callback` (`sanitize_text_field`, `sanitize_textarea_field`, `absint`, `sanitize_key`, `esc_url_raw`, `sanitize_email`).
- Admin: `wp_unslash()` før sanitization. Læs aldrig `$_POST` udenfor den verificerede handler.
- Valider **forretningsregler** i services (fx at slug er unik og brugeren ikke allerede er creator), ikke kun format.
- Arrays/JSON: valider struktur og nøgler (whitelist) og afvis ukendte.

### Output: escaping

- PHP: escape **ved output** med den kontekst-rigtige funktion: `esc_html`, `esc_attr`, `esc_url`, `esc_js` (sjældent), `wp_kses_post` (begrænset HTML) og `wp_json_encode` til inline-JSON.
- Oversættelser: `esc_html__()`/`esc_html_e()`/`esc_attr__()`.
- React escaper tekst automatisk. `dangerouslySetInnerHTML` er forbudt (ESLint `react/no-danger`). URL'er fra data valideres (kun `http(s):`) før de bruges i `href`.
- REST-svar indeholder rå (ikke HTML-escaped) data. Klienten renderer sikkert.

### SQL injection

- Altid `$wpdb->prepare()` med placeholders (`%d`, `%s`, `%f`, `%i` for identifiers).
- Tabelnavne kun fra `Tables`-klassen. `ORDER BY`/kolonner kun fra whitelist. `LIKE` med `$wpdb->esc_like()`.
- Ingen SQL udenfor repositories.
- PHPCS (`WordPress.DB.PreparedSQL*`) håndhæver dette. `phpcs:ignore` kræver en begrundelse i kommentaren.

### Data leakage

- API-svar bygges fra eksplicitte `to_array()`/whitelists. Aldrig `(array) $object` eller hele WP_User-objekter.
- Creators ser **aldrig** kunders PII (se [WOOCOMMERCE.md](WOOCOMMERCE.md#dashboard-data-og-privacy)).
- Fejlbeskeder til klienten er generiske. Detaljer går i log (uden secrets).
- Custom tables og meta vises aldrig i generiske WordPress-queries eller -søgning. Ingen public CPT'er.
- Dashboard-sider sendes med `nocache_headers()`, så delte caches ikke gemmer personlige sider. Kontrollér at hosting/CDN-cache bypasses for indloggede brugere og for `/creator-dashboard/*` og `/wp-json/ccmerch/*`.

### Redirects

`wp_safe_redirect()` (aldrig `wp_redirect()` med input) og `wp_validate_redirect()` for `redirect_to`. Efterfulgt af `exit`.

### Filuploads (fremtid: avatar, produktbilleder)

- Via `media_handle_upload()` / `wp_handle_upload()` med `mimes`-whitelist (kun jpeg/png/webp), `wp_check_filetype_and_ext()`, størrelsesgrænse og capability-/ejerskabs-tjek.
- Ingen SVG-upload (XSS-risiko), medmindre den saniteres med et dedikeret bibliotek (kræver ADR).
- Filer serveres fra uploads-mappen og eksekveres aldrig. Filnavne saniteres af WordPress.
- Creators får **ikke** `upload_files`. Uploads går gennem vores eget endpoint, som sætter ejerskab.

### Security headers (dashboard-shell)

`X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Content-Security-Policy: frame-ancestors 'self'` (senere fuld CSP med nonces via `wp_inline_script_attributes`), `X-Robots-Tag: noindex, nofollow`, `nocache_headers()`.

### Secrets og credentials

- Aldrig i repo'et: `.env*`, `auth.json` og nøgler er gitignored. Secrets placeres i `wp-config.php` som konstanter (`CCMERCH_<PROVIDER>_SECRET`) eller i options med `autoload = no`.
- Aldrig sendt til frontend. Aldrig logget.
- Webhooks verificeres med HMAC (`hash_equals()`), og der er tidsstempel-tolerance.
- Hvis følsomme creator-data (fx bankoplysninger til payouts) nogensinde skal gemmes: foretræk at payout-udbyderen opbevarer dem. Ellers krypteres med `sodium_crypto_secretbox` og en nøgle fra `wp-config.php` (kræver ADR).

### Dependencies

- Ingen runtime-dependencies fra tredjepart (ADR-004). Det minimerer supply chain-risiko i produktion.
- Dev-dependencies: `npm audit` og `composer audit` gennemgås ved opdateringer. Status i [DEVELOPMENT.md](DEVELOPMENT.md#kendte-advisories).

### Audit og logging

Alle adgangs- og rettighedsændringer, creator-statusændringer og settings-ændringer logges i channel `audit` med actor og target. Fejlede autorisationsforsøg på creator-endpoints logges i channel `access` på `warning`-niveau (rate-begrænset for at undgå log-flooding).

## Tjekliste pr. ændring

- [ ] Hvert nyt endpoint/handler har capability- eller `CreatorContext`-tjek **server-side**.
- [ ] State-ændrende requests er nonce-beskyttede.
- [ ] Al input valideres (type, format, forretningsregel) og saniteres. Ukendte felter ignoreres.
- [ ] Al output escapes kontekst-korrekt.
- [ ] Al SQL bruger `prepare()`, og identifiers/ORDER BY kommer fra whitelist.
- [ ] Ingen ressource slås op via klient-ID uden ejerskabsfilter (IDOR).
- [ ] Svar indeholder kun whitelistede felter. Ingen kunde-PII til creators.
- [ ] Redirects bruger `wp_safe_redirect()`.
- [ ] Ingen secrets i kode, logs eller frontend.
- [ ] Tests for uautentificeret, uautoriseret, suspenderet, IDOR og ugyldigt input.
- [ ] `npm run lint` er grøn, og ingen nye `phpcs:ignore` uden begrundelse.
