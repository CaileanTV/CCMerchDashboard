# WordPress Admin

> Status: **Admin komplet til og med fase 12:** topmenu med Overview (`merchboard_manage`), Creators (fase 6/10), Settings (fase 9, dashboard-slug fra fase 12) og Audit log (`merchboard_view_logs`, fase 11). **Fase 17 (✅ 2026-09-28) med WooCommerce aktivt:** creatorens redigeringsside har afsnittene "Product category" og "Products", Creators-listen kolonnen "Product categories" og visningen "Category reconciliation", Audit log viser produkt- og kategori-hændelserne læsbart, og WooCommerce' produktliste har en skrivebeskyttet "Creator"-kolonne og et filter (se [WooCommerce-skærme](#woocommerce-skærme-fase-17)). Intet nyt menupunkt og ingen ny capability.

## Principper

- Følger WordPress' admin-mønstre: `add_menu_page`/`add_submenu_page`, Settings API, `WP_List_Table`, admin notices og standard-CSS-klasser (`wrap`, `form-table`, `button-primary`). Administratorer kender dem, de er tilgængelige, og de kræver ingen build.
- Server-renderet PHP. Formularer poster til `admin-post.php?action=merchboard_*` med `check_admin_referer()` og `current_user_can()` og følger Post/Redirect/Get med admin notice.
- React bruges kun på en admin-skærm, hvis der er et konkret behov for rig interaktivitet (fx grafer), og da gerne med `@wordpress/components`.

## Menustruktur

```text
Merch Dashboard                       ✅ fase 10: menu-capability merchboard_manage (slug `merchboard`, Modules/Admin/AdminMenu)
├── Overview                   ✅ fase 10: creator-tal, dinglende links og systemstatus; ✅ fase 11: seneste aktivitet (kun med merchboard_view_logs)
├── Creators                   ✅ fase 6: liste (WP_List_Table: status-filter, sortering, paginering)
│   ├── (Add/Edit creator)     ✅ fase 6: visningsnavn, status, WordPress-bruger; fase 17 (WooCommerce): afsnittene "Product category" og "Products"
│   └── (Category reconciliation) fase 17 (WooCommerce): tørkørsel, bekræftelse og "Needs attention" (action=category-sync)
├── Settings                   ✅ fase 9: "Data on uninstall" (opt-in for permanent sletning); ✅ fase 12: "Creator dashboard" (URL-slug); ✅ fase 18, trin 6: "Sales ledger" (tællende ordrestatusser, kun med WooCommerce); ✅ fase 21: "Branding" (logo). Tabs General · Access · Advanced kommer, når de får indhold
├── Payouts                    ✅ fase 23, trin 23.6 (WooCommerce, ADR-055/056/058): kø og historik for creatorernes payout-anmodninger, afvisning og registrering af manuel overførsel; kræver merchboard_manage_payouts; fulde bank-/PayPal-oplysninger kræver desuden merchboard_view_payout_details
├── Order history              ✅ fase 22 (WooCommerce, ADR-051/052): ordrehistorik for sales ledgeren pr. ordre; kræver merchboard_manage_creators; sletning af en testordres historik kræver merchboard_delete_order_history
└── Audit log                  ✅ fase 11: read-only, filtrér på hændelse, creator og periode; kræver merchboard_view_logs (tekniske logs: WooCommerce → Status → Logs)
```

### Kritik af det oprindelige forslag og ændringer

| Oprindeligt | Anbefaling | Hvorfor |
| --- | --- | --- |
| **Products** og **Orders** som egne menupunkter | **Integrér i WooCommerce' egne skærme** i stedet: "Creator"-kolonne og -filter på produktlisten (fase 17), en kolonne i ordrelisten (udskudt, ADR-030). Selve koblingen administreres på creatorens redigeringsside i Merch Dashboard, ikke i produktets data-panel (ADR-032) | At duplikere WooCommerce' produkt- og ordre-UI giver to steder at redigere, synk-fejl og meget kode. Administratorer arbejder allerede i WooCommerce. |
| **Statistics** som eget menupunkt | Pr. creator: en "Sales"-tab på creator-redigeringssiden. Global oversigt: på Overview. Et separat "Reports"-punkt tilføjes først, når der er rapporter nok til at retfærdiggøre det. | Undgår en tom side i månedsvis |
| **Integrations** som eget menupunkt | Tab under Settings, indtil der er flere integrationer med egen status/log-UI | Samme grund |
| **Creator access** som egen side | En del af creator-redigeringen (status: aktiv/inaktiv) | Adgang er en egenskab ved creator'en, ikke en separat entitet |
| **Dashboard** | Kaldes **Overview** i admin | Undgår forveksling med WordPress' eget "Dashboard" og med Creator Dashboard |

## Skærme

### Creators-liste ✅ (fase 6)

`Modules/Creators/Admin/CreatorsListTable` (`WP_List_Table`). Kolonner: visningsnavn (link til redigering), WordPress-bruger ("Display Name (login)"; link til `user-edit.php` kun hvis den aktuelle bruger må redigere brugeren), status, oprettet og sidst opdateret (sitets tidszone). Views: Alle / Aktive / Inaktive. Sortering på visningsnavn, status, oprettet og opdateret (whitelisted igen i repositoriet). 20 pr. side.

