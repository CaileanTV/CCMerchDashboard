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

**Login-redirect for rene creators (✅ fase 12):** `DashboardRoute::dashboard_url_for()` accepterer kun en `redirect_to`, der er på samme site (`wp_validate_redirect`, samme host, kun `http`/`https`) og hvis sti er præcis dashboardet eller ligger under det (ikke `/creator-dashboard-evil/`). `..`, `\` og `@` afvises også efter URL-decoding. URL'en genopbygges ud fra den normaliserede sti, så query, fragment og credentials fra input aldrig overlever. Alt andet (wp-admin, andre sider, eksterne hosts, `//evil`, `javascript:`) giver dashboardets startside.

### Filuploads (fremtid: avatar, produktbilleder)

- Via `media_handle_upload()` / `wp_handle_upload()` med `mimes`-whitelist (kun jpeg/png/webp), `wp_check_filetype_and_ext()`, størrelsesgrænse og capability-/ejerskabs-tjek.
- Ingen SVG-upload (XSS-risiko), medmindre den saniteres med et dedikeret bibliotek (kræver ADR).
- Filer serveres fra uploads-mappen og eksekveres aldrig. Filnavne saniteres af WordPress.
- Creators får **ikke** `upload_files`. Uploads går gennem vores eget endpoint, som sætter ejerskab.

### Security headers (dashboard-shell)

✅ Fase 12 (`ShellController::security_headers()`, på alle dashboard-svar inkl. redirect og 403): `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN`, `X-Robots-Tag: noindex, nofollow`, `nocache_headers()` og `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'self'`. Siderne har ingen JavaScript, så CSP'en tillader ingen scripts. Fase 13 tilføjer `script-src` for vores egne handles (evt. nonces via `wp_inline_script_attributes`).

**wp-admin for rene creators (✅ fase 12, ADR-026):** `AdminAreaGuard` redirecter til dashboardet på `admin_init` (prioritet 0) og på `admin_page_access_denied` (skærme, WordPress afviser før `admin_init`). AJAX, `admin-post.php`, `async-upload.php`, cron, REST og CLI undtages. Admin bar skjules (`show_admin_bar`).

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

### Audit log (fase 11)

- **Adgang:** `merchboard_view_logs` (kun administrator som standard). Den tjekkes af menuen, af WordPress ved direkte URL og igen i `render()` (fail closed med 403). Creator-adgang, `merchboard_manage*` og `manage_options` alene giver ikke adgang. Verificeret over HTTP.
- **Read-only:** `AuditRepository` har stadig kun `append`, `for_creator` og nu `paginate` (læsning). Siden har ingen POST-formular, bulk actions eller row actions. Antallet af rækker er uændret efter visning (testet).
- **SQL:** Filtre valideres (enum, positivt heltal, eksakt `Y-m-d`) og bruges kun som `prepare()`-argumenter i faste SQL-fragmenter. Tabelnavnet kommer fra `Tables`. Wildcards og SQL i filterværdier ignoreres (testet mod MariaDB).
- **Data:** Context vises via en whitelist (ingen rå JSON). Brugere vises som "Display Name (login)", aldrig e-mail, og profil-link kræver `edit_user`. Slettede brugere og creators vises som "#ID". Alt output escapes.
- **Adskillelse:** Tekniske logs vises ikke (ADR-021). Der er ingen ny log-tabel.

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
