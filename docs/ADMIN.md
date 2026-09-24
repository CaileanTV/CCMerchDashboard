# WordPress Admin

> Status: **Creator-administration implementeret (fase 6).** Overview og Settings kommer i fase 9, audit-visning i fase 11.

## Principper

- Følger WordPress' admin-mønstre: `add_menu_page`/`add_submenu_page`, Settings API, `WP_List_Table`, admin notices og standard-CSS-klasser (`wrap`, `form-table`, `button-primary`). Administratorer kender dem, de er tilgængelige, og de kræver ingen build.
- Server-renderet PHP. Formularer poster til `admin-post.php?action=merchboard_*` med `check_admin_referer()` og `current_user_can()` og følger Post/Redirect/Get med admin notice.
- React bruges kun på en admin-skærm, hvis der er et konkret behov for rig interaktivitet (fx grafer), og da gerne med `@wordpress/components`.

## Menustruktur

```text
Merch Dashboard                       (fase 6: menu-capability merchboard_manage_creators; fase 9: merchboard_manage)
├── Overview                   [fase 9]                   nøgletal, seneste audit-hændelser, systemstatus (WooCommerce aktiv? rewrite OK? migrationer OK?)
├── Creators                   ✅ fase 6: liste (WP_List_Table: status-filter, sortering, paginering)
│   └── (Add/Edit creator)     ✅ fase 6: visningsnavn, status, WordPress-bruger
├── Settings                   [fase 9] tabs: General · Access · Advanced (senere: Integrations, WooCommerce)
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
- Brugervalget viser kun ID, display name og login, aldrig e-mail. Det tilbyder op til 500 brugere, som ikke allerede er koblet til en anden creator. Den aktuelle bruger forbliver valgbar. Serveren validerer altid (bruger findes, ikke koblet andetsteds), og UNIQUE-indexet er sidste sikkerhedsnet.
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

### Bruger-profil (`user-edit.php`)

En lille informationsboks: "Denne bruger er creator: <link>" eller "Gør til creator" (link til opret-siden med forvalgt bruger).

### Settings

| Tab | Indstillinger |
| --- | --- |
| General | Dashboard-slug (default `creator-dashboard`), branding (logo, primærfarve, navn vist i dashboard) |
| Access | Login-redirect for creators (til), blokér wp-admin for creators (til), hvilke roller ud over administrator der får admin-caps (fx `shop_manager`) |
| Advanced | Audit-retention (dage), "Slet alle data ved afinstallation" (fra). Teknisk log-niveau styres af `WP_DEBUG`/filteret `merchboard_log_level` (ADR-021). |

Settings gemmes i én option `merchboard_settings` via Settings API med `sanitize_callback` for hele arrayet (whitelist af nøgler og typer).

### Audit log

`WP_List_Table` over `merchboard_audit_log` (fase 6/11): tidspunkt (lokal tid), level-badge, channel, besked, actor (bruger-link) og creator. Filtrering på level, channel og periode. Detaljevisning af context-JSON (escaped). Ingen redigering. Sletning kun via retention.

## WooCommerce-skærme (fase 17)

- Produktliste: kolonne "Creator" og dropdown-filter.
- Produkt-redigering: felt "Creator" i *General*-tabben (select over aktive creators). Gemmes via `$product->update_meta_data( '_merchboard_creator_id', … )` med capability-check (`edit_product`) og WooCommerce' egen nonce.
- Ordreliste (HPOS og legacy): valgfri kolonne "Creators" (afledt fra ordrelinjer).
