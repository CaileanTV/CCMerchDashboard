# Feature: Admin – creator management

## Purpose
Lade administratorer vælge, hvilke WordPress-brugere der er creators, og vedligeholde creator-oplysninger og adgang.

## Current status
Implementeret i fase 6 (flyttet frem fra fase 10). Resterende i fase 10: link på `user-edit.php` og søgbart brugervalg (AJAX) til store sites.

## User-facing functionality
Ingen direkte. Effekten er, at creators får eller mister adgang til dashboardet.

## Admin functionality
- **Merch Dashboard → Creators:** liste med statusfilter, sortering og paginering. Row actions: Rediger og Aktivér/Deaktivér.
- **Add New Creator:** visningsnavn, status (default inaktiv) og valgfri WordPress-bruger (eksisterende brugere, der ikke er koblet andetsteds).
- **Rediger:** visningsnavn, status og WordPress-bruger (tilknyt, skift eller frakobl).
- Ingen sletning. Ingen oprettelse af WordPress-brugere herfra.
- Alle ændringer registreres i audit trail (visning i fase 11).

Se [ADMIN.md](../ADMIN.md#creators-liste--fase-6).

## Technical implementation
- `Modules/Creators/Admin/CreatorsAdminPage`: menu, list/form-rendering og notices.
- `Modules/Creators/Admin/CreatorsListTable` (`WP_List_Table`).
- `Modules/Creators/Admin/CreatorAdminActions`: `admin_post_merchboard_save_creator` og `admin_post_merchboard_set_creator_status`. Capability → nonce (bundet til ID) → input → service → PRG med whitelistet notice.
- `Modules/Creators/CreatorService`: authorization, forretningsregler, transaktion (ændring + audit) og rolle-tildeling efter commit.
- `Modules/Creators/UserAccounts` / `WordPressUserAccounts`: bruger-eksistens, labels og creator-rollen.
- `Core/Audit/*` og `Core/Database/Transaction`.

## Relevant files
`src/Modules/Creators/Admin/*`, `src/Modules/Creators/CreatorService.php`, `src/Modules/Creators/CreatorException.php`, `src/Modules/Creators/*UserAccounts.php`, `src/Core/Audit/*`, `src/Core/Database/Transaction.php`, `src/Core/Database/Migrations/CreateAuditLogTable.php` og `tests/Unit/Modules/Creators/**`, `tests/Unit/Core/Audit/*`.

## Database/data used
`merchboard_creators`, `merchboard_audit_log`, WordPress-brugere (læsning af ID, display name og login) og rollen `merchboard_creator` i `wp_capabilities`.

## Permissions
`merchboard_manage_creators` på side, handlers og service. Nonce bundet til handling og creator-ID.

## API endpoints
Ingen (server-renderet). Admin-REST kun ved senere behov (se [API.md](../API.md#admin-scope-merchboardv1admin)).

## Hooks
Lytter på `admin_menu`, `admin_init` og `admin_post_merchboard_*`. Udløser endnu ingen egne actions (audit trail dækker registreringen).

## Dependencies
Core (Capabilities, Logger, Audit, Transaction, Migrator). WooCommerce er ikke påkrævet (verificeret med og uden).

## Tests
Se [TESTING.md](../TESTING.md). Service, handlers, rendering, audit, transaktion og repository, plus Playground-flow via det rigtige admin-UI.

## Known limitations
- Ingen import/eksport af creators.
- Brugervalget er en select med op til 500 brugere (søgbart valg i fase 10).
- Ved validering går indtastede værdier tabt ved redirect (PRG). Brugeren ser fejlmeddelelsen og indtaster igen.
- Sletning af en tilknyttet WordPress-bruger efterlader et dinglende `user_id` indtil `deleted_user`-hooken (fase 8).

## Future improvements
Sales-tab pr. creator, CSV-eksport og invitations-flow med branded e-mail.
