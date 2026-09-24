# Feature: Admin audit log

## Purpose
Lader administratorer se den forretningsmæssige historik: hvem der oprettede, ændrede, aktiverede, deaktiverede eller koblede creators, og hvem der ændrede settings. Det er ikke en browser for tekniske logs.

## Current status
Færdig (fase 11).

## User-facing functionality
Ingen for creators. Creator-adgang giver aldrig adgang til audit-loggen.

## Admin functionality
- Merch Dashboard → Audit log: read-only liste, nyeste først, 20 pr. side.
- Filtre: hændelse, creator (via filterlinks i listen) og periode (fra/til i sitets tidszone). "Clear filters" nulstiller.
- Overview viser de 5 seneste hændelser (tid og hændelse) med link hertil.
- Ingen redigering, sletning, oprettelse, eksport eller bulk actions.

## Technical implementation
- `Modules/Admin/AuditLogPage`: menu (submenu under `merchboard`, prioritet 30), capability-tjek, filter-parsing (`filter_from_request`) og rendering.
- `Modules/Admin/AuditLogListTable` (`WP_List_Table`): henter én side via `AuditRepository::paginate` og slår brugerlabels op i én forespørgsel (`UserAccounts::labels`) og creator-navne pr. unik creator på siden.
- `Modules/Admin/AuditLogFormatter`: labels for alle `AuditAction`-cases og læsbar context (whitelist).
- `Core/Audit/AuditFilter` og `AuditRepository::paginate`: validerede filtre og faste, prepared SQL-fragmenter.

## Relevant files
- `src/Modules/Admin/AuditLog*.php`, `src/Modules/Admin/OverviewPage.php`
- `src/Core/Audit/AuditFilter.php`, `src/Core/Audit/AuditRepository.php`
- `tests/Unit/Modules/Admin/AuditLogTest.php`, `tests/Integration/Admin/AuditLogPageTest.php`

## Database/data used
`merchboard_audit_log` (læsning), `merchboard_creators` (navne) og `wp_users` (display name og login, aldrig e-mail). Se [DATABASE.md](../DATABASE.md).

## Permissions
`merchboard_view_logs` for siden og Overview-aktiviteten. Links til creators kræver `merchboard_manage_creators`, til brugerprofiler `edit_user` og til WooCommerce-logs `manage_woocommerce`. Filtre er GET på en read-only side (ingen nonce).

## API endpoints
Ingen.

## Hooks
`admin_menu` (prioritet 30).

## Dependencies
Admin-modulet (menu), Creators-modulet (`CreatorRepository`, `UserAccounts`) og Core (Audit, Capabilities, Logger).

## Tests
Se [TESTING.md](../TESTING.md) under "Fase 11".

## Known limitations
- Ingen retention: records bevares, indtil opt-in-uninstall sletter alt (åbent spørgsmål 10).
- Creator-filteret sættes via links i listen (ingen creator-select), og der er ingen fritekstsøgning.
- En ugyldig række i tabellen (fx ukendt action fra en fremtidig version) stopper visningen med en neutral fejl og logges (fail closed).

## Future improvements
- Retention-politik og evt. CSV-eksport, hvis der opstår et konkret behov.
