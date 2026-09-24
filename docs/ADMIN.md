# WordPress Admin

> Status: **Admin-shell komplet (fase 10):** topmenu med Overview (`merchboard_manage`), Creators (fase 6, søgbart brugervalg fra fase 10) og Settings (fase 9). Audit-visning kommer i fase 11.

## Principper

- Følger WordPress' admin-mønstre: `add_menu_page`/`add_submenu_page`, Settings API, `WP_List_Table`, admin notices og standard-CSS-klasser (`wrap`, `form-table`, `button-primary`). Administratorer kender dem, de er tilgængelige, og de kræver ingen build.
- Server-renderet PHP. Formularer poster til `admin-post.php?action=merchboard_*` med `check_admin_referer()` og `current_user_can()` og følger Post/Redirect/Get med admin notice.
- React bruges kun på en admin-skærm, hvis der er et konkret behov for rig interaktivitet (fx grafer), og da gerne med `@wordpress/components`.

## Menustruktur

```text
Merch Dashboard                       ✅ fase 10: menu-capability merchboard_manage (slug `merchboard`, Modules/Admin/AdminMenu)
├── Overview                   ✅ fase 10: creator-tal, dinglende links og systemstatus (seneste audit-hændelser: fase 11)
├── Creators                   ✅ fase 6: liste (WP_List_Table: status-filter, sortering, paginering)
│   └── (Add/Edit creator)     ✅ fase 6: visningsnavn, status, WordPress-bruger
├── Settings                   ✅ fase 9: kun "Data on uninstall" (opt-in for permanent sletning). Tabs General · Access · Advanced kommer, når de får indhold
└── Audit log  [fase 11]                 filtrér på hændelse, creator og periode; kræver merchboard_view_logs (tekniske logs: WooCommerce → Status → Logs)
```

### Kritik af det oprindelige forslag og ændringer

| Oprindeligt | Anbefaling | Hvorfor |
| --- | --- | --- |
| **Products** og **Orders** som egne menupunkter | **Integrér i WooCommerce' egne skærme** i stedet: "Creator"-kolonne og -filter på produkt- og ordrelisterne samt et "Creator"-felt i produktets data-panel | At duplikere WooCommerce' produkt- og ordre-UI giver to steder at redigere, synk-fejl og meget kode. Administratorer arbejder allerede i WooCommerce. |
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
- Der vises ingen salgstal eller statistik, fordi de ikke findes endnu. Seneste audit-hændelser kommer med audit-visningen i fase 11 (`merchboard_view_logs`). Rewrite-status kommer, når dashboard-routen findes (fase 12).

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

```text
Deaktivering
→ data bevares (tabeller, options, rolle og capabilities uændrede)

Afinstallation (slet plugin) uden eksplicit opt-in
→ data bevares

Afinstallation med eksplicit opt-in ("Delete all Merch Dashboard data when the plugin is deleted")
→ Merch Dashboards egne data slettes
→ WordPress- og WooCommerce-data bevares
```

**Planlagte settings** (når de får indhold):

| Tab | Indstillinger |
| --- | --- |
| General | Dashboard-slug (default `creator-dashboard`), branding (logo, primærfarve, navn vist i dashboard) |
| Access | Login-redirect for creators (til), blokér wp-admin for creators (til), hvilke roller ud over administrator der får admin-caps (fx `shop_manager`) |
| Advanced | Audit-retention (dage), "Slet alle data ved afinstallation" (fra). Teknisk log-niveau styres af `WP_DEBUG`/filteret `merchboard_log_level` (ADR-021). |

Settings gemmes i én option `merchboard_settings`. Alt læses gennem `Core\Settings\Settings::sanitize()` (whitelist af nøgler og typer), så en forfalsket eller korrupt værdi aldrig giver en uventet indstilling.

### Audit log

`WP_List_Table` over `merchboard_audit_log` (fase 6/11): tidspunkt (lokal tid), level-badge, channel, besked, actor (bruger-link) og creator. Filtrering på level, channel og periode. Detaljevisning af context-JSON (escaped). Ingen redigering. Sletning kun via retention.

## WooCommerce-skærme (fase 17)

- Produktliste: kolonne "Creator" og dropdown-filter.
- Produkt-redigering: felt "Creator" i *General*-tabben (select over aktive creators). Gemmes via `$product->update_meta_data( '_merchboard_creator_id', … )` med capability-check (`edit_product`) og WooCommerce' egen nonce.
- Ordreliste (HPOS og legacy): valgfri kolonne "Creators" (afledt fra ordrelinjer).
