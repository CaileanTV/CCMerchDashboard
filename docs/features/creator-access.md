# Feature: Creator access

## Purpose
Sikre, at kun udvalgte WordPress-brugere kan bruge Creator Dashboard, at de sendes dertil efter login, og at en creator aldrig får adgang til andet end sine egne data og ikke til wp-admin.

## Current status
Delvist implementeret. Roller og capabilities (fase 5), domæne (fase 6), adgangspolitik `CreatorContext` og reaktion på slettede brugere (fase 8), **login-redirect, wp-admin-blokering, skjult admin bar og Application Passwords slået fra for rene creators (fase 12, ADR-026)**. Meta-capabilities kommer i fase 14.

## User-facing functionality
- Creator logger ind via den normale WordPress-login og lander på `/creator-dashboard/`.
- Creator, der åbner `/wp-admin/`, sendes til dashboardet.
- Ingen admin bar for creators.
- Inaktiv creator: Dashboardet viser "Your access is not active. Please contact <sitenavn>." og et log ud-link (HTTP 403). API'et svarer 403.
- Ikke-creator, der åbner dashboardet: samme besked (uden at afsløre, om brugeren "næsten" er creator).

## Admin functionality
- Oprette en creator for en eksisterende eller ny WP-bruger (tildeler rollen `merchboard_creator`).
- Deaktivere, genaktivere og frakoble brugeren. Se [admin-creator-management.md](admin-creator-management.md).
- Settings → Access (planlagt): ekstra roller med admin-caps. Login-redirect og wp-admin-blokering er altid slået til for rene creators (ADR-026).

## Technical implementation
- ✅ `MerchBoard\Modules\Access\CreatorContext` (fase 8): `current(): ?Creator`, `current_user_has_access()`, `for_user( int )` og `user_has_access( int )`. Evalueres ved hvert kald (ikke memoiseret, så deaktivering virker straks). Regler i [USER-ROLES.md](../USER-ROLES.md#adgangsregel-for-creator-dashboard).
- ✅ `deleted_user` → `Modules\Creators\DeletedUserHandler` → `CreatorService::release_deleted_user()` (fase 8).
- ✅ `CreatorRestrictions` (fase 12): `applies_to( int )` definerer en ren creator (`merchboard_access_dashboard` uden `merchboard_manage`, `edit_posts` og `manage_woocommerce`). Filtrene `show_admin_bar` og `wp_is_application_passwords_available_for_user` (fail closed uden `WP_User`).
- ✅ `LoginRedirect` (fase 12): `login_redirect` og `woocommerce_login_redirect` (prioritet 100). Kun validerede dashboard-deep-links respekteres (`DashboardRoute::dashboard_url_for()`).
- ✅ `AdminAreaGuard` (fase 12): `admin_init` og `admin_page_access_denied` (prioritet 0). Undtager `wp_doing_ajax()`, `wp_doing_cron()`, REST, CLI, `admin-post.php` og `async-upload.php`.
- `Core\Security\Capabilities`: opretter rolle/caps ved aktivering og versionsskift samt `map_meta_cap` for `merchboard_view_creator`/`merchboard_edit_creator`.

## Relevant files
`src/Core/Security/Capabilities.php`, `src/Modules/Access/*`, `src/Modules/Creators/DeletedUserHandler.php`, `tests/Unit/Modules/Access/*`, `tests/Unit/Modules/Creators/DeletedUserTest.php` og `tests/Integration/Access/*`.

## Database/data used
`merchboard_creators` (`user_id`, `status`) og WordPress roles (`wp_user_roles`-option og `wp_capabilities`-user meta).

## Permissions
Se [USER-ROLES.md](../USER-ROLES.md).

## API endpoints
Ingen egne. Alle `/me`-endpoints bruger `CreatorContext` i deres permission callback.

## Hooks
- Lytter: `deleted_user` (✅ fase 8), `login_redirect`, `woocommerce_login_redirect`, `admin_init`, `admin_page_access_denied`, `show_admin_bar` og `wp_is_application_passwords_available_for_user` (✅ fase 12). Planlagt: `map_meta_cap` (fase 14).
- Udløser (planlagt): `merchboard_creator_access_denied` (til logging/monitorering).

## Dependencies
Creators-modulet (repository) og Core (Capabilities, Logger).

## Tests
- Unit: `CreatorContext` for alle kombinationer af logget ind/cap/record/status.
- ✅ Unit (fase 12): matrix over brugertyper for alle fire restriktioner, redirect-mål (eksterne, `//`, `javascript:`, traversal, deep links), undtagelser og hook-registrering.
- ✅ Integration (fase 12, rigtige roller): login-redirect (også WooCommerce-filteret), wp-admin-redirect og undtagelser, admin bar, Application Passwords (også eksisterende passwords via `wp_authenticate_application_password()`), suspension virker på eksisterende session.

## Known limitations
- Én bruger pr. creator (v1).
- Ingen impersonation.

## Future improvements
- Teams (flere brugere pr. creator med roller).
- Branded login-side (styling af `wp-login.php`).
- Valgfri "log alle sessioner ud ved suspension".