Row actions: **Rediger** og **Aktivér/Deaktivér** (GET-link til `admin-post.php` med nonce, der er bundet til creator-ID'et). Ingen bulk actions og **ingen Delete** (se DATABASE.md). E-mails, passwords og andre brugerdata vises aldrig.

### Opret/rediger creator ✅ (fase 6)

- Felter: **visningsnavn** (1-191 tegn, `sanitize_text_field`), **status** (`active`/`inactive`; **default `inactive`** for nye creators, som sikker default) og **WordPress-bruger** (valgfri select).
- **Brugervalg (✅ fase 10, skalerbart):** et tekstfelt `user` med brugerens **login**. Feltet skal være tomt for "ingen bruger". `#<id>` bevarer uændret et link til en bruger, der ikke længere findes, så linket ikke frakobles stille. Serveren slår login op (`UserAccounts::find_by_login`), og `CreatorService` validerer som før, at brugeren findes og ikke er koblet andetsteds. UNIQUE-indexet er sidste sikkerhedsnet. Der hentes ingen brugerliste til siden.
- **Forslag (progressive enhancement):** `assets/js/creator-user-search.js` (vanilla JS, ingen build, kun på Creators-skærmen) henter forslag fra `admin-ajax.php?action=merchboard_search_users` (`CreatorUserSearch`: `merchboard_manage_creators` + nonce, ingen `nopriv`-handler) og viser dem i en `<datalist>` med `textContent` (aldrig HTML). Der søges i **login og display name, aldrig e-mail**, også når søgeordet indeholder `@`. Der kræves mindst 2 tegn og returneres højst 20 resultater. Brugere, der allerede er koblet til en creator, udelades. Svaret indeholder kun login og "Display Name (login)". Uden JavaScript skrives login direkte.
- **Profil-link:** Redigeringsformularen viser "Open WordPress user profile" (`get_edit_user_link()`), kun når brugeren findes og den aktuelle administrator har `edit_user` for den. Et link til en slettet bruger vises som "Linked to user #ID, which no longer exists".
- **Tilknyt bruger** giver brugeren rollen `merchboard_creator` som *ekstra* rolle (eksisterende roller bevares). **Frakobl** fjerner kun den rolle. Hverken brugeren eller creator'en slettes. Rolleændringen sker efter commit (ADR-023).
- **Deaktivering** bevarer rollen. Adgang (fase 8) kræver `active`.
- Vi opretter ikke nye WordPress-brugere fra denne side (fase 6). Brugere oprettes via WordPress' egen "Users → Add New".

### Request-flow (sikkerhed)

Alle ændringer går via `Modules/Creators/Admin/CreatorAdminActions` på `admin-post.php` (`merchboard_save_creator`, `merchboard_set_creator_status`):

1. `current_user_can( Capabilities::MANAGE_CREATORS )`, ellers `wp_die` 403.
2. Nonce via `wp_verify_nonce()`, bundet til handling **og** creator-ID (`merchboard_save_creator_{id}`, `merchboard_set_creator_status_{id}`), ellers 403. Et gyldigt link til creator 1 kan ikke genbruges på creator 2.
3. Input: `absint` for ID'er, `CreatorStatus::tryFrom` for status, `sanitize_text_field` for navn. Ikke-skalære felter behandles som tomme. Actor, tidsstempler og ID'er kan ikke sættes fra requesten.
4. `CreatorService` tjekker capability igen, validerer forretningsregler og skriver ændring og audit i én transaktion.
5. Post/Redirect/Get med en **whitelistet notice-kode** (`merchboard_notice`). Der er ingen fri tekst i URL'en, og ukendte koder vises ikke. Tekniske fejl logges (Logger) og vises neutralt uden SQL, stier eller exception-tekst.

Admin-siden selv er beskyttet af menu-capability'en og tjekker capability igen i `render()`.

Ligger pluginets tabeller på en storage engine uden transaktioner (fx MyISAM), viser siden en fejl-notice om, at creator-ændringer er slået fra, indtil tabellerne er konverteret til InnoDB. Ændringer afvises så med den neutrale tekniske fejl (ADR-024). Noticen nævner ingen tabelnavne.

### Overview ✅ (fase 10)

`Modules/Admin/OverviewPage` (capability `merchboard_manage`, tjekkes igen i `render()`). Siden viser kun data, der findes i dag, og ingen personoplysninger:

- **Creators:** antal aktive, inaktive og i alt, antal koblet til en WordPress-bruger og en advarsel, hvis creators er koblet til brugere, der ikke længere findes. Der linkes til Creators, hvis brugeren har `merchboard_manage_creators`.
- **Systemstatus:** pluginversion, databaseschema (aktuelt, afventer eller fejlet), transaktionsunderstøttelse (engine-tjek, ADR-024), roller og capabilities (synkroniseret eller afventer), WooCommerce (aktiv med version eller ikke aktiv) og om data slettes ved afinstallation. Der linkes til Settings, hvis brugeren har `merchboard_manage_settings`.
- **Seneste aktivitet (✅ fase 11):** de 5 nyeste audit-records (tid og hændelse, ingen navne) med link til Audit log. Vises kun for brugere med `merchboard_view_logs`.
- Der vises ingen salgstal eller statistik, fordi de ikke findes endnu. Rewrite-status kommer, når dashboard-routen findes (fase 12).

### Navigation og adgang ✅ (fase 10)

`Modules/Admin/AdminMenu` ejer topmenuen (`merchboard`, `merchboard_manage`) og tilføjer den på `admin_menu` med prioritet 9. Feature-moduler tilføjer derefter deres submenuer med egen, mere specifik capability: Creators `merchboard_manage_creators` og Settings `merchboard_manage_settings`. En skjult menu er aldrig den eneste beskyttelse: WordPress afviser direkte adgang til en side uden sidens capability, og hver side og handler tjekker capability igen. Verificeret over HTTP: subscriber og creator får 403 overalt. En bruger med kun `merchboard_manage_creators` kan åbne Creators, men ikke Overview eller Settings.

**WooCommerce-note:** Med WooCommerce aktiv sender WooCommerce brugere uden `edit_posts`, `manage_woocommerce` eller `view_admin_dashboard` fra wp-admin til "Min konto". En rolle, der kun har `merchboard_manage_creators`, skal derfor også have en af dem for at nå wp-admin.

### Bruger-profil (`user-edit.php`) ✅ (fase 10)

`Modules/Creators/Admin/UserProfileSection` (hooks `edit_user_profile` og `show_user_profile`) vises kun for brugere med `merchboard_manage_creators`. Er brugeren koblet, vises "Linked to <creator> (status)" med link til redigering. Ellers vises "This user is not linked to a creator" med link til opret-siden med brugeren forvalgt (`user_id`, bruges kun hvis brugeren findes, og valideres igen ved gem). Kun creator'ens navn og status vises. Der ændres intet herfra.

### Settings

✅ **Fase 9:** `Modules/Settings`: `SettingsPage` (submenu "Settings", capability `merchboard_manage_settings`), `SettingsActions` (`admin-post.php?action=merchboard_save_settings`) og `SettingsService`. Samme mønster som creator-administrationen: capability → nonce → whitelistede felter → service (tjekker capability igen) → Post/Redirect/Get med whitelistede notice-koder. WordPress' Settings API (`options.php`) bruges bevidst ikke, fordi den som standard kræver `manage_options`. Pluginet autoriserer med sin egen `merchboard_manage_settings`.

**"Delete Merch Dashboard data when the plugin is deleted (the history listed above is kept)"** (default fra; før ADR-059 "Delete all Merch Dashboard data when the plugin is deleted"):

- Siden viser tydeligt, om permanent sletning er slået til (rød advarsel) eller fra, hvad der slettes, og hvad der aldrig slettes.
- **Fra ADR-059** viser siden desuden:
  - hvad der bevares som historisk dokumentation: payout-historik, afregningsbilag med rækker og nummerering, audit-loggen og de arkiverede creators;
  - at en geninstallation kun understøtter historisk dokumentation, med advarslen om forkert tilskrivning ved genaktivering af en arkiveret creator;
  - antallet af ventende payouts, når sletning er slået til. Sletning af pluginet afvises, så længe en payout er ventende.
- **Creators** viser "(archived on uninstall)" i listen og en advarsel på redigeringssiden for arkiverede creators.
- **Plugins-skærmen** viser en advarsel, mens pluginet er aktivt, sletning er slået til, og der findes ventende payouts.
- **Overview** angiver, hvad der bevares.
- De danske oversættelser af de nye tekster kom i et separat commit (`121efd7`).
- **Slå til:** afkryds **og** skriv `DELETE` i bekræftelsesfeltet. Det tjekkes server-side i `SettingsService`, ikke kun i formularen.
- **Slå fra:** kræver ingen bekræftelse, fordi det altid er sikkert.
- Hver ændring skriver en `settings_updated`-audit-record (actor = administratoren). Audit og option skrives i én transaktion.
- Kun `merchboard_manage_settings` (administratorer). Creators, subscribers og brugere med kun `merchboard_manage_creators` eller WordPress' `manage_options` får 403.

**"Creator dashboard"** (✅ fase 12, egen formular `admin-post.php?action=merchboard_save_dashboard_slug` og egen nonce, så den aldrig rører sletnings-opt-in'en):

- Viser dashboardets aktuelle adresse og et felt til slug'en (default `creator-dashboard`). Ved plain permalinks vises en note om, at slug'en ikke bruges.
- Validering i `SettingsService::set_dashboard_slug()`: 3-50 tegn, små bogstaver, cifre og enkelte bindestreger. Ikke en reserveret sti (fx `wp-admin`, `wp-json`, `login`, `feed`, `shop`, `cart`, `checkout`, `my-account`, `product`). Ikke brugt af indhold (post, side eller produkt i alle statusser undtagen papirkurv) eller af en rewrite-base (offentlige post types, arkiver, taksonomier, kategori- og tag-base). Notices: `invalid_slug` og `slug_in_use`.
- En ændring skriver `settings_updated` med `setting`, `previous_value` og `value` (i samme transaktion som option'en) og sletter de gemte rewrite rules. Den gamle URL giver derefter 404, og login-redirect og wp-admin-redirect følger den nye.
- Kun `merchboard_manage_settings`.

```text
Deaktivering
→ data bevares (tabeller, options, rolle og capabilities uændrede)

Afinstallation (slet plugin) uden eksplicit opt-in
→ data bevares

Afinstallation med eksplicit opt-in ("Delete Merch Dashboard data when the plugin is deleted")
→ ventende payout eller fejl ved optællingen: intet slettes, og sletningen af pluginet afvises (ADR-059)
→ ellers slettes Merch Dashboards egne data, inkl. product meta `_merchboard_creator_id` (fra fase 17, ADR-030) og de krypterede betalingsoplysninger
→ bevares (ADR-059): creators (arkiveret), audit-loggen, payouts, afregningsbilag, bilagsrækker og bilagstællere
→ alle andre WordPress- og WooCommerce-data bevares
```

**"Branding"** (✅ fase 21, trin 6, ADR-042; egen formular `admin-post.php?action=merchboard_save_logo` og egen nonce; står efter "Creator dashboard"):

- **Logoet:** ét globalt logo, ikke pr. creator, til toppen af creator-dashboardet og WordPress' login-side, når creators logger ind til dashboardet. Andre login-sider ændres ikke.
- **Visning:** siden viser logoet i brug (eget logo eller Merch Dashboard-standardlogoet) og en advarsel, hvis et valgt logo ikke længere kan bruges (slettet, ændret eller på et andet domæne). Så vises standardlogoet.
- **"Choose logo"** åbner WordPress' mediebibliotek, filtreret til PNG, JPEG og WebP (kræver JavaScript), og **"Save logo"** gemmer valget.
- **"Use the default logo"** går tilbage til standardlogoet og virker også uden JavaScript.
- **Server-side validering** (`SettingsService::set_logo_attachment_id()` → `LogoAttachment`):
  - kun et offentligt attachment;
  - PNG, JPEG eller WebP, og filendelsen skal passe til typen. SVG afvises altid;
  - mindst 64 px højt, højst 2000 px på den længste side og mellem kvadratisk og seks gange så bredt som højt;
  - billedet skal ligge på sitets eget domæne.
  - Notices: `logo_not_found`, `logo_wrong_type`, `logo_wrong_size` og `logo_foreign_url`.
- **Lagring:** kun attachment-ID'et i `merchboard_settings['logo_attachment_id']` (0 = standardlogo). En ændring skriver `settings_updated` med `setting`, `previous_value` og `value` (ID'er) i samme transaktion. Attachmentet ejes af mediebiblioteket og slettes aldrig af pluginet.
- **Adgang:** kun `merchboard_manage_settings`; capability tjekkes før nonce.
- **Brug:** logoet skal kunne læses på lys baggrund. På dashboardets mørke temaer vises det på en lys plade.

**"Sales ledger"** (✅ fase 18, trin 6, runtime-verificeret i Merch Dashboard Local med HPOS og legacy, ADR-038 statusnote "trin 6 afsluttet"; ADR-038 S1–S14 og statusnote 2026-09-30):

- Tilføjes af WooCommerce-modulet via udvidelsespunktet `merchboard_settings_sections` (kaldes i `SettingsPage::render()` efter capability-tjekket); Settings-modulet kender ikke WooCommerce. Vises kun med WooCommerce aktivt og står efter "Creator dashboard" og før "Data on uninstall".
- Egen formular og nonce (egen admin-post-action); capability `merchboard_manage_settings` tjekkes før nonce. Notices via sektionens egne query-argumenter.
- Én afkrydsning pr. registreret status fra `wc_get_order_statuses()` (gemt uden `wc-`), undtagen `pending`, `failed`, `cancelled`, `refunded`, `checkout-draft` og `trash`. Gemte statusser, der ikke længere er registreret, vises som "ikke registreret" og kan beholdes eller fravælges, men ikke tilføjes på ny. Registrerede statusser, der ikke kan understøttes (nøgle uden `wc-`, ugyldigt format som store bogstaver, eller slug over 17 tegn), vises under "Not supported" med årsag og uden afkrydsning (ADR-038, statusnote 2026-09-30). Mindst én status kræves; standard `processing` + `completed`.
- Forklaring i UI: Ændringen gælder for synkroniseringer efter ændringen – også af eksisterende ordrer, ikke kun nye ordrer. Der sker ingen masse-genberegning og ingen backfill; en ordre vurderes efter den aktuelle liste, næste gang den synkroniseres, og kan så få reversal og lukning eller en ny cyklus med behandlingstidspunktet som `occurred_at` (følger af den tilstandsbaserede synkronisering, ADR-038 pkt. 13). Historiske rækker ændres aldrig.
- En ændring skriver `settings_updated` med `setting` = `counted_order_statuses`, `previous_value` og `value` som lister (vises som `a, b`), i samme transaktion som option'en.

**Planlagte settings** (når de får indhold):

| Tab | Indstillinger |
| --- | --- |
| General | Dashboard-slug (✅ fase 12) og Branding/logo (✅ fase 21) er implementeret. En admin-styret primærfarve er fravalgt (kan ikke kontrastgaranteres, ADR-043); dashboardets farver kommer fra de seks temaer, som hver bruger vælger på sin Profile-side. |
| Access | Hvilke roller ud over administrator der får admin-caps (fx `shop_manager`). Login-redirect og wp-admin-blokering for rene creators er altid slået til (ADR-026). Til/fra-knapper tilføjes kun ved et konkret behov. |
| Advanced | "Slet alle data ved afinstallation" (fra). En indstilling for audit-retention (dage) findes ikke i v0.1.0 (ADR-029) og afhænger af den retention-politik, der skal besluttes før produktion. Teknisk log-niveau styres af `WP_DEBUG`/filteret `merchboard_log_level` (ADR-021). |

Settings gemmes i én option `merchboard_settings`. Alt læses gennem `Core\Settings\Settings::sanitize()` (whitelist af nøgler og typer), så en forfalsket eller korrupt værdi aldrig giver en uventet indstilling.

### Audit log ✅ (fase 11)

`Modules/Admin/AuditLogPage` og `AuditLogListTable` (`WP_List_Table`), capability `merchboard_view_logs`. Den tjekkes i menuen, af WordPress ved direkte adgang og igen i `render()`. Siden er **read-only**: der er ingen formularer, der ændrer noget, ingen bulk- eller row actions, ingen sletning og ingen oprettelse. Se [features/admin-audit-log.md](features/admin-audit-log.md).

- **Kolonner:** tid (sitets tidszone), hændelse (link, der filtrerer på den), creator (navn med redigeringslink for `merchboard_manage_creators` og et filterlink; "Creator #ID (not found)" hvis recorden ikke findes; "—" uden creator), actor ("System" ved NULL; "Display Name (login)" med profil-link kun med `edit_user`; "User #ID (no longer exists)" for slettede brugere) og detaljer.
- **Detaljer** vises ikke som rå JSON. `AuditLogFormatter` gør de kendte context-nøgler læsbare (bruger, status, tidligere status, ændrede felter, årsag, setting og ny værdi). Fra fase 17, trin 14 også produkt- og kategori-hændelserne: produkt og produktkategori(er) som "Navn (#ID)" eller "#ID", når de ikke længere findes (produktet linker kun til redigering med `edit_product`, kategorier linker ikke), tidligere creator, oprindelse og tidligere oprindelse ("Manual"/"Product category"), årsag og anledning, valget ved fjernelse af en mapping, "Triggered by" (brugerlabel), kørsel (de første 8 tegn af `run_id`, fuldt id som `title`), omfang, tørkørsel og optællingerne fra `category_sync_completed` som én linje i afstemningens rækkefølge. Ukendte koder vises som de er, escaped. Produkter, kategorier og brugere slås op samlet for hele siden (én forespørgsel hver), creators pr. unik creator som før; context ændres aldrig. Ukendte nøgler vises kun som renset nøgle og kort skalar værdi, og indlejrede data som "…". Alt escapes. Fra fase 18, trin 7 vises `sales_backfill_completed` som "Sales ledger backfill completed" med kørsel, "Triggered by", scannede ordrer, udfaldene på én linje, rækker pr. type og fejl pr. kode (backfillen har intet admin-UI; den startes med WP-CLI, se WOOCOMMERCE.md).
- **Filtre (dokumenteret scope):** hændelse (`AuditAction`-select), creator (via filterlinks) og periode (fra/til, dage i sitets tidszone, omregnet til UTC-grænser). Filtrene er GET-parametre på en read-only side og har derfor ingen nonce. Ugyldige værdier ignoreres med en notice og når aldrig SQL. Gyldige værdier går kun som `prepare()`-argumenter (`AuditRepository::paginate`).
- **Paginering:** 20 pr. side i databasen (`LIMIT/OFFSET` og `COUNT(*)`), så hele tabellen hentes aldrig.
- **Tekniske logs** vises ikke her. Med WooCommerce linkes til WooCommerce → Status → Logs (source `merchboard`) for brugere med `manage_woocommerce`. Uden WooCommerce står der, at de ligger i PHP's fejllog.
- **Sletning:** Records slettes kun ved opt-in-uninstall (ADR-025). I v0.1.0 bevares records ubegrænset uden automatisk sletning (ADR-029). En retention-politik for produktion besluttes før produktionsbrug.

| | **Business audit trail** | **Teknisk logging** |
| --- | --- | --- |
| Formål | Hvem ændrede hvad, hvornår (creators, brugerrelationer, settings) | Fejl og diagnostik til drift |
| Lager | Tabellen `merchboard_audit_log` (ADR-023), append-only | WooCommerce-logger (source `merchboard`) eller PHP `error_log` (ADR-021). Ingen tabel. |
| Visning | Merch Dashboard → Audit log (`merchboard_view_logs`, fase 11) | WooCommerce → Status → Logs (WooCommerce' egen adgang) eller serverens `debug.log` |
| Indhold | Handling, actor, creator og minimal context. Ingen stack traces, ingen tekniske fejl. | Tekniske beskeder og renset context. Aldrig forretningshistorik. |

## WooCommerce-skærme (fase 17)

Kræver WooCommerce ≥ 10.3. Med en ældre version vises kun en requirements-notice og ingen af skærmene nedenfor (ADR-030).

- **Produktkoblinger (ADR-032):**
  - Creatorens redigeringsside (Merch Dashboard → Creators → rediger) får afsnittet "Products". Det viser creatorens koblede produkter og lader admin tilknytte og fjerne produkter. Det er den manuelle skrivevej (`source: admin`). Den anden er den automatiske kategori-afstemning (`source: category`, ADR-033, implementeret i trin 8-9b).
  - Formularerne går via `admin-post.php` med egen nonce og kræver `merchboard_manage_creators` og `edit_product` for hvert produkt. Skrivning sker kun gennem `ProductCreatorLink` (`source: admin`), aldrig med `update_meta_data()`.
  - Et produkt, der er koblet til en anden creator, overtages kun ved eksplicit admin-valg.
  - **Implementeret (fase 17, trin 4):**
    - Afsnittet vises under creator-formularen via hooken `merchboard_creators_edit_sections` (WooCommerce-modulet; Creators-modulet kender ikke WooCommerce).
    - Produktnavne vises kun for brugere med `edit_products`.
    - **Tilknyttede produkter:** højst 200, med navn (#ID), varenummer, status og en knap "Remove". Den afviser et produkt, der tilhører en anden creator, med `linked_elsewhere`.
    - **"Add products":** søgning på navn, beskrivelse eller varenummer via WooCommerce' egen produktsøgning og på produktkategori (`product_cat`, hvis navn indeholder søgeordet). Kategorisøgningen finder kun produkter, der er direkte tildelt kategorien (ingen arv fra parent-kategorier). Resultaterne kombineres uden dubletter, navn/varenummer-match først (højst 20 resultater, kun parent-produkter, alle statusser). Søgningen opretter ingen kobling; den hjælper kun med at finde produkter. Automatiske koblinger kommer kun fra en kategori-mapping (ADR-033). Hvert resultat kan vælges, og flere kan tilknyttes på én gang.
    - **Overtagelse:** Produkter, der er koblet til en anden creator, vises med "Linked to <navn>" og et separat afkrydsningsfelt "Take over the product from <navn>". Det er aldrig valgt på forhånd og sendes som `replace_existing` for netop det produkt. Uden det afvises produktet (`already_linked`) uden ændring.
    - En ugyldig kobling kan erstattes eller fjernes på samme måde.
    - Hvilke produkter der kan kobles (varianter, papirkurv, inaktive creators, `edit_product`), afgøres af `ProductCreatorLink`. Resultatet vises pr. produkt efter Post/Redirect/Get, så fejl ikke skjules.
  - **Implementeret (ADR-033, trin 12a og 13a):**
    - Kolonnen "Origin" viser "Manual" (`admin`, eller uden eller med ukendt oprindelse) eller "Product category" med "Via: <kategori>" og, når afstemningen vil fjerne eller flytte koblingen, en note (vurderet af `CategoryLinkStatus` med den eksisterende reconciler).
    - **Remove afvises for kategori-styrede produkter** i `ProductCreatorLink::unlink()` (`category_managed`); afsnittet viser ingen Remove på dem, men forklaringen og "Keep as manual". Der er ingen skjult undtagelsesliste.
    - **"Keep as manual"** er en eksplicit handling pr. kategori-styret produkt med nonce og bekræftelsesfelt. Den ændrer oprindelsen til `admin`, beholder creatoren og auditeres. Derefter fjernes koblingen aldrig automatisk.
    - Overtagelse af et kategori-styret produkt til en anden creator giver en manuel (`admin`) kobling.
  - **Konflikter (ADR-033):** tvetydige produkter i flere creators' kategorier og produkter i en inaktiv creators kategori vises under "Needs attention" i værktøjet til fuld afstemning (se nedenfor), ligesom manuelle koblinger, der afviger fra produktets kategorier. En manuel kobling (`admin`, uden eller med ukendt oprindelse, eller ugyldig) afviger, når produktets direkte kategorier er mappet til: én anden, aktiv creator ("manual_other_creator"); én anden, inaktiv creator ("manual_inactive_creator"); eller to eller flere creators ("manual_ambiguous"). Ingen mappet creator, eller kun den manuelt koblede (også inaktiv), er ingen afvigelse. Visningen er kun diagnostisk: den manuelle kobling vinder og ændres aldrig.
- **Sales (✅ fase 20, trin 7, ADR-040 pkt. 13 og statusnote 2026-10-07):** afsnittet "Sales" nederst på creatorens redigeringsside (`CreatorSalesSection`, WooCommerce-modulet, `merchboard_creators_edit_sections` med prioritet 20 efter "Products"). Uden WooCommerce findes afsnittet ikke.
  - **Adgang:** kun for `merchboard_manage_creators` (siden afviser andre med `wp_die`, og afsnittet renderer selv intet uden capability). Creatoren er den, siden har indlæst (`creator` i URL'en efter capability-tjekket); afsnittet læser ingen egne request-parametre, så et andet ID i query'en ændrer intet. Vises for aktive og inaktive creators.
  - **Indhold:** standardperioden fra `/me/sales` (30 kalenderdage inklusive i dag i sitets tidszone, `day`) med perioden, forklaringen ("Butikkens nettosalg af creatorens produkter efter rabatter, ekskl. moms, fragt og gebyrer. Det er ikke en afregning. …") og pr. valuta en overskrift med valutakoden, en tabel med "Net sales (excl. VAT)", "Units" og "Orders" og den daglige serie som tabel i en sammenfoldet `<details>` ("Net sales per day"). Flere valutaer: én blok pr. valuta og teksten "Tallene vises pr. valuta og lægges aldrig sammen"; ingen sum og ingen omregning. Ingen salg: "No net sales in the last 30 days." (ingen nul-tal). Ingen månedstotaler, ingen produktopdeling, ingen graf og ingen kundedata (heller ikke ordre-ID'er eller produktnavne).
  - **Inkl. moms (ADR-053, 2026-10-10):** forklaringen siger nu "inkl. moms, ekskl. fragt og gebyrer"; tabellerne viser "Sales (incl. VAT)" (`net_sales_incl_vat`), når alle periodens rækker har gemt moms, ellers "Net sales (excl. VAT)" med noten om manglende moms. Tom periode: "No sales in the last 30 days."
  - **Samme definitioner og cache som creator-dashboardet:** afsnittet kalder `SalesReports` (i produktion `CachedSalesReports` → `SalesReport` → `SalesStatistics`) direkte i PHP med `SalesQuery`'s standardperiode, uden HTTP-kald til `/me/sales` og uden admin-REST. Nettosalg, enheder, ordrer, valuta, tidszone og perioder er derfor præcis ADR-040's (pkt. 2–9), og afsnittet deler cache-post med creatorens `GET /me/sales` uden parametre; en ledger-skrivning invaliderer begge (pkt. 10). Der er ingen egen cache eller egen SQL.
  - **Beløb:** decimalstrengene fra rapporten formateres med heltalsaritmetik (`SalesAmount`, afrundet til to decimaler væk fra nul) og sitets tal-format, fx "1.234,50 DKK"; aldrig floats. En læsefejl giver en notice uden detaljer og logges.
- **Pricing (✅ fase 22, ADR-048/049):**
  - **Placering:** afsnittet "Pricing" på creatorens redigeringsside (`CreatorPricingSection`, `merchboard_creators_edit_sections` med prioritet 8, over "Products"). Ingen ny menu og ingen ændring af WooCommerces skærme.
  - **Model (ADR-049):** siden forklarer, at WooCommerce-salgsprisen er den endelige kundepris inkl. creator-profit og moms, at creator-profit = salgspris × markup %, og at Merch Dashboard aldrig lægger noget oven i eller ændrer WooCommerce-priser. Der er ingen Anvend-knap, ingen grundpris, ingen afvigelse (drift) og ingen forhåndsvisning af en ny pris.
  - **Adgang:** afsnittet renderes kun for `merchboard_manage_creator_pricing`.
    - Alle handlinger går via `admin-post.php` (`CreatorPricingActions`): først capability (403), så en nonce bundet til handling og creator (403), så domæneservicen.
    - Ændring af et produkts egen markup kræver desuden `edit_product` på parent-produktet.
    - Creator-ID'et fra formularen bruges aldrig som ejerskab: det sammenlignes kun med den rigtige ejer (`ProductCreatorLink`), og et produkt tilhørende en anden creator afvises med `not_owned`.
  - **Creator-markup:** creatorens andel af salgsprisen i procent (0–100, højst to decimaler, "." eller ","), gemt som basis points via `MarkupBps`. Nye creators har 0 %. Eksempel på siden: 100,00 × 20 % = 20,00.
  - **Momsregistreret (Ja/Nej):** standard "Nej". "Ja: creatoren får hele creator-profitten udbetalt." "Nej: creatoren får creator-profitten minus 20 %, altså 80 % af profitten." En ændring påvirker hverken priser, tidligere ordrelinjer eller tidligere udbetalinger.
  - **Produktmarkup:**
    - Tabellen viser creatorens koblede produkter (højst 200) med den gældende markup og dens kilde (Creator-markup eller Produktmarkup), WooCommerces priser (normal, udsalg og aktiv, kun visning) og et eksempel på profit og udbetaling ved den aktuelle pris med creatorens nuværende momsstatus.
    - Variable produkter har en række for parent med markup'en og én række pr. variation med variationens egne priser og eksempler ("Hovedproduktets markup").
    - "Redigér markup" (pr. parent): en produktmarkup i procent, som erstatter creatorens markup for produktet og alle varianter; "Brug creatorens markup" fjerner den. En produktmarkup sat for en tidligere ejer vises som ignoreret.
    - Tabellen scroller inden for siden (højst 70 % af vinduets højde).
  - **Resultater:** vises som notices efter Post/Redirect/Get, fx at markup eller momsstatus er gemt og gælder for ordrelinjer, der oprettes fremover, uden prisændring.
- **Payouts (✅ fase 23, trin 23.6, ADR-055/056/058):** undermenuen "Payouts" (`admin.php?page=merchboard-payouts`, `PayoutsPage`) mellem Settings og Order history, kun med WooCommerce.
  - **Adgang:** `merchboard_manage_payouts` (menu, `render()` og handlinger; ellers `wp_die` 403). Fulde oplysninger kræver desuden `merchboard_view_payout_details`; begge kontrolleres i servicen (`PayoutDecisions`), ikke kun i UI'et.
  - **Kø og historik:** faner "Kø" (ventende, ældste først) og "Historik" (øvrige statusser, nyeste behandling først), 20 pr. side: payout-ID, creator, beløb, metode, maskeret modtager, status og datoer. Fulde oplysninger vises aldrig i listerne.
  - **Saldi (✅ trin 24.2, ADR-054):** fanen "Saldi" (`&view=balances`), 20 creators pr. side sorteret efter navn, én række pr. creator og valuta fra `PayoutBalances`: frigivet optjening (salg efter karensperioden fra afregningsstarten; refunderinger med det samme), reserveret (ventende), udbetalt (gennemført), saldo, disponibelt (vist i brugerfladen som ‘Tilgængelig’) og udestående. Kan en ledger-række ikke beregnes, vises optjening, saldo, disponibelt og udestående som "Ukendt" med antallet af ukendte rækker, aldrig som 0. En creator uden optjening eller udbetalinger får rækken "Ingen optjening eller udbetalinger endnu." En side ud over den sidste viser den sidste side. Kun læsning; ingen betalingsoplysninger.
  - **Detalje** (`&payout=<id>`): payoutens data inkl. begrundelse og reference. For en ventende: "Vis fulde betalingsoplysninger" (et nonce-beskyttet link pr. payout; hver visning skrives som `payout_details_viewed` før visning, uden oplysningerne), "Registrér som udbetalt" (kræver afkrydsning af, at overførslen er gennemført uden for Merch Dashboard, valgfri reference) og "Afvis" (begrundelse påkrævet, vises for creatoren). Merch Dashboard sender aldrig penge.
  - **Handlinger:** `admin-post.php` (`PayoutActions`): capability → nonce bundet til handling og payout → input → `PayoutDecisions` (lås, transaktion, audit). Post/Redirect/Get med en fast resultatkode. Dækker saldoen ikke længere beløbet, eller er den ukendt, afvises registreringen til manuel behandling; payouten forbliver ventende. Uden gyldig `MERCHBOARD_PAYOUT_KEY` viser siden en advarsel, og ventende anmodninger kan kun afvises.
    - **Fritekst (`PayoutFreeText`):**
      - Referencen ved "Registrér som udbetalt" og begrundelsen ved "Afvis" afvises, hvis de indeholder betalingsoplysninger: en e-mailadresse, en IBAN med landets længde og gyldig checksum (samlet eller i grupper, også med tekst efter) eller payoutens eget kontonummer (når oplysningerne kan læses; med eller uden mellemrum, punktum eller bindestreg, evt. efter payoutens eget reg.nr. og også ved siden af en dato eller et andet tal; andre cifre skrevet sammen med kontonummeret regnes ikke som kontonummeret). Grænserne står i SECURITY.md.
      - Intet gemmes, og resultatkoden `payment_details_in_text` viser en fejl uden at gengive værdien. Felterne har en hjælpetekst.
  - **Gavekort (✅ trin 23.7, ADR-057):** en ventende gavekort-payout viser beløbet, payout-referencen `MB-PAYOUT-<id>`, et link til pluginets oprettelsesside og formularen "Registrér gavekort" (kodefelt med `autocomplete="off"`, POST, nonce bundet til payout og handling); ingen "Vis fulde betalingsoplysninger" eller "Registrér som udbetalt". `PayoutGiftCards` validerer under creator-låsen og gennemfører payouten; resultatkoden i redirectet, aldrig koden. En gennemført gavekort-payout viser udbyder og kortets ID samt kortets aktuelle status og saldo i pluginet (afstemning), aldrig koden. Uden et valgt og tilgængeligt gavekortplugin kan gavekort ikke registreres (kun afvises).
  - **Afregningsbilag (✅ trin 23.8, ADR-060):** en gennemført payout viser sit afregningsbilag som udstedt: mærket "Foreløbigt, ikke godkendt af revisor/SKAT.", bilagsnummer, udstedelse, periode, creator, momsstatus ved udstedelsen, kommission, overført saldo, udbetalt, saldo efter bilaget, antal afregnede rækker og eventuelle korrigerende bilag. Bilaget dannes automatisk i samme transaktion som "Registrér som udbetalt" eller gavekortregistreringen; fejler det, gennemføres payouten ikke. Payouts gennemført før schema-version 11 har intet bilag. Afregningsstarten kan ikke ændres, når creatoren har et bilag (D3), og en ordre i et bilag kan ikke slettes under Order history (D5, resultatkode `settled`). Admin-formular til korrigerende bilag findes ikke endnu (D11).
  - **Settings → Payouts → Payout-gavekort:** valg af gavekortplugin (standard "Intet"; i dag kun YITH WooCommerce Gift Cards) med status (tilgængelig eller årsagskode); ændringen auditeres som `payout_settings_changed`. Valget installerer, aktiverer eller konfigurerer intet plugin.
- **Order history (✅ fase 22, ADR-051):** undermenuen "Order history" (`admin.php?page=merchboard-order-history`, `OrderHistoryPage`) mellem Settings og Audit log, kun med WooCommerce.
  - **Adgang:** `merchboard_manage_creators` (menu og `render()`; ellers `wp_die` 403). Ren GET-visning uden skrivninger, nonce eller audit.
  - **Liste:** én række pr. ordre i sales ledgeren, 20 pr. side (server-side), nyeste først: ordre-ID, ledger-datoer, creators, valuta, hændelser/linjer, gemt nettobeløb, momsdata ("x af y rækker") og profitdata (komplet med beløb, ellers "Ufuldstændig"/"Ukendt" med antal kendte og ukendte rækker). Filtre: ordre-ID, creator og profitdata ("Alle ordrer", "Med profitdata", "Komplet", "Ufuldstændig", "Ukendt"); ugyldige parametre ignoreres med en notice. **Standard (2026-10-10):** kun ordrer med profitdata; ordrer helt uden profitdata (fx fra før snapshots og moms blev gemt) skjules med en notits om antallet og linket "Vis alle ordrer". Intet slettes.
  - **Detalje** (`&order=<id>`): WooCommerce-status og "Åbn i WooCommerce" (via CRUD; "Slettet i WooCommerce", hvis ordren mangler), ledgerens cyklusser, ordrelinjer med snapshot (andel, kilde, momsstatus) og alle ledger-hændelser med gemt moms og profit eller "Ukendt" med årsagen (`ProfitDiagnosis`).
  - **Afregning (✅ trin 24.2, ADR-060):** listen har kolonnen "Afregning": "Ikke afregnet" eller antal afregnede rækker af ordrens rækker (fx "2 af 3 rækker") med bilagsnumrene; med creator-filteret tælles kun den valgte creators afregnede rækker og bilag. Ordrens ledger-hændelser har kolonnen "Afregning" med bilagsnummeret pr. række eller "Ikke afregnet". Læses fra de gemte afregningsbilag; intet genberegnes. Bilagsnummer og optælling vises for alle med adgang til ordrehistorikken (`merchboard_manage_creators`; bevidst valg 2026-10-13); kun linket til udbetalingen (Payouts) kræver `merchboard_manage_payouts`.
  - **Forklaring** nederst: hvad komplet, ufuldstændig og ukendt betyder, og at historikken er append-only og ikke kan slettes her.
  - **Ingen PII** og ingen WooCommerce-skrivning.
  - **Slet gemt historik (ADR-052, 2026-10-10):** på ordrens side, kun med `merchboard_delete_order_history` (administrator). Er WooCommerce-ordren stadig aktiv, vises kun forklaringen "kan kun slettes, når WooCommerce-ordren ligger i papirkurven eller er slettet". Ellers en formular (POST til `admin-post.php`, nonce pr. ordre) med advarsel og indtastning af ordre-ID'et. Sletter ordrens rækker i ledger, cyklusser, gemt moms og snapshots i én transaktion med audit `order_history_deleted`; ordren forsvinder fra creatorens salg og profit. WooCommerce-ordren ændres ikke. Resultatet vises som notice efter redirect. Ingen massesletning.
- **Produktliste:** skrivebeskyttet kolonne "Creator" og et creator-filter (ADR-032). De viser og filtrerer, men ændrer aldrig koblingen.
  - **Implementeret (fase 17, trin 5, `ProductListCreators`):**
    - Kun på WooCommerce' produktliste (skærmen `edit-product`, også quick edit) og kun for brugere med `merchboard_manage_creators`. Andre brugere får hverken kolonne, filter eller query-ændring, så filterparameteren kan ikke bruges til at afsløre ejerskab.
    - **Kolonnen "Creator"** (før "Date") læser kun `_merchboard_creator_id` på parent-produktet (en variant følger sin parent). Den viser creatorens navn med link til creatorens redigeringsside, "Invalid link", "Creator #ID (not found)" eller "—" uden creator. Kategorier læses ikke.
    - **Filteret** (`merchboard_creator`) står ved WooCommerce' egne filtre: "All creators" og alle eksisterende creators sorteret efter navn (inaktive markeret "(inactive)"). En gyldig værdi tilføjer en meta-query på koblingsnøglen til WordPress' `request` (en eksisterende meta-query bevares med `AND`); "All creators" og ugyldige værdier ændrer intet. Det kombineres med WooCommerce' filtre og paginering, fordi det er en almindelig GET-parameter i listens filterformular. Som `linked_to()` sammenligner MySQL nøglen uden hensyn til store og små bogstaver, så et produkt med en ugyldig kobling kan findes og vises som "Invalid link".
    - Ingen skrivning, ingen audit og ingen kald til `ProductCreatorLink`'s skrivemetoder.
- **WooCommerce-produktskærmen** (klassisk og blok-baseret) viser **ikke** creator-relationen og har intet creator-felt, ingen meta box og intet save-hook (ADR-032).
- Ingen Creator-felt i quick/bulk edit. WooCommerce REST og CSV-import kan ikke ændre koblingen direkte, og "Duplicate" kopierer ikke koblingen (ADR-030). Ændres produktets kategorier via disse veje, eller arver en kopi en creator-kategori, afstemmes koblingen automatisk (ADR-033).
- Ordreliste: kolonnen "Creators" (afledt af ordrelinjer) er **udskudt** til en senere fase (ADR-030 D3).
- **Kategori-mapping (ADR-033, erstatter ADR-031's model; implementeret i trin 7-11):**
  - Creatorens redigeringsside har afsnittet "Product category" (over "Products") med creatorens mapping og en select over `product_cat`. Standardkategorien vises ikke, og kategorier, der er mappet til en anden creator, er deaktiverede med creatorens navn. En mapping til en slettet kategori vises som "Deleted category" og kan ryddes op med samme valg som en fjernelse. Fase 17 tillader én kategori pr. creator (tabellen `merchboard_creator_categories` understøtter teknisk flere).
  - Handlingerne går via `admin-post.php` med egen nonce pr. creator og kræver `merchboard_manage_creators` (`CreatorCategoriesActions`). Nye mappings kan kun gives til aktive creators.
  - Teksten ved feltet siger, at mappingen er en **vedvarende regel**: produkter, der direkte ligger i kategorien, kobles automatisk, og produkter, der forlader den, mister kategori-koblingen. Manuelle koblinger røres ikke.
  - **Oprettelse:** tørkørsel med konsekvenser (kobles, allerede manuelt koblet til samme creator, konflikt med manuel kobling til en anden creator, tvetydig, creator inaktiv), derefter eksplicit bekræftelse. Den første afstemning køres i batches via Action Scheduler og afsluttes med `category_sync_completed`.
  - **Fjernelse:** tørkørsel af begge valg og et eksplicit valg: "Remove category-managed links" eller "Keep as manual links". Ingen masseændring uden bekræftelse. "Keep as manual links" konverterer i admin-requesten (højst 200 pr. kørsel) og fjerner først mappingen, når ingen kategori-kobling af den er tilbage.
  - Sletter nogen en mappet kategori i WooCommerce, fjernes mappingen og de kategori-koblinger, der kun fandtes på grund af den (`reason: term_deleted`). Manuelle koblinger påvirkes ikke.
  - Creators-listen har kolonnen "Product categories" (skrivebeskyttet, trin 11).
- **Fuld afstemning (ADR-033, erstatter ADR-031's backfill; implementeret):** sikkerhedsnet for kategoriændringer, som ingen hook ser. Den separate engangs-backfill "Link products from categories" (ADR-031) bygges ikke.
  - **Placering:** knappen "Category reconciliation" ved "Add New Creator" på Creators-listen åbner visningen `admin.php?page=merchboard-creators&action=category-sync`. Intet nyt menupunkt, ingen ny capability: kun `merchboard_manage_creators` ser knappen og visningen (`CategorySyncScreen`). Creators-siden har fået udvidelsespunkterne `merchboard_creators_list_tools` og `merchboard_creators_view_<action>` (kører først efter capability-tjekket).
  - **Tørkørsel:** "Run a dry run" (GET, skrivebeskyttet) kører `CategoryReconciliationBatch::preview()` over alle relevante produkter og viser tællinger for alle otte udfald, "Needs attention" og de forventede ændringer (højst 500, med nuværende og ny creator). Intet skrives, auditeres eller sættes i kø.
  - **Needs attention:** tvetydige produkter, produkter hvis eneste mappede creator er inaktiv, produkter, der ikke kunne vurderes, og manuelle koblinger, der afviger fra produktets kategorier (se "Konflikter" ovenfor; de tælles stadig som "Manual link, never changed"). Pr. produkt: navn og varenummer (link til produktet kun med `edit_product`), årsag, nuværende creator (markeret "Manual" ved en manuel afvigelse, "(inactive)" ved en inaktiv creator) og de mappede kategorier med creator. Listen beregnes ved hver tørkørsel og gemmes ikke.
  - **Bekræftelse:** formularen (`admin-post.php`, action `merchboard_category_full_sync`, nonce) kræver et afkrydset bekræftelsesfelt og sender tørkørslens fingeraftryk (SHA-256 af tællinger, ændringer og needs attention). `CategorySyncActions` tjekker capability (403), nonce (403) og bekræftelse, og kører så en ny tørkørsel. Afviger den fra den bekræftede, starter intet, og admin får den nye tørkørsel ("Nothing started: the products or mappings changed …"). En forældet tørkørsel bruges aldrig som grundlag.
  - **Kørsel:** asynkront via den eksisterende kæde: `CategoryReconciliationScheduler::run()` med fuldt scope og anledningen `full_sync` sætter et startjob i kø; jobbene kører 100 produkter ad gangen gennem `CategoryReconciliationBatch::run_page()` og `ProductCreatorLink::apply_category_link()` med begrænset retry. `run_id` tildeles af det første job; resumeet er audit-posten `category_sync_completed` (actor: systemet) med tællinger. Uden Action Scheduler starter intet ("queue_unavailable").
  - **Deduplikering:** kun et **ventende** startjob med identisk payload (scope, anledning og `trigger_user_id`) sættes ikke i kø igen, fx ved et dobbeltklik. Er en tidligere kørsel allerede startet (dens første job har kørt, og de næste sider står i kø eller kører), eller starter en anden administrator, sættes en ny kørsel i kø. Flere fulde afstemninger kan derfor køre samtidig, hver med sit eget `run_id` og sin egen `category_sync_completed`. Det er ufarligt, fordi afstemningen er tilstandsbaseret og idempotent: den kørsel, der kommer sidst til et produkt, skriver intet, hvis tilstanden allerede er afstemt.
  - **Fejl pr. produkt:** kan et produkt ikke afstemmes (fx en fejlet skrivning eller læsning), logges det, tælles som `errors`, og kørslen fortsætter med næste produkt. Produktet gentages ikke inden for den fulde afstemning; der er ingen separat retry-løkke pr. produkt. (Jobs for enkelte produkter fra hooks har deres egen begrænsede retry.)
  - **Fejl pr. side:** fejler en hel side (fx kandidatforespørgslen), sættes samme side i kø igen fra samme tilstand, så tællingerne ikke dobbelttælles, med den eksisterende begrænsede retry: højst 3 gentagelser efter 1, 5 og 15 minutter (`CategoryReconciliationJobs`). Derefter opgives kørslen og logges; der skrives ingen `category_sync_completed`.
  - Ingen AJAX-, REST- eller `nopriv`-rute, og ingen ny persistent tilstand.
