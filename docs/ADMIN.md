# WordPress Admin

> Status: **Design.** Admin-shell kommer i fase 9, creator-administration i fase 10, logs i fase 11.

## Principper

- Følger WordPress' admin-mønstre: `add_menu_page`/`add_submenu_page`, Settings API, `WP_List_Table`, admin notices og standard-CSS-klasser (`wrap`, `form-table`, `button-primary`). Administratorer kender dem, de er tilgængelige, og de kræver ingen build.
- Server-renderet PHP. Formularer poster til `admin-post.php?action=merchboard_*` med `check_admin_referer()` og `current_user_can()` og følger Post/Redirect/Get med admin notice.
- React bruges kun på en admin-skærm, hvis der er et konkret behov for rig interaktivitet (fx grafer), og da gerne med `@wordpress/components`.

## Menustruktur

```text
Merch Dashboard                       (capability: merchboard_manage)
├── Overview                   nøgletal, seneste audit-hændelser, systemstatus (WooCommerce aktiv? rewrite OK? migrationer OK?)
├── Creators                   liste (WP_List_Table: søg, filtrér på status, paginering)
│   └── (Add/Edit creator)     skjult side: vælg/opret WP-bruger, visningsnavn, slug, status, profilfelter
├── Settings                   tabs: General · Access · Advanced (senere: Integrations, WooCommerce)
└── Audit log                  filtrér på hændelse, creator og periode; kræver merchboard_view_logs (tekniske logs: WooCommerce → Status → Logs)
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

### Creators-liste

Kolonner: Visningsnavn (link til redigering), WP-bruger (link til `user-edit.php`), status-badge, antal produkter (fra fase 17) og oprettet. Row actions: Rediger, Deaktivér/Aktivér. Bulk actions: Deaktivér, Aktivér. Alle actions er nonce-beskyttede og audit-logges.

### Opret/rediger creator

- **Opret:** vælg eksisterende WP-bruger (søgbar dropdown, kun brugere uden creator-record) **eller** opret ny bruger (brugernavn og e-mail; WordPress sender "set password"-mail, og vi opretter aldrig passwords).
- Oprettelse af creator giver automatisk rollen `merchboard_creator` (tilføjet som *ekstra* rolle, så eksisterende roller, fx `customer`, bevares).
- Ændring af status til inaktiv fjerner adgang øjeblikkeligt: `CreatorContext` kræver `active`.
- Sikkerhed: En admin kan ikke tildele en rolle højere end sin egen. Creator-rollen giver ingen admin-caps.

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
