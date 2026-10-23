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

WordPress' egen login og sessions. Intet eget auth-system (ADR-006). Application Passwords slås fra for rene creators, både ved oprettelse og ved brug (✅ fase 12, ADR-026). Brute-force-beskyttelse og 2FA håndteres af etablerede plugins eller hosting, ikke af os.

### Authorization

- **Admin:** `current_user_can( '<specifik cap>' )` i *hver* handler og page-callback, ikke kun i menuregistreringen.
- **Afregning og saldi i admin (trin 24.2, ADR-060):** ordrehistorikken viser bilagsnummer og antal afregnede rækker til alle med `merchboard_manage_creators` (bevidst valg 2026-10-13); bilagets indhold og linket til udbetalingen kræver `merchboard_manage_payouts`. Saldi pr. creator vises kun på Payouts-siden (`merchboard_manage_payouts`) og indeholder ingen betalingsoplysninger.
- **Creator:** `CreatorContext::current()` (✅ fase 8, se [USER-ROLES.md](USER-ROLES.md#adgangsregel-for-creator-dashboard)) i hver controller og REST-permission callback. Adgang = capability `merchboard_access_dashboard` **og** en aktiv creator-record, der er koblet til brugeren. Rollen alene giver aldrig adgang. Services, der modtager et ressource-ID, tjekker ejerskab mod den aktuelle creator.
- **Direkte filadgang:** Hver PHP-fil i `src/` og `templates/` starter med `defined( 'ABSPATH' ) || exit;`, bortset fra rene klassefiler, der ikke eksekverer kode ved include. Dem kræver PHPCS ikke guard for, men vi tilføjer den for konsistens.

### Bootstrap (fase 2)

- Autoloaderen håndterer kun `MerchBoard\`-klasser og afviser klassenavne, der ikke er rene namespace-segmenter (`..`, `/`, `:`, null-bytes). Klassenavne kan nå autoloadere fra utroværdigt input via `class_exists()` i anden kode (ADR-017).
- Versionstjekkets admin notice vises kun for brugere med `activate_plugins` (den afslører serverversioner), og alt output er escaped.
- Pluginet skriver intet til databasen og læser intet brugerinput i fase 2. (Fra fase 9 sletter `uninstall.php` kun data ved eksplicit opt-in, se nedenfor.)

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
- Creator-redigerbare felter er en **eksplicit whitelist** (✅ fase 14: `display_name`, `bio`, `social_links` i `MeController` og igen i `CreatorProfileService`). `status`, `user_id`, `slug` og rolle kan aldrig sættes fra creator-endpoints (testet over HTTP). Body læses kun som JSON/form-body, aldrig fra query-parametre. Stamdata til afregningsbilag (creatormeta `billing_details`: juridisk navn, adresse, CVR/SE-nr., ADR-062 K09) er ikke creator-redigerbare: kun administratorer med `merchboard_manage_payouts` skriver dem (capability og nonce pr. creator før input), og audit-loggen får kun navnene på de ændrede felter, aldrig værdierne. Creatoren ser dem kun i sine egne bilag. Opbevaring og sletning af disse personoplysninger hører under R3.
- **REST (✅ fase 14):** Alle routes har permission callback via `CreatorContext`. Creator'en genopslås i endpointet og i servicen (aktiv, koblet til nuværende bruger). Svar bygges af `to_array()` med kun de dokumenterede felter (ingen e-mail, intet bruger- eller creator-ID). Fejl har faste koder og generiske beskeder. Input ekkoes ikke i fejl. Uventede fejl logges og giver en generisk 500. Sociale links accepteres kun som https-URL'er uden credentials og farlige tegn, og React renderer dem som tekst i felter (ingen links i fase 14).
- **Dashboard-sektioner (✅ fase 15):** Registry'et afgør server-side, hvilke sektioner brugeren ser (capability pr. sektion). Filtrerede poster valideres, og capability-navne sendes ikke til browseren. Sektioner er kun navigation. Hvert endpoint håndhæver stadig sin egen adgang.
- **Oversættelser (✅ fase 15):** Oversatte strenge escapes som alle andre, og `TranslationsTest` afviser oversættelser, der tilføjer markup eller ændrer pladsholdere. Script-oversættelser udskrives af WordPress som inline-script med CSP-noncen.
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

**Login-redirect for rene creators (✅ fase 12):** `DashboardRoute::dashboard_url_for()` accepterer kun en `redirect_to`, der er på samme site (`wp_validate_redirect`, samme host, kun `http`/`https`) og hvis sti er præcis dashboardet eller ligger under det (ikke `/creator-dashboard-evil/`). `..`, `\` og `@` afvises også efter URL-decoding. URL'en genopbygges ud fra den normaliserede sti, så query, fragment og credentials fra input aldrig overlever. Alt andet (wp-admin, andre sider, eksterne hosts, `//evil`, `javascript:`) giver dashboardets startside.

### Branding, login og tema (fase 21, ADR-042–044)

- **Logo:** kun et attachment-ID gemmes, og det vælges kun af `merchboard_manage_settings` (capability før nonce, egen admin-post-action, audit).
  - `LogoAttachment` validerer ved gem og ved hver visning: offentligt attachment, PNG, JPEG eller WebP med matchende filendelse, størrelsesgrænser og samme origin.
  - Der er ingen SVG-upload og intet `upload_mimes`-filter; pluginet åbner ingen ny uploadvej (logoet vælges blandt eksisterende medier).
  - Billeder fra et andet domæne bruges aldrig (dashboardets CSP `img-src 'self' data:` er uændret); så vises standardlogoet.
- **Standardlogo:** `assets/brand/logo.svg` er pluginets egen, reviewede SVG uden script, event-attributter, eksterne referencer eller fonte. Den vises kun som `<img>` og CSS-baggrund, hvor scripts ikke kører.
- **Login:** `wp-login.php` brandes kun i Merch Dashboard-kontekst (login-handlingen, ikke interim login, og en `redirect_to`, der består `DashboardRoute::dashboard_url_for()`).
  - Kun præsentation via `login_form_login` (passiv observatør), `login_enqueue_scripts`, `login_body_class`, `login_headerurl` og `login_headertext`.
  - Ingen egen login-route, formular, credential-håndtering, `wp_signon()`, `authenticate`-hook, `login_url`-filter eller ændring af glemt adgangskode, reset eller logout. CAPTCHA, 2FA og hosting-regler på `/wp-login.php` virker uændret.
  - `redirect_to` er kun en betingelse og udskrives aldrig af pluginet.
  - Logo-URL'en i den inline CSS skal bestå af rene URL-tegn (ingen `"`, `(`, `)`, `\`, `<`, `>`, `;`, `{`, `}` eller whitespace); ellers udelades reglen. Stylesheetet er scopet til `body.merchboard-login` og indlæser ingen eksterne ressourcer.
- **Tema:** `PATCH /me/preferences` skriver kun den aktuelle brugers user meta (`get_current_user_id()`). Kun body'en tæller; ID'er i body og query ignoreres.
  - Værdien er en lukket enum og valideres to gange (skema og endpoint).
  - `data-theme` udskrives kun for en gyldig værdi, escapet og tjekket igen i skabelonen.
  - Ingen localStorage og ingen cookies; temaet er ikke bootstrap-data.
- **Verifikation:** unit-, integrations- og runtime-tests (Merch Dashboard Local via WP-CLI og HTTP, 2026-10-08). Login-siden og dashboardet er ikke verificeret visuelt i en browser.

### Filuploads (fremtid: avatar, produktbilleder)

- Via `media_handle_upload()` / `wp_handle_upload()` med `mimes`-whitelist (kun jpeg/png/webp), `wp_check_filetype_and_ext()`, størrelsesgrænse og capability-/ejerskabs-tjek.
- Ingen SVG-upload (XSS-risiko), medmindre den saniteres med et dedikeret bibliotek (kræver ADR).
- Filer serveres fra uploads-mappen og eksekveres aldrig. Filnavne saniteres af WordPress.
- Creators får **ikke** `upload_files`. Uploads går gennem vores eget endpoint, som sætter ejerskab.

### Security headers (dashboard-shell)

✅ Fase 12 (`ShellController::security_headers()`, på alle dashboard-svar inkl. redirect og 403): `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN`, `X-Robots-Tag: noindex, nofollow`, `nocache_headers()` og `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'self'`. Redirect, 403-siden og 503-siden har ingen JavaScript, så deres CSP tillader ingen scripts.

✅ **Fase 13 (React-shellen):** `Content-Security-Policy: default-src 'none'; script-src 'self' 'nonce-<ny pr. request>'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'self'`. Noncen (128 bit) sættes på alle `<script>`-tags, som WordPress udskriver for bundlen og dens dependencies, via `wp_script_attributes`/`wp_inline_script_attributes`. Filtrene er kun aktive, mens shellen renderes. Ingen `unsafe-eval` og ingen `unsafe-inline` for scripts. Verificeret i Chrome: 0 CSP-brud.

**Frontend-sikkerhed (fase 13):**

- **XSS:** Serverværdier renderes som tekst af React. `dangerouslySetInnerHTML` er forbudt (ESLint `react/no-danger`). Bootstrap-JSON'en hex-escapes (`JSON_HEX_TAG` m.fl.), så den ikke kan lukke `<script>`.
- **Open redirect / `javascript:`:** Alle URL'er fra bootstrap-data valideres som absolutte `http(s)`-URL'er på sidens egen origin uden credentials. Routeren accepterer kun dashboard-stier (`[a-z0-9-]`-segmenter).
- **Identitet:** Bootstrap-data indeholder intet creator-ID eller creator-navn. API-klienten sender aldrig et creator-ID for at vælge creator'en, og den kan kun kalde `merchboard/v1`. Adgang afgøres af serveren (`CreatorContext`), aldrig af UI-state.
- **Fejl:** Svar fra 401/403/5xx reduceres til en fast fejltype. Serverens fejlbeskeder og -data vises aldrig.
- **Storage:** Ingen credentials, nonces eller data i `localStorage`, `sessionStorage`, IndexedDB eller cookies fra dashboard-koden (håndhævet af ESLint og testet). Application Passwords bruges aldrig af frontend.
- **TypeScript er ikke sikkerhed:** Typer tjekkes kun ved build. Data fra serveren er `unknown`, indtil de er valideret.

**wp-admin for rene creators (✅ fase 12, ADR-026):** `AdminAreaGuard` redirecter til dashboardet på `admin_init` (prioritet 0) og på `admin_page_access_denied` (skærme, WordPress afviser før `admin_init`). AJAX, `admin-post.php`, `async-upload.php`, cron, REST og CLI undtages. Admin bar skjules (`show_admin_bar`).

### Secrets og credentials

- Aldrig i repo'et: `.env*`, `auth.json` og nøgler er gitignored. Secrets placeres i `wp-config.php` som konstanter (`MERCHBOARD_<PROVIDER>_SECRET`) eller i options med `autoload = no`.
- Aldrig sendt til frontend. Aldrig logget.
- Webhooks verificeres med HMAC (`hash_equals()`), og der er tidsstempel-tolerance.
- Hvis følsomme creator-data (fx bankoplysninger til payouts) nogensinde skal gemmes: foretræk at payout-udbyderen opbevarer dem. Ellers krypteres med `sodium_crypto_secretbox` og en nøgle fra `wp-config.php` (kræver ADR).
- **Payouts (fase 23, ADR-056):** bank- og PayPal-oplysninger krypteres med sodium `secretbox` og `MERCHBOARD_PAYOUT_KEY` (32 bytes, base64, i `wp-config.php`) i `merchboard_payout_secrets` og slettes, når payouten ikke længere venter. Uden gyldig nøgle er bank og PayPal utilgængelige. Fulde oplysninger vises kun med `merchboard_view_payout_details` og audit-logges før visning; audit, logs og bilag indeholder kun maskerede hints. Gavekortkoder gemmes aldrig i Merch Dashboard (ADR-057), og logsanitizeren redigerer nøgler med "card" i navnet.

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

### Creator-administration (fase 6)

- **Authorization:** `merchboard_manage_creators` tjekkes i admin-sidens `render()`, i hver admin-post-handler (før input læses) og igen i `CreatorService`. Ikke `manage_options`, `is_admin()` eller rollenavne. Creator-brugere og subscribers får 403 (verificeret i Playground).
- **CSRF/IDOR:** Nonces er bundet til creator-ID'et, så en gyldig nonce for én creator ikke virker på en anden (test og Playground). Creator-ID valideres server-side (`absint` og eksistens). Statuslinks er nonce-beskyttede GET-links (WordPress' row-action-mønster).
- **Ingen klientstyret tilstand:** actor (`get_current_user_id()`), tidsstempler (`current_time( …, true )`) og ID'er bestemmes server-side. Ekstra POST-felter som `actor_user_id` og `created_at` ignoreres (test).
- **Input/output:** Kun whitelistede felter læses. Status er enum, ID'er `absint`, navn `sanitize_text_field`. Al output escapes (`esc_html`, `esc_attr`, `esc_url`). Notices bruger whitelistede koder.
- **Privilege escalation:** Tilknytning giver kun rollen `merchboard_creator` (`read` + `merchboard_access_dashboard`). Ingen andre roller kan tildeles herfra.
- **Ingen permanent sletning** fra UI'et.
- **Brugerdata:** Brugervalg viser display name og login, aldrig e-mail. Fra fase 10 søges der kun i login og display name med eksplicitte `search_columns`. Uden dem søger WordPress også i e-mail, når søgeordet indeholder `@`, og så kunne man afsløre, om en e-mail tilhører en bruger. Søge-endpointet kræver `merchboard_manage_creators` og nonce, og der findes ingen `nopriv`-variant. Forslag er kun en bekvemmelighed, fordi den gemte værdi valideres igen server-side.
- **Navigation (fase 10):** Topmenuen kræver `merchboard_manage`. Creators og Settings beholder deres egne capabilities, og alle sider og handlere tjekker capability selv (verificeret med direkte URL'er).

### Audit trail (fase 6, ADR-023)

Creator-ændringer registreres i `merchboard_audit_log` med server-side actor, UTC-tid og minimal context (feltnavne, statusser og bruger-ID'er, **ingen display names eller andre værdier**). Context renses desuden via `LogContext`. Records er append-only. Ændring og audit-record skrives i én transaktion. Settings-ændringer registreres som `settings_updated` (✅ fase 9). Fejlede autorisationsforsøg på creator-endpoints logges som tekniske `warning`-poster (rate-begrænset for at undgå log-flooding), når de første creator-endpoints findes (fase 12/14).

### Database-hardening (fase 7, ADR-024)

- **Migrationslås:** atomisk (`INSERT IGNORE`), overtagelse af forladte låse med compare-and-swap og ejer-token ved frigivelse. To requests kan ikke migrere samtidig, og en langsom request kan ikke slette en andens lås. Verificeret med 6 parallelle processer mod MariaDB 10.11.19.
- **Fail closed uden transaktioner:** Ligger en plugin-tabel på en ikke-transaktionel engine (fx MyISAM), skriver pluginet intet, i stedet for at efterlade en creator-ændring uden audit-record (eller omvendt). Noticen i admin nævner ingen tabelnavne. Detaljerne står kun i loggen.
- **Audit-context kan ikke vælte en ændring:** Context gemmes som ASCII-JSON, og ugyldig UTF-8 eller ikke-endelige tal erstattes. En defekt context kan hverken bryde audit-skrivningen eller, via tabellens charset, få forretningsændringen rullet tilbage.
- **Manipulerede rækker:** Ukendte audit-actions og creator-statusser i databasen afvises ved læsning (`DatabaseException`). Ugyldig JSON-context læses som tom (verificeret mod MariaDB).
- **SQL injection mod rigtig database:** Strenge med quotes, backslashes og `%`-placeholders gemmes bogstaveligt, og injicerede sorteringsparametre ignoreres (integrationstests).
- **Testisolation:** Integrationstestene bruger en separat testdatabase (tabelprefix `mbtest_`). Lokalt kører den på en midlertidig MariaDB-server, som kun lever under testkørslen og kun lytter på 127.0.0.1. Kørslen afbrydes, hvis porten er optaget, i stedet for at forbinde til en anden database.

### Creator access og brugersletning (fase 8)

- **Én politik:** `CreatorContext` er den eneste implementering af creator-adgang. Den kombinerer `user_can()` (rolle-modellen) med den aktive record og løser creator'en ud fra brugerens ID, aldrig ud fra klient-data (IDOR). Testet med request-data, der peger på en anden creator.
- **Ingen privilege escalation:** Creator-adgang giver ingen admin-capabilities (verificeret mod rigtig WordPress: `merchboard_manage*`, `manage_options`, `edit_posts`, `list_users` og `manage_woocommerce` er alle falske). En administrator har ikke automatisk creator-adgang.
- **Fail closed:** En databasefejl under adgangstjekket logges og giver "ingen adgang".
- **Brugersletning:** `deleted_user` udløser en systemhandling uden capability-tjek, men kun for brugere, der ikke længere findes. Den kan derfor ikke misbruges til at frakoble eller deaktivere en levende brugers creator. Frigivelsen er betinget af, at creator'en stadig er koblet til netop den bruger (race mod gen-kobling). Audit-actor er NULL (systemhandling), også når en administrator udløste sletningen. Context indeholder kun bruger-ID og `reason` (ingen e-mail, login eller navn).
- **Dinglende link efter fejl:** Fejler frigivelsen (fx databasefejl), logges det, og linket peger på et ID, der ikke findes. Selv hvis et bruger-ID senere genbruges, kræver adgang capability'en, som kun gives ved kobling i admin (integrationstest).

### Permanent sletning og uninstall (fase 9, ADR-025)

- **Opt-in er svær at aktivere ved et uheld:** Den kræver `merchboard_manage_settings`, en gyldig nonce og det skrevne ord `DELETE`, og det hele tjekkes server-side. Default er fra. Kun præcis `true` i optionen tæller, så `"1"`, `"yes"` eller `1` (fx skrevet af anden kode) aktiverer ikke sletning. Ændringer audit-logges.
- **Ingen privilege escalation:** Creator-adgang, `merchboard_manage_creators`, `manage_options` og `delete_plugins` alene kan ikke ændre settingen (unit-, integrations- og HTTP-tests). Admin-siden og handleren tjekker capability før input.
- **Direkte kald:** `uninstall.php` afslutter uden `WP_UNINSTALL_PLUGIN`, som WordPress kun definerer i `uninstall_plugin()` efter sit eget `delete_plugins`-tjek. Filen kører kun, når netop dette plugin afinstalleres (`plugin_basename`-tjek), og ikke på en PHP/WordPress-version, som pluginet ikke understøtter. Uden opt-in slettes intet.
- **Kun egne data:** Tabeller slettes efter præcist navn fra `Tables` med `%i` (ingen mønstre, ingen input). Roller og capabilities fjernes efter de præcise definerede navne (`Capabilities::all()` og `merchboard_creator`), ikke efter prefix. En fremmed rolle eller capability, der tilfældigvis starter med `merchboard_`, bevares (fundet og rettet via integrationstest). Brugere slettes aldrig.
- **Delvis sletning:** Rækkefølgen og det, at opt-in'en slettes sidst, gør et afbrudt forløb genoptageligt. Der er ingen farlig mellemtilstand: pluginet er deaktiveret under uninstall.
- **Multisite:** Kun det aktuelle sites data (se [DATABASE.md](DATABASE.md#afinstallation-og-datalivscyklus)).
- **Ingen output:** Fejl logges via `Logger` (tabelnavn) og vises aldrig.
- **Bevaret historik (ADR-059, B2):**
  - Creators, audit-log, payouts, afregningsbilag, bilagsrækker og bilagstællere bevares ved opt-in-afinstallation. De krypterede betalingsoplysninger slettes altid.
  - Bevarede data indeholder ingen fulde kontonumre eller PayPal-adresser: de fulde oplysninger findes kun krypteret, mens en payout er ventende, og tømmes ved enhver afgørelse. Payouts og bilagssnapshots har kun den maskerede angivelse (`•••• 1234` uden reg.nr., `j•••@g•••.com`), og audit har kun ID'er, beløb, valuta og metode.
  - **Fritekst (`PayoutFreeText`):**
    - Referencen og afvisningsbegrundelsen afvises, før noget skrives, hvis de indeholder en e-mailadresse, en IBAN med landets længde og gyldig checksum eller payoutens eget kontonummer (når oplysningerne kan læses). Værdien gengives aldrig i fejlbesked, redirect, log eller audit.
    - IBAN findes samlet eller i grupper på fire adskilt af mellemrum, punktum eller bindestreg, også med tekst lige efter. Kontonummeret findes også med skilletegn, foranstillede nuller, payoutens eget reg.nr. foran (adskilt eller sammenskrevet) eller en dato eller et andet tal før eller efter (adskilt). Andre cifre skrevet sammen med kontonummeret (fx `4412345678` for konto 12345678 med reg.nr. 1234) regnes ikke som kontonummeret.
    - **Grænser:** vagten er en heuristik mod oplysninger indtastet ved en fejl, ikke en garanti. Andres kontonumre og kontonumre med færre end 6 betydende cifre genkendes ikke, og heller ikke kontonummeret skrevet sammen med et andet reg.nr. end payoutens. En IBAN i andre grupperinger (fx to og to), med bogstaver eller cifre klistret på eller fra et land uden for IBAN-registret i koden findes ikke. Bevidst omskrevne oplysninger (fx ord eller andre tegn mellem cifrene) fanges ikke. En reference, der tilfældigt har en IBAN's form, landekode, længde og gyldige checksum, afvises (omkring 1 ud af 97 af sådanne kandidater). Allerede gemte tekster ændres ikke.
- **Ingen adgang efter geninstallation:**
  - Afinstallationen arkiverer alle creators (`inactive`, `user_id = NULL`) og fjerner rollen og capabilities fra alle brugere.
  - Adgang kræver capability, en koblet record og status `active` (ADR-007), så en geninstallation giver ingen bruger adgang til den bevarede historik.
  - Den bevarede creator-tabel lader AUTO_INCREMENT fortsætte: et nyt creator-ID genbruger aldrig et arkiveret.
  - Manuel genaktivering af en arkiveret creator er ikke understøttet, men kun advaret (risiko for forkert tilskrivning, se DATABASE.md).
- **Ventende payouts (fail closed):** med opt-in afbryder en ventende payout eller en fejlet optælling afinstallationen, før noget ændres. WordPress beholder pluginets filer, så de ventende payouts kan behandles (ADR-059).
- **Logfilter:** `LogContext` redigerer også nøgler med `account` og `registration`.

### Audit log (fase 11)

- **Adgang:** `merchboard_view_logs` (kun administrator som standard). Den tjekkes af menuen, af WordPress ved direkte URL og igen i `render()` (fail closed med 403). Creator-adgang, `merchboard_manage*` og `manage_options` alene giver ikke adgang. Verificeret over HTTP.
- **Read-only:** `AuditRepository` har stadig kun `append`, `for_creator` og nu `paginate` (læsning). Siden har ingen POST-formular, bulk actions eller row actions. Antallet af rækker er uændret efter visning (testet).
- **SQL:** Filtre valideres (enum, positivt heltal, eksakt `Y-m-d`) og bruges kun som `prepare()`-argumenter i faste SQL-fragmenter. Tabelnavnet kommer fra `Tables`. Wildcards og SQL i filterværdier ignoreres (testet mod MariaDB).
- **Data:** Context vises via en whitelist (ingen rå JSON). Brugere vises som "Display Name (login)", aldrig e-mail, og profil-link kræver `edit_user`. Slettede brugere og creators vises som "#ID". Alt output escapes.
- **Adskillelse:** Tekniske logs vises ikke (ADR-021). Der er ingen ny log-tabel.

### Products og Orders i creator-dashboardet (fase 19, ADR-039)

- **Adgang:** `GET /me/products` og `GET /me/orders` kræver en indlogget bruger med en aktiv creator via `CreatorContext` (`require_creator()` i både permission callback og endpoint): 401 udlogget, 403 uden creator-adgang (også inaktiv creator). Routes og dashboard-sektioner findes kun, når WooCommerce-modulet er indlæst.
- **Creator-identitet:** kommer kun fra `CreatorContext` på serveren. Routes tager kun `page` og `per_page`; et `creator_id` eller andre parametre fra klienten ignoreres og bruges aldrig til autorisation (testet).
- **IDOR:** creator-filtret ligger i selve SQL'en: produkter via præcis én `_merchboard_creator_id`-række lig creator'ens ID (byte for byte), ordrelinjer via `creator_id` i sales ledgeren (`SalesOrderLines`). En ordre med linjer fra flere creators returnerer kun creator'ens egne linjer. Der er intet detalje-endpoint (`/me/orders/{id}`) i fase 19.
- **Data leakage:** svarene er whitelists (`ProductSummary`, `OrderLineView`): ingen kundedata (navn, e-mail, telefon, adresse, by, postnummer, **land**, noter, IP, betaling, kunde-/bruger-ID), ingen beløb eller valuta i ordrelinjer og ingen creator-, ordre-, produkt- eller variant-id'er (ordrelinjens `line_id` er kun en nøgle). Navne sendes som ren tekst og renderes som tekst.
- **Slettede og papirkurvsordrer:** vises som `deleted`/"Deleted" (ordre-ID'et som nummer) eller `trashed`/"Trashed" alene ud fra ordreobjektets eksistens og status; der læses intet andet fra ordren, så ingen kundedata kan slippe ud.
- **Billeder:** kun thumbnails med samme origin som sitet; andre bliver `null`. Dashboardets CSP er uændret (`img-src 'self' data:`).
- **Tests:** integrationstests for 401/403/inaktiv creator/IDOR/ugyldig paginering for begge endpoints og runtime-verifikation i Merch Dashboard Local (se [TESTING.md](TESTING.md#products-og-orders-fase-19)).

### Creator pricing (fase 22, ADR-048/049)

- **Ingen prisskrivning:** Merch Dashboard skriver aldrig en WooCommerce-pris (regular, sale eller `_price`), har ingen prisfiltre og ingen SQL mod WooCommerces data. `PricingProductStore` har kun læsemetoder. Kundernes pris kan derfor ikke ændres via creator pricing.
- **Adgang:**
  - Creator-markup og momsstatus kræver `merchboard_manage_creator_pricing` (kun administrator); en produktmarkup kræver desuden WooCommerce' `edit_product` på parent-produktet. Begge tjekkes i `CreatorPricingSettings`.
  - Creatorens egen rabatkode (ADR-068) kræver samme capability (K4). Den kontrolleres i `CreatorCodeActions` sammen med en nonce bundet til handling og creator og igen i `CreatorCodeService`. Creatoren læser kun sin egen kode via `GET /me/discount-code` (`CreatorContext`, ingen parametre, `no-store`). Udelukkelsen af ordrer sker kun server-side i synkroniseringen, og ukendte kupondata giver fail closed.
  - Creators får ingen af delene. Der er intet REST-endpoint.
- **Ingen tillid til input:**
  - Creatoren er altid parent-produktets nuværende ejer (`ProductCreatorLink`), aldrig et ID fra kalderen.
  - En produktmarkup gælder kun for den ejer, den blev sat for.
- **Historik:** snapshots er append-only, og ingen konfigurationsændring ændrer et snapshot, en profit eller en udbetaling bagud.
- **Audit:** hver ændring auditeres med aktør, creator og gammel og ny værdi, uden kundedata (`creator_markup_changed`, `creator_vat_status_changed`, `product_markup_set`/`_removed`).
- **Admin-UI:**
  - `CreatorPricingActions` tjekker capability før nonce (bundet til handling og creator), og domæneservicen tjekker capability, `edit_product` og ejerskab igen.
  - Creator-ID'et fra formularen bruges kun til at afvise et produkt, der tilhører en anden creator; det giver aldrig ejerskab.
  - Forhåndsvisningen er et skrivebeskyttet GET bag samme capability.
  - Al output escapes. Beløb og valuta valideres server-side.
- **Sletning af en ordres historik (ADR-052):** kun med `merchboard_delete_order_history` (administrator), via `admin-post.php` (POST, kun indloggede): capability (403), nonce bundet til ordren (403), indtastet ordre-ID som bekræftelse, og kun når WooCommerce-ordren ligger i papirkurven eller er slettet (tjekket under ledgerens ordrelås). Én transaktion med audit `order_history_deleted` (antal rækker og ID'er, ingen beløb eller kundedata). WooCommerce ændres aldrig.
- **Momsstatus (ADR-048):** ændres kun med `merchboard_manage_creator_pricing` (capability før nonce, domænetjek og audit `creator_vat_status_changed`). `merchboard_sales_tax` indeholder kun ledger-nøgle, ordre- og linje-ID, beløb og valuta, aldrig kundedata.
- **Snapshots (trin 5):** `merchboard_sales_line_pricing` indeholder kun ID'er, valuta, status og årsag samt enhedspriser (forretningsdata), aldrig kundens navn, e-mail, adresse, land eller andre checkout-data. Snapshottet tages som systemarbejde i kundens request uden capability-tjek og kaster aldrig; det kan ikke ændres bagefter, og der er ingen API til det.

### Købs-alerts (fase 25, ADR-065)

- **Kill switch:** alerts registreres kun, når `alerts_enabled` er slået til af en bruger med `merchboard_manage_alerts` (capability før nonce, domænetjek og audit `settings_updated`), og aldrig mens MerchAlert er aktivt.
- **Ingen tillid til input:** creatoren for en ordrelinje er altid produktets tilknytning (`ProductCreatorLink`) på det tidspunkt, ordren betales, og kun aktive creators får alerts. Routeren kører som systemarbejde uden capability og kaster aldrig.
- **Import fra MerchAlert (trin 25.10.1):** kun brugere med `merchboard_manage_alerts`; admin-post med nonce, en påkrævet backup-afkrydsning og tørkørslens fingerprint (en frisk tørkørsel skal give det samme, ellers sker intet). Importen kører ikke, mens MerchAlert er aktivt. MerchAlerts data læses kun (forberedte forespørgsler mod dets egne tabelnavne) og ændres aldrig. Kundernes fornavne og Twitch-tokens/app-secret flyttes ikke; chatskabelonens `$Name` bliver `$UserName`. En lyd gives kun til den creator, MerchAlert havde som ejer (eller en administrators upload); en anden creators lyd fjernes fra indstillingerne. Én transaktion pr. creator; audit uden persondata.
- **Opsætning af creator-siden (trin 25.9.6):** kun brugere med `merchboard_manage_alerts` læser og ændrer den (admin-REST, wp-api-fetch-nonce, audit). Input normaliseres mod faste lister af kort- og feltnøgler (ukendte og gentagne fjernes, manglende tilføjes), så en opsætning aldrig kan skjule et felt eller tilføje noget; den styrer kun rækkefølge og placering. Creators læser den kun med deres eget overlay-svar.
- **Creatorens egne designs (tilføjelse efter trin 25.9.5):** kun via `/me` (CreatorContext); en creator kan aldrig læse, bruge, ændre eller slette en anden creators designs (et ukendt eller fremmed id giver `unknown_design`). Navne renses (ingen HTML, én linje, højst 60 tegn), højst 10 pr. creator. Når et design tages i brug, valideres indstillingerne som overlayets: en ikke længere godkendt font giver standardfonten, og tekstfyld beholder kun billeder, creatoren må bruge. Kun creatorens egne lydfiler (attachment-meta) slettes, og kun når hverken overlayet eller et design bruger dem.
- **Standard-overlays (trin 25.9.5):** kun brugere med `merchboard_manage_alerts` opretter, designer og sletter dem (admin-REST, audit). Creators læser dem og kopierer en ind i deres eget overlay via `/me` (CreatorContext; aldrig en anden creators overlay). Tekstfyld i en standard kan kun bruge billedbiblioteket, og ved kopien beholder creatoren kun billeder, creatoren må bruge; en ikke længere godkendt font giver standardfonten.
- **Globalt overlay (trin 25.9.4):** totalt isoleret fra creators' overlays: egen option (uden autoload), eget hemmeligt ID (128 bit, `hash_equals()`), egen offentlig feed-route og URL, egen testkø (transient-scope `global`, så den aldrig deler kø med et creator-overlay med samme række-ID), egne lyde og billeder (egen attachment-meta; en creators filer kan hverken vælges, afspilles eller slettes). Admin-routes kræver `merchboard_manage_alerts`. Feedet svarer 404, når overlayet er slået fra, kill switchen er fra eller MerchAlert er aktiv. Test og "Afspil seneste ordre" sender aldrig chatbeskeder.
- **Ordrelog (trin 25.9.3):** kun brugere med `merchboard_manage_alerts`; ren visning (GET-parametrene periode og side valideres mod kendte værdier og tal); alt escapes ved output. Kun kundens frivillige brugernavn vises, aldrig navn eller kontaktoplysninger.
- **Grænser for lydfiler (trin 25.9.2):** kun brugere med `merchboard_manage_alerts` kan ændre dem (capability, nonce, validering af hele tal inden for faste intervaller, audit). Kontrollen af størrelse, type og længde sker stadig på serveren ved hver upload, og sitets upload-grænse kan ikke overskrides. En creators egne grænser sættes kun af administratoren på creatorens redigeringsside (`merchboard_manage_alerts`, nonce bundet til creator-ID, auditeret); creatoren kan ikke selv ændre dem, og REST-API'et tager ingen grænser fra klienten.
- **Brugernavn i checkout (trin 25.9.1):** feltet er valgfrit og slået fra som standard og vises kun med en creators produkt i kurven. Kunden skal først sætte en afkrydsning, hvis tekst siger, at navnet vises offentligt i creatorens livestream og chat; uden den bruges navnet ikke (heller ikke hvis en værdi alligevel sendes). Værdien renses (ingen tags, kontroltegn eller linjeskift) og må højst være 50 tegn; den gemmes som ordre-meta via WooCommerce CRUD og slettes ved opt-in-afinstallation.
- **Personoplysninger:** `merchboard_alert_events` gemmer kun kundens frivillige brugernavn fra checkout, aldrig navnet (projektejerens beslutning 2026-10-19; migration 19 fjernede fornavnet). Fejl logges kun med ordre-ID og fejltype, aldrig med brugernavne. Der er ingen retention-politik endnu (åbent punkt i ROADMAP, fase 25); tabellen slettes ved opt-in-afinstallation.
- **Overlay-side og feed (trin 25.3):** offentlige, fordi OBS ikke kan logge ind. Det hemmelige overlay-ID (128 bit) er adgangen; det valideres som præcis 32 hex-tegn og sammenlignes med `hash_equals()`. Ukendt ID, regenereret ID og inaktiv creator giver samme 404. Feedet tillader højst 60 forespørgsler pr. overlay pr. minut (kun eksisterende overlays tælles, så gæt skriver intet) og returnerer kun det, overlayet viser: færdig tekst og billed-URL'er på samme site, ingen kunde- eller ordre-ID'er. Siden har egen CSP (scripts kun fra sitet med nonce, ingen forbindelser, billeder eller fonte udefra), `noindex`, ingen caching og `Referrer-Policy: no-referrer`, så ID'et ikke lækker i Referer-headers. CSS-værdier valideres strengt i `OverlaySettings` (hex-farver, heltal, simpelt fontnavn, én kontrolleret tekstskygge), så MerchAlerts CSS-injektion er lukket; tekster indsættes med `textContent`, aldrig som HTML, og skabeloner erstattes i ét gennemløb.
- **Creatorens overlay (trin 25.4):** `/me/alerts/overlay` bruger `CreatorContext` (ADR-007) og tager aldrig et ID fra klienten, så overlay-URL'en med det hemmelige ID kun sendes til den creator, den tilhører (`Cache-Control: no-store`). PATCH ændrer kun formularens whitelistede felter og normaliserer dem; forkerte typer giver 400. Oprettelse og nyt ID auditeres uden ID'er (`alert_overlay_created`, `alert_overlay_id_regenerated`). Testalerts ligger højst fem ad gangen i en transient pr. overlay (nøglen er en hash af rækkens ID), gemmes ikke som alerts og sendes ikke til Twitch.
- **Google Fonts (trin 25.5, ADR-067):** serveren kalder kun katalog-endpointet `https://www.googleapis.com/webfonts/v1/webfonts` og kun, når en administrator med `merchboard_manage_alerts` beder om det (timeout 15 sekunder, ingen redirects); API-nøglen ligger i en option uden autoload, formatet tjekkes, den vises, logges og auditeres aldrig og sendes aldrig til frontend, og den slettes ved opt-in-afinstallation. Kun familier fra kataloget kan godkendes, og navnet skal være et rent familienavn (bogstaver, tal, mellemrum og bindestreger, højst 64 tegn), før det bruges i CSS eller en URL; stylesheet-URL'en bygges af serveren og valideres igen i dashboardet. Creatoren kan kun gemme en godkendt font (ellers 400). En fjernet font indlæses ingen steder. CSP: dashboard-shellen og overlay-siden tillader `https://fonts.googleapis.com` i `style-src` og `https://fonts.gstatic.com` i `font-src`; `connect-src` og `script-src` er uændrede, så scripts aldrig kan kontakte Google. GDPR-forbeholdet i ADR-067 gælder: browseren sender creatorens (og OBS-computerens) IP-adresse til Google, når en godkendt font er valgt.
- **Designerens layout (trin 25.5):** `PATCH /me/alerts/overlay` med `layout` normaliseres helt af `OverlaySettings` (kendte elementer, heltal inden for grænserne, booleans); ukendte nøgler forsvinder.
- **Lyd-upload (trin 25.6):** kun via `POST /me/alerts/overlay/sounds/{slot}` for creatoren fra `CreatorContext` (REST-nonce som alle `/me`-routes); slottet skal være standardlyden eller en af creatorens egne kategorier. Kontrol i rækkefølge: upload-fejlkode, størrelse (højst 10 MB eller sitets mindre grænse), endelse og faktisk type via `wp_check_filetype_and_ext()` med kun `mp3`, `wav` og `ogg`/`oga` → flytning med `wp_handle_upload()` (unikt filnavn i uploads-mappen) → læsning med getID3 (`wp_read_audio_metadata()`), hvor formatet skal passe til endelsen, og længden højst 30 sekunder. En afvist fil slettes straks, og en vedhæftning, hvis indstillinger ikke kan gemmes, slettes igen. Metadata skrives direkte (ingen `wp_generate_attachment_metadata()`, som kan læse indlejret album-art med GD/Imagick og opbruge hukommelsen). Hver fil får creatoren som ejer (`_merchboard_alert_sound_creator`); kun ejerens filer afspilles, vises eller slettes, også hvis indstillingerne peger på en anden fil (fx efter en import). Lyd-URL'er skal være på sitet selv, og overlay-siden (`media-src 'self'`) og dashboardet (nu også `media-src 'self'`) afspiller kun lyd derfra. Ændringer af lyde auditeres ikke (udseende).
- **Twitch-chatbot (trin 25.8, ADR-066):** serveren kalder kun Twitchs godkendte endpoints (`id.twitch.tv/oauth2/token`, `api.twitch.tv/helix/users`, `/chat/messages`, `/eventsub/subscriptions`; `wp_remote_request` uden redirects, timeout 10 s). Beskeder sendes med et app access token (client credentials). Bruger-tokens fra OAuth (bot-kontoen og creatorens `channel:bot`) bruges kun til at slå kontoen op og gemmes aldrig; kun id og login gemmes. Client Secret, app-token og webhook-secret krypteres med libsodium (`sodium_crypto_secretbox`, nøgle afledt af `wp_salt( 'auth' )` med et chatbot-domæne; adskilt fra `MERCHBOARD_PAYOUT_KEY`; skifter saltet, skal app og bot sættes op igen) i en option uden autoload. OAuth bruger en tilfældig enkeltbrugs-state (128 bit, 10 minutter) bundet til brugeren og creatoren; callbacken handler som den bruger, der startede, og kun mod sitets egne sider (`wp_validate_redirect`). Botten kan ikke tilføjes sin egen kanal. Webhooken accepterer kun beskeder med gyldig HMAC-SHA256-signatur (`hash_equals`) og højst 10 minutter gamle. Chatloggen er persondata (seernes navne og beskeder) og gemmes højst 24 timer og 200 pr. kanal; admin-vinduet indsætter beskeder som tekst og bruger kun farver på formen `#rrggbb`. Chatbeskeder fra købs-alerts indeholder kun alert-variablerne (ingen beløb). Admin-handlinger kræver `merchboard_manage_alerts` (også i domæneklasserne) og nonce; creatorens ruter er `/me`-scoped (CreatorContext).
- **Kick-chatbot (trin 25.8.2, ADR-069):** serveren kalder kun Kicks godkendte endpoints (`id.kick.com/oauth/token` og `/revoke`, `api.kick.com/public/v1/channels`, `/chat`, `/events/subscriptions`, `/public-key`; `wp_remote_request` uden redirects, timeout 10 s). **Creatorens Kick-token gemmes** (projektejerens godkendelse 2026-10-20), fordi Kick kun sender en bot-besked med kanalejerens token: access- og refresh-token krypteres med libsodium (`ChatCipher`, nøgle afledt af `wp_salt( 'auth' )` med Kicks eget domæne, adskilt fra Twitch og `MERCHBOARD_PAYOUT_KEY`) og dekrypteres kun på serveren i det øjeblik, de sendes til Kick. De returneres aldrig af en REST-route eller admin-side, logges ikke og står ikke i audit-loggen (kun kanalens navn og platformen). Et nyt token-par gemmes i én skrivning; afviser Kick fornyelsen, slettes tokens og kanal. "Fjern botten" tilbagekalder begge tokens hos Kick og sletter dem. Begrænsning: den, der har både databasen og sitets salte, kan dekryptere (som al kryptering på sitet). OAuth bruger PKCE (S256) og en enkeltbrugs-state; verifier'en forbliver på serveren. Webhooken accepterer kun beskeder med gyldig RSA-SHA256-signatur fra Kicks offentlige nøgle (caches et døgn; hentes højst hvert 10. minut igen ved en ugyldig signatur) og højst 10 minutter gamle.
- **YouTube-chatbot (trin 25.8.4, ADR-070):** serveren kalder kun Googles godkendte endpoints (`oauth2.googleapis.com/token` og `/revoke`; YouTube Data API v3 `channels`, `liveBroadcasts` og `liveChat/messages`; `wp_remote_request` uden redirects, timeout 10 s); browseren sendes til `accounts.google.com` for at logge ind. **Bottens og creatorens Google-tokens gemmes** (projektejerens godkendelse 2026-10-20), fordi YouTube kun skriver i en live-chat med en brugers token: bottens access- og refresh-token og Client Secret ligger i option `merchboard_alert_youtube`, creatorens i kanaltabellen, alle krypteret med libsodium (`ChatCipher`, YouTubes eget domæne, adskilt fra Twitch, Kick og `MERCHBOARD_PAYOUT_KEY`). Botten har kun `youtube.force-ssl`, creatoren kun `youtube.readonly` (læse egen livestream). Tokens returneres aldrig af en REST-route eller admin-side, logges ikke og står ikke i audit-loggen (kun kanalens navn og platformen); en fejlet login logges med trin, grund og Googles HTTP-status, aldrig kode eller token; en besked, YouTube afviser, logges med creatorens id, grund, HTTP-status og Googles egen fejltekst (renset, højst 300 tegn), aldrig beskedens tekst eller et token; det samme gælder et afvist live-tjek (fra 2026-10-22). Afviser Google bottens refresh-token, markeres botten og intet sendes, før en administrator logger ind igen. Et afvist creator-token fornyes én gang; afviser Google også fornyelsen, slettes tokens og kanal. "Fjern botten" og "Afbryd botten" tilbagekalder tokens hos Google. Botten kan ikke tilføjes til sin egen kanal. OAuth bruger PKCE (S256), offline-adgang og en enkeltbrugs-state, bundet til brugeren og creatoren. Admin-siden indlejrer `youtube.com/live_chat` (iframe fra YouTube) i wp-admin; creator-dashboardet indlejrer intet fra Google. Samme begrænsning som Kick: den, der har både databasen og sitets salte, kan dekryptere.
- **YouTube-chatbottens privatlivspolitik og vilkår (trin 25.8.5, ADR-071):** seks offentlige sider under dashboardets adresse er en bevidst undtagelse fra login-kravet. De besvares fra en præcis whitelist (`LegalPage::page()`) på `template_redirect` før dashboardet; alle andre dashboard-stier kræver login som før. Siderne har ingen scripts, formularer eller cookies (CSP `default-src 'none'`, `frame-ancestors 'none'`). Teksterne redigeres kun med `merchboard_manage_alerts` (nonce, Post/Redirect/Get) og begrænses til `wp_kses_post()` ved gem og visning; ændringer auditeres uden teksten. Godkendelser gemmes som user meta (`{version, at}`) og i audit-loggen (version og rolle); de kræves server-side (REST 400 og admin-handling uden kryds afvises), ikke kun i browseren. En ny version stopper YouTube-beskeder, indtil botten og creatoren har godkendt den.
- **Alert-historik (trin 25.7):** historikken, gensend og "Stop alle alerts" er `/me`-ruter (CreatorContext, ADR-007); id'er i ruterne vælger kun blandt creatorens egne alerts (en anden creators alert eller ordre giver 404, også i en ordre med flere creators). Historikken viser kun det, en alert viser (ordrenummer, produkt, variant, antal, kundens frivillige brugernavn, tid). Gensend og stop ændrer kun, hvad overlayet viser, og auditeres ikke (som testalerts); køen er begrænset til 20. "Anonymisér brugernavne" er en UI-præference i user meta, ikke i browser-storage.
- **Billeder til tekstfyld (trin 25.6.10):** `ImageFiles` tjekker filendelse og reel type før flytning (`wp_check_filetype_and_ext`, kun PNG/JPG/GIF/WebP; SVG afvises) og læser derefter billedet (`wp_getimagesize`, typen skal passe, højst 4096 × 4096 pixels og 5 MB); en afvist fil slettes straks. Creatorens billeder markeres med ejeren og vises, listes og slettes kun for ejeren; en `text_fills`-værdi med et andet billede end creatorens eget eller et biblioteksbillede ryddes ved gem og vises aldrig i overlayet. Overlay-scriptet accepterer kun billed-URL'er fra samme origin og sætter dem som escaped CSS `url()`. Biblioteket ændres kun med `merchboard_manage_alerts` (tjekket igen i `AlertImageLibrary`), nonce pr. handling og pr. billede, og auditeres (filnavnet).
- **Lydbibliotek (trin 25.6.3):** kun `merchboard_manage_alerts` tilføjer og fjerner bibliotekslyde (capability i handleren og igen i `AlertSoundLibrary`, nonce pr. handling og pr. lyd); filerne valideres som creatorernes (`SoundFiles`) og markeres med `_merchboard_alert_sound_library`. Creators kan kun vælge attachments med den markering (ellers 400) og sletter dem aldrig. Ændringer auditeres med filnavnet.
- **Skjult OBS-URL (trin 25.6.2):** dashboardet viser overlay-ID'et maskeret, indtil creatoren trykker "Show URL"; "Open overlay" er en knap (ikke et link, hvis adresse ville ses i statuslinjen). URL'en sendes stadig i API-svaret til creatoren selv (`Cache-Control: no-store`).
- **Senere trin:** Twitch (ADR-066) dokumenteres her, når det implementeres.

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
