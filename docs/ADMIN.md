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
├── Settings                   ✅ fase 9: "Data on uninstall" (opt-in for permanent sletning); ✅ fase 12: "Creator dashboard" (URL-slug). Tabs General · Access · Advanced kommer, når de får indhold
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

**"Delete all Merch Dashboard data when the plugin is deleted"** (default fra):

- Siden viser tydeligt, om permanent sletning er slået til (rød advarsel) eller fra, hvad der slettes, og hvad der aldrig slettes.
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

Afinstallation med eksplicit opt-in ("Delete all Merch Dashboard data when the plugin is deleted")
→ Merch Dashboards egne data slettes, inkl. product meta `_merchboard_creator_id` (fra fase 17, ADR-030)
→ alle andre WordPress- og WooCommerce-data bevares
```

**Planlagte settings** (når de får indhold):

| Tab | Indstillinger |
| --- | --- |
| General | Branding (logo, primærfarve, navn vist i dashboard). Dashboard-slug er implementeret (fase 12). |
| Access | Hvilke roller ud over administrator der får admin-caps (fx `shop_manager`). Login-redirect og wp-admin-blokering for rene creators er altid slået til (ADR-026). Til/fra-knapper tilføjes kun ved et konkret behov. |
| Advanced | "Slet alle data ved afinstallation" (fra). En indstilling for audit-retention (dage) findes ikke i v0.1.0 (ADR-029) og afhænger af den retention-politik, der skal besluttes før produktion. Teknisk log-niveau styres af `WP_DEBUG`/filteret `merchboard_log_level` (ADR-021). |

Settings gemmes i én option `merchboard_settings`. Alt læses gennem `Core\Settings\Settings::sanitize()` (whitelist af nøgler og typer), så en forfalsket eller korrupt værdi aldrig giver en uventet indstilling.

### Audit log ✅ (fase 11)

`Modules/Admin/AuditLogPage` og `AuditLogListTable` (`WP_List_Table`), capability `merchboard_view_logs`. Den tjekkes i menuen, af WordPress ved direkte adgang og igen i `render()`. Siden er **read-only**: der er ingen formularer, der ændrer noget, ingen bulk- eller row actions, ingen sletning og ingen oprettelse. Se [features/admin-audit-log.md](features/admin-audit-log.md).

- **Kolonner:** tid (sitets tidszone), hændelse (link, der filtrerer på den), creator (navn med redigeringslink for `merchboard_manage_creators` og et filterlink; "Creator #ID (not found)" hvis recorden ikke findes; "—" uden creator), actor ("System" ved NULL; "Display Name (login)" med profil-link kun med `edit_user`; "User #ID (no longer exists)" for slettede brugere) og detaljer.
- **Detaljer** vises ikke som rå JSON. `AuditLogFormatter` gør de kendte context-nøgler læsbare (bruger, status, tidligere status, ændrede felter, årsag, setting og ny værdi). Fra fase 17, trin 14 også produkt- og kategori-hændelserne: produkt og produktkategori(er) som "Navn (#ID)" eller "#ID", når de ikke længere findes (produktet linker kun til redigering med `edit_product`, kategorier linker ikke), tidligere creator, oprindelse og tidligere oprindelse ("Manual"/"Product category"), årsag og anledning, valget ved fjernelse af en mapping, "Triggered by" (brugerlabel), kørsel (de første 8 tegn af `run_id`, fuldt id som `title`), omfang, tørkørsel og optællingerne fra `category_sync_completed` som én linje i afstemningens rækkefølge. Ukendte koder vises som de er, escaped. Produkter, kategorier og brugere slås op samlet for hele siden (én forespørgsel hver), creators pr. unik creator som før; context ændres aldrig. Ukendte nøgler vises kun som renset nøgle og kort skalar værdi, og indlejrede data som "…". Alt escapes.
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
