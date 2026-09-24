# Feature: Creator access

## Purpose
Sikre, at kun udvalgte WordPress-brugere kan bruge Creator Dashboard, at de sendes dertil efter login, og at en creator aldrig får adgang til andet end sine egne data og ikke til wp-admin.

## Current status
Delvist implementeret. Roller og capabilities (fase 5), domæne (fase 6), **adgangspolitik `CreatorContext` og reaktion på slettede brugere (fase 8)**. Login-redirect, wp-admin-blokering, admin bar og application passwords kommer i fase 12 sammen med dashboard-routen. Meta-capabilities kommer i fase 14.

## User-facing functionality
- Creator logger ind via den normale WordPress-login og lander på `/creator-dashboard/`.
- Creator, der åbner `/wp-admin/`, sendes til dashboardet.
- Ingen admin bar for creators.
- Inaktiv creator: Dashboardet viser "Din adgang er ikke aktiv. Kontakt <virksomhed>" og et log ud-link. API'et svarer 403.
- Ikke-creator, der åbner dashboardet: samme besked (uden at afsløre, om brugeren "næsten" er creator).

## Admin functionality
- Oprette en creator for en eksisterende eller ny WP-bruger (tildeler rollen `merchboard_creator`).
- Deaktivere, genaktivere og frakoble brugeren. Se [admin-creator-management.md](admin-creator-management.md).
- Settings → Access: login-redirect til/fra, blokering af wp-admin til/fra, ekstra roller med admin-caps.

## Technical implementation
- ✅ `MerchBoard\Modules\Access\CreatorContext` (fase 8): `current(): ?Creator`, `current_user_has_access()`, `for_user( int )` og `user_has_access( int )`. Evalueres ved hvert kald (ikke memoiseret, så deaktivering virker straks). Regler i [USER-ROLES.md](../USER-ROLES.md#adgangsregel-for-creator-dashboard).
- ✅ `deleted_user` → `Modules\Creators\DeletedUserHandler` → `CreatorService::release_deleted_user()` (fase 8).
- `LoginRedirect`: filter `login_redirect` (+ WooCommerce `woocommerce_login_redirect`).
- `AdminAreaGuard`: `admin_init`. Redirecter creators uden `merchboard_manage` væk fra wp-admin og undtager `admin-ajax.php`, `admin-post.php` og `async-upload.php`. Brug `wp_doing_ajax()` og tjek `$pagenow`.
- `show_admin_bar`-filter og `wp_is_application_passwords_available_for_user`-filter.
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
- Lytter: `deleted_user` (✅ fase 8). Planlagt: `login_redirect`, `woocommerce_login_redirect`, `admin_init`, `show_admin_bar`, `wp_is_application_passwords_available_for_user` (fase 12) og `map_meta_cap` (fase 14).
- Udløser (planlagt): `merchboard_creator_access_denied` (til logging/monitorering).

## Dependencies
Creators-modulet (repository) og Core (Capabilities, Logger).

## Tests
- Unit: `CreatorContext` for alle kombinationer af logget ind/cap/record/status.
- Integration: login-redirect, wp-admin-redirect (og undtagelser), application passwords deaktiveret, suspension virker på eksisterende session.

## Known limitations
- Én bruger pr. creator (v1).
- Ingen impersonation.

## Future improvements
- Teams (flere brugere pr. creator med roller).
- Branded login-side (styling af `wp-login.php`).
- Valgfri "log alle sessioner ud ved suspension".
