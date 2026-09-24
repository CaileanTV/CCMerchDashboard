# Security

> Security er prioritet nr. 1. Dette dokument er både arkitektur og tjekliste. Hver fase, der rører input, output, data eller adgang, skal gennemgå [tjeklisten](#tjekliste-pr-ændring).

## Trusselsmodel (kort)

| Aktør | Mål | Primært forsvar |
| --- | --- | --- |
| Anonym besøgende | Tilgå dashboard/API, enumerere creators | Login-krav, permission callbacks, 401/404, ingen offentlige endpoints |
| Creator A | Se/ændre Creator B's data (IDOR) | `/me`-API uden ID'er, `CreatorContext`, ejerskabs-tjek i services, 404 |
| Creator | Eskalere til admin-rettigheder | Minimal rolle, ingen rolle-/meta-skrivning fra creator-input, felt-whitelist |
| Inaktiv creator med aktiv session | Fortsætte adgang | Status tjekkes på **hver** request (ingen caching af adgang i sessionen) |
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

- Autoloaderen håndterer kun `MerchBoard\`-klasser og afviser klassenavne, der ikke er rene namespace-segmenter (`..`, `/`, `:`, null-bytes). Klassenavne kan nå autoloadere fra utroværdigt input via `class_exists()` i anden kode (ADR-017).
- Versionstjekkets admin notice vises kun for brugere med `activate_plugins` (den afslører serverversioner), og alt output er escaped.
- Pluginet skriver intet til databasen og læser intet brugerinput i fase 2. `uninstall.php` sletter intet endnu.

### Datalaget (fase 3)

- **Ingen offentlige endpoints til creator-data.** Fase 3 tilføjer ingen REST-routes, AJAX-handlers eller admin-handlers (verificeret: `/wp-json/merchboard/v1` giver 404).
- **Repositories udfører ingen authorization** og antager ikke, at kalderen er autoriseret. Kontrakten står i `CreatorRepository`s docblock: kaldere skal tjekke capabilities eller bruge `CreatorContext` (fase 8), før de videregiver noget, der stammer fra en request. Authorization-laget holdes adskilt fra datalaget.
- **SQL injection:** Alle queries går gennem `$wpdb->prepare()` (`%i` for tabelnavn, `%d`/`%s` for værdier) eller `$wpdb->insert()` med eksplicitte formater. PHPCS' `PreparedSQL`-sniffs gælder også i repositories (verificeret med en negativ kontrol). Kun `DirectDatabaseQuery`-sniffene er slået fra for datalaget, fordi custom tables ikke har et WordPress-API-alternativ.
- **Dynamiske tabelnavne:** `Tables::name()` accepterer kun registrerede konstanter. Et repository har ingen tabel-parameter og kan ikke bringes til at læse vilkårlige tabeller.
- **ID-validering:** Creator- og user-ID'er skal være positive heltal (`int`-typet). 0/negative afvises *før* der forespørges. Der er bevidst ingen "hent alle"-metode uden scope.
- **Creator/user-adskillelse:** Creator-ID tildeles af databasen og er uafhængigt af user-ID. `UNIQUE(user_id)` håndhæver højst én creator pr. bruger i databasen (ADR-020).
- **Data leakage:** Kolonner vælges eksplicit (ingen `SELECT *`). Databasefejl bliver til `DatabaseException` med generiske beskeder uden SQL, `last_error` eller værdier. Migrationsfejl vises kun som generisk admin notice for `activate_plugins`-brugere.
- **Integritet ved læsning:** Ukendte statusværdier og ugyldige tidsstempler i databasen afvises (`DatabaseException`) i stedet for at blive gættet.
- **Privilege escalation:** Datalaget opretter ingen roller eller capabilities og ændrer ingen brugere (roller: se nedenfor, fase 5).

### IDOR

1. Creator-API'et har **ingen creator-ID-parametre** (`/me/...`).
2. Underressourcer (ordre, produkt) slås op *gennem* creator'en: `WHERE creator_id = %d AND id = %d`, ikke `WHERE id = %d` efterfulgt af et tjek, der kan glemmes.
3. Svar ved manglende ejerskab er **404** (skjuler eksistens).
4. Obligatoriske integrationstests "Creator A ↛ Creator B" for hvert endpoint.

### Privilege escalation

- Creator-rollen har **præcis** `read` og `merchboard_access_dashboard` (`Capabilities`, fase 5). `RoleManager` håndhæver det ved hver synkronisering: capabilities tilføjet til rollen udefra (fx med en rolle-editor) fjernes og logges. Verificeret i Playground: `manage_options` og `merchboard_manage_creators` tilføjet til rollen blev fjernet ved næste sync.
- Pluginets kode tildeler aldrig capabilities ud fra input. Rolle/caps defineres kun i kode (`Capabilities`), og synkroniseringen rører kun `merchboard_creator`, pluginets egne `merchboard_*`-capabilities på `administrator`, og aldrig brugere.
- Permission-checks er positive capability-checks via konstanter (`current_user_can( Capabilities::… )`). Aldrig "logget ind", rolle-/display-navne eller hardcodede ID'er.
- En capability alene giver ikke creator-adgang. Aktiv creator-record kræves også (fase 8).
- Creator-redigerbare felter er en **eksplicit whitelist** (fx `display_name`, `bio`, `social_links`). `status`, `user_id`, `slug` og rolle kan aldrig sættes fra creator-endpoints.
- Admin-handlinger, der opretter brugere, kan kun tildele `merchboard_creator`. Aldrig vilkårlige roller fra input.
- Ingen `update_user_meta` med nøgler fra input.

### CSRF

- REST: cookie-auth **kræver** `X-WP-Nonce` (WordPress afviser ellers som uautentificeret). Leveres af `api-fetch`.
- Admin-formularer: `wp_nonce_field( 'merchboard_<handling>' )` + `check_admin_referer( 'merchboard_<handling>' )`.
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
- Dashboard-sider sendes med `nocache_headers()`, så delte caches ikke gemmer personlige sider. Kontrollér at hosting/CDN-cache bypasses for indloggede brugere og for `/creator-dashboard/*` og `/wp-json/merchboard/*`.

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

- Aldrig i repo'et: `.env*`, `auth.json` og nøgler er gitignored. Secrets placeres i `wp-config.php` som konstanter (`MERCHBOARD_<PROVIDER>_SECRET`) eller i options med `autoload = no`.
- Aldrig sendt til frontend. Aldrig logget.
- Webhooks verificeres med HMAC (`hash_equals()`), og der er tidsstempel-tolerance.
- Hvis følsomme creator-data (fx bankoplysninger til payouts) nogensinde skal gemmes: foretræk at payout-udbyderen opbevarer dem. Ellers krypteres med `sodium_crypto_secretbox` og en nøgle fra `wp-config.php` (kræver ADR).

### Dependencies

- Ingen runtime-dependencies fra tredjepart (ADR-004). Det minimerer supply chain-risiko i produktion.
- Dev-dependencies: `npm audit` og `composer audit` gennemgås ved opdateringer. Status i [DEVELOPMENT.md](DEVELOPMENT.md#kendte-advisories).

### Logging

Tekniske logs (fase 4, ADR-021, [ARCHITECTURE.md §11](ARCHITECTURE.md#11-logging-arkitektur)) må aldrig blive en kanal for datalæk.

**Må aldrig logges:** passwords, auth-tokens, API-nøgler, cookies, sessionsdata, WordPress-credentials og salts, database-credentials, secrets fra miljøvariabler eller `wp-config.php`, betalingsoplysninger, kundedata (navn, e-mail, telefon, adresse) og creator-private data (medmindre en senere feature eksplicit definerer og begrunder det). Brug ID'er i stedet.

**Kaldernes ansvar (første forsvarslinje):** Beskeder er konstante sætninger. Context indeholder kun diagnostiske værdier (ID'er, versioner, klassenavne). Hele request-, bruger- eller ordreobjekter sendes aldrig.

**`LogContext` (sidste forsvarslinje), anvendt på alt, før det skrives:**

- Værdier under nøgler, der matcher `pass|pwd|secret|token|api[_-]?key|private[_-]?key|auth|cookie|session|nonce|salt|credential|card|cvv|iban|email|phone|address` (case-insensitive, hvor som helst i nøglen og også i indlejrede arrays), erstattes med `[redacted]`. Mønsteret er bevidst bredt, så falske positiver accepteres.
- **Exceptions** bliver til `{exception: klasse, code}`. Beskeden medtages kun for pluginets egne exceptions (`MerchBoard\…`), hvis beskeder er generiske pr. kontrakt. Beskeder fra WordPress/PHP/tredjeparter kan indeholde SQL, stier eller værdier og droppes. Stack traces og filstier logges aldrig.
- Andre objekter bliver til deres klassenavn, og resources droppes.
- Kontroltegn (linjeskift, null-bytes, escape-sekvenser) fjernes fra beskeden og alle strengværdier (forhindrer log-forfalskning). Strenge afkortes til 500 tegn, og dybden er højst 3.

**Databasefejl:** Brugere og UI ser aldrig SQL, `last_error`, stack traces, stier eller forbindelsesdetaljer. Migrationsfejl vises kun som generisk admin notice (for `activate_plugins`). Loggen får en **renset** DDL-fejl: HTML fjernet, whitespace normaliseret, en eventuel driver-backtrace fjernet og afkortet til 200 tegn. Fejl fra DML (inserts/selects) logges ikke med `last_error`, fordi de kan indeholde brugerdata (fx værdien i en duplicate-key-fejl).

**Production:** Standardtærsklen er `warning`, og debug-output kræver `WP_DEBUG` (som pluginet aldrig ændrer). Der sker ingen output til browseren.

### Audit trail (planlagt, fase 6)

Adgangs- og rettighedsændringer, creator-statusændringer og settings-ændringer registreres i en separat audit-tabel med actor og target (ADR-021). Fejlede autorisationsforsøg på creator-endpoints logges som tekniske `warning`-poster (rate-begrænset for at undgå log-flooding) fra fase 8.

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
- [ ] Tests for uautentificeret, uautoriseret, inaktiv creator, IDOR og ugyldigt input.
- [ ] `npm run lint` er grøn, og ingen nye `phpcs:ignore` uden begrundelse.
