# Feature: Admin – creator management

## Purpose
Lade administratorer vælge, hvilke WordPress-brugere der er creators, og vedligeholde creator-oplysninger og adgang.

## Current status
Planlagt (fase 10).

## User-facing functionality
Ingen direkte. Effekten er, at creators får eller mister adgang til dashboardet.

## Admin functionality
- **Merch Dashboard → Creators:** liste med søgning, statusfilter, paginering, row actions og bulk actions.
- **Tilføj creator:** vælg eksisterende bruger (uden creator-record) eller opret ny bruger (brugernavn og e-mail; WordPress sender set-password-mail).
- **Rediger:** visningsnavn, slug, status og profilfelter.
- **Statusskift:** Suspendér, Genaktivér og Arkivér med bekræftelse.
- Link fra brugerprofil (`user-edit.php`) til creator og omvendt.
- Alle ændringer audit-logges.

Se [ADMIN.md](../ADMIN.md#creators-liste).

## Technical implementation
- `Modules/Creators/Admin/CreatorsPage` (routing mellem liste og redigering via `?page=merchboard-creators&action=edit&creator=ID`).
- `CreatorsListTable extends WP_List_Table`.
- Form-handlers på `admin_post_merchboard_save_creator` og `admin_post_merchboard_change_creator_status` med `check_admin_referer()` + `current_user_can( 'merchboard_manage_creators' )` og PRG-redirect med notice.
- `CreatorService` validerer (unik bruger, unik slug og gyldig status) og udløser `merchboard_creator_created`/`_updated`/`_status_changed`, som Logger lytter på (audit).

## Relevant files
Planlagt: `src/Modules/Creators/Admin/*`, `src/Modules/Creators/CreatorService.php` og `tests/Integration/Creators/*`.

## Database/data used
`merchboard_creators`, `merchboard_creatormeta`, `wp_users` (oprettelse via `wp_insert_user()`) og roller.

## Permissions
`merchboard_manage_creators` på side, handlers og row actions. Nonce på alle formularer og links med sideeffekt.

## API endpoints
Ingen i v1 (server-renderet). Admin-REST kun ved behov (se [API.md](../API.md#admin-scope-merchboardv1admin)).

## Dependencies
Core (Capabilities, Logger, Migrator) og Creators-domænet.

## Tests
Integration: handlers uden capability → afvist, uden nonce → afvist, dublet-bruger/slug → valideringsfejl, statusskift → adgang ændres og audit-log skrives.

## Known limitations
Ingen import/eksport af creators i v1.

## Future improvements
Sales-tab pr. creator, CSV-eksport og invitations-flow med branded e-mail.
